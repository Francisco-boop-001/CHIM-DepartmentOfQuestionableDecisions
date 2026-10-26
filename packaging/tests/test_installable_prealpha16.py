from __future__ import annotations

import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "build-installable-prealpha16.py"
SPEC = importlib.util.spec_from_file_location("department_prealpha16", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load prealpha.16 package builder")
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


def fixture_object() -> dict:
    rows = []
    expected_flags = ((False, False), (True, False), (True, True), (True, True))
    for name, (authenticated, brainwash) in zip(BUILDER.CASE_NAMES, expected_flags, strict=True):
        response = {
            "ok": True,
            "data": {
                "authenticated": authenticated,
                "access": {"brainwash": brainwash},
            },
        }
        rows.append({
            "name": name,
            "requestBody": json.dumps({"operation": "bootstrap"}),
            "responseStatus": 200,
            "responseBody": json.dumps(response),
            "authenticated": authenticated,
            "brainwash": brainwash,
        })
    return {
        "schemaVersion": 1,
        "sourceFiles": list(BUILDER.SERVER_SOURCE_PATHS),
        "stateCleanup": "removed",
        "cases": rows,
    }


class FixtureSchemaTests(unittest.TestCase):
    def test_accepts_exact_four_case_ids_and_serialized_api_responses(self) -> None:
        fixture = BUILDER._validate_fixture(json.dumps(fixture_object()).encode("utf-8"))
        self.assertEqual([row["name"] for row in fixture["cases"]], list(BUILDER.CASE_NAMES))

    def test_rejects_extra_or_misordered_cases_and_source_paths(self) -> None:
        valid = fixture_object()
        too_many = copy.deepcopy(valid)
        too_many["cases"].append(copy.deepcopy(too_many["cases"][-1]))
        wrong_order = copy.deepcopy(valid)
        wrong_order["cases"][0], wrong_order["cases"][1] = wrong_order["cases"][1], wrong_order["cases"][0]
        wrong_source = copy.deepcopy(valid)
        wrong_source["sourceFiles"].pop()

        for item in (too_many, wrong_order, wrong_source):
            with self.subTest(item=item):
                with self.assertRaises(BUILDER.PackageError):
                    BUILDER._validate_fixture(json.dumps(item).encode("utf-8"))

    def test_rejects_non_boolean_expectations_and_mismatched_response(self) -> None:
        wrong_type = fixture_object()
        wrong_type["cases"][0]["authenticated"] = 0
        mismatch = fixture_object()
        mismatch["cases"][1]["responseBody"] = json.dumps({
            "ok": True,
            "data": {"authenticated": False, "access": {"brainwash": False}},
        })
        for item in (wrong_type, mismatch):
            with self.subTest(item=item):
                with self.assertRaises(BUILDER.PackageError):
                    BUILDER._validate_fixture(json.dumps(item).encode("utf-8"))


class ReceiptValidationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.dll = b"synthetic in-memory test DLL bytes"
        self.fixture = json.dumps(fixture_object()).encode("utf-8")

    def test_native_build_receipt_requires_six_ctest_targets_and_four_case_ids(self) -> None:
        receipt = {
            "schemaVersion": 1,
            "status": "native-plugin-and-host-tests-built",
            "toolchain": {"generator": "unit-test-only"},
            "inputs": {"phpVersion": "PHP 8.3.35 (cli)"},
            "plugin": {
                "name": "WorldOfDramaLlama.dll",
                "bytes": len(self.dll),
                "sha256": BUILDER.BASE.sha256(self.dll),
                "linkOptionsConfigured": True,
            },
            "hostTests": {"result": "100% tests passed, 0 tests failed out of 6", "count": 6},
            "serverBootstrapFixtures": {
                "sha256": BUILDER.BASE.sha256(self.fixture),
                "caseNames": list(BUILDER.CASE_NAMES),
                "sourceFiles": list(BUILDER.SERVER_SOURCE_PATHS),
                "stateCleanup": "removed",
            },
            "buildAccess": {
                "receiptOmitsLocalPaths": True,
                "installedGameFilesRead": False,
                "dllPathScanIsSeparateEvidence": True,
            },
        }
        BUILDER._validate_native_build_receipt(json.dumps(receipt).encode("utf-8"), self.fixture, self.dll)

        wrong_count = copy.deepcopy(receipt)
        wrong_count["hostTests"] = {"result": "100% tests passed, 0 tests failed out of 5", "count": 5}
        wrong_cases = copy.deepcopy(receipt)
        wrong_cases["serverBootstrapFixtures"]["caseNames"] = ["4 cases passed"]
        live_access = copy.deepcopy(receipt)
        live_access["buildAccess"]["installedGameFilesRead"] = True
        for item in (wrong_count, wrong_cases, live_access):
            with self.subTest(item=item):
                with self.assertRaises(BUILDER.PackageError):
                    BUILDER._validate_native_build_receipt(json.dumps(item).encode("utf-8"), self.fixture, self.dll)

    def test_php_generator_receipt_binds_the_actual_generator_and_all_included_sources(self) -> None:
        commit = "b" * 40
        source_bytes = {path: f"source:{path}".encode("utf-8") for path in BUILDER.SERVER_SOURCE_PATHS}
        receipt = {
            "schemaVersion": 1,
            "phpVersion": "8.3.35",
            "generatorPath": BUILDER.GENERATOR_PATH,
            "generatorSha256": BUILDER.BASE.sha256(source_bytes[BUILDER.GENERATOR_PATH]),
            "fixturePath": BUILDER.FIXTURE_PATH,
            "fixtureSha256": BUILDER.BASE.sha256(self.fixture),
            "sourceFiles": [
                {"path": path, "sha256": BUILDER.BASE.sha256(source_bytes[path])}
                for path in BUILDER.SERVER_SOURCE_PATHS
            ],
            "cases": list(BUILDER.CASE_NAMES),
            "stateCleanup": "removed",
            "negativeOutputPath": {
                "exitCode": 1, "reason": "output_path_invalid",
                "outputCreated": False, "stateRootCreated": False,
            },
        }

        def read_source(path, _label, _limit=64 * 1024 * 1024):
            relative = Path(path).relative_to(BUILDER.ROOT).as_posix()
            return source_bytes[relative]

        with patch.object(
                BUILDER.BASE, "git_blob", side_effect=lambda _git, _commit, path: source_bytes[path]), \
                patch.object(BUILDER, "_regular_file", side_effect=read_source):
            BUILDER._validate_php_generator_receipt(
                json.dumps(receipt).encode("utf-8"), self.fixture,
                BUILDER._validate_fixture(self.fixture), "git", commit,
            )

        bad_source_hash = copy.deepcopy(receipt)
        bad_source_hash["sourceFiles"][0]["sha256"] = "0" * 64
        with patch.object(
                BUILDER.BASE, "git_blob", side_effect=lambda _git, _commit, path: source_bytes[path]), \
                patch.object(BUILDER, "_regular_file", side_effect=read_source):
            with self.assertRaises(BUILDER.PackageError):
                BUILDER._validate_php_generator_receipt(
                    json.dumps(bad_source_hash).encode("utf-8"), self.fixture,
                    BUILDER._validate_fixture(self.fixture), "git", commit,
                )

    def test_final_dll_run_must_bind_exact_ids_and_reject_a_negative_case(self) -> None:
        receipt = {
            "schemaVersion": 1,
            "status": "passed",
            "dllSha256": BUILDER.BASE.sha256(self.dll),
            "fixtureSha256": BUILDER.BASE.sha256(self.fixture),
            "caseNames": list(BUILDER.CASE_NAMES),
            "negativeCases": 1,
            "limits": dict(BUILDER.DLL_LIMITS),
        }
        BUILDER._validate_dll_bootstrap_receipt(json.dumps(receipt).encode("utf-8"), self.dll, self.fixture)
        for field, value in (("negativeCases", 0), ("caseNames", ["four cases passed"])):
            invalid = copy.deepcopy(receipt)
            invalid[field] = value
            with self.subTest(field=field):
                with self.assertRaises(BUILDER.PackageError):
                    BUILDER._validate_dll_bootstrap_receipt(
                        json.dumps(invalid).encode("utf-8"), self.dll, self.fixture,
                    )

    def test_cross_boundary_receipt_binds_hashes_sources_and_exact_case_ids(self) -> None:
        commit = "a" * 40
        php_receipt = b"test-only PHP receipt bytes"
        native_receipt = b"test-only native build receipt bytes"
        dll_receipt = b"test-only final DLL run receipt bytes"
        native_blobs = {path: f"{index + 1:040x}" for index, path in enumerate(sorted(BUILDER.NATIVE_SOURCE_PATHS))}
        server_blobs = {path: f"{index + 101:040x}" for index, path in enumerate(BUILDER.SERVER_SOURCE_PATHS)}
        test_source_bytes = b"test-only native bootstrap test source"
        test_source_sha256 = BUILDER.BASE.sha256(test_source_bytes)
        binding = {
            "schemaVersion": 1,
            "sourceCommit": commit,
            "baselineSha256": BUILDER.BASELINE_SHA256,
            "dllSha256": BUILDER.BASE.sha256(self.dll),
            "nativeBuildReceiptSha256": BUILDER.BASE.sha256(native_receipt),
            "phpGeneratorReceiptSha256": BUILDER.BASE.sha256(php_receipt),
            "fixtureSha256": BUILDER.BASE.sha256(self.fixture),
            "nativeTestTarget": "dql_server_bootstrap_tests",
            "nativeTestResult": "passed",
            "caseNames": list(BUILDER.CASE_NAMES),
            "nativeSourceBlobs": native_blobs,
            "serverSourceBlobs": server_blobs,
            "dllBootstrapReceiptSha256": BUILDER.BASE.sha256(dll_receipt),
            "nativeTestSourceSha256": test_source_sha256,
        }
        with patch.object(BUILDER, "_expected_blob_map", side_effect=[native_blobs, server_blobs]), \
                patch.object(BUILDER.BASE, "git_blob", return_value=test_source_bytes):
            BUILDER._validate_cross_boundary_binding(
                json.dumps(binding).encode("utf-8"), "git", commit, self.dll, self.fixture,
                php_receipt, native_receipt, dll_receipt,
            )

        wrong_case_ids = copy.deepcopy(binding)
        wrong_case_ids["caseNames"] = ["four cases passed"]
        wrong_dll = copy.deepcopy(binding)
        wrong_dll["dllSha256"] = "0" * 64
        wrong_test_source = copy.deepcopy(binding)
        wrong_test_source["nativeTestSourceSha256"] = "f" * 64
        for item in (wrong_case_ids, wrong_dll, wrong_test_source):
            with self.subTest(item=item):
                with patch.object(BUILDER, "_expected_blob_map", side_effect=[native_blobs, server_blobs]), \
                        patch.object(BUILDER.BASE, "git_blob", return_value=test_source_bytes):
                    with self.assertRaises(BUILDER.PackageError):
                        BUILDER._validate_cross_boundary_binding(
                            json.dumps(item).encode("utf-8"), "git", commit, self.dll, self.fixture,
                            php_receipt, native_receipt, dll_receipt,
                        )


class PackageBoundaryTests(unittest.TestCase):
    def test_only_dll_and_app_js_can_change_in_the_22_member_archive(self) -> None:
        baseline = {path: f"baseline:{path}".encode("utf-8") for path in BUILDER.BASELINE_MO2_MEMBERS}
        result = BUILDER._assemble_mod(baseline, b"new DLL", b"new app.js")
        changed = {path for path in result if result[path] != baseline[path]}
        self.assertEqual(changed, {BUILDER.DLL_MEMBER, BUILDER.APP_JS_MEMBER})
        self.assertEqual(len(result), 22)
        self.assertTrue(all(result[path] == baseline[path] for path in BUILDER.SERVER_PACKAGE_MEMBERS))

    def test_rejects_an_unpinned_baseline_archive(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "baseline.zip"
            path.write_bytes(b"not the pinned prealpha.15 archive")
            with self.assertRaisesRegex(BUILDER.PackageError, "exact verified prealpha.15"):
                BUILDER._read_baseline(path)


if __name__ == "__main__":
    unittest.main()
