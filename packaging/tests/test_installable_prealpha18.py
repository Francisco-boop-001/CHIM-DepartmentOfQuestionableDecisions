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
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha18.py"


def load_builder():
    if not BUILDER_PATH.is_file():
        raise AssertionError("the prealpha.18 builder has not been implemented")
    spec = importlib.util.spec_from_file_location("department_prealpha18", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.18 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha18BuilderTests(unittest.TestCase):
    def _require_builder(self):
        if not BUILDER_PATH.is_file():
            self.skipTest("the prealpha.18 builder is not present yet")
        return load_builder()

    def test_builder_declares_the_pinned_baseline_and_narrow_receipt(self) -> None:
        source = BUILDER_PATH.read_text(encoding="utf-8") if BUILDER_PATH.is_file() else ""
        self.assertIn('VERSION = "0.1.0-prealpha.18"', source)
        self.assertIn('PCV_VERSION = "0.1.0-prealpha.10"', source)
        self.assertIn('BASELINE_SHA256 = "0ba15af00904aa776c8186064d15d72608a8aeccd34a0aa70023be3857354197"', source)
        self.assertIn("--maintenance-checks", source)

    def test_shared_overlay_guard_tracks_the_reviewed_state_postimage(self) -> None:
        path = ROOT / "packaging" / "build-installable-prealpha8.py"
        spec = importlib.util.spec_from_file_location("department_prealpha8_postimage_test", path)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        self.assertEqual(
            module.PCV_FILE_POSTIMAGES["state.php"],
            "3fbc48a0f25178011f0b838cc01d3664932822695d7d39c35d49cbc11fa057c1",
        )

    def test_receipt_is_bound_to_exact_sources_fixed_checks_and_limits(self) -> None:
        builder = self._require_builder()
        commit = "a" * 40
        blobs = {path: ("committed:" + path).encode("utf-8") for path in builder.SOURCE_HASH_PATHS}
        receipt = self._receipt(builder, commit, blobs)

        with patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]):
            parsed = builder._validate_maintenance_receipt(
                "git", commit, builder.BASE.json_bytes(receipt)
            )
        self.assertEqual(parsed["sourceCommit"], commit)

        invalid = []
        wrong_source = copy.deepcopy(receipt)
        wrong_source["sourceCommit"] = "b" * 40
        invalid.append(wrong_source)
        wrong_hash = copy.deepcopy(receipt)
        wrong_hash["sourceHashes"][next(iter(blobs))] = "f" * 64
        invalid.append(wrong_hash)
        missing_source = copy.deepcopy(receipt)
        del missing_source["sourceHashes"][next(iter(blobs))]
        invalid.append(missing_source)
        missing_check = copy.deepcopy(receipt)
        missing_check["checks"].pop()
        invalid.append(missing_check)
        wrong_count = copy.deepcopy(receipt)
        wrong_count["checks"][0]["passed"] -= 1
        invalid.append(wrong_count)
        claimed_http = copy.deepcopy(receipt)
        claimed_http["limits"]["http"] = True
        invalid.append(claimed_http)
        red_as_green = copy.deepcopy(receipt)
        red_as_green["regressionControls"][0]["status"] = "passed"
        invalid.append(red_as_green)

        for candidate in invalid:
            with self.subTest(candidate=candidate):
                with patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]):
                    with self.assertRaises(builder.PackageError):
                        builder._validate_maintenance_receipt(
                            "git", commit, builder.BASE.json_bytes(candidate)
                        )

    def test_baseline_reader_requires_exact_prealpha17_identity_and_archive_shape(self) -> None:
        builder = self._require_builder()
        baseline = self._synthetic_baseline(builder)
        real_sha = builder.BASE.sha256

        def synthetic_sha(data: bytes) -> str:
            if data is baseline:
                return builder.BASELINE_SHA256
            if data == b"synthetic DLL bytes":
                return builder.DLL_SHA256
            return real_sha(data)

        with patch.object(builder.BASE, "sha256", side_effect=synthetic_sha):
            files, department, pcv = builder._read_baseline_bytes(baseline)
        self.assertEqual(len(files), 22)
        self.assertEqual(len(department), 29)
        self.assertEqual(len(pcv), 6)

        with self.assertRaisesRegex(builder.PackageError, "exact verified prealpha.17"):
            builder._read_baseline_bytes(b"wrong archive")

    def test_mod_assembly_replaces_only_two_versioned_packages_and_app_view(self) -> None:
        builder = self._require_builder()
        baseline = self._synthetic_baseline_files(builder)
        assembled = builder._assemble_mod(
            baseline, b"new Department DWPkg", b"new PCV DWPkg", b"updated app.js"
        )

        self.assertEqual(len(assembled), 22)
        self.assertEqual(
            set(baseline) - set(assembled),
            {builder.BASELINE_DEPARTMENT_MEMBER, builder.BASELINE_PCV_MEMBER},
        )
        self.assertEqual(
            set(assembled) - set(baseline), {builder.DEPARTMENT_MEMBER, builder.PCV_MEMBER}
        )
        changed = {
            path for path in set(baseline) & set(assembled) if baseline[path] != assembled[path]
        }
        self.assertEqual(changed, {builder.UI_MEMBER})
        self.assertEqual(
            sum(baseline[path] == assembled[path] for path in set(baseline) & set(assembled)), 19
        )
        self.assertEqual(assembled[builder.DLL_MEMBER], baseline[builder.DLL_MEMBER])

    def test_new_packages_win_when_old_and_new_versioned_paths_are_merged(self) -> None:
        builder = self._require_builder()
        published_prealpha17_timestamp = (2026, 10, 26, 0, 0, 0)
        candidate_archive = builder.BASE.stable_zip_bytes({
            builder.DEPARTMENT_MEMBER: b"new Department DWPkg",
            builder.PCV_MEMBER: b"new PCV companion DWPkg",
        })
        with zipfile.ZipFile(io.BytesIO(candidate_archive)) as archive:
            department_timestamp = archive.getinfo(builder.DEPARTMENT_MEMBER).date_time
            pcv_timestamp = archive.getinfo(builder.PCV_MEMBER).date_time

        self.assertEqual(department_timestamp, builder.ZIP_TIMESTAMP)
        self.assertEqual(pcv_timestamp, builder.ZIP_TIMESTAMP)
        self.assertGreater(department_timestamp, published_prealpha17_timestamp)
        self.assertGreater(pcv_timestamp, published_prealpha17_timestamp)

        for old_path, new_path in (
            (builder.BASELINE_DEPARTMENT_MEMBER, builder.DEPARTMENT_MEMBER),
            (builder.BASELINE_PCV_MEMBER, builder.PCV_MEMBER),
        ):
            old_new = [
                (old_path, published_prealpha17_timestamp),
                (new_path, department_timestamp if new_path == builder.DEPARTMENT_MEMBER else pcv_timestamp),
            ]
            for candidates in (old_new, list(reversed(old_new))):
                # The CHIM client sorts visible paths by mtime only; filenames do not
                # participate in choosing among old and new package versions.
                selected = sorted(candidates, key=lambda item: item[1], reverse=True)[0][0]
                self.assertEqual(selected, new_path)

    def test_dwpkg_audit_rejects_checksum_gaps_and_unapproved_payload_changes(self) -> None:
        builder = self._require_builder()
        old_server = self._minimal_server_files(builder, "pcv_companion", builder.BASELINE_PCV_VERSION)
        old = self._component(builder, "pcv_companion", builder.BASELINE_PCV_VERSION, old_server)
        builder._audit_component(old, "pcv_companion", builder.BASELINE_PCV_VERSION)

        files = builder.BASE.read_zip_bytes(old, "test PCV DWPkg")
        files["server/companion.php"] = b"changed companion pin"
        files["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in files.items() if path != "checksums.sha256"}
        )
        changed_package = builder.BASE.stable_zip_bytes(files)
        builder._compare_component_update(
            builder.BASE.read_zip_bytes(old, "old PCV DWPkg"),
            builder.BASE.read_zip_bytes(changed_package, "new PCV DWPkg"),
            "pcv_companion",
            builder.PCV_VERSION,
            {"manifest.json", "server/manifest.json", "server/companion.php", "checksums.sha256"},
        )

        corrupt = dict(files)
        corrupt["server/companion.php"] = b"tampered after checksum"
        with self.assertRaisesRegex(builder.PackageError, "checksum"):
            builder._audit_component(builder.BASE.stable_zip_bytes(corrupt), "pcv_companion", builder.PCV_VERSION)

        unexpected = dict(files)
        unexpected["server/unreviewed.php"] = b"unexpected"
        unexpected["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in unexpected.items() if path != "checksums.sha256"}
        )
        with self.assertRaisesRegex(builder.PackageError, "only the reviewed"):
            builder._compare_component_update(
                builder.BASE.read_zip_bytes(old, "old PCV DWPkg"),
                unexpected,
                "pcv_companion",
                builder.PCV_VERSION,
                {"manifest.json", "server/manifest.json", "server/companion.php", "checksums.sha256"},
            )

    def test_department_delta_allowlist_covers_only_reviewed_runtime_and_overlay_members(self) -> None:
        builder = self._require_builder()
        old = self._department_baseline_component(builder)
        old_files = builder.BASE.read_zip_bytes(old, "baseline Department DWPkg")
        builder._audit_component(old, "questionable_decisions", builder.BASELINE_VERSION)

        current = dict(old_files)
        for path in ("manifest.json", "server/manifest.json"):
            manifest = json.loads(current[path].decode("utf-8"))
            manifest["version"] = builder.VERSION
            current[path] = builder.BASE.json_bytes(manifest)
        for path in (
            "server/adapters/pcv.php",
            "server/adapters/runtime_support.php",
            "server/lib/overlay_setup.php",
            "server/setup/pcv-overlay/source-manifest.json",
            "server/setup/pcv-overlay/state.php",
        ):
            current[path] = b"reviewed update: " + path.encode("ascii")
        current["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in current.items() if path != "checksums.sha256"}
        )
        candidate = builder.BASE.stable_zip_bytes(current)
        verified = builder._audit_component(candidate, "questionable_decisions", builder.VERSION)
        builder._compare_component_update(
            old_files,
            verified,
            "questionable_decisions",
            builder.VERSION,
            builder.DEPARTMENT_UPDATED_MEMBERS,
        )
        changed = {path for path in old_files if old_files[path] != verified[path]}
        self.assertEqual(changed, builder.DEPARTMENT_UPDATED_MEMBERS)
        self.assertIn("server/setup/pcv-overlay/state.php", changed)

        unreviewed = dict(verified)
        unreviewed["server/adapters/correspondence.php"] = b"unreviewed"
        unreviewed["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in unreviewed.items() if path != "checksums.sha256"}
        )
        with self.assertRaisesRegex(builder.PackageError, "only the reviewed"):
            builder._compare_component_update(
                old_files,
                unreviewed,
                "questionable_decisions",
                builder.VERSION,
                builder.DEPARTMENT_UPDATED_MEMBERS,
            )

    def test_source_allowlist_contains_only_required_task_sources_and_tests(self) -> None:
        builder = self._require_builder()
        allowlist = json.loads(
            (ROOT / "packaging" / "public-source-allowlist.json").read_text(encoding="utf-8")
        )
        paths = {row["path"] for row in allowlist["files"]}
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS.issubset(paths))
        self.assertTrue(
            {
                "server/tests/pcv/runtime_support_limits.php",
                "server/tests/onboarding/overlay.php",
                "packaging/build-installable-prealpha18.py",
                "packaging/tests/test_installable_prealpha18.py",
                "docs/audits/2026-10-26-maintainer-world-prompt.md",
                "docs/audits/2026-10-26-maintainer-world-findings.md",
                "docs/audits/2026-10-26-maintainer-world-coverage.md",
                "docs/audits/2026-10-26-maintainer-world-contracts.md",
                "docs/superpowers/plans/2026-10-26-maintainer-fixes.md",
            }.issubset(paths)
        )

    @staticmethod
    def _receipt(builder, commit: str, blobs: dict[str, bytes]) -> dict:
        return {
            "schemaVersion": 1,
            "kind": "department-maintainer-prealpha18",
            "sourceCommit": commit,
            "baseline": {
                "version": builder.BASELINE_VERSION,
                "sha256": builder.BASELINE_SHA256,
            },
            "sourceHashes": {
                path: builder.BASE.sha256(data) for path, data in blobs.items()
            },
            "checks": [
                {
                    "name": name,
                    "status": "passed",
                    "passed": counts[0],
                    "total": counts[1],
                    "evidenceName": evidence,
                    "evidenceSha256": "1" * 64,
                }
                for name, counts, evidence in builder.EXPECTED_CHECKS
            ],
            "regressionControls": [
                {
                    "name": name,
                    "status": "expected-red",
                    "passed": counts[0],
                    "total": counts[1],
                    "evidenceName": evidence,
                    "evidenceSha256": "2" * 64,
                }
                for name, counts, evidence in builder.EXPECTED_REGRESSION_CONTROLS
            ],
            "limits": dict(builder.PROOF_LIMITS),
        }

    @staticmethod
    def _minimal_server_files(builder, name: str, version: str) -> dict[str, bytes]:
        return {
            "manifest.json": builder.BASE.json_bytes(
                {
                    "name": name,
                    "version": version,
                    "schema_version": 2,
                    "description": "test component",
                    "config_url": "../ext/questionable_decisions/index.php",
                }
            ),
            "companion.php": b"old companion",
            "globals.php": b"globals",
            "preprocessing.php": b"preprocessing",
        }

    @staticmethod
    def _component(builder, name: str, version: str, server_files: dict[str, bytes]) -> bytes:
        outer = {
            "schema_version": 4,
            "name": name,
            "version": version,
            "description": "test component",
            "server": {"mutable_paths": []},
        }
        files = {"manifest.json": builder.BASE.json_bytes(outer)}
        files.update({f"server/{path}": data for path, data in server_files.items()})
        files["checksums.sha256"] = builder.BASE.make_checksums(files)
        return builder.BASE.stable_zip_bytes(files)

    @classmethod
    def _synthetic_baseline_files(cls, builder) -> dict[str, bytes]:
        files = {
            path: ("baseline:" + path).encode("utf-8")
            for path in builder.EXPECTED_BASELINE_MEMBERS
        }
        files[builder.BASELINE_DEPARTMENT_MEMBER] = b"old Department"
        files[builder.BASELINE_PCV_MEMBER] = b"old PCV"
        files[builder.DLL_MEMBER] = b"synthetic DLL bytes"
        files[builder.UI_MEMBER] = b"old app.js"
        return files

    @classmethod
    def _synthetic_baseline(cls, builder) -> bytes:
        files = cls._synthetic_baseline_files(builder)
        old_dept = cls._department_baseline_component(builder)
        old_pcv = cls._component(
            builder,
            "pcv_companion",
            builder.BASELINE_PCV_VERSION,
            cls._minimal_server_files(builder, "pcv_companion", builder.BASELINE_PCV_VERSION),
        )
        files[builder.BASELINE_DEPARTMENT_MEMBER] = old_dept
        files[builder.BASELINE_PCV_MEMBER] = old_pcv
        return builder.BASE.stable_zip_bytes(files)

    @classmethod
    def _department_baseline_component(cls, builder) -> bytes:
        inner = builder.BASE.json_bytes(
            {
                "name": "questionable_decisions",
                "version": builder.BASELINE_VERSION,
                "schema_version": 2,
                "description": "A small Skyrim-side Department for the affairs CHIM plugins leave behind.",
                "status": "development_candidate",
                "config_url": "../ext/questionable_decisions/index.php",
                "server_compatibility_reference": "0ec590b42c8087860138bb4e9aab470049ff9a2b",
            }
        )
        server = {
            path.removeprefix("server/"): b"baseline:" + path.encode("utf-8")
            for path in builder.SERVER_SOURCE_PATHS
        }
        server["manifest.json"] = inner
        for path in (
            "source-manifest.json",
            *builder.BASE.PCV_FILE_POSTIMAGES,
        ):
            server[f"setup/pcv-overlay/{path}"] = b"baseline overlay:" + path.encode("utf-8")
        return cls._component(builder, "questionable_decisions", builder.BASELINE_VERSION, server)


if __name__ == "__main__":
    unittest.main()
