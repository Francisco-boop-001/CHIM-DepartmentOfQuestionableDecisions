from __future__ import annotations

import importlib.util
import io
import json
import unittest
import zipfile
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha25.py"
V24_TEST_PATH = ROOT / "packaging" / "tests" / "test_installable_prealpha24.py"


def _load(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise AssertionError(f"cannot load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _owner_adapter(version: str, pins: dict[str, str]) -> bytes:
    entries = "\n".join(f"        '{path}' => '{digest}'," for path, digest in pins.items())
    return f"    '{version}' => [\n{entries}\n    ],\n".encode("utf-8")


def _department_fixture(builder):
    v24_tests = _load(V24_TEST_PATH, "prealpha24_fixture_helpers")
    old23, source24 = v24_tests.Prealpha24BuilderTests._department_fixture(builder.P24)
    package, _files = builder.P24._build_department_package(old23, source24.__getitem__)
    old = builder.BASE.read_zip_bytes(package, "fixture prealpha.24 Department DWPkg")
    old_pins = builder.P22._literal_owner_closure_pins(old[builder.DEPARTMENT_ADAPTER], builder.BASELINE_OWNER_VERSION)
    pins = dict(old_pins)
    pins.update(builder.OWNER_CHANGED_CLOSURE_PINS)
    adapter = old[builder.DEPARTMENT_ADAPTER] + _owner_adapter(builder.SF_OWNER_VERSION, pins)
    source = {path: old["server/" + path.removeprefix("server/")] for path in builder.SERVER_SOURCE_PATHS}
    source[builder.DEPARTMENT_ADAPTER] = adapter
    inner = json.loads(old["server/manifest.json"])
    inner["version"] = builder.VERSION
    source["server/manifest.json"] = builder.BASE.json_bytes(inner)
    for path in builder.SOURCE_HASH_PATHS:
        source.setdefault(path, f"source:{path}".encode("utf-8"))
    source[builder.DEPARTMENT_ADAPTER] = adapter
    return old, source, pins


class Prealpha25BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = _load(BUILDER_PATH, "department_prealpha25")

    def test_contract_pins_baseline_timestamp_and_department_only_delta(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.25")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.24")
        self.assertEqual(builder.BASELINE_SHA256, "3d4a8cfbf8c27b7b655865c5191c8a5d45e6d3fb4f9c999184098786b9c5e237")
        self.assertEqual(builder.DLL_SHA256, "5b55122b8916629c1130260a24781b709e0192a34049211a67f91e337177df6a")
        self.assertEqual(builder.BASELINE_SOURCE_COMMIT, "5b0fa12db93000a866493548b913cf6d6e16a7fd")
        self.assertGreater(builder.ZIP_TIMESTAMP, builder.BASELINE_ZIP_TIMESTAMP)
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.EXPECTED_INSTALLABLE_MEMBERS), 22)
        self.assertEqual(builder.DEPARTMENT_UPDATED_MEMBERS, {
            "manifest.json", "server/manifest.json", builder.DEPARTMENT_ADAPTER, "checksums.sha256",
        })
        self.assertEqual(builder.SF_OWNER_VERSION, "0.1.0-dev.29")
        self.assertEqual(builder.SF_OWNER_COMMIT, "dd128bb7568e0095423c49e02c0e70a395208a18")
        self.assertEqual(builder.OWNER_MANIFEST_SHA256, "40a837fad35a830b2f39cb210ddc04900b10f2c7d02b3134dcb806cba7cfcb26")
        self.assertEqual(builder.PROOF_LIMITS, {
            "server": False, "database": False, "http": False, "provider": False,
            "skyrim": False, "installedGame": False, "live": False,
        })

    def test_baseline_rejects_nonpublished_bytes(self) -> None:
        with self.assertRaisesRegex(self.builder.PackageError, "exact verified prealpha.24"):
            self.builder._read_baseline_bytes(b"not the published archive")

    def test_dev29_pins_retain_dev28_closure_and_require_migration006(self) -> None:
        builder = self.builder
        old, source, pins = _department_fixture(builder)
        parsed = builder._validate_dev29_adapter(old[builder.DEPARTMENT_ADAPTER], source[builder.DEPARTMENT_ADAPTER])
        self.assertEqual(parsed, pins)
        self.assertEqual(len(parsed), 20)
        self.assertEqual(set(parsed), set(builder.DEV28_OWNER_PATHS) | {builder.NEW_OWNER_MIGRATION})
        self.assertIn(builder.NEW_OWNER_MIGRATION, parsed)

        missing_migration = dict(pins)
        del missing_migration[builder.NEW_OWNER_MIGRATION]
        with self.assertRaisesRegex(builder.PackageError, "exact 20-file owner closure"):
            builder._validate_dev29_adapter(
                old[builder.DEPARTMENT_ADAPTER],
                old[builder.DEPARTMENT_ADAPTER] + _owner_adapter(builder.SF_OWNER_VERSION, missing_migration),
            )

        dropped_old = dict(pins)
        dropped = next(iter(builder.DEV28_OWNER_PATHS - {"manifest.json"}))
        del dropped_old[dropped]
        dropped_old["lib/unexpected.php"] = "8" * 64
        with self.assertRaisesRegex(builder.PackageError, "retained dev.28 closure"):
            builder._validate_dev29_adapter(
                old[builder.DEPARTMENT_ADAPTER],
                old[builder.DEPARTMENT_ADAPTER] + _owner_adapter(builder.SF_OWNER_VERSION, dropped_old),
            )

    def test_department_package_changes_only_four_member_contents(self) -> None:
        builder = self.builder
        old, source, _pins = _department_fixture(builder)
        package, files = builder._build_department_package(old, source.__getitem__)
        self.assertEqual(set(files), builder.DEPARTMENT_EXPECTED_MEMBERS)
        self.assertEqual(builder.P24._audit_department_component(package, builder.VERSION), files)
        changed = {path for path in old.keys() | files.keys() if old.get(path) != files.get(path)}
        self.assertEqual(changed, builder.DEPARTMENT_UPDATED_MEMBERS)
        self.assertEqual(
            builder.BASE.parse_checksums(files["checksums.sha256"], "prealpha.25 fixture"),
            {path: builder.BASE.sha256(data) for path, data in files.items() if path != "checksums.sha256"},
        )

    def test_department_package_rejects_unrelated_source_drift(self) -> None:
        builder = self.builder
        old, source, _pins = _department_fixture(builder)
        unchanged = next(
            path for path in builder.SERVER_SOURCE_PATHS
            if path not in {builder.DEPARTMENT_ADAPTER, "server/manifest.json"}
        )
        source[unchanged] += b" drift"
        with self.assertRaisesRegex(builder.PackageError, "unchanged from prealpha.24"):
            builder._build_department_package(old, source.__getitem__)

    def test_compatibility_input_binds_source_commit_owner_pins_checks_and_limits(self) -> None:
        builder = self.builder
        old, source, pins = _department_fixture(builder)
        commit = "a" * 40
        receipt = self._receipt(builder, commit, source, pins)
        blobs = {path: source[path] for path in builder.SOURCE_HASH_PATHS}
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]):
            validated = builder._validate_offline_compatibility(
                receipt, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old,
            )
        self.assertEqual(validated, receipt)

    def test_compatibility_input_rejects_wrong_owner_pin_readiness_and_source_drift(self) -> None:
        builder = self.builder
        old, source, pins = _department_fixture(builder)
        commit = "b" * 40
        good = self._receipt(builder, commit, source, pins)
        blobs = {path: source[path] for path in builder.SOURCE_HASH_PATHS}
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]):
            wrong_pin = json.loads(json.dumps(good))
            wrong_pin["ownerClosurePins"][builder.NEW_OWNER_MIGRATION] = "0" * 64
            with self.assertRaisesRegex(builder.PackageError, "owner pin map differs"):
                builder._validate_offline_compatibility(wrong_pin, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old)

            not_ready = json.loads(json.dumps(good))
            not_ready["checks"]["newInterfaceService"] = False
            with self.assertRaisesRegex(builder.PackageError, "must pass every fixed source-only check"):
                builder._validate_offline_compatibility(not_ready, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old)

            drift = json.loads(json.dumps(good))
            drift["sourceHashes"][builder.DEPARTMENT_ADAPTER] = "0" * 64
            with self.assertRaisesRegex(builder.PackageError, "source hash differs"):
                builder._validate_offline_compatibility(drift, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old)

            wrong_owner = json.loads(json.dumps(good))
            wrong_owner["ownerCommit"] = "c" * 40
            with self.assertRaisesRegex(builder.PackageError, "owner identity differs"):
                builder._validate_offline_compatibility(wrong_owner, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old)

            runtime_claim = json.loads(json.dumps(good))
            runtime_claim["limits"]["database"] = True
            with self.assertRaisesRegex(builder.PackageError, "offline limits"):
                builder._validate_offline_compatibility(runtime_claim, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old)

    def test_changed_owner_hashes_are_rejected_even_when_adapter_and_receipt_agree(self) -> None:
        builder = self.builder
        old, source, pins = _department_fixture(builder)
        commit = "1" * 40
        for path in builder.CHANGED_OWNER_PATHS - {"manifest.json"}:
            with self.subTest(path=path):
                changed_pins = dict(pins)
                changed_pins[path] = "0" * 64
                changed_source = dict(source)
                changed_source[builder.DEPARTMENT_ADAPTER] = (
                    old[builder.DEPARTMENT_ADAPTER]
                    + _owner_adapter(builder.SF_OWNER_VERSION, changed_pins)
                )
                receipt = self._receipt(builder, commit, changed_source, changed_pins)
                blobs = {name: changed_source[name] for name in builder.SOURCE_HASH_PATHS}
                with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, name: blobs[name]):
                    with self.assertRaisesRegex(builder.PackageError, "exact frozen changed owner hashes"):
                        builder._validate_offline_compatibility(
                            receipt, commit, "git", set(builder.REQUIRED_SOURCE_PATHS), old,
                        )

    def test_offline_source_hashes_must_be_publicly_allowlisted(self) -> None:
        builder = self.builder
        old, source, pins = _department_fixture(builder)
        commit = "f" * 40
        receipt = self._receipt(builder, commit, source, pins)
        blobs = {path: source[path] for path in builder.SOURCE_HASH_PATHS}
        allowlist = set(builder.REQUIRED_SOURCE_PATHS) - {"server/tests/correspondence/dev29-transaction-memo.php"}
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]):
            with self.assertRaisesRegex(builder.PackageError, "not in the public source allowlist"):
                builder._validate_offline_compatibility(receipt, commit, "git", allowlist, old)

    def test_source_allowlist_requires_every_candidate_source_and_no_duplicates(self) -> None:
        builder = self.builder
        paths = sorted(builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS)
        self.assertEqual(builder._validate_source_allowlist(paths), set(paths))
        with self.assertRaisesRegex(builder.PackageError, "missing required candidate paths"):
            builder._validate_source_allowlist([path for path in paths if path != "server/tests/correspondence/dev29-transaction-memo.php"])
        with self.assertRaisesRegex(builder.PackageError, "duplicate paths"):
            builder._validate_source_allowlist(paths + [paths[0]])

    def test_source_delta_rejects_native_or_ui_changes(self) -> None:
        builder = self.builder
        expected = sorted(builder.EXPECTED_SERVER_SOURCE_CHANGES)
        raw = "\0".join(expected).encode("utf-8") + b"\0"
        with mock.patch.object(builder.BASE, "git_output", return_value=raw):
            builder._validate_selected_source_delta("git", "d" * 40)
        with mock.patch.object(builder.BASE, "git_output", return_value=raw + b"ui/views/app.js\0"):
            with self.assertRaisesRegex(builder.PackageError, "native/UI source changed"):
                builder._validate_selected_source_delta("git", "d" * 40)

    def test_installable_replacement_preserves_all_other_21_payloads(self) -> None:
        builder = self.builder
        baseline = {path: f"payload:{path}".encode("utf-8") for path in builder.EXPECTED_BASELINE_MEMBERS}
        old_dpkg = baseline[builder.BASELINE_DPKG_MEMBER]
        result = builder._assemble_mod(baseline, b"new Department package")
        self.assertEqual(set(result), builder.EXPECTED_INSTALLABLE_MEMBERS)
        self.assertNotIn(builder.BASELINE_DPKG_MEMBER, result)
        self.assertEqual(result[builder.DPKG_MEMBER], b"new Department package")
        common = set(baseline) & set(result)
        self.assertEqual(len(common), 21)
        self.assertTrue(all(baseline[path] == result[path] for path in common))
        self.assertEqual(baseline[builder.BASELINE_DPKG_MEMBER], old_dpkg)

    def test_offline_proof_contains_only_input_summary_and_generated_provenance(self) -> None:
        builder = self.builder
        _old, source, pins = _department_fixture(builder)
        receipt = self._receipt(builder, "e" * 40, source, pins)
        offline_bytes = builder.BASE.json_bytes(receipt)
        proof = builder._proof_zip("e" * 40, b"mod", b"source", b"department", offline_bytes, receipt)
        members = builder.BASE.read_zip_bytes(proof, "prealpha.25 proof fixture")
        self.assertEqual(set(members), {builder.INPUT_COMPATIBILITY, "package-provenance.json"})
        provenance = json.loads(members["package-provenance.json"].decode("utf-8"))
        self.assertEqual(provenance["unchangedMo2Members"], 21)
        self.assertEqual(provenance["owner"]["closurePins"], pins)
        self.assertEqual(provenance["retainedPayloadPins"]["nativeDll"]["sha256"], builder.DLL_SHA256)
        with zipfile.ZipFile(io.BytesIO(proof)) as archive:
            self.assertTrue(all(info.date_time == builder.ZIP_TIMESTAMP for info in archive.infolist()))

    def test_checksum_manifest_rejects_changed_digest_or_member_list(self) -> None:
        builder = self.builder
        outputs = {
            builder.OUTPUT_MOD_ZIP: b"mod",
            builder.OUTPUT_SOURCE_ZIP: b"source",
            builder.OUTPUT_PROOF_ZIP: b"proof",
        }
        checksums = builder.BASE.make_checksums(outputs)
        builder.BASE.audit_checksums_file(checksums, outputs)
        with self.assertRaises(builder.PackageError):
            builder.BASE.audit_checksums_file(builder.BASE.make_checksums({**outputs, builder.OUTPUT_MOD_ZIP: b"changed"}), outputs)
        with self.assertRaises(builder.PackageError):
            builder.BASE.audit_checksums_file(checksums.replace(builder.OUTPUT_MOD_ZIP.encode("ascii"), b"other.zip"), outputs)

    @staticmethod
    def _receipt(builder, commit, source, pins):
        return {
            "schemaVersion": 1,
            "kind": "department-offline-compatibility",
            "sourceCommit": commit,
            "ownerVersion": builder.SF_OWNER_VERSION,
            "ownerCommit": builder.SF_OWNER_COMMIT,
            "ownerClosurePins": dict(pins),
            "sourceHashes": {path: builder.BASE.sha256(source[path]) for path in builder.SOURCE_HASH_PATHS},
            "checks": {name: True for name in builder.OFFLINE_CHECK_NAMES},
            "receiptHashes": {name: "4" * 64 for name in builder.OFFLINE_CHECK_NAMES},
            "limits": dict(builder.PROOF_LIMITS),
        }


if __name__ == "__main__":
    unittest.main()
