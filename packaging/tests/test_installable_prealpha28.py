from __future__ import annotations

import contextlib
import importlib.util
import hashlib
import io
import json
import os
import struct
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha28.py"


def _load_builder():
    if not BUILDER_PATH.is_file():
        return None
    spec = importlib.util.spec_from_file_location("department_installable_prealpha28", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("prealpha.28 release builder could not be loaded")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _synthetic_plugin_pe(machine: int = 34404, packed_version: int = 65824) -> bytes:
    data = bytearray(0x900)
    data[:2] = b"MZ"
    struct.pack_into("<I", data, 0x3C, 0x80)
    data[0x80:0x84] = b"PE\0\0"
    struct.pack_into("<HHIIIHH", data, 0x84, machine, 1, 0, 0, 0, 0xF0, 0x2022)
    optional = 0x98
    struct.pack_into("<H", data, optional, 0x20B)
    struct.pack_into("<I", data, optional + 108, 16)
    struct.pack_into("<II", data, optional + 112, 0x300, 0x100)
    section = optional + 0xF0
    struct.pack_into("<8sIIIIIIHHI", data, section, b".rdata", 0x700, 0x200, 0x700, 0x200, 0, 0, 0, 0, 0x40000040)

    struct.pack_into("<IIHHIIIIIII", data, 0x300, 0, 0, 0, 0, 0x410, 1, 3, 3, 0x380, 0x390, 0x3C0)
    for index, rva in enumerate((0x600, 0x700, 0x800)):
        struct.pack_into("<I", data, 0x380 + index * 4, rva)
    for index, rva in enumerate((0x420, 0x432, 0x446)):
        struct.pack_into("<I", data, 0x390 + index * 4, rva)
        struct.pack_into("<H", data, 0x3C0 + index * 2, index)
    for offset, name in zip((0x420, 0x432, 0x446), (
        b"SKSEPlugin_Load\0",
        b"SKSEPlugin_Version\0",
        b"DQL_ValidateBootstrapResponse\0",
    )):
        data[offset:offset + len(name)] = name
    struct.pack_into("<II", data, 0x700, 1, packed_version)
    return bytes(data)


def _source_files(builder):
    paths = set(builder.NATIVE_CLOSURE_PATHS) | set(builder.BOOTSTRAP_FIXTURE_SOURCE_PATHS)
    paths.add(builder.NATIVE_BUILD_RECIPE)
    return {path: (ROOT / path).read_bytes() for path in paths}


def _source_binding(builder, source_files, receipt_bytes=b"synthetic receipt", dll_bytes=b"synthetic DLL"):
    return {
        "schemaVersion": 1,
        "kind": "department-native-source-binding",
        "sourceCommit": "a" * 40,
        "nativeVersion": "0.1.18.0",
        "nativeClosure": {
            path: hashlib.sha256(source_files[path]).hexdigest()
            for path in builder.NATIVE_CLOSURE_PATHS
        },
        "recipeSha256": hashlib.sha256(source_files[builder.NATIVE_BUILD_RECIPE]).hexdigest(),
        "bootstrapFixtureSourceHashes": {
            path: hashlib.sha256(source_files[path]).hexdigest()
            for path in builder.BOOTSTRAP_FIXTURE_SOURCE_PATHS
        },
        "recipeReceiptSha256": hashlib.sha256(receipt_bytes).hexdigest(),
        "dllSha256": hashlib.sha256(dll_bytes).hexdigest(),
    }


def _allowlist_bytes(paths):
    lines = [
        '{\n',
        '  "schemaVersion": 1,\n',
        '  "kind": "department-public-source-allowlist",\n',
        '  "files": [\n',
    ]
    for index, path in enumerate(paths):
        comma = "," if index < len(paths) - 1 else ""
        lines.append(f'    {{ "path": "{path}" }}{comma}\n')
    lines.extend(['  ]\n', '}\n'])
    return "".join(lines).encode("utf-8")


def _synthetic_native_build_receipt(builder, dll_bytes):
    lock = json.loads((ROOT / "native" / "deps.lock.json").read_bytes())
    toolchain = lock["toolchain"]
    inputs = lock
    return {
        "schemaVersion": 1,
        "status": "native-plugin-and-host-tests-built",
        "toolchain": {
            "cmakeVersion": f"cmake version {toolchain['cmakeVersion']}-msvc11",
            "ctestVersion": f"ctest version {toolchain['cmakeVersion']}-msvc11",
            "generator": toolchain["generator"],
            "platform": toolchain["platform"],
            "configuration": toolchain["configuration"],
            "msvcCompilerVersion": toolchain["msvcCompilerVersion"],
            "msvcToolsVersion": toolchain["msvcToolsVersion"],
            "msvcPlatformToolset": toolchain["msvcPlatformToolset"],
            "windowsSdkVersion": toolchain["windowsSdkVersion"],
        },
        "inputs": {
            "phpVersion": "PHP 8.3.35 (cli) (synthetic test fixture)",
            "commonLib": {
                "version": inputs["commonLib"]["version"],
                "commit": inputs["commonLib"]["commit"],
                "archiveSha256": inputs["commonLib"]["sha256"].upper(),
            },
            "fmt": {"version": inputs["fmt"]["version"], "archiveSha256": inputs["fmt"]["sha256"].upper()},
            "spdlog": {"version": inputs["spdlog"]["version"], "archiveSha256": inputs["spdlog"]["sha256"].upper()},
            "rapidcsv": {"version": inputs["rapidcsv"]["version"], "archiveSha256": inputs["rapidcsv"]["sha256"].upper()},
            "nlohmannJson": {"version": inputs["nlohmannJson"]["version"], "sha256": inputs["nlohmannJson"]["sha256"].upper()},
            "prismaUi": {
                "repository": inputs["prismaUi"]["repository"],
                "commit": inputs["prismaUi"]["commit"],
                "sha256": inputs["prismaUi"]["sha256"].upper(),
            },
        },
        "serverBootstrapFixtures": {
            "sha256": "a" * 64,
            "caseNames": [
                "unauthenticated_bootstrap",
                "authenticated_all_readiness_disabled",
                "authenticated_full_access",
                "authenticated_brainwash_only",
            ],
            "sourceFiles": sorted(builder.BOOTSTRAP_FIXTURE_SOURCE_PATHS),
            "stateCleanup": "removed",
        },
        "hostTests": {"result": "100% tests passed, 0 tests failed out of 6", "count": 6},
        "plugin": {
            "name": "WorldOfDramaLlama.dll",
            "bytes": len(dll_bytes),
            "sha256": hashlib.sha256(dll_bytes).hexdigest().upper(),
            "linkOptionsConfigured": True,
        },
        "buildAccess": {
            "receiptOmitsLocalPaths": True,
            "installedGameFilesRead": False,
            "dllPathScanIsSeparateEvidence": True,
        },
    }


def _synthetic_acceptance_receipt(builder, source_files):
    adapter = source_files["server/adapters/correspondence.php"]
    pins = builder.P26.P25._literal_owner_closure_pins(adapter, builder.P26.SF_OWNER_VERSION)
    return {
        "schemaVersion": 1,
        "kind": "department-letter-recovery-acceptance",
        "sourceCommit": "a" * 40,
        "sourceHashes": {
            path: hashlib.sha256(source_files[path]).hexdigest()
            for path in builder.ACCEPTANCE_SOURCE_PATHS
        },
        "ownerVersion": builder.P26.SF_OWNER_VERSION,
        "ownerCommit": builder.P26.SF_OWNER_COMMIT,
        "ownerClosurePins": pins,
        "checks": {
            "native": {"status": "passed", "cases": 1},
            "ui": {"status": "passed", "cases": 1},
            "postgres": {"status": "passed", "cases": 1, "assertions": 1},
        },
        "ownerReview": "accepted",
        "openScopedFindings": 0,
        "limits": {
            "live": False,
            "installedGame": False,
            "skyrim": False,
            "provider": False,
            "physicalDelivery": False,
            "mouseFix": False,
        },
        "evidenceHashes": {"synthetic-test-receipt.json": "b" * 64},
    }


class Prealpha28ReleaseBuilderTests(unittest.TestCase):
    def test_native_pe_parser_reads_the_x64_skse_version_export(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")

        result = builder.parse_native_pe(_synthetic_plugin_pe())

        self.assertEqual(result["machine"], 34404)
        self.assertEqual(result["sksePluginVersionPacked"], 65824)
        self.assertEqual(
            result["exports"],
            {"SKSEPlugin_Load", "SKSEPlugin_Version", "DQL_ValidateBootstrapResponse"},
        )

    def test_native_pe_parser_rejects_a_non_x64_binary(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")

        with self.assertRaisesRegex(builder.PackageError, "x64"):
            builder.parse_native_pe(_synthetic_plugin_pe(machine=0x14C))

    def test_native_source_binding_requires_exact_selected_commit_and_27_hashes(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "validate_native_source_binding"), "native source binding guard is missing")
        source_files = _source_files(builder)
        binding = _source_binding(builder, source_files)

        result = builder.validate_native_source_binding(binding, "a" * 40, source_files, b"synthetic receipt", b"synthetic DLL")

        self.assertEqual(result["nativeClosureCount"], 27)
        binding["nativeClosure"]["native/src/Plugin.cpp"] = "0" * 64
        with self.assertRaisesRegex(builder.PackageError, "source hash differs"):
            builder.validate_native_source_binding(binding, "a" * 40, source_files, b"synthetic receipt", b"synthetic DLL")

    def test_installable_assembly_changes_only_department_dll_and_two_ui_payloads(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "assemble_installable_files"), "installable delta assembler is missing")
        baseline = {path: f"baseline:{path}".encode() for path in builder.EXPECTED_BASELINE_MEMBERS}

        release = builder.assemble_installable_files(
            baseline,
            b"department prealpha.28",
            b"native 0.1.18.0",
            b"new app.js",
            b"new index.html",
        )

        self.assertEqual(set(release), (set(baseline) - {builder.BASELINE_DPKG_MEMBER}) | {builder.DPKG_MEMBER})
        common_changes = {path for path in set(release) & set(baseline) if release[path] != baseline[path]}
        self.assertEqual(common_changes, {builder.DLL_MEMBER, builder.UI_APP_MEMBER, builder.UI_INDEX_MEMBER})
        for path in set(release) & set(baseline) - common_changes:
            self.assertEqual(release[path], baseline[path])

    def test_source_allowlist_transition_appends_only_the_declared_release_paths(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "validate_allowlist_transition"), "source allowlist transition guard is missing")
        baseline = _allowlist_bytes(["README.md"])
        release = _allowlist_bytes(["README.md", *builder.RELEASE_ALLOWLIST_ADDITIONS])

        result = builder.validate_allowlist_transition(
            release,
            hashlib.sha256(baseline).hexdigest(),
            baseline,
        )

        self.assertEqual(result["appendedPaths"], list(builder.RELEASE_ALLOWLIST_ADDITIONS))
        tampered = _allowlist_bytes(["README.md", "docs/unreviewed.md", *builder.RELEASE_ALLOWLIST_ADDITIONS])
        with self.assertRaisesRegex(builder.PackageError, "old prefix"):
            builder.validate_allowlist_transition(tampered, hashlib.sha256(baseline).hexdigest(), baseline)

    def test_committed_allowlist_has_only_the_declared_release_suffix(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        document = json.loads((ROOT / builder.ALLOWLIST_PATH).read_bytes())
        paths = [entry["path"] for entry in document["files"]]

        self.assertEqual(paths[-len(builder.RELEASE_ALLOWLIST_ADDITIONS):], list(builder.RELEASE_ALLOWLIST_ADDITIONS))
        self.assertEqual(len(paths), len(set(paths)))

    def test_native_build_receipt_is_bound_to_locked_recipe_and_dll(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "validate_native_build_receipt"), "native build receipt guard is missing")
        dll = _synthetic_plugin_pe()
        receipt = _synthetic_native_build_receipt(builder, dll)

        result = builder.validate_native_build_receipt(receipt, dll, _source_files(builder))

        self.assertEqual(result["hostTests"]["count"], 6)
        receipt["plugin"]["bytes"] += 1
        with self.assertRaisesRegex(builder.PackageError, "DLL bytes"):
            builder.validate_native_build_receipt(receipt, dll, _source_files(builder))

    def test_native_build_receipt_rejects_malformed_fixture_paths_cleanly(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        dll = _synthetic_plugin_pe()
        receipt = _synthetic_native_build_receipt(builder, dll)
        receipt["serverBootstrapFixtures"]["sourceFiles"] = [{}]

        with self.assertRaisesRegex(builder.PackageError, "bootstrap fixture closure"):
            builder.validate_native_build_receipt(receipt, dll, _source_files(builder))

    def test_root_pe_receipt_binds_the_selected_commit_and_parsed_exports(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "validate_native_pe_receipt"), "root PE receipt guard is missing")
        dll = _synthetic_plugin_pe()
        receipt = {
            "schemaVersion": 1,
            "kind": "department-native-pe-verification",
            "sourceCommit": "a" * 40,
            "dllSha256": hashlib.sha256(dll).hexdigest(),
            "dllBytes": len(dll),
            "machine": 34404,
            "sksePluginVersion": [0, 1, 18, 0],
            "sksePluginVersionPacked": 65824,
            "requiredExports": ["SKSEPlugin_Load", "SKSEPlugin_Version", "DQL_ValidateBootstrapResponse"],
            "passed": True,
        }

        result = builder.validate_native_pe_receipt(receipt, "a" * 40, dll, builder.parse_native_pe(dll))

        self.assertEqual(result["sksePluginVersion"], [0, 1, 18, 0])
        receipt["sourceCommit"] = "b" * 40
        with self.assertRaisesRegex(builder.PackageError, "source commit"):
            builder.validate_native_pe_receipt(receipt, "a" * 40, dll, builder.parse_native_pe(dll))

    def test_root_acceptance_requires_source_hashes_owner_review_and_all_three_gates(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "validate_acceptance_receipt"), "root acceptance receipt guard is missing")
        paths = set(builder.ACCEPTANCE_SOURCE_PATHS)
        source_files = {path: (ROOT / path).read_bytes() for path in paths}
        receipt = _synthetic_acceptance_receipt(builder, source_files)
        receipt["checks"]["native"]["transcript"] = "raw dialogue that must not be published"

        result = builder.validate_acceptance_receipt(receipt, "a" * 40, source_files)

        self.assertEqual(result["ownerVersion"], builder.P26.SF_OWNER_VERSION)
        self.assertNotIn("transcript", result["checks"]["native"])
        receipt["sourceHashes"]["server/runtime.php"] = "0" * 64
        with self.assertRaisesRegex(builder.PackageError, "source hash differs"):
            builder.validate_acceptance_receipt(receipt, "a" * 40, source_files)

    def test_server_manifest_transition_is_version_only_and_canonical(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        old = {"schemaVersion": 1, "id": "questionable_decisions", "version": builder.BASELINE_VERSION}
        release = dict(old, version=builder.VERSION)
        old_bytes = (json.dumps(old, indent=2) + "\n").encode("utf-8")
        release_bytes = (json.dumps(release, indent=2) + "\n").encode("utf-8")

        summary = builder.validate_manifest_transition(old_bytes, release_bytes)

        self.assertEqual(summary["baselineSha256"], hashlib.sha256(old_bytes).hexdigest())
        tampered = dict(release, capabilities=["extra"])
        tampered_bytes = (json.dumps(tampered, indent=2) + "\n").encode("utf-8")
        with self.assertRaisesRegex(builder.PackageError, "version-only"):
            builder.validate_manifest_transition(old_bytes, tampered_bytes)

    def test_published_prealpha27_and_committed_sources_build_the_expected_department_delta(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        baseline_path = Path(os.environ.get(
            "DEPARTMENT_PREALPHA27_ZIP",
            ROOT.parents[1] / "artifacts" / "department-pcv25-release-20261029" / "public-download"
            / "WorldOfDramaLlama-0.1.0-prealpha.27.zip",
        ))
        if not baseline_path.is_file():
            self.skipTest("set DEPARTMENT_PREALPHA27_ZIP to the public prealpha.27 ZIP")

        baseline_bytes = baseline_path.read_bytes()
        baseline_files, old_department, old_pcv = builder._read_prealpha27_baseline(baseline_bytes)
        self.assertEqual(old_pcv, builder.P20._audit_component(
            baseline_files[builder.PCV_MEMBER], "pcv_companion", builder.PCV_VERSION
        ))
        self.assertEqual(old_department["server/manifest.json"], builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, builder.SERVER_MANIFEST
        ))

        read_committed_source = lambda path: builder.BASE.git_blob("git", "HEAD", path)
        package_bytes, department = builder._build_department_package(
            baseline_files[builder.BASELINE_DPKG_MEMBER],
            read_committed_source,
            old_department["server/manifest.json"],
        )

        self.assertEqual(department["server/manifest.json"], read_committed_source(builder.SERVER_MANIFEST))
        self.assertEqual(builder.PCV._audit_department(
            package_bytes, builder.VERSION, pcv25=True
        ), department)
        self.assertEqual(set(builder.DEPARTMENT_DPKG_CHANGED_MEMBERS), {
            path for path in old_department.keys() | department.keys()
            if old_department.get(path) != department.get(path)
        })

    def test_output_inventory_requires_exact_three_archives_and_checksums(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "_expected_output_files"), "release output inventory guard is missing")

        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            for name in builder.OUTPUT_FILE_NAMES:
                (directory / name).write_bytes(b"fixture")
            self.assertEqual(set(builder._expected_output_files(directory)), builder.OUTPUT_FILE_NAMES)
            (directory / "unexpected.txt").write_bytes(b"extra")
            with self.assertRaisesRegex(builder.PackageError, "only the installable"):
                builder._expected_output_files(directory)

    def test_proof_zip_uses_curated_receipt_summaries_and_is_deterministic(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "build_release_proof_zip"), "sanitized proof archive builder is missing")
        release = {
            "schemaVersion": 1,
            "kind": "synthetic",
            "sourceCommit": "a" * 40,
            "unchangedInstallableMembers": ["one", "two"],
            "limits": {"live": False, "installedGame": False},
            "sourceAllowlistSha256": "f" * 64,
        }
        native = {
            "sourceBinding": {"sourceCommit": "a" * 40},
            "buildReceiptSha256": "d" * 64,
            "build": {"plugin": {"sha256": "b" * 64}},
            "peVerification": {"dllSha256": "e" * 64},
            "privatePath": "C:\\Users\\private\\native-build",
        }
        acceptance = {
            "sourceCommit": "a" * 40,
            "ownerReview": "accepted",
            "evidenceHashes": {"native.json": "c" * 64},
        }

        first = builder.build_release_proof_zip(release, b"runtime", b"source", b"dwpkg", native, acceptance)
        second = builder.build_release_proof_zip(release, b"runtime", b"source", b"dwpkg", native, acceptance)

        self.assertEqual(first, second)
        proof = builder.BASE.read_zip_bytes(first, "synthetic release proof")
        self.assertEqual(set(proof), {
            "offline-proof.json", "native-build.json", "native-pe-verification.json",
            "native-source-binding.json", "root-acceptance.json", "package-provenance.json",
        })
        joined = b"".join(proof.values())
        self.assertNotIn(b"C:\\Users\\private", joined)
        self.assertNotIn(b"raw dialogue", joined)

    def test_release_builder_refuses_anything_but_the_published_baseline(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        self.assertTrue(hasattr(builder, "build_release_assets"), "receipt-gated release assembly is missing")

        with self.assertRaisesRegex(builder.PackageError, "exact published prealpha.27"):
            builder.build_release_assets(
                b"not the published baseline", b"dll", b"build", b"binding", b"pe", b"acceptance",
                "a" * 40, [], {}, b"source", b"manifest", b"allowlist",
            )

    def test_cli_requires_final_native_and_root_receipts(self):
        builder = _load_builder()
        self.assertIsNotNone(builder, "prealpha.28 release builder is missing")
        stderr = io.StringIO()
        with contextlib.redirect_stderr(stderr), self.assertRaises(SystemExit) as raised:
            builder.parse_args([
                "build", "--prealpha27-zip", "baseline.zip", "--source-commit", "a" * 40,
                "--output-dir", "out",
            ])
        self.assertEqual(raised.exception.code, 2)
        self.assertIn("--native-dll", stderr.getvalue())
        self.assertIn("--acceptance-receipt", stderr.getvalue())


if __name__ == "__main__":
    unittest.main()
