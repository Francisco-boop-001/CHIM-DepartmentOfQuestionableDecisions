from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha23.py"


def load_builder():
    if not BUILDER_PATH.is_file():
        raise AssertionError("the prealpha.23 builder scaffold is missing")
    spec = importlib.util.spec_from_file_location("department_prealpha23", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.23 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha23BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = load_builder()

    def test_contract_pins_published_baseline_and_fixed_package_delta(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.23")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.22")
        self.assertEqual(
            builder.BASELINE_SHA256,
            "688c6bd761031902d5017a6593b2ff50e13a191f154d7950708adefdffe94bfd",
        )
        self.assertEqual(builder.BASELINE_ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 10))
        self.assertEqual(builder.ZIP_TIMESTAMP, (2026, 10, 27, 0, 0, 12))
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.EXPECTED_INSTALLABLE_MEMBERS), 22)
        self.assertEqual(builder._ui_app_member(builder.EXPECTED_BASELINE_MEMBERS), "PrismaUI/views/WorldOfDramaLlama/app.js")
        self.assertIn("ui/app.js", builder.REQUIRED_SOURCE_PATHS)
        self.assertEqual(len(builder.DEPARTMENT_EXPECTED_MEMBERS), 30)
        self.assertEqual(builder.DEPARTMENT_UPDATED_MEMBERS, {
            "manifest.json",
            "server/manifest.json",
            "server/adapters/runtime_support.php",
            "server/adapters/pcv.php",
            "server/adapters/mp.php",
            "server/adapters/brainwash.php",
            "checksums.sha256",
        })
        self.assertEqual(
            builder.MMP_PACKAGE_SHA256,
            "9f1d8221954a293116b5bc7c202f13c4eb2062982d3fdabeb9134dfa0e05cec8",
        )
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["version"], builder.VERSION)

    def test_public_source_allowlist_covers_required_candidate_sources(self) -> None:
        builder = self.builder
        allowlist = json.loads((ROOT / "packaging" / "public-source-allowlist.json").read_text(encoding="utf-8"))
        paths = [entry["path"] for entry in allowlist["files"]]
        self.assertEqual(len(paths), len(set(paths)))
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS <= set(paths))

    def test_baseline_rejects_anything_except_the_published_archive_hash(self) -> None:
        with self.assertRaisesRegex(self.builder.PackageError, "exact verified prealpha.22"):
            self.builder._read_baseline_bytes(b"not the published archive")

    def test_inputs_read_ui_app_from_the_selected_committed_source(self) -> None:
        builder = self.builder
        commit = "a" * 40
        selected_ui_app = b"selected commit ui app bytes\n"
        mmp_package = self._brainwash_component(builder, builder.MMP_VERSION)
        baseline = self._mo2_baseline_fixture(builder)
        args = type("Args", (), {
            "git": "git",
            "source_commit": commit,
            "prealpha22_zip": Path("unused-baseline.zip"),
            "mmp_dwpkg": Path("unused-mmp.dwpkg"),
            "normal_chim_proof_dir": Path("unused-proof"),
        })()

        with mock.patch.object(builder.BASE, "canonical_source_commit", return_value=commit), \
                mock.patch.object(builder, "_read_baseline", return_value=(baseline, {}, {}, {})), \
                mock.patch.object(builder, "_read_approved_mmp", return_value=mmp_package), \
                mock.patch.object(builder, "_read_proof_inputs", return_value={}), \
                mock.patch.object(builder, "_validate_acceptance_receipts", return_value={}), \
                mock.patch.object(builder.BASE, "source_allowlist", return_value=list(builder.REQUIRED_SOURCE_PATHS)), \
                mock.patch.object(builder.BASE, "committed_worktree_file", return_value=selected_ui_app) as committed_file:
            result = builder._inputs(args)

        self.assertEqual(result[-1], selected_ui_app)
        committed_file.assert_called_once_with("git", commit, set(builder.REQUIRED_SOURCE_PATHS), "ui/app.js")

    def test_department_assembly_changes_only_the_fixed_runtime_delta(self) -> None:
        builder = self.builder
        old_package = self._department_component(builder, builder.BASELINE_VERSION)
        source = self._department_sources(builder, old_package)
        new_zip, new_package = builder._build_department_package(old_package, source.__getitem__)

        changes = {
            path for path in old_package.keys() | new_package.keys()
            if old_package.get(path) != new_package.get(path)
        }
        self.assertEqual(changes, builder.DEPARTMENT_UPDATED_MEMBERS)
        self.assertEqual(new_package["server/globals.php"], old_package["server/globals.php"])
        self.assertEqual(
            builder.P21._audit_department_component(new_zip, builder.VERSION),
            new_package,
        )

    def test_department_assembly_rejects_unlisted_source_drift(self) -> None:
        builder = self.builder
        old_package = self._department_component(builder, builder.BASELINE_VERSION)
        source = self._department_sources(builder, old_package)
        source["server/adapters/sas.php"] = b"unexpected source edit"
        with self.assertRaisesRegex(builder.PackageError, "fixed prealpha.23 runtime delta"):
            builder._build_department_package(old_package, source.__getitem__)

    def test_mo2_assembly_changes_only_the_source_bound_ui_app_and_two_packages(self) -> None:
        builder = self.builder
        baseline = self._mo2_baseline_fixture(builder)
        mmp_package = self._brainwash_component(builder, builder.MMP_VERSION)
        ui_app = b"committed ui app bytes\n"
        actual_sha256 = builder.BASE.sha256

        def sha256(data: bytes) -> str:
            if data == b"accepted native DLL fixture":
                return builder.DLL_SHA256
            return actual_sha256(data)

        with mock.patch.object(builder, "MMP_PACKAGE_SHA256", actual_sha256(mmp_package)), \
                mock.patch.object(builder.BASE, "sha256", side_effect=sha256):
            assembled = builder._assemble_mod(baseline, b"department dev23 package", mmp_package, ui_app)

        ui_member = builder._ui_app_member(baseline)
        self.assertEqual(assembled[ui_member], ui_app)
        self.assertNotEqual(assembled[ui_member], baseline[ui_member])
        changed_common = {
            path for path in set(baseline) & set(assembled)
            if baseline[path] != assembled[path]
        }
        self.assertEqual(changed_common, {ui_member})
        self.assertEqual(
            sum(baseline[path] == assembled[path] for path in set(baseline) & set(assembled)),
            19,
        )
        self.assertEqual(assembled[builder.DLL_MEMBER], baseline[builder.DLL_MEMBER])

    def test_mo2_audit_rejects_other_ui_payload_drift_and_ui_source_mismatch(self) -> None:
        builder = self.builder
        baseline = self._mo2_baseline_fixture(builder)
        mmp_package = self._brainwash_component(builder, builder.MMP_VERSION)
        ui_app = b"committed ui app bytes\n"
        actual_sha256 = builder.BASE.sha256

        def sha256(data: bytes) -> str:
            if data == b"accepted native DLL fixture":
                return builder.DLL_SHA256
            return actual_sha256(data)

        with mock.patch.object(builder, "MMP_PACKAGE_SHA256", actual_sha256(mmp_package)), \
                mock.patch.object(builder.BASE, "sha256", side_effect=sha256):
            assembled = builder._assemble_mod(baseline, b"department dev23 package", mmp_package, ui_app)
            ui_css = "PrismaUI/views/WorldOfDramaLlama/app.css"
            unrelated_ui_drift = dict(assembled)
            unrelated_ui_drift[ui_css] += b"changed"
            with self.assertRaisesRegex(builder.PackageError, "unrelated MO2 payload"):
                builder._assert_mod_delta(baseline, unrelated_ui_drift, ui_app)

            mismatched_ui_source = dict(assembled)
            mismatched_ui_source[builder._ui_app_member(baseline)] = b"another source blob"
            with self.assertRaisesRegex(builder.PackageError, "selected committed source"):
                builder._assert_mod_delta(baseline, mismatched_ui_source, ui_app)

    def test_brainwash_baseline_package_requires_complete_checksums_and_identity(self) -> None:
        builder = self.builder
        package = self._brainwash_component(builder, builder.BASELINE_MMP_VERSION)
        parsed = builder._read_brainwash_component(package, builder.BASELINE_MMP_VERSION)
        self.assertEqual(len(parsed), 12)

        changed = dict(parsed)
        changed["server/runtime.php"] += b"tampered"
        tampered = builder.BASE.stable_zip_bytes(changed)
        with self.assertRaisesRegex(builder.PackageError, "checksum closure"):
            builder._read_brainwash_component(tampered, builder.BASELINE_MMP_VERSION)

    def test_mmp_dev4_accepts_only_the_frozen_package_pin(self) -> None:
        builder = self.builder
        self.assertEqual(builder.MMP_VERSION, "0.1.0-dev.4")
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "brainwash-dev4.dwpkg"
            candidate = self._brainwash_component(builder, builder.MMP_VERSION)
            path.write_bytes(candidate)
            with self.assertRaisesRegex(builder.PackageError, "differs from the approved dev.4 package pin"):
                builder._read_approved_mmp(path)
            with self.assertRaisesRegex(builder.PackageError, "differs from the approved dev.4 package pin"):
                builder._assemble_mod({}, b"department", candidate, b"ui app")

            baseline = self._brainwash_component(builder, builder.BASELINE_MMP_VERSION)
            path.write_bytes(baseline)
            with self.assertRaisesRegex(builder.PackageError, "differs from the approved dev.4 package pin"):
                builder._read_approved_mmp(path)

    def test_receipts_bind_real_source_blobs_all_ready_http_and_native_checks(self) -> None:
        builder = self.builder
        commit, blobs, proof_files, expected, mmp_package = self._proof_fixture(builder)
        with mock.patch.object(
            builder.BASE,
            "git_blob",
            side_effect=lambda _git, _commit, path: blobs[path],
        ):
            accepted = builder._validate_acceptance_receipts(proof_files, commit, "git", mmp_package)
        self.assertEqual(accepted, expected)

        invalid = []
        false_capability = json.loads(proof_files[builder.INPUT_HTTP_RECEIPT].decode("utf-8"))
        false_capability["checks"]["authenticatedAllFive"] = False
        invalid.append({**proof_files, builder.INPUT_HTTP_RECEIPT: builder.BASE.json_bytes(false_capability)})
        wrong_source_hash = json.loads(proof_files[builder.INPUT_HTTP_RECEIPT].decode("utf-8"))
        source_path = sorted(builder.HTTP_SOURCE_HASH_PATHS)[0]
        wrong_source_hash["departmentSourceHashes"][source_path] = "f" * 64
        invalid.append({**proof_files, builder.INPUT_HTTP_RECEIPT: builder.BASE.json_bytes(wrong_source_hash)})
        wrong_owner_version = json.loads(proof_files[builder.INPUT_HTTP_RECEIPT].decode("utf-8"))
        wrong_owner_version["ownerVersions"]["mp"] = "0.1.29"
        invalid.append({**proof_files, builder.INPUT_HTTP_RECEIPT: builder.BASE.json_bytes(wrong_owner_version)})
        token_field = json.loads(proof_files[builder.INPUT_HTTP_RECEIPT].decode("utf-8"))
        token_field["pairToken"] = "must never enter a public proof"
        invalid.append({**proof_files, builder.INPUT_HTTP_RECEIPT: builder.BASE.json_bytes(token_field)})
        wrong_fixture_limit = json.loads(proof_files[builder.INPUT_HTTP_RECEIPT].decode("utf-8"))
        wrong_fixture_limit["limits"]["fixtureDatabase"] = False
        invalid.append({**proof_files, builder.INPUT_HTTP_RECEIPT: builder.BASE.json_bytes(wrong_fixture_limit)})
        wrong_summary_hash = json.loads(proof_files[builder.INPUT_ACCEPTANCE_RECEIPT].decode("utf-8"))
        wrong_summary_hash["httpSummarySha256"] = "f" * 64
        invalid.append({**proof_files, builder.INPUT_ACCEPTANCE_RECEIPT: builder.BASE.json_bytes(wrong_summary_hash)})
        native_reject = json.loads(proof_files[builder.INPUT_NATIVE_RECEIPT].decode("utf-8"))
        native_reject["checks"]["invalidResponseRefused"] = False
        invalid.append({**proof_files, builder.INPUT_NATIVE_RECEIPT: builder.BASE.json_bytes(native_reject)})

        for candidate in invalid:
            with self.subTest(names=set(candidate)):
                with mock.patch.object(
                    builder.BASE,
                    "git_blob",
                    side_effect=lambda _git, _commit, path: blobs[path],
                ), self.assertRaises(builder.PackageError):
                    builder._validate_acceptance_receipts(candidate, commit, "git", mmp_package)

    def test_native_receipt_rejects_ui_app_hash_mismatch(self) -> None:
        builder = self.builder
        commit, blobs, proof_files, _expected, mmp_package = self._proof_fixture(builder)
        native = json.loads(proof_files[builder.INPUT_NATIVE_RECEIPT].decode("utf-8"))
        native["uiAppSha256"] = "f" * 64
        candidate = {
            **proof_files,
            builder.INPUT_NATIVE_RECEIPT: builder.BASE.json_bytes(native),
        }
        with mock.patch.object(
            builder.BASE,
            "git_blob",
            side_effect=lambda _git, _commit, path: blobs[path],
        ), self.assertRaisesRegex(
            builder.PackageError,
            "UI app hash differs from the selected committed source",
        ):
            builder._validate_acceptance_receipts(candidate, commit, "git", mmp_package)

    def test_brainwash_owner_tree_hash_uses_sorted_server_member_bytes(self) -> None:
        builder = self.builder
        package = self._brainwash_component(builder, builder.MMP_VERSION)
        files = builder.BASE.read_zip_bytes(package, "Brainwash tree-hash fixture")
        server_members = {
            path.removeprefix("server/"): content
            for path, content in files.items()
            if path.startswith("server/")
        }
        expected_frame = b"".join(
            path.encode("utf-8") + b"\0" + builder.BASE.sha256(content).encode("ascii") + b"\n"
            for path, content in sorted(server_members.items())
        )
        self.assertEqual(
            builder._brainwash_server_tree_hash(package),
            builder.BASE.sha256(expected_frame),
        )

    def test_http_receipt_refuses_brainwash_tree_hash_from_another_package(self) -> None:
        builder = self.builder
        commit, blobs, proof_files, _receipt, _mmp_package = self._proof_fixture(builder)
        other_files = self._brainwash_component(builder, builder.MMP_VERSION)
        parsed = builder.BASE.read_zip_bytes(other_files, "Brainwash changed-tree fixture")
        parsed["server/runtime.php"] += b"changed candidate bytes"
        parsed["checksums.sha256"] = builder.BASE.make_checksums(
            {path: content for path, content in parsed.items() if path != "checksums.sha256"}
        )
        other_package = builder.BASE.stable_zip_bytes(parsed)
        with mock.patch.object(
            builder.BASE,
            "git_blob",
            side_effect=lambda _git, _commit, path: blobs[path],
        ), self.assertRaisesRegex(builder.PackageError, "owner tree hash differs from the approved dev.4 DWPkg"):
            builder._validate_acceptance_receipts(proof_files, commit, "git", other_package)

    def test_proof_zip_contains_only_fixed_summaries_and_sanitized_provenance(self) -> None:
        builder = self.builder
        commit, _blobs, proof_files, receipt, _mmp_package = self._proof_fixture(builder)
        ui_app = b"proof ui app source"
        proof = builder._proof_zip(commit, b"installable", b"source", b"department", b"mmp", ui_app, proof_files, receipt)
        proof_contents = builder.BASE.read_zip_bytes(proof, "prealpha.23 proof fixture")
        self.assertEqual(
            set(proof_contents),
            builder.PROOF_INPUT_NAMES | {"package-provenance.json"},
        )
        provenance = json.loads(proof_contents["package-provenance.json"].decode("utf-8"))
        self.assertEqual(provenance["unchangedMo2Members"], 19)
        self.assertEqual(provenance["uiApp"], {
            "member": "PrismaUI/views/WorldOfDramaLlama/app.js",
            "sha256": builder.BASE.sha256(ui_app),
        })
        self.assertEqual(provenance["departmentDpkgDelta"], sorted(builder.DEPARTMENT_UPDATED_MEMBERS))
        self.assertEqual(provenance["acceptance"]["checks"], receipt["checks"])
        self.assertEqual(
            provenance["acceptance"]["httpChecks"],
            json.loads(proof_files[builder.INPUT_HTTP_RECEIPT])["checks"],
        )
        self.assertEqual(
            provenance["acceptance"]["nativeChecks"],
            json.loads(proof_files[builder.INPUT_NATIVE_RECEIPT])["checks"],
        )
        self.assertEqual(provenance["acceptance"]["limits"], json.loads(proof_files[builder.INPUT_HTTP_RECEIPT])["limits"])
        self.assertNotIn("rawEvidence", provenance)

    def test_receipt_directory_rejects_extra_files(self) -> None:
        builder = self.builder
        _commit, _blobs, proof_files, _receipt, _mmp_package = self._proof_fixture(builder)
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            for name, data in proof_files.items():
                (directory / name).write_bytes(data)
            self.assertEqual(builder._read_proof_inputs(directory), proof_files)

            (directory / "extra.json").write_text("{}", encoding="utf-8")
            with self.assertRaisesRegex(builder.PackageError, "only the three fixed receipt files"):
                builder._read_proof_inputs(directory)

    @staticmethod
    def _proof_fixture(builder):
        commit = "a" * 40
        blobs = {
            path: f"committed source: {path}".encode("utf-8")
            for path in builder.HTTP_SOURCE_HASH_PATHS
        }
        blobs[builder.UI_SOURCE_PATH] = b"committed source: ui/app.js"
        mmp_package = Prealpha23BuilderTests._brainwash_component(builder, builder.MMP_VERSION)
        owner_tree_hashes = {name: "1" * 64 for name in builder.OWNER_VERSIONS}
        owner_tree_hashes["brainwash"] = builder._brainwash_server_tree_hash(mmp_package)
        http = {
            "schemaVersion": 1,
            "departmentSourceHashes": {
                path: builder.BASE.sha256(blobs[path])
                for path in builder.HTTP_SOURCE_HASH_PATHS
            },
            "hostMainSha256": builder.HOST_MAIN_SHA256,
            "sharmatCommit": builder.SHARMAT_COMMIT,
            "ownerVersions": dict(builder.OWNER_VERSIONS),
            "ownerSourceTreeHashes": owner_tree_hashes,
            "checks": {name: True for name in builder.HTTP_CHECK_NAMES},
            "rawEvidenceSha256": "2" * 64,
            "limits": dict(builder.HTTP_LIMITS),
        }
        native = {
            "schemaVersion": 1,
            "dllSha256": builder.DLL_SHA256,
            "uiAppSha256": builder.BASE.sha256(blobs[builder.UI_SOURCE_PATH]),
            "checks": {name: True for name in builder.NATIVE_CHECK_NAMES},
            "rawEvidenceSha256": "3" * 64,
        }
        files = {
            builder.INPUT_HTTP_RECEIPT: builder.BASE.json_bytes(http),
            builder.INPUT_NATIVE_RECEIPT: builder.BASE.json_bytes(native),
        }
        aggregate = {
            "schemaVersion": 1,
            "sourceCommit": commit,
            "httpSummarySha256": builder.BASE.sha256(files[builder.INPUT_HTTP_RECEIPT]),
            "nativeSummarySha256": builder.BASE.sha256(files[builder.INPUT_NATIVE_RECEIPT]),
            "checks": {name: True for name in builder.AGGREGATE_CHECK_NAMES},
        }
        files[builder.INPUT_ACCEPTANCE_RECEIPT] = builder.BASE.json_bytes(aggregate)
        return commit, blobs, files, aggregate, mmp_package


    @staticmethod
    def _department_component(builder, version: str) -> dict[str, bytes]:
        files = {
            path: f"fixture:{path}".encode("utf-8")
            for path in builder.DEPARTMENT_EXPECTED_MEMBERS
        }
        files["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": "questionable_decisions",
            "version": version,
            "description": "fixture component",
            "server": {"mutable_paths": []},
        })
        files["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "questionable_decisions",
            "version": version,
            "description": "fixture component",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL["questionable_decisions"],
        })
        files["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in files.items() if path != "checksums.sha256"}
        )
        package = builder.BASE.stable_zip_bytes(files)
        return builder.P21._audit_department_component(package, version)

    @staticmethod
    def _mo2_baseline_fixture(builder) -> dict[str, bytes]:
        baseline = {path: b"baseline member:" + path.encode("utf-8") for path in builder.EXPECTED_BASELINE_MEMBERS}
        baseline[builder.DLL_MEMBER] = b"accepted native DLL fixture"
        return baseline

    @staticmethod
    def _department_sources(builder, old_package: dict[str, bytes]) -> dict[str, bytes]:
        source = {
            path: old_package["server/" + path.removeprefix("server/")]
            for path in builder.SERVER_SOURCE_PATHS
        }
        source["server/globals.php"] = old_package["server/globals.php"]
        source["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "questionable_decisions",
            "version": builder.VERSION,
            "description": "fixture component",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL["questionable_decisions"],
        })
        for path in builder.DEPARTMENT_SOURCE_UPDATES:
            source[path] = b"reviewed source update: " + path.encode("utf-8")
        return source

    @staticmethod
    def _brainwash_component(builder, version: str) -> bytes:
        members = {
            "server/api.php",
            "server/assets/editor.css",
            "server/assets/editor.js",
            "server/assets/web.js",
            "server/context_pre.php",
            "server/index.php",
            "server/lib/service.php",
            "server/lib/web.php",
            "server/runtime.php",
            "server/manifest.json",
            "manifest.json",
            "checksums.sha256",
        }
        files = {path: f"fixture:{path}".encode("utf-8") for path in members}
        files["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": "brainwash",
            "version": version,
            "description": "fixture owner",
            "server": {"mutable_paths": []},
        })
        files["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "brainwash",
            "version": version,
            "description": "fixture owner",
            "config_url": builder.BRAINWASH_CONFIG_URL,
        })
        files["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in files.items() if path != "checksums.sha256"}
        )
        return builder.BASE.stable_zip_bytes(files)


if __name__ == "__main__":
    unittest.main()
