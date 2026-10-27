from __future__ import annotations

import copy
import importlib.util
import io
import json
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha22.py"


def load_builder():
    if not BUILDER_PATH.is_file():
        raise AssertionError("the prealpha.22 builder has not been implemented")
    spec = importlib.util.spec_from_file_location("department_prealpha22", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.22 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha22BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = load_builder()

    def test_contract_pins_exact_prealpha21_and_dev27_inputs(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.22")
        self.assertEqual(builder.OWNER_VERSION, "0.1.0-dev.27")
        self.assertEqual(builder.OWNER_COMMIT, "d1eaf714a7661a1f785a4d8c2131c3565a6eddee")
        self.assertEqual(
            builder.OWNER_MANIFEST_SHA256,
            "157610b7d2d79cf68d6203c61fb7768399b3d5b6d4e0bdfc56651d2d0ab3b22d",
        )
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.21")
        self.assertEqual(
            builder.BASELINE_DPKG_MEMBER,
            "CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.21.dwpkg",
        )
        self.assertEqual(
            builder.BASELINE_SHA256,
            "53cc3fcf7d625a8e2694a5ab857c39b488854d6060bcf87bf346fdc67a2081d0",
        )
        self.assertEqual(builder.BASELINE_ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 8))
        self.assertEqual(builder.ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 10))
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.DEPARTMENT_EXPECTED_MEMBERS), 30)
        self.assertEqual(builder.DEPARTMENT_UPDATED_MEMBERS, {
            "manifest.json",
            "server/manifest.json",
            "server/adapters/correspondence.php",
            "checksums.sha256",
        })
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["version"], builder.VERSION)

    def test_baseline_and_build_preserve_21_mo2_members_and_globals(self) -> None:
        builder = self.builder
        baseline = self._synthetic_baseline(builder)
        with patch.object(builder.BASE, "sha256", side_effect=self._fixture_sha(builder, baseline)):
            base_files, old_department, old_pcv = builder._read_baseline_bytes(baseline)
            source = self._department_sources(builder, old_department)
            department_zip, department = builder._build_department_package(old_department, source.__getitem__)
            assembled = builder._assemble_mod(base_files, department_zip)

            self.assertEqual(len(assembled), 22)
            self.assertEqual(set(base_files) - set(assembled), {builder.BASELINE_DPKG_MEMBER})
            self.assertEqual(set(assembled) - set(base_files), {builder.DPKG_MEMBER})
            unchanged = set(base_files) & set(assembled)
            self.assertEqual(len(unchanged), 21)
            self.assertTrue(all(base_files[path] == assembled[path] for path in unchanged))
            self.assertEqual(len(old_department), 30)
            self.assertEqual(len(old_pcv), 6)
            self.assertEqual(department["server/globals.php"], old_department["server/globals.php"])
            changed = {path for path in old_department if old_department[path] != department[path]}
            self.assertEqual(changed, builder.DEPARTMENT_UPDATED_MEMBERS)
            self.assertEqual(
                builder.P21._audit_department_component(department_zip, builder.VERSION), department
            )

            installable = builder.BASE.stable_zip_bytes(assembled)
            self.assertEqual(builder._read_installable(installable), assembled)
            with zipfile.ZipFile(io.BytesIO(installable)) as archive:
                timestamp = archive.getinfo(builder.DPKG_MEMBER).date_time
            self.assertEqual(timestamp, builder.ZIP_TIMESTAMP)
            self.assertGreater(timestamp, builder.BASELINE_ZIP_TIMESTAMP)

        with self.assertRaisesRegex(builder.PackageError, "exact verified prealpha.21"):
            builder._read_baseline_bytes(b"wrong archive")

    def test_owner_pin_parser_requires_manifest_only_change_in_exact_closure(self) -> None:
        builder = self.builder
        old_pins = self._pins(builder, "bee3cd6fea49e49c5e631d8da86073c02d2c931100a4da942c42d4488ce2e0f8")
        new_pins = dict(old_pins)
        new_pins["manifest.json"] = builder.OWNER_MANIFEST_SHA256
        old_adapter = self._adapter_map("0.1.0-dev.26", old_pins)
        new_adapter = self._adapter_map("0.1.0-dev.27", new_pins)
        self.assertEqual(builder._validate_pin_transition(old_adapter, new_adapter), new_pins)

        changed_runtime = dict(new_pins)
        changed_runtime["lib/file-00.php"] = "f" * 64
        with self.assertRaisesRegex(builder.PackageError, "only the owner manifest"):
            builder._validate_pin_transition(old_adapter, self._adapter_map("0.1.0-dev.27", changed_runtime))

        with self.assertRaises(builder.PackageError):
            builder._literal_owner_closure_pins(self._adapter_map("0.1.0-dev.27", dict(list(new_pins.items())[:-1])), "0.1.0-dev.27")

    def test_package_rejects_unreviewed_source_manifest_and_pin_drift(self) -> None:
        builder = self.builder
        previous = self._department_component(builder, builder.BASELINE_VERSION)
        source = self._department_sources(builder, previous)

        extra_adapter_change = dict(source)
        extra_adapter_change["server/adapters/mp.php"] = b"unreviewed adapter change"
        with self.assertRaisesRegex(builder.PackageError, "fixed prealpha.22"):
            builder._build_department_package(previous, extra_adapter_change.__getitem__)

        changed_manifest = dict(source)
        inner = json.loads(changed_manifest["server/manifest.json"].decode("utf-8"))
        inner["description"] = "unreviewed manifest drift"
        changed_manifest["server/manifest.json"] = builder.BASE.json_bytes(inner)
        with self.assertRaisesRegex(builder.PackageError, "manifest"):
            builder._build_department_package(previous, changed_manifest.__getitem__)

        changed_pin = dict(source)
        pins = self._pins(builder, builder.OWNER_MANIFEST_SHA256)
        pins["lib/file-00.php"] = "f" * 64
        changed_pin["server/adapters/correspondence.php"] = (
            self._adapter_map("0.1.0-dev.26", self._pins(builder, "bee3cd6fea49e49c5e631d8da86073c02d2c931100a4da942c42d4488ce2e0f8"))
            + self._adapter_map("0.1.0-dev.27", pins)
        )
        with self.assertRaisesRegex(builder.PackageError, "only the owner manifest"):
            builder._build_department_package(previous, changed_pin.__getitem__)

        missing = dict(source)
        del missing["server/adapters/correspondence.php"]
        with self.assertRaises(builder.PackageError):
            builder._build_department_package(previous, missing.__getitem__)

        changed_globals = dict(source)
        changed_globals["server/globals.php"] = b"unreviewed connection guard drift"
        with self.assertRaisesRegex(builder.PackageError, "source changes.*globals"):
            builder._build_department_package(previous, changed_globals.__getitem__)

    def test_compatibility_receipt_is_canonical_source_bound_and_fixed(self) -> None:
        builder = self.builder
        commit, blobs, receipt = self._receipt_fixture(builder)
        with patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]):
            accepted = builder._validate_compatibility_receipt("git", commit, builder.BASE.json_bytes(receipt))
        self.assertEqual(accepted["ownerCommit"], builder.OWNER_COMMIT)

        invalid = []
        wrong_source = copy.deepcopy(receipt)
        wrong_source["sourceCommit"] = "b" * 40
        invalid.append(builder.BASE.json_bytes(wrong_source))
        wrong_owner = copy.deepcopy(receipt)
        wrong_owner["ownerCommit"] = "c" * 40
        invalid.append(builder.BASE.json_bytes(wrong_owner))
        wrong_pin = copy.deepcopy(receipt)
        wrong_pin["ownerClosurePins"]["lib/file-00.php"] = "f" * 64
        invalid.append(builder.BASE.json_bytes(wrong_pin))
        wrong_adapter_hash = copy.deepcopy(receipt)
        wrong_adapter_hash["adapterSha256"] = "f" * 64
        invalid.append(builder.BASE.json_bytes(wrong_adapter_hash))
        missing_check = copy.deepcopy(receipt)
        missing_check["checks"].pop()
        invalid.append(builder.BASE.json_bytes(missing_check))
        unknown_field = copy.deepcopy(receipt)
        unknown_field["fixtureToken"] = "must not enter release proof"
        invalid.append(builder.BASE.json_bytes(unknown_field))
        wrong_limit = copy.deepcopy(receipt)
        wrong_limit["limits"]["http"] = 1
        invalid.append(builder.BASE.json_bytes(wrong_limit))
        canonical = builder.BASE.json_bytes(receipt)
        invalid.extend((b"\xef\xbb\xbf" + canonical, canonical.replace(b"\n", b"\r\n")))

        for data in invalid:
            with self.subTest(data=data[:40]), patch.object(
                builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]
            ), self.assertRaises(builder.PackageError):
                builder._validate_compatibility_receipt("git", commit, data)

    def test_proof_contains_only_fixed_receipt_and_provenance_and_checksums_are_lf(self) -> None:
        builder = self.builder
        commit, _blobs, receipt = self._receipt_fixture(builder)
        compatibility = builder.BASE.json_bytes(receipt)
        proof = builder._proof_zip(commit, b"installable", b"source", b"department", compatibility, receipt)
        proof_files = builder.BASE.read_zip_bytes(proof, "compatibility proof fixture")
        self.assertEqual(set(proof_files), {builder.INPUT_COMPATIBILITY, "package-provenance.json"})
        provenance = json.loads(proof_files["package-provenance.json"].decode("utf-8"))
        self.assertEqual(provenance["unchangedMo2Members"], 21)
        self.assertEqual(provenance["departmentDpkgDelta"], sorted(builder.DEPARTMENT_UPDATED_MEMBERS))
        self.assertEqual(provenance["limits"], builder.PROOF_LIMITS)
        self.assertNotIn("rawEvidence", provenance)

        outputs = {"a.zip": b"A", "b.zip": b"B"}
        checksums = builder.BASE.make_checksums(outputs)
        self.assertNotIn(b"\r", checksums)
        builder.BASE.audit_checksums_file(checksums, outputs)

    def test_public_allowlist_contains_release_sources_and_no_artifacts(self) -> None:
        builder = self.builder
        document = json.loads((ROOT / "packaging" / "public-source-allowlist.json").read_text(encoding="utf-8"))
        paths = {entry["path"] for entry in document["files"]}
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS.issubset(paths))
        self.assertFalse(any(path.startswith("artifacts/") for path in paths))

    @staticmethod
    def _pins(builder, manifest_hash: str) -> dict[str, str]:
        pins = {"manifest.json": manifest_hash}
        pins.update({f"lib/file-{index:02d}.php": f"{index:064x}" for index in range(18)})
        return pins

    @staticmethod
    def _adapter_map(version: str, pins: dict[str, str]) -> bytes:
        rows = "".join(f"        '{path}' => '{digest}',\n" for path, digest in pins.items())
        return f"    '{version}' => [\n{rows}    ],\n".encode("utf-8")

    @classmethod
    def _receipt_fixture(cls, builder):
        commit = "a" * 40
        pins = cls._pins(builder, builder.OWNER_MANIFEST_SHA256)
        adapter = cls._adapter_map("0.1.0-dev.27", pins)
        protocol = b"protocol source at selected commit"
        blobs = {builder.DEPARTMENT_ADAPTER: adapter, builder.DEPARTMENT_PROTOCOL: protocol}
        receipt = {
            "schemaVersion": 1,
            "sourceCommit": commit,
            "ownerVersion": builder.OWNER_VERSION,
            "ownerCommit": builder.OWNER_COMMIT,
            "ownerClosurePins": pins,
            "adapterSha256": builder.BASE.sha256(adapter),
            "protocolSha256": builder.BASE.sha256(protocol),
            "checks": [
                {"name": name, "status": "passed", "receiptSha256": "1" * 64}
                for name in sorted(builder.CHECK_NAMES)
            ],
            "limits": dict(builder.PROOF_LIMITS),
        }
        return commit, blobs, receipt

    @classmethod
    def _server_manifest(cls, builder, name: str, version: str) -> bytes:
        return builder.BASE.json_bytes({
            "schema_version": 2,
            "name": name,
            "version": version,
            "description": "test component",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL[name],
        })

    @classmethod
    def _component(cls, builder, name: str, version: str) -> dict[str, bytes]:
        expected = (
            builder.DEPARTMENT_EXPECTED_MEMBERS
            if name == "questionable_decisions"
            else builder.PCV_EXPECTED_MEMBERS
        )
        files = {path: ("fixture:" + path).encode("utf-8") for path in expected}
        files["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": name,
            "version": version,
            "description": "test component",
            "server": {"mutable_paths": []},
        })
        files["server/manifest.json"] = cls._server_manifest(builder, name, version)
        files["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in files.items() if path != "checksums.sha256"}
        )
        return files

    @classmethod
    def _department_component(cls, builder, version: str) -> dict[str, bytes]:
        package = cls._component(builder, "questionable_decisions", version)
        old_pins = cls._pins(builder, "bee3cd6fea49e49c5e631d8da86073c02d2c931100a4da942c42d4488ce2e0f8")
        package["server/adapters/correspondence.php"] = cls._adapter_map("0.1.0-dev.26", old_pins)
        package["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in package.items() if path != "checksums.sha256"}
        )
        return package

    @classmethod
    def _synthetic_baseline_files(cls, builder) -> dict[str, bytes]:
        files = {path: ("baseline:" + path).encode("utf-8") for path in builder.EXPECTED_BASELINE_MEMBERS}
        files[builder.DLL_MEMBER] = b"synthetic DLL bytes"
        files[builder.BASELINE_DPKG_MEMBER] = builder.BASE.stable_zip_bytes(
            cls._department_component(builder, builder.BASELINE_VERSION)
        )
        files[builder.BASELINE_PCV_MEMBER] = builder.BASE.stable_zip_bytes(
            cls._component(builder, "pcv_companion", "0.1.0-prealpha.11")
        )
        return files

    @classmethod
    def _synthetic_baseline(cls, builder) -> bytes:
        with patch.object(builder.BASE, "ZIP_TIMESTAMP", builder.BASELINE_ZIP_TIMESTAMP):
            return builder.BASE.stable_zip_bytes(cls._synthetic_baseline_files(builder))

    @classmethod
    def _department_sources(cls, builder, previous: dict[str, bytes]) -> dict[str, bytes]:
        source = {
            path: previous["server/" + path.removeprefix("server/")]
            for path in builder.SERVER_SOURCE_PATHS
        }
        source["server/globals.php"] = previous["server/globals.php"]
        inner = json.loads(source["server/manifest.json"].decode("utf-8"))
        inner["version"] = builder.VERSION
        source["server/manifest.json"] = builder.BASE.json_bytes(inner)
        old_pins = cls._pins(builder, "bee3cd6fea49e49c5e631d8da86073c02d2c931100a4da942c42d4488ce2e0f8")
        new_pins = dict(old_pins)
        new_pins["manifest.json"] = builder.OWNER_MANIFEST_SHA256
        source["server/adapters/correspondence.php"] = (
            cls._adapter_map("0.1.0-dev.26", old_pins)
            + cls._adapter_map("0.1.0-dev.27", new_pins)
        )
        return source

    @classmethod
    def _fixture_sha(cls, builder, baseline: bytes):
        real_sha = builder.BASE.sha256

        def fixture_sha(data: bytes) -> str:
            if data == baseline:
                return builder.BASELINE_SHA256
            if data == b"synthetic DLL bytes":
                return builder.DLL_SHA256
            return real_sha(data)

        return fixture_sha


if __name__ == "__main__":
    unittest.main()
