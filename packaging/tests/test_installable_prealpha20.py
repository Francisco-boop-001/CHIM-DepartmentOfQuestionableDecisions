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
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha20.py"


def load_builder():
    if not BUILDER_PATH.is_file():
        raise AssertionError("the prealpha.20 builder has not been implemented")
    spec = importlib.util.spec_from_file_location("department_prealpha20", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.20 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha20BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = load_builder()

    def test_candidate_versions_baseline_and_two_component_delta_are_fixed(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.20")
        self.assertEqual(builder.PCV_VERSION, "0.1.0-prealpha.11")
        self.assertEqual(builder.OWNER_VERSION, "0.1.24")
        self.assertEqual(builder.OWNER_TAG, "private_conversation-v0.1.24")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.19")
        self.assertEqual(
            builder.BASELINE_SHA256,
            "d7481f55074025650628644df7e914ad78bc1f6cf1762faac3d3fac7a3e55a93",
        )
        self.assertEqual(builder.ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 6))
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.DEPARTMENT_EXPECTED_MEMBERS), 29)
        self.assertEqual(len(builder.PCV_EXPECTED_MEMBERS), 6)
        self.assertEqual(len(builder.PROTECTED_PAYLOAD_PATHS), 5)
        self.assertFalse(set(builder.PROTECTED_PAYLOAD_PATHS) & builder.DEPARTMENT_ALLOWED_CHANGES)

    def test_owner_stage_manifest_separates_base_and_postimage_and_fails_closed(self) -> None:
        builder = self.builder
        manifest_bytes, files = self._owner_stage_fixture(builder)
        commit = "c" * 40
        source = {builder.OWNER_SOURCE_MANIFEST: manifest_bytes}
        source.update({f"{builder.OWNER_STAGE_DIRECTORY}/{name}": data for name, data in files.items()})
        with patch.object(builder, "OWNER_COMMIT", None):
            with self.assertRaisesRegex(builder.PackageError, "not pinned"):
                builder._owner_stage(source.__getitem__)
        with patch.object(builder, "OWNER_COMMIT", commit):
            actual_manifest, actual_files, parsed = builder._owner_stage(source.__getitem__)
        self.assertEqual(actual_manifest, manifest_bytes)
        self.assertEqual(actual_files, files)
        self.assertEqual(parsed["upstream"]["commit"], commit)
        self.assertNotEqual(
            {row["path"]: row["baseSha256"] for row in parsed["files"]},
            {row["path"]: row["postimageSha256"] for row in parsed["files"]},
        )

        invalid_manifest = copy.deepcopy(parsed)
        invalid_manifest["files"][0]["baseSha256"] = invalid_manifest["files"][0]["postimageSha256"]
        invalid_source = dict(source)
        invalid_source[builder.OWNER_SOURCE_MANIFEST] = builder.BASE.json_bytes(invalid_manifest)
        with patch.object(builder, "OWNER_COMMIT", commit):
            with self.assertRaisesRegex(builder.PackageError, "modified flag"):
                builder._owner_stage(invalid_source.__getitem__)

        wrong_postimage = dict(source)
        wrong_postimage[f"{builder.OWNER_STAGE_DIRECTORY}/state.php"] = b"unreviewed owner bytes"
        with patch.object(builder, "OWNER_COMMIT", commit):
            with self.assertRaisesRegex(builder.PackageError, "postimage"):
                builder._owner_stage(wrong_postimage.__getitem__)

    def test_receipt_is_canonical_commit_bound_and_checks_all_exact_evidence_links(self) -> None:
        builder = self.builder
        source_commit = "a" * 40
        owner_commit = "c" * 40
        manifest_bytes, stage_files = self._owner_stage_fixture(builder, owner_commit)
        blobs = {path: ("committed:" + path).encode("utf-8") for path in builder.SOURCE_HASH_PATHS}
        blobs[builder.OWNER_SOURCE_MANIFEST] = manifest_bytes
        for name, data in stage_files.items():
            blobs[f"{builder.OWNER_STAGE_DIRECTORY}/{name}"] = data
        baseline_department = self._department_component(builder, builder.BASELINE_VERSION)
        receipt = self._receipt(builder, source_commit, owner_commit, blobs, baseline_department)
        with patch.object(builder, "OWNER_COMMIT", owner_commit), patch.object(
            builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]
        ):
            accepted = builder._validate_paired_receipt(
                "git", source_commit, builder.BASE.json_bytes(receipt), baseline_department
            )
        self.assertEqual(accepted["owner"]["commit"], owner_commit)

        invalid_receipts = []
        wrong_source = copy.deepcopy(receipt)
        wrong_source["sourceCommit"] = "b" * 40
        invalid_receipts.append(wrong_source)
        wrong_owner = copy.deepcopy(receipt)
        wrong_owner["owner"]["commit"] = "d" * 40
        invalid_receipts.append(wrong_owner)
        wrong_base_pin = copy.deepcopy(receipt)
        wrong_base_pin["owner"]["basePins"]["state.php"] = "f" * 64
        invalid_receipts.append(wrong_base_pin)
        wrong_manifest = copy.deepcopy(receipt)
        wrong_manifest["owner"]["manifestSha256"] = "f" * 64
        invalid_receipts.append(wrong_manifest)
        wrong_source_hash = copy.deepcopy(receipt)
        wrong_source_hash["sourceHashes"][builder.DEPARTMENT_ADAPTER] = "f" * 64
        invalid_receipts.append(wrong_source_hash)
        wrong_protected = copy.deepcopy(receipt)
        wrong_protected["protectedPayload"][builder.PROTECTED_PAYLOAD_PATHS[0]] = "f" * 64
        invalid_receipts.append(wrong_protected)
        missing_check = copy.deepcopy(receipt)
        missing_check["checks"].pop()
        invalid_receipts.append(missing_check)
        failed_check = copy.deepcopy(receipt)
        failed_check["checks"][0]["status"] = "expected-red"
        invalid_receipts.append(failed_check)
        broken_migration_link = copy.deepcopy(receipt)
        broken_migration_link["overlayMigration"]["receiptSha256"] = "f" * 64
        invalid_receipts.append(broken_migration_link)
        inflated_limit = copy.deepcopy(receipt)
        inflated_limit["limits"]["wholeRequestDeadline"] = True
        invalid_receipts.append(inflated_limit)

        canonical = builder.BASE.json_bytes(receipt)
        malformed = [
            canonical.replace(b"{", b'{"schemaVersion":1,', 1),
            b"\xef\xbb\xbf" + canonical,
            canonical.replace(b"\n", b"\r\n"),
        ]
        with patch.object(builder, "OWNER_COMMIT", owner_commit), patch.object(
            builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]
        ):
            for invalid in invalid_receipts:
                with self.subTest(invalid=list(invalid.keys())):
                    with self.assertRaises(builder.PackageError):
                        builder._validate_paired_receipt(
                            "git", source_commit, builder.BASE.json_bytes(invalid), baseline_department
                        )
            for invalid_bytes in malformed:
                with self.subTest(invalid_bytes=invalid_bytes[:24]):
                    with self.assertRaises(builder.PackageError):
                        builder._validate_paired_receipt(
                            "git", source_commit, invalid_bytes, baseline_department
                        )

    def test_exact19_baseline_reader_and_installable_preserve_the_other_twenty_members(self) -> None:
        builder = self.builder
        baseline = self._synthetic_baseline(builder)
        real_sha = builder.BASE.sha256

        def fixture_sha(data: bytes) -> str:
            if data == baseline:
                return builder.BASELINE_SHA256
            if data == b"synthetic DLL bytes":
                return builder.DLL_SHA256
            return real_sha(data)

        with patch.object(builder.BASE, "sha256", side_effect=fixture_sha):
            baseline_files, department, pcv = builder._read_baseline_bytes(baseline)
            self.assertEqual(len(baseline_files), 22)
            self.assertEqual(len(department), 29)
            self.assertEqual(len(pcv), 6)
            with self.assertRaisesRegex(builder.PackageError, "exact verified prealpha.19"):
                builder._read_baseline_bytes(b"wrong archive")

        department_source, _stage = self._department_sources(builder, department)
        pcv_source = {
            "pcv-companion/manifest.json": self._server_manifest(builder, "pcv_companion", builder.PCV_VERSION),
            "pcv-companion/companion.php": b"paired companion source",
            "pcv-companion/globals.php": pcv["server/globals.php"],
            "pcv-companion/preprocessing.php": b"paired preprocessing source",
        }
        with patch.object(builder, "OWNER_COMMIT", "c" * 40):
            department_package, _ = builder._build_department_package(department, department_source.__getitem__)
        pcv_package, _ = builder._build_pcv_package(pcv, pcv_source.__getitem__)

        with patch.object(builder.BASE, "sha256", side_effect=fixture_sha):
            assembled = builder._assemble_mod(baseline_files, department_package, pcv_package)
            self.assertEqual(len(assembled), 22)
            self.assertEqual(set(baseline_files) - set(assembled), {
                builder.BASELINE_DPKG_MEMBER, builder.BASELINE_PCV_MEMBER
            })
            self.assertEqual(set(assembled) - set(baseline_files), {builder.DPKG_MEMBER, builder.PCV_MEMBER})
            unchanged = set(baseline_files) & set(assembled)
            self.assertEqual(len(unchanged), 20)
            self.assertTrue(all(baseline_files[path] == assembled[path] for path in unchanged))
            self.assertEqual(assembled[builder.DLL_MEMBER], baseline_files[builder.DLL_MEMBER])

            archive_bytes = builder.BASE.stable_zip_bytes(assembled)
            self.assertEqual(builder._read_installable(archive_bytes), assembled)
            with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
                self.assertEqual(archive.getinfo(builder.DPKG_MEMBER).date_time, builder.ZIP_TIMESTAMP)
                self.assertEqual(archive.getinfo(builder.PCV_MEMBER).date_time, builder.ZIP_TIMESTAMP)
                self.assertGreater(builder.ZIP_TIMESTAMP, builder.BASELINE_ZIP_TIMESTAMP)

    def test_department_builder_accepts_only_fixed_sources_and_checksums(self) -> None:
        builder = self.builder
        previous = self._department_component(builder, builder.BASELINE_VERSION)
        source, _stage = self._department_sources(builder, previous)
        with patch.object(builder, "OWNER_COMMIT", "c" * 40):
            package, current = builder._build_department_package(previous, source.__getitem__)
        self.assertEqual(builder._audit_component(package, "questionable_decisions", builder.VERSION), current)
        changed = {path for path in previous if previous[path] != current[path]}
        self.assertTrue({
            "manifest.json", "server/manifest.json", "checksums.sha256",
            builder.STAGE_ROOT + "source-manifest.json", builder.DEPARTMENT_ADAPTER,
            builder.OVERLAY_SETUP,
        }.issubset(changed))
        self.assertTrue(changed.issubset(builder.DEPARTMENT_ALLOWED_CHANGES))
        self.assertTrue(all(previous[path] == current[path] for path in builder.PROTECTED_PAYLOAD_PATHS))

        unexpected = dict(source)
        unexpected["server/adapters/mp.php"] = b"unapproved unrelated adapter edit"
        with patch.object(builder, "OWNER_COMMIT", "c" * 40):
            with self.assertRaises(builder.PackageError):
                builder._build_department_package(previous, unexpected.__getitem__)

        tampered = dict(current)
        tampered["server/adapters/mp.php"] = b"tampered protected member"
        with self.assertRaises(builder.PackageError):
            builder._audit_component(
                builder.BASE.stable_zip_bytes(tampered), "questionable_decisions", builder.VERSION
            )

    def test_pcv_package_reads_only_companion_sources_and_checksum_closure(self) -> None:
        builder = self.builder
        previous = self._pcv_component(builder, builder.BASELINE_PCV_VERSION)
        source = {
            "pcv-companion/manifest.json": self._server_manifest(builder, "pcv_companion", builder.PCV_VERSION),
            "pcv-companion/companion.php": b"paired companion source",
            "pcv-companion/globals.php": previous["server/globals.php"],
            "pcv-companion/preprocessing.php": b"paired preprocessing source",
        }
        package, current = builder._build_pcv_package(previous, source.__getitem__)
        self.assertEqual(builder._audit_component(package, "pcv_companion", builder.PCV_VERSION), current)
        changed = {path for path in previous if previous[path] != current[path]}
        self.assertTrue({"manifest.json", "server/manifest.json", "checksums.sha256"}.issubset(changed))
        self.assertTrue(changed.issubset(builder.PCV_ALLOWED_CHANGES))

        broken = dict(current)
        broken["server/companion.php"] = b"checksum mismatch"
        with self.assertRaisesRegex(builder.PackageError, "checksum closure"):
            builder._audit_component(builder.BASE.stable_zip_bytes(broken), "pcv_companion", builder.PCV_VERSION)

    def test_public_source_allowlist_has_no_evidence_fixtures_or_owner23_exports(self) -> None:
        builder = self.builder
        raw = (ROOT / "packaging" / "public-source-allowlist.json").read_bytes()
        document = json.loads(raw.decode("utf-8"))
        paths = {entry["path"] for entry in document["files"]}
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS.issubset(paths))
        self.assertFalse(any(path.startswith("artifacts/") for path in paths))
        self.assertFalse(any("owner23-baseline" in path or "timeout-results" in path for path in paths))

    @classmethod
    def _owner_stage_fixture(cls, builder, commit: str = "c" * 40):
        files = {name: ("owner .24 postimage:" + name).encode("utf-8") for name in builder.OWNER_FILE_NAMES}
        entries = []
        for name, data in files.items():
            entries.append({
                "path": name,
                "baseSha256": builder.BASE.sha256(("owner .24 base:" + name).encode("utf-8")),
                "postimageSha256": builder.BASE.sha256(data),
                "modified": True,
            })
        manifest = {
            "schemaVersion": 1,
            "upstream": {"version": builder.OWNER_VERSION, "tag": builder.OWNER_TAG, "commit": commit},
            "stagedSourceDirectory": builder.OWNER_STAGE_DIRECTORY,
            "installDirectory": "ext/private_conversation",
            "files": entries,
        }
        return builder.BASE.json_bytes(manifest), files

    @classmethod
    def _receipt(cls, builder, source_commit: str, owner_commit: str, blobs: dict[str, bytes], baseline: dict[str, bytes]) -> dict:
        manifest = json.loads(blobs[builder.OWNER_SOURCE_MANIFEST].decode("utf-8"))
        base_pins = {entry["path"]: entry["baseSha256"] for entry in manifest["files"]}
        postimage_pins = {entry["path"]: entry["postimageSha256"] for entry in manifest["files"]}
        checks = [
            {"name": name, "status": "passed", "evidence": evidence, "receiptSha256": ("1" if name != "overlayMigration" else "2") * 64}
            for name, evidence in builder.CHECK_ROWS
        ]
        protected = {path: builder.BASE.sha256(baseline[path]) for path in builder.PROTECTED_PAYLOAD_PATHS}
        return {
            "schemaVersion": 1,
            "kind": "department-pcv-paired-prealpha20",
            "sourceCommit": source_commit,
            "baseline": {"version": builder.BASELINE_VERSION, "sha256": builder.BASELINE_SHA256},
            "owner": {
                "version": builder.OWNER_VERSION,
                "tag": builder.OWNER_TAG,
                "commit": owner_commit,
                "manifestSha256": builder.BASE.sha256(blobs[builder.OWNER_SOURCE_MANIFEST]),
                "basePins": base_pins,
                "postimagePins": postimage_pins,
            },
            "sourceHashes": {path: builder.BASE.sha256(blobs[path]) for path in builder.SOURCE_HASH_PATHS},
            "overlayMigration": {
                "legacyVersion": "0.1.23",
                "activeVersion": builder.OWNER_VERSION,
                "newManifestSha256": builder.BASE.sha256(blobs[builder.OWNER_SOURCE_MANIFEST]),
                "receiptSha256": "2" * 64,
            },
            "protectedPayload": protected,
            "checks": checks,
            "limits": dict(builder.PROOF_LIMITS),
        }

    @classmethod
    def _department_sources(cls, builder, previous: dict[str, bytes]):
        source = {
            path: previous["server/" + path.removeprefix("server/")]
            for path in builder.SERVER_SOURCE_PATHS
        }
        inner = json.loads(source["server/manifest.json"].decode("utf-8"))
        inner["version"] = builder.VERSION
        source["server/manifest.json"] = builder.BASE.json_bytes(inner)
        source[builder.DEPARTMENT_ADAPTER] = b"paired PCV adapter source"
        source[builder.OVERLAY_SETUP] = b"paired overlay setup source"
        manifest_bytes, stage_files = cls._owner_stage_fixture(builder)
        source[builder.OWNER_SOURCE_MANIFEST] = manifest_bytes
        source.update({f"{builder.OWNER_STAGE_DIRECTORY}/{name}": data for name, data in stage_files.items()})
        return source, stage_files

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
        expected = builder.DEPARTMENT_EXPECTED_MEMBERS if name == "questionable_decisions" else builder.PCV_EXPECTED_MEMBERS
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
        return cls._component(builder, "questionable_decisions", version)

    @classmethod
    def _pcv_component(cls, builder, version: str) -> dict[str, bytes]:
        return cls._component(builder, "pcv_companion", version)

    @classmethod
    def _synthetic_baseline_files(cls, builder) -> dict[str, bytes]:
        files = {path: ("baseline:" + path).encode("utf-8") for path in builder.EXPECTED_BASELINE_MEMBERS}
        files[builder.DLL_MEMBER] = b"synthetic DLL bytes"
        files["SKSE/Plugins/WorldOfDramaLlama.ini"] = b"[Server]\nBaseUrl=\nPairToken=\n[Input]\nOpenKeyCode=-1\n"
        files[builder.BASELINE_DPKG_MEMBER] = builder.BASE.stable_zip_bytes(
            cls._department_component(builder, builder.BASELINE_VERSION)
        )
        files[builder.BASELINE_PCV_MEMBER] = builder.BASE.stable_zip_bytes(
            cls._pcv_component(builder, builder.BASELINE_PCV_VERSION)
        )
        return files

    @classmethod
    def _synthetic_baseline(cls, builder) -> bytes:
        return builder.BASE.stable_zip_bytes(cls._synthetic_baseline_files(builder))


if __name__ == "__main__":
    unittest.main()
