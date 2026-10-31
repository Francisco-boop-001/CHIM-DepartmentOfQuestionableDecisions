from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha30.py"


def _load_builder():
    spec = importlib.util.spec_from_file_location("department_installable_prealpha30", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("prealpha.30 release builder could not be loaded")
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
    return _required_path("DEPARTMENT_PREALPHA29_ZIP", "published .29 installable ZIP")


def _owner_dwpkg_path() -> Path:
    return _required_path("SEALED_FATES_DEV31_DWPKG", "public Sealed Fates dev.31 DWPkg")


def _source_files(builder):
    return {
        path: (ROOT / path).read_bytes()
        for path in (builder.SERVER_MANIFEST, builder.ADAPTER_SOURCE, builder.UI_APP_SOURCE)
    }


class Prealpha30ReleaseBuilderTests(unittest.TestCase):
    def test_actual_published_baseline_hash_and_member_set(self):
        builder = _load_builder()
        baseline = _baseline_path().read_bytes()
        files, _department, _pcv = builder._read_baseline(baseline)
        self.assertEqual(builder.BASE.sha256(baseline), builder.BASELINE_SHA256)
        self.assertEqual(set(files), set(builder.EXPECTED_BASELINE_MEMBERS))
        self.assertEqual(len(files), 22)

    def test_actual_baseline_owner_snapshot_and_exact_payload_delta(self):
        builder = _load_builder()
        baseline = _baseline_path().read_bytes()
        owner_dwpkg = _owner_dwpkg_path().read_bytes()
        baseline_files, old_department, old_pcv = builder._read_baseline(baseline)
        owner_files = builder._audit_public_owner_dwpkg(owner_dwpkg)
        source = _source_files(builder)
        old_manifest = builder._git_blob(builder.BASELINE_SOURCE_COMMIT, builder.SERVER_MANIFEST)

        transition = builder.validate_owner_map_transition(
            old_department[builder.ADAPTER_SOURCE],
            source[builder.ADAPTER_SOURCE],
            owner_files,
        )
        self.assertEqual(transition["preservedVersionCount"], 16)
        self.assertEqual(transition["addedVersion"], "0.1.0-dev.31")
        self.assertEqual(transition["addedPinCount"], 21)

        release_zip, audit = builder.assemble_installable(
            baseline, source, old_manifest, owner_dwpkg
        )
        release = builder.BASE.read_zip_bytes(release_zip, "prealpha.30 installable ZIP")
        self.assertEqual(set(release), set(builder.EXPECTED_INSTALLABLE_MEMBERS))
        self.assertEqual(len(release), 22)
        self.assertEqual(
            {path for path in set(release) & set(baseline_files) if release[path] != baseline_files[path]},
            {builder.UI_APP_MEMBER},
        )
        self.assertEqual(release[builder.PCV_MEMBER], baseline_files[builder.PCV_MEMBER])
        self.assertEqual(release[builder.DLL_MEMBER], baseline_files[builder.DLL_MEMBER])
        self.assertEqual(len(audit["unchangedInstallableMembers"]), 20)
        self.assertEqual(
            audit["departmentDwpkgChangedMembers"],
            ["checksums.sha256", "manifest.json", builder.ADAPTER_SOURCE, builder.SERVER_MANIFEST],
        )
        self.assertEqual(
            builder.PREVIOUS.P20._audit_component(release[builder.PCV_MEMBER], "pcv_companion", builder.PCV_VERSION),
            old_pcv,
        )

    def test_rejects_a_non_published_baseline(self):
        builder = _load_builder()
        with self.assertRaisesRegex(builder.PackageError, "exact published prealpha.29"):
            builder._read_baseline(_baseline_path().read_bytes() + b"x")

    def test_rejects_a_non_public_owner_dwpkg(self):
        builder = _load_builder()
        with self.assertRaisesRegex(builder.PackageError, "exact verified public"):
            builder._audit_public_owner_dwpkg(_owner_dwpkg_path().read_bytes() + b"x")

    def test_rejects_unknown_adapter_source_changes(self):
        builder = _load_builder()
        baseline = _baseline_path().read_bytes()
        old_adapter = builder._read_baseline(baseline)[1][builder.ADAPTER_SOURCE]
        current_adapter = (ROOT / builder.ADAPTER_SOURCE).read_bytes()
        with self.assertRaisesRegex(builder.PackageError, "outside the dev.31 pin and capability gates"):
            builder.validate_adapter_delta(old_adapter, current_adapter + b"\n// out-of-scope\n")

    def test_refuses_unknown_source_paths(self):
        builder = _load_builder()
        with self.assertRaisesRegex(builder.PackageError, "outside the prealpha.30 release scope"):
            builder.validate_source_diff_paths(["server/runtime.php"])
        builder.validate_source_diff_paths([
            "README.md",
            "docs/BUILD.md",
            "docs/DEV31-COMPATIBILITY.md",
            "server/manifest.json",
            builder.ADAPTER_SOURCE,
            builder.UI_APP_SOURCE,
            builder.UI_TEST_SOURCE,
            "server/tests/dev31-pg/generation_change_driver.php",
            "tasks/todo.md",
        ])

    def test_clean_frozen_commit_build_and_audit_commands(self):
        builder = _load_builder()
        baseline = _baseline_path()
        owner_dwpkg = _owner_dwpkg_path()
        source_commit = builder._git("rev-parse", "HEAD").decode("ascii").strip()
        with tempfile.TemporaryDirectory(prefix="department-prealpha30-") as temporary:
            output = Path(temporary) / "candidate"
            command = [
                sys.executable, "-B", str(BUILDER_PATH), "build",
                "--baseline-zip", str(baseline),
                "--owner-dwpkg", str(owner_dwpkg),
                "--source-commit", source_commit,
                "--output-dir", str(output),
            ]
            built = subprocess.run(command, cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(built.returncode, 0, built.stdout + built.stderr)

            audit_command = command.copy()
            audit_command[audit_command.index("build")] = "audit"
            audited = subprocess.run(audit_command, cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(audited.returncode, 0, audited.stdout + audited.stderr)
            files = {path.name for path in output.iterdir()}
            self.assertEqual(files, builder.OUTPUT_FILE_NAMES)
            checksums = (output / builder.CHECKSUMS_NAME).read_bytes()
            self.assertTrue(checksums.endswith(b"\n"))
            self.assertNotIn(b"\r", checksums)
            builder.BASE.audit_checksums_file(
                checksums,
                {name: (output / name).read_bytes() for name in builder.OUTPUT_NAMES},
            )


if __name__ == "__main__":
    unittest.main()
