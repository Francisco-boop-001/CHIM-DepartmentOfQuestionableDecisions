from __future__ import annotations

import importlib.util
import json
import os
import struct
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha31.py"


def _load_builder():
    spec = importlib.util.spec_from_file_location("department_installable_prealpha31", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("prealpha.31 release builder could not be loaded")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _required_path(variable: str, description: str) -> Path:
    value = os.environ.get(variable)
    if not value:
        raise AssertionError(f"set {variable} to the actual {description}; release input coverage is required")
    path = Path(value)
    if path.is_symlink() or not path.is_file():
        raise AssertionError(f"{variable} must name the actual {description}")
    return path


def _baseline_path() -> Path:
    return _required_path("DEPARTMENT_PREALPHA30_ZIP", "published prealpha.30 installable ZIP")


def _native_inputs() -> tuple[bytes, bytes, bytes, bytes]:
    return (
        _required_path("DEPARTMENT_PREALPHA31_NATIVE_DLL", "native 0.1.19.0 DLL").read_bytes(),
        _required_path("DEPARTMENT_PREALPHA31_NATIVE_BUILD_RECEIPT", "native build/test receipt").read_bytes(),
        _required_path("DEPARTMENT_PREALPHA31_NATIVE_SOURCE_BINDING", "native source binding receipt").read_bytes(),
        _required_path("DEPARTMENT_PREALPHA31_NATIVE_PE_RECEIPT", "native PE verification receipt").read_bytes(),
    )


def _source_snapshot(builder) -> tuple[str, dict[str, bytes]]:
    source_commit = builder.PREVIOUS._git("rev-parse", "HEAD").decode("ascii").strip()
    allowlist = builder.PREVIOUS._git_blob(source_commit, builder.ALLOWLIST_PATH)
    paths = builder.PREVIOUS._allowlist_paths(allowlist, "committed source allowlist")
    source_files = {path: builder.PREVIOUS._git_blob(source_commit, path) for path in paths}
    return source_commit, source_files


class Prealpha31ReleaseBuilderTests(unittest.TestCase):
    def test_actual_published_baseline_hash_and_member_set(self):
        builder = _load_builder()
        baseline = _baseline_path().read_bytes()
        files, _department, _pcv = builder._read_baseline(baseline)
        self.assertEqual(builder.BASE.sha256(baseline), builder.BASELINE_SHA256)
        self.assertEqual(set(files), set(builder.EXPECTED_BASELINE_MEMBERS))
        self.assertEqual(len(files), 22)
        with self.assertRaisesRegex(builder.PackageError, "not 0.1.19.0"):
            builder.parse_native_pe(files[builder.DLL_MEMBER])

    def test_actual_native_build_and_exact_installable_delta(self):
        builder = _load_builder()
        baseline = _baseline_path().read_bytes()
        dll, build_receipt, binding, pe_receipt = _native_inputs()
        source_commit, source_files = _source_snapshot(builder)
        old_manifest = builder.PREVIOUS._git_blob(builder.BASELINE_SOURCE_COMMIT, builder.SERVER_MANIFEST)
        baseline_allowlist = builder.PREVIOUS._git_blob(builder.BASELINE_SOURCE_COMMIT, builder.ALLOWLIST_PATH)
        builder._validate_source_inputs(source_commit, source_files, baseline_allowlist)
        builder._validate_source_diff(source_commit)

        release_zip, audit = builder.assemble_installable(
            baseline, source_files, old_manifest, dll, build_receipt, binding, pe_receipt, source_commit
        )
        parsed_pe = builder.parse_native_pe(dll)
        wrong_schema_dll = bytearray(dll)
        wrong_schema_dll[parsed_pe["versionDataOffset"]:parsed_pe["versionDataOffset"] + 4] = struct.pack("<I", 2)
        with self.assertRaisesRegex(builder.PackageError, "data schema is not version 1"):
            builder.parse_native_pe(bytes(wrong_schema_dll))
        release = builder.BASE.read_zip_bytes(release_zip, "prealpha.31 installable ZIP")
        baseline_files, _department, old_pcv = builder._read_baseline(baseline)
        self.assertEqual(set(release), set(builder.EXPECTED_INSTALLABLE_MEMBERS))
        self.assertEqual(len(release), 22)
        self.assertEqual(
            {path for path in set(release) & set(baseline_files) if release[path] != baseline_files[path]},
            {builder.UI_APP_MEMBER, builder.DLL_MEMBER},
        )
        self.assertEqual(len(audit["unchangedInstallableMembers"]), 19)
        self.assertEqual(
            audit["departmentDwpkgChangedMembers"],
            ["checksums.sha256", "manifest.json", builder.SERVER_MANIFEST],
        )
        self.assertEqual(release[builder.PCV_MEMBER], baseline_files[builder.PCV_MEMBER])
        self.assertEqual(
            builder.P20._audit_component(release[builder.PCV_MEMBER], "pcv_companion", builder.PCV_VERSION),
            old_pcv,
        )
        self.assertEqual(audit["nativeDll"]["version"], "0.1.19.0")
        self.assertEqual(audit["nativeEvidence"]["peVerification"]["sksePluginVersion"], [0, 1, 19, 0])

    def test_rejects_wrong_dll_and_stale_build_receipt(self):
        builder = _load_builder()
        baseline = _baseline_path().read_bytes()
        baseline_files, _department, _pcv = builder._read_baseline(baseline)
        dll, build_receipt, binding, pe_receipt = _native_inputs()
        source_commit, source_files = _source_snapshot(builder)

        with self.assertRaisesRegex(builder.PackageError, "source binding dllSha256"):
            builder.validate_native_evidence(
                baseline_files[builder.DLL_MEMBER], build_receipt, binding, pe_receipt,
                source_commit, source_files,
            )

        receipt = json.loads(build_receipt.decode("utf-8"))
        receipt["plugin"]["sha256"] = "0" * 64
        with self.assertRaisesRegex(builder.PackageError, "recipeReceiptSha256"):
            builder.validate_native_evidence(
                dll, builder.BASE.json_bytes(receipt), binding, pe_receipt,
                source_commit, source_files,
            )

        pe = json.loads(pe_receipt.decode("utf-8"))
        pe["sksePluginVersion"] = [0, 1, 18, 0]
        with self.assertRaisesRegex(builder.PackageError, "does not confirm SKSE version 0.1.19.0"):
            builder.validate_native_evidence(
                dll, build_receipt, binding, builder.BASE.json_bytes(pe),
                source_commit, source_files,
            )

    def test_refuses_unknown_source_paths(self):
        builder = _load_builder()
        with self.assertRaisesRegex(builder.PackageError, "outside the prealpha.31 release scope"):
            builder.validate_source_diff_paths(["native/tests/UnreviewedTest.cpp"])
        builder.validate_source_diff_paths([
            "README.md", "docs/BUILD.md", "docs/REPAIR31.md", "server/manifest.json",
            "native/src/Plugin.cpp", "native/tests/NativeBoundaryTests.cpp",
            builder.UI_APP_SOURCE, builder.UI_TEST_SOURCE,
            "packaging/build-installable-prealpha31.py",
            "packaging/tests/test_installable_prealpha31.py", "tasks/todo.md",
        ])


if __name__ == "__main__":
    unittest.main()
