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
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha19.py"


def load_builder():
    if not BUILDER_PATH.is_file():
        raise AssertionError("the prealpha.19 builder has not been implemented")
    spec = importlib.util.spec_from_file_location("department_prealpha19", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.19 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha19BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = load_builder()

    def test_builder_pins_published18_and_narrow_department_delta(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.19")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.18")
        self.assertEqual(
            builder.BASELINE_SHA256,
            "24c558a938db919fc39ac5b16ca0a15e46738b0d9d82e4332c6711f3c6c0bd02",
        )
        self.assertEqual(builder.DEPARTMENT_UPDATED_MEMBERS, {
            "manifest.json",
            "server/manifest.json",
            "server/adapters/correspondence.php",
            "checksums.sha256",
        })
        self.assertEqual(builder.ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 4))

    def test_dev26_pin_parser_requires_exact_literal_19_file_map(self) -> None:
        builder = self.builder
        pins = {"manifest.json": "a" * 64}
        pins.update({f"lib/file-{index}.php": f"{index:064x}" for index in range(18)})
        adapter = self._adapter_map("0.1.0-dev.26", pins)
        self.assertEqual(builder._literal_owner_closure_pins(adapter), pins)

        needle = f"        'lib/file-0.php' => '{pins['lib/file-0.php']}',\n".encode()
        duplicate = adapter.replace(needle, needle + needle)
        with self.assertRaises(builder.PackageError):
            builder._literal_owner_closure_pins(duplicate)

        wrong_count = self._adapter_map("0.1.0-dev.26", dict(list(pins.items())[:-1]))
        with self.assertRaises(builder.PackageError):
            builder._literal_owner_closure_pins(wrong_count)

    def test_compatibility_receipt_is_canonical_source_bound_and_fixed(self) -> None:
        builder = self.builder
        commit = "a" * 40
        adapter = self._adapter_map("0.1.0-dev.26", self._pins())
        protocol = b"protocol source at selected commit"
        blobs = {
            builder.DEPARTMENT_ADAPTER: adapter,
            builder.DEPARTMENT_PROTOCOL: protocol,
        }
        receipt = self._receipt(builder, commit, blobs)
        with patch.object(
            builder.BASE,
            "git_blob",
            side_effect=lambda _git, _commit, path: blobs[path],
        ):
            accepted = builder._validate_compatibility_receipt(
                "git", commit, builder.BASE.json_bytes(receipt)
            )
        self.assertEqual(accepted["ownerCommit"], builder.OWNER_COMMIT)

        invalid = []
        wrong_source = copy.deepcopy(receipt)
        wrong_source["sourceCommit"] = "b" * 40
        invalid.append(builder.BASE.json_bytes(wrong_source))
        wrong_owner = copy.deepcopy(receipt)
        wrong_owner["ownerCommit"] = "c" * 40
        invalid.append(builder.BASE.json_bytes(wrong_owner))
        wrong_pin = copy.deepcopy(receipt)
        wrong_pin["ownerClosurePins"]["lib/file-0.php"] = "f" * 64
        invalid.append(builder.BASE.json_bytes(wrong_pin))
        wrong_adapter_hash = copy.deepcopy(receipt)
        wrong_adapter_hash["adapterSha256"] = "f" * 64
        invalid.append(builder.BASE.json_bytes(wrong_adapter_hash))
        wrong_protocol_hash = copy.deepcopy(receipt)
        wrong_protocol_hash["protocolSha256"] = "f" * 64
        invalid.append(builder.BASE.json_bytes(wrong_protocol_hash))
        missing_check = copy.deepcopy(receipt)
        missing_check["checks"].pop()
        invalid.append(builder.BASE.json_bytes(missing_check))
        wrong_limit = copy.deepcopy(receipt)
        wrong_limit["limits"]["sourceOnly"] = 1
        invalid.append(builder.BASE.json_bytes(wrong_limit))
        canonical = builder.BASE.json_bytes(receipt)
        invalid.append(canonical.replace(b"{", b'{"schemaVersion":1,', 1))
        invalid.append(b"\xef\xbb\xbf" + builder.BASE.json_bytes(receipt))
        invalid.append(builder.BASE.json_bytes(receipt).replace(b"\n", b"\r\n"))
        for data in invalid:
            with self.subTest(data=data[:40]):
                with patch.object(
                    builder.BASE,
                    "git_blob",
                    side_effect=lambda _git, _commit, path: blobs[path],
                ):
                    with self.assertRaises(builder.PackageError):
                        builder._validate_compatibility_receipt("git", commit, data)

    def test_exact18_baseline_reader_checks_members_dll_and_department_package(self) -> None:
        builder = self.builder
        baseline = self._synthetic_baseline(builder)
        real_sha = builder.BASE.sha256

        def synthetic_sha(data: bytes) -> str:
            if data == baseline:
                return builder.BASELINE_SHA256
            if data == b"synthetic DLL bytes":
                return builder.DLL_SHA256
            return real_sha(data)

        with patch.object(builder.BASE, "sha256", side_effect=synthetic_sha):
            files, department = builder._read_baseline_bytes(baseline)
        self.assertEqual(len(files), 22)
        self.assertEqual(len(department), 29)
        with self.assertRaisesRegex(builder.PackageError, "exact verified prealpha.18"):
            builder._read_baseline_bytes(b"wrong archive")

    def test_installable_replaces_only_department_and_uses_newer_canonical_timestamp(self) -> None:
        builder = self.builder
        baseline = self._synthetic_baseline_files(builder)
        assembled = builder._assemble_mod(baseline, b"new Department DWPkg")
        self.assertEqual(len(assembled), 22)
        self.assertEqual(
            set(baseline) - set(assembled), {builder.BASELINE_DPKG_MEMBER}
        )
        self.assertEqual(set(assembled) - set(baseline), {builder.DPKG_MEMBER})
        unchanged = set(baseline) & set(assembled)
        self.assertEqual(len(unchanged), 21)
        self.assertTrue(all(baseline[path] == assembled[path] for path in unchanged))
        self.assertEqual(assembled[builder.DLL_MEMBER], baseline[builder.DLL_MEMBER])

        archive_bytes = builder.BASE.stable_zip_bytes(assembled)
        real_sha = builder.BASE.sha256
        with patch.object(
            builder.BASE,
            "sha256",
            side_effect=lambda data: builder.DLL_SHA256 if data == b"synthetic DLL bytes" else real_sha(data),
        ):
            self.assertEqual(builder._read_installable(archive_bytes), assembled)
            with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
                timestamp = archive.getinfo(builder.DPKG_MEMBER).date_time
            self.assertEqual(timestamp, builder.ZIP_TIMESTAMP)
            self.assertGreater(timestamp, builder.BASELINE_ZIP_TIMESTAMP)

            with self.assertRaisesRegex(builder.PackageError, "canonical"):
                builder._read_installable(self._zip_with_timestamp(builder, assembled, (2026, 10, 26, 0, 0, 2)))

    def test_department_package_delta_is_exact_and_checksummed(self) -> None:
        builder = self.builder
        previous = self._department_component(builder, builder.BASELINE_VERSION)
        current = dict(previous)
        for path in ("manifest.json", "server/manifest.json"):
            value = json.loads(current[path].decode("utf-8"))
            value["version"] = builder.VERSION
            current[path] = builder.BASE.json_bytes(value)
        current["server/adapters/correspondence.php"] = b"dev26 adapter source"
        current["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in current.items() if path != "checksums.sha256"}
        )
        changed = {
            path for path in previous if previous[path] != current[path]
        }
        self.assertEqual(changed, builder.DEPARTMENT_UPDATED_MEMBERS)
        builder._compare_dpkg_update(previous, current)

        unapproved = dict(current)
        unapproved["server/adapters/runtime_support.php"] = b"unexpected"
        unapproved["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in unapproved.items() if path != "checksums.sha256"}
        )
        with self.assertRaisesRegex(builder.PackageError, "only its two manifests"):
            builder._compare_dpkg_update(previous, unapproved)

    def test_source_allowlist_contains_current_recipe_and_focused_dev26_evidence(self) -> None:
        builder = self.builder
        allowlist = json.loads(
            (ROOT / "packaging" / "public-source-allowlist.json").read_text(encoding="utf-8")
        )
        paths = {entry["path"] for entry in allowlist["files"]}
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS.issubset(paths))
        self.assertTrue({
            "docs/DEV26-COMPATIBILITY.md",
            "docs/superpowers/plans/2026-10-26-dev26-pairing.md",
            "packaging/build-installable-prealpha19.py",
            "packaging/tests/test_installable_prealpha19.py",
            "server/tests/correspondence/dev26-source-contract.php",
        }.issubset(paths))
        self.assertFalse(any(path.startswith("artifacts/") for path in paths))

    @staticmethod
    def _pins() -> dict[str, str]:
        pins = {"manifest.json": "a" * 64}
        pins.update({f"lib/file-{index}.php": f"{index:064x}" for index in range(18)})
        return pins

    @staticmethod
    def _adapter_map(version: str, pins: dict[str, str]) -> bytes:
        rows = "".join(
            f"        '{path}' => '{digest}',\n" for path, digest in pins.items()
        )
        return f"    '{version}' => [\n{rows}    ],\n".encode("utf-8")

    @classmethod
    def _receipt(cls, builder, commit: str, blobs: dict[str, bytes]) -> dict:
        pins = cls._pins()
        return {
            "schemaVersion": 1,
            "sourceCommit": commit,
            "ownerVersion": builder.OWNER_VERSION,
            "ownerCommit": builder.OWNER_COMMIT,
            "ownerClosurePins": pins,
            "adapterSha256": builder.BASE.sha256(blobs[builder.DEPARTMENT_ADAPTER]),
            "protocolSha256": builder.BASE.sha256(blobs[builder.DEPARTMENT_PROTOCOL]),
            "checks": [
                {"name": name, "status": "passed", "receiptSha256": "1" * 64}
                for name in sorted(builder.CHECK_NAMES)
            ],
            "limits": dict(builder.PROOF_LIMITS),
        }

    @classmethod
    def _department_component(cls, builder, version: str) -> dict[str, bytes]:
        files = {
            path: ("fixture:" + path).encode("utf-8")
            for path in builder.DEPARTMENT_EXPECTED_MEMBERS
        }
        files["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": "questionable_decisions",
            "version": version,
            "description": "test component",
            "server": {"mutable_paths": []},
        })
        files["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "questionable_decisions",
            "version": version,
            "description": "test component",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL["questionable_decisions"],
        })
        files["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in files.items() if path != "checksums.sha256"}
        )
        return files

    @classmethod
    def _synthetic_baseline_files(cls, builder) -> dict[str, bytes]:
        files = {
            path: ("baseline:" + path).encode("utf-8")
            for path in builder.EXPECTED_BASELINE_MEMBERS
        }
        files[builder.BASELINE_DPKG_MEMBER] = b"old Department DWPkg"
        files[builder.DLL_MEMBER] = b"synthetic DLL bytes"
        files["SKSE/Plugins/WorldOfDramaLlama.ini"] = (
            b"[Server]\nBaseUrl=\nPairToken=\n[Input]\nOpenKeyCode=-1\n"
        )
        return files

    @classmethod
    def _synthetic_baseline(cls, builder) -> bytes:
        files = cls._synthetic_baseline_files(builder)
        department = cls._department_component(builder, builder.BASELINE_VERSION)
        files[builder.BASELINE_DPKG_MEMBER] = builder.BASE.stable_zip_bytes(department)
        return builder.BASE.stable_zip_bytes(files)

    @staticmethod
    def _zip_with_timestamp(builder, files: dict[str, bytes], timestamp: tuple[int, int, int, int, int, int]) -> bytes:
        original = builder.ZIP_TIMESTAMP
        builder.BASE.ZIP_TIMESTAMP = timestamp
        try:
            return builder.BASE.stable_zip_bytes(files)
        finally:
            builder.BASE.ZIP_TIMESTAMP = original


if __name__ == "__main__":
    unittest.main()
