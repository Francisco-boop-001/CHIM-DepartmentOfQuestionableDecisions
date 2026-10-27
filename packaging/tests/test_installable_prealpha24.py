from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha24.py"


def load_builder():
    spec = importlib.util.spec_from_file_location("department_prealpha24", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.24 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha24BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = load_builder()

    def test_contract_pins_prealpha23_baseline_and_fixed_payload_delta(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.24")
        self.assertEqual(builder.PCV_VERSION, "0.1.0-prealpha.12")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.23")
        self.assertEqual(
            builder.BASELINE_SHA256,
            "1d2cc2aaf1472dcfdc7dc6d979ae29d748ff3687bee1f0d05a85f7a390b9cf28",
        )
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.EXPECTED_INSTALLABLE_MEMBERS), 22)
        self.assertEqual(len(builder.DEPARTMENT_EXPECTED_MEMBERS), 30)
        self.assertEqual(len(builder.PCV_EXPECTED_MEMBERS), 6)
        self.assertIn(builder.TEMPLATE_OVERLAY_PREPROCESSING_MEMBER, builder.DEPARTMENT_EXPECTED_MEMBERS)
        self.assertNotIn(builder.ACTIVE_OVERLAY_PREPROCESSING_MEMBER, builder.DEPARTMENT_EXPECTED_MEMBERS)
        self.assertEqual(builder.OUTPUT_PROOF_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.24-offline-proof.zip")
        self.assertEqual(builder.PROOF_LIMITS, {
            "server": False,
            "database": False,
            "http": False,
            "provider": False,
            "skyrim": False,
            "installedGame": False,
            "live": False,
        })
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        pcv_manifest = json.loads((ROOT / "pcv-companion" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["version"], builder.VERSION)
        self.assertEqual(pcv_manifest["version"], builder.PCV_VERSION)

    def test_offline_source_hash_map_is_the_fixed_runtime_and_native_input_closure(self) -> None:
        self.assertEqual(self.builder.SOURCE_HASH_PATHS, {
            "server/adapters/correspondence.php",
            "server/lib/overlay_setup.php",
            "packaging/companion-only/pcv-0.1.24-source-manifest.json",
            "server/manifest.json",
            "pcv-companion/companion.php",
            "pcv-companion/manifest.json",
            "native/CMakeLists.txt",
            "native/deps.lock.json",
            "native/src/Plugin.cpp",
            "native/src/GoldInventory.h",
        })
        self.assertEqual(len(self.builder.NATIVE_BUILD_SOURCE_PATHS), 27)

    def test_baseline_refuses_nonpublished_bytes(self) -> None:
        with self.assertRaisesRegex(self.builder.PackageError, "exact verified prealpha.23"):
            self.builder._read_baseline_bytes(b"not the published archive")

    def test_dev28_adapter_changes_only_the_frozen_manifest_pin(self) -> None:
        builder = self.builder
        adapter = (ROOT / "server" / "adapters" / "correspondence.php").read_bytes()
        pins = builder._validate_dev28_adapter(adapter, adapter)
        self.assertEqual(len(pins), 19)
        self.assertEqual(pins["manifest.json"], builder.OWNER_MANIFEST_SHA256)
        stale = adapter.replace(
            builder.OWNER_MANIFEST_SHA256.encode("ascii"),
            b"0" * 64,
            1,
        )
        with self.assertRaisesRegex(builder.PackageError, "manifest"):
            builder._validate_dev28_adapter(adapter, stale)

    def test_overlay_stage_maps_preprocessing_to_template_and_rejects_active_basename(self) -> None:
        builder = self.builder
        manifest, staged = self._overlay_fixture(builder)
        builder._validate_overlay_stage(manifest, staged)
        active = dict(staged)
        active["preprocessing.php"] = active.pop("preprocessing.php.template")
        with self.assertRaisesRegex(builder.PackageError, "exact template-safe payload set"):
            builder._validate_overlay_stage(manifest, active)

    def test_overlay_manifest_requires_exact_staged_path_key_order(self) -> None:
        builder = self.builder
        manifest, _staged = self._overlay_fixture(builder)
        data = json.loads(manifest)
        entry = data["files"][3]
        staged_path = entry.pop("stagedPath")
        entry["stagedPath"] = staged_path
        reordered = builder.BASE.json_bytes(data)
        with self.assertRaisesRegex(builder.PackageError, "entry fields differ from the staged-path schema"):
            builder._overlay_mapping(reordered)

    def test_department_package_assembly_has_exact_template_and_dev28_delta(self) -> None:
        builder = self.builder
        old_package, source = self._department_fixture(builder)
        package, files = builder._build_department_package(old_package, source.__getitem__)
        self.assertEqual(set(files), builder.DEPARTMENT_EXPECTED_MEMBERS)
        self.assertNotIn(builder.ACTIVE_OVERLAY_PREPROCESSING_MEMBER, files)
        self.assertEqual(
            files[builder.TEMPLATE_OVERLAY_PREPROCESSING_MEMBER],
            source[f"{builder.OWNER_STAGE_DIRECTORY}/preprocessing.php"],
        )
        self.assertEqual(builder._audit_department_component(package, builder.VERSION), files)
        changes = {
            path for path in old_package.keys() | files.keys()
            if old_package.get(path) != files.get(path)
        }
        self.assertEqual(changes, builder.DEPARTMENT_UPDATED_MEMBERS)

    def test_department_package_rejects_staged_source_drift(self) -> None:
        builder = self.builder
        old_package, source = self._department_fixture(builder)
        source[f"{builder.OWNER_STAGE_DIRECTORY}/preprocessing.php"] += b" drift"
        with self.assertRaisesRegex(builder.PackageError, "postimage"):
            builder._build_department_package(old_package, source.__getitem__)

    def test_department_package_rejects_wrong_server_manifest_version(self) -> None:
        builder = self.builder
        old_package, source = self._department_fixture(builder)
        inner = json.loads(source["server/manifest.json"])
        inner["version"] = "0.1.0-prealpha.23"
        source["server/manifest.json"] = builder.BASE.json_bytes(inner)
        with self.assertRaisesRegex(builder.PackageError, "server manifest changed beyond its version"):
            builder._build_department_package(old_package, source.__getitem__)

    def test_pcv_companion12_assembly_contains_only_the_companion_projection_delta(self) -> None:
        builder = self.builder
        old_package, source = self._pcv_fixture(builder)
        package, files = builder._build_pcv_package(old_package, source.__getitem__)
        self.assertEqual(set(files), builder.PCV_EXPECTED_MEMBERS)
        self.assertEqual(builder.P20._audit_component(package, "pcv_companion", builder.PCV_VERSION), files)
        changes = {path for path in old_package if old_package[path] != files[path]}
        self.assertEqual(changes, builder.PCV_UPDATED_MEMBERS)

    def test_source_allowlist_rejects_missing_and_duplicate_paths(self) -> None:
        builder = self.builder
        paths = sorted(builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS)
        self.assertEqual(builder._validate_source_allowlist(paths), set(paths))
        with self.assertRaisesRegex(builder.PackageError, "missing required candidate paths"):
            builder._validate_source_allowlist(paths[:-1])
        with self.assertRaisesRegex(builder.PackageError, "duplicate paths"):
            builder._validate_source_allowlist(paths + [paths[0]])

    def test_offline_receipt_binds_commit_source_dll_build_receipt_and_limits(self) -> None:
        builder = self.builder
        commit = "a" * 40
        dll = b"native DLL fixture"
        sources = self._source_fixture(builder)
        build_receipt = self._native_build_receipt(builder, sources, dll)
        receipt = self._offline_receipt(builder, commit, dll, build_receipt, sources)
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: sources[path]):
            validated = builder._validate_offline_checks(
                receipt,
                commit,
                "git",
                builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS,
                dll,
                build_receipt,
            )
        self.assertEqual(validated, receipt)

    def test_offline_receipt_rejects_source_drift_and_normal_chim_receipt(self) -> None:
        builder = self.builder
        commit = "b" * 40
        dll = b"native DLL fixture"
        sources = self._source_fixture(builder)
        build_receipt = self._native_build_receipt(builder, sources, dll)
        receipt = self._offline_receipt(builder, commit, dll, build_receipt, sources)
        changed = dict(receipt)
        changed["sourceFiles"] = dict(receipt["sourceFiles"])
        changed["sourceFiles"][next(iter(builder.SOURCE_HASH_PATHS))] = "0" * 64
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: sources[path]):
            with self.assertRaisesRegex(builder.PackageError, "source hash differs"):
                builder._validate_offline_checks(changed, commit, "git", builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS, dll, build_receipt)
            with self.assertRaisesRegex(builder.PackageError, "fields differ from the fixed schema"):
                builder._validate_offline_checks(
                    {"schemaVersion": 1, "checks": {}, "limits": {"http": True}},
                    commit,
                    "git",
                    builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS,
                    dll,
                    build_receipt,
                )

    def test_offline_receipt_rejects_true_runtime_limit_or_wrong_native_version(self) -> None:
        builder = self.builder
        commit = "c" * 40
        dll = b"native DLL fixture"
        sources = self._source_fixture(builder)
        build_receipt = self._native_build_receipt(builder, sources, dll)
        receipt = self._offline_receipt(builder, commit, dll, build_receipt, sources)
        enabled_http = json.loads(json.dumps(receipt))
        enabled_http["limits"]["http"] = True
        wrong_native = json.loads(json.dumps(receipt))
        wrong_native["native"]["version"] = "0.1.16.0"
        changed_build = json.loads(build_receipt)
        changed_build["warning"] = "different canonical build receipt"
        changed_build_bytes = builder.BASE.json_bytes(changed_build)
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: sources[path]):
            with self.assertRaisesRegex(builder.PackageError, "offline limits"):
                builder._validate_offline_checks(enabled_http, commit, "git", builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS, dll, build_receipt)
            with self.assertRaisesRegex(builder.PackageError, "native version"):
                builder._validate_offline_checks(wrong_native, commit, "git", builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS, dll, build_receipt)
            with self.assertRaisesRegex(builder.PackageError, "build receipt differs"):
                builder._validate_offline_checks(receipt, commit, "git", builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS, dll, changed_build_bytes)

    def test_native_build_receipt_rejects_changed_compiled_source_and_wrong_dll(self) -> None:
        builder = self.builder
        commit = "e" * 40
        dll = b"native DLL fixture"
        sources = self._source_fixture(builder)
        receipt = self._native_build_receipt(builder, sources, dll)
        changed_source = json.loads(receipt)
        changed_source["sourceTree"]["files"] = [dict(row) for row in changed_source["sourceTree"]["files"]]
        plugin_row = next(row for row in changed_source["sourceTree"]["files"] if row["path"] == "native/src/Plugin.cpp")
        plugin_row["sha256"] = "0" * 64
        changed_source["sourceTree"]["sha256"] = self._native_source_tree_hash(builder, changed_source["sourceTree"]["files"])
        wrong_dll = json.loads(receipt)
        wrong_dll["outputs"]["plugin"]["sha256"] = "0" * 64
        with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: sources[path]):
            with self.assertRaisesRegex(builder.PackageError, "native build source hash differs"):
                builder._validate_native_build_receipt(builder.BASE.json_bytes(changed_source), commit, "git", builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS, dll)
            with self.assertRaisesRegex(builder.PackageError, "DLL version, byte count, or hash differs"):
                builder._validate_native_build_receipt(builder.BASE.json_bytes(wrong_dll), commit, "git", builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS, dll)

    def test_mo2_assembly_rejects_wrong_or_old_dll(self) -> None:
        builder = self.builder
        baseline = {path: f"baseline:{path}".encode("utf-8") for path in builder.EXPECTED_BASELINE_MEMBERS}
        dll = b"new native dll"
        baseline[builder.DLL_MEMBER] = b"old native dll"
        department = b"new department package"
        pcv = b"new pcv package"
        dll_hash = builder.BASE.sha256(dll)
        files = builder._assemble_mod(baseline, department, pcv, dll, dll_hash)
        builder._assert_mod_delta(baseline, files, dll)
        with self.assertRaisesRegex(builder.PackageError, "offline receipt"):
            builder._assemble_mod(baseline, department, pcv, dll, "0" * 64)

    def test_checksums_reject_tampered_asset_digest(self) -> None:
        builder = self.builder
        outputs = {"candidate.zip": b"candidate bytes"}
        correct = builder.BASE.make_checksums(outputs)
        builder.BASE.audit_checksums_file(correct, outputs)
        with self.assertRaises(builder.PackageError):
            builder.BASE.audit_checksums_file(correct.replace(b"candidate.zip", b"other.zip"), outputs)

    def test_offline_proof_bundles_summary_public_native_receipt_and_provenance(self) -> None:
        builder = self.builder
        commit = "d" * 40
        dll = b"new native DLL"
        native_receipt = b"native receipt"
        receipt_hashes = {name: "1" * 64 for name in builder.OFFLINE_CHECK_NAMES}
        source_hashes = {path: "2" * 64 for path in builder.SOURCE_HASH_PATHS}
        checks = {name: True for name in builder.OFFLINE_CHECK_NAMES}
        offline = {
            "schemaVersion": 1,
            "kind": "department-offline-repair-checks",
            "sourceCommit": commit,
            "checks": checks,
            "sourceFiles": source_hashes,
            "native": {
                "sha256": builder.BASE.sha256(dll),
                "version": builder.NATIVE_VERSION,
                "buildReceiptSha256": builder.BASE.sha256(native_receipt),
            },
            "receiptHashes": receipt_hashes,
            "limits": builder.PROOF_LIMITS,
        }
        offline_bytes = builder.BASE.json_bytes(offline)
        proof = builder._proof_zip(commit, b"mod", b"source", b"department", b"pcv", dll, native_receipt, offline_bytes, offline)
        members = builder.BASE.read_zip_bytes(proof, "offline proof fixture")
        self.assertEqual(set(members), {
            builder.INPUT_OFFLINE_CHECKS,
            "native-build-receipt.json",
            "package-provenance.json",
        })
        self.assertEqual(members["native-build-receipt.json"], native_receipt)
        self.assertNotIn("normal-chim-http.json", members)
        provenance = json.loads(members["package-provenance.json"].decode("utf-8"))
        self.assertEqual(provenance["offlineChecks"]["checks"], checks)
        self.assertEqual(provenance["limits"], builder.PROOF_LIMITS)

    @staticmethod
    def _owner_adapter(builder, version: str, pins: dict[str, str]) -> bytes:
        entries = "\n".join(f"        '{path}' => '{digest}'," for path, digest in pins.items())
        return f"    '{version}' => [\n{entries}\n    ],\n".encode("utf-8")

    @classmethod
    def _department_fixture(cls, builder):
        owner_paths = (
            "manifest.json", "lib/runtime.php", "lib/store.php", "lib/boundaries.php", "lib/bootstrap.php",
            "lib/autonomy_policy.php", "lib/autonomy_store.php", "lib/autonomy_host.php",
            "lib/offscreen_policy.php", "lib/offscreen.php", "lib/offscreen_host.php",
            "lib/companion.php", "lib/companion_policy.php", "lib/companion_host.php",
            "migrations/001_initial.sql", "migrations/002_autonomy.sql", "migrations/003_offscreen.sql",
            "migrations/004_restore_state.sql", "migrations/005_companion.sql",
        )
        old_adapter_pins = {}
        for index, path in enumerate(owner_paths):
            old_adapter_pins[path] = "a" * 64 if path == "manifest.json" else f"{index + 1:064x}"
        new_adapter_pins = dict(old_adapter_pins)
        new_adapter_pins["manifest.json"] = builder.OWNER_MANIFEST_SHA256
        old_adapter = cls._owner_adapter(builder, "0.1.0-dev.27", old_adapter_pins)
        new_adapter = old_adapter + cls._owner_adapter(builder, "0.1.0-dev.28", new_adapter_pins)

        old_package = {
            path: f"old:{path}".encode("utf-8")
            for path in builder.P23.DEPARTMENT_EXPECTED_MEMBERS
        }
        old_package["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": "questionable_decisions",
            "version": builder.BASELINE_VERSION,
            "description": "fixture department",
            "server": {"mutable_paths": []},
        })
        old_package["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "questionable_decisions",
            "version": builder.BASELINE_VERSION,
            "description": "fixture department",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL["questionable_decisions"],
        })
        old_package["server/globals.php"] = b"baseline globals"
        old_package[builder.DEPARTMENT_ADAPTER] = old_adapter

        source = {
            "server/" + path.removeprefix("server/"): old_package["server/" + path.removeprefix("server/")]
            for path in builder.SERVER_SOURCE_PATHS
        }
        source[builder.DEPARTMENT_ADAPTER] = new_adapter
        source["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "questionable_decisions",
            "version": builder.VERSION,
            "description": "fixture department",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL["questionable_decisions"],
        })
        source["server/lib/overlay_setup.php"] = b"new overlay setup"
        source["server/globals.php"] = old_package["server/globals.php"]
        manifest_bytes, staged = cls._overlay_fixture(builder)
        for staged_name, content in staged.items():
            old_stage_name = staged_name.removesuffix(".template") if staged_name.endswith(".template") else staged_name
            old_package[builder.STAGE_ROOT + old_stage_name] = content
        source[builder.OWNER_SOURCE_MANIFEST] = manifest_bytes
        for owner_name, data in staged.items():
            source[f"{builder.OWNER_STAGE_DIRECTORY}/{owner_name.removesuffix('.template') if owner_name.endswith('.template') else owner_name}"] = data
        return old_package, source

    @staticmethod
    def _pcv_fixture(builder):
        old_package = {
            path: f"old:{path}".encode("utf-8")
            for path in builder.PCV_EXPECTED_MEMBERS
        }
        old_package["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": "pcv_companion",
            "version": builder.BASELINE_PCV_VERSION,
            "description": "fixture PCV companion",
            "server": {"mutable_paths": []},
        })
        old_package["server/manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 2,
            "name": "pcv_companion",
            "version": builder.BASELINE_PCV_VERSION,
            "description": "fixture PCV companion",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL["pcv_companion"],
        })
        source = {
            "pcv-companion/manifest.json": builder.BASE.json_bytes({
                "schema_version": 2,
                "name": "pcv_companion",
                "version": builder.PCV_VERSION,
                "description": "fixture PCV companion",
                "config_url": builder.BASE.PACKAGE_CONFIG_URL["pcv_companion"],
            }),
            "pcv-companion/companion.php": b"new Solo owner projection",
            "pcv-companion/globals.php": old_package["server/globals.php"],
            "pcv-companion/preprocessing.php": old_package["server/preprocessing.php"],
        }
        return old_package, source

    @staticmethod
    def _overlay_fixture(builder):
        content = {name: f"owner postimage:{name}".encode("utf-8") for name in builder.OWNER_FILE_NAMES}
        entries = []
        staged = {}
        for name in builder.OWNER_FILE_NAMES:
            staged_name = builder.OVERLAY_STAGED_NAME_BY_OWNER.get(name, name)
            postimage = content[name]
            base = postimage if name not in {"preprocessing.php", "scope.php", "state.php"} else b"upstream base:" + name.encode()
            entries.append({
                "path": name,
                "stagedPath": staged_name,
                "baseSha256": builder.BASE.sha256(base),
                "postimageSha256": builder.BASE.sha256(postimage),
                "modified": base != postimage,
            })
            staged[staged_name] = postimage
        manifest = builder.BASE.json_bytes({
            "schemaVersion": 1,
            "upstream": {"version": builder.PCV_OWNER_VERSION, "tag": builder.PCV_OWNER_TAG, "commit": builder.PCV_OWNER_COMMIT},
            "stagedSourceDirectory": builder.OWNER_STAGE_DIRECTORY,
            "installDirectory": "ext/private_conversation",
            "files": entries,
        })
        return manifest, staged

    @classmethod
    def _offline_receipt(cls, builder, commit, dll, build_receipt, sources):
        return {
            "schemaVersion": 1,
            "kind": "department-offline-repair-checks",
            "sourceCommit": commit,
            "checks": {name: True for name in builder.OFFLINE_CHECK_NAMES},
            "sourceFiles": {path: builder.BASE.sha256(sources[path]) for path in builder.SOURCE_HASH_PATHS},
            "native": {
                "sha256": builder.BASE.sha256(dll),
                "version": builder.NATIVE_VERSION,
                "buildReceiptSha256": builder.BASE.sha256(build_receipt),
            },
            "receiptHashes": {name: "3" * 64 for name in builder.OFFLINE_CHECK_NAMES},
            "limits": dict(builder.PROOF_LIMITS),
        }

    @staticmethod
    def _source_fixture(builder):
        sources = {
            path: f"committed:{path}".encode("utf-8")
            for path in builder.SOURCE_HASH_PATHS | builder.NATIVE_BUILD_SOURCE_PATHS
        }
        sources["native/deps.lock.json"] = builder.BASE.json_bytes({"schemaVersion": 1, "fixture": "locked"})
        return sources

    @staticmethod
    def _native_source_tree_hash(builder, files):
        hashes = {row["path"]: row["sha256"].lower() for row in files}
        canonical = "".join(f"{path}|{hashes[path]}\n" for path in sorted(hashes)).encode("utf-8")
        return builder.BASE.sha256(canonical)

    @classmethod
    def _native_build_receipt(cls, builder, sources, dll):
        files = [
            {"path": path, "sha256": builder.BASE.sha256(sources[path]).upper()}
            for path in sorted(builder.NATIVE_BUILD_SOURCE_PATHS)
        ]
        source_tree_hash = cls._native_source_tree_hash(builder, files)
        return builder.BASE.json_bytes({
            "schemaVersion": 1,
            "status": "native-offline-plugin-and-focused-test-built",
            "sourceTree": {
                "algorithm": "SHA-256",
                "canonicalManifest": "UTF-8 relative-path|lowercase-file-SHA256 lines sorted by relative path; LF with final LF",
                "sha256": source_tree_hash,
                "fileCount": len(files),
                "files": files,
            },
            "outputs": {
                "plugin": {
                    "version": builder.NATIVE_VERSION,
                    "bytes": len(dll),
                    "sha256": builder.BASE.sha256(dll).upper(),
                },
            },
            "regression": {"green": {"result": "passed", "passed": 1, "failed": 0}},
            "toolchain": {"cmake": "fixture"},
            "dependencyLock": {
                "path": "native/deps.lock.json",
                "sha256": builder.BASE.sha256(sources["native/deps.lock.json"]).upper(),
                "contents": json.loads(sources["native/deps.lock.json"]),
            },
        })


if __name__ == "__main__":
    unittest.main()
