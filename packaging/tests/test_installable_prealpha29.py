from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha29.py"


def _load_builder():
    spec = importlib.util.spec_from_file_location("department_installable_prealpha29", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("prealpha.29 release builder could not be loaded")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _baseline_path() -> Path:
    value = os.environ.get("DEPARTMENT_PREALPHA28_ZIP")
    if not value:
        raise AssertionError("set DEPARTMENT_PREALPHA28_ZIP to the published .28 ZIP; baseline coverage is required")
    path = Path(value)
    if path.is_symlink() or not path.is_file():
        raise AssertionError("DEPARTMENT_PREALPHA28_ZIP must name the actual published .28 ZIP")
    return path


class Prealpha29ReleaseBuilderTests(unittest.TestCase):
    def test_actual_published_baseline_hash_and_member_set(self):
        builder = _load_builder()
        baseline_path = _baseline_path()
        baseline_bytes = baseline_path.read_bytes()
        files, _department, _pcv = builder._read_baseline(baseline_bytes)
        self.assertEqual(builder.BASE.sha256(baseline_bytes), builder.BASELINE_SHA256)
        self.assertEqual(set(files), set(builder.EXPECTED_BASELINE_MEMBERS))
        self.assertEqual(len(files), 22)

    def test_actual_published_baseline_and_exact_payload_delta(self):
        builder = _load_builder()
        baseline_bytes = _baseline_path().read_bytes()
        source_files = {
            builder.SERVER_MANIFEST: (ROOT / builder.SERVER_MANIFEST).read_bytes(),
            builder.UI_APP_SOURCE: (ROOT / builder.UI_APP_SOURCE).read_bytes(),
        }

        release_zip, audit = builder.assemble_installable(
            baseline_bytes,
            source_files,
            builder._git_blob(builder.BASELINE_SOURCE_COMMIT, builder.SERVER_MANIFEST),
        )

        baseline, _department, pcv = builder._read_baseline(baseline_bytes)
        release = builder.BASE.read_zip_bytes(release_zip, "prealpha.29 installable ZIP")
        self.assertEqual(set(release), set(builder.EXPECTED_INSTALLABLE_MEMBERS))
        self.assertEqual(len(release), 22)
        self.assertEqual(
            {path for path in set(release) & set(baseline) if release[path] != baseline[path]},
            {builder.UI_APP_MEMBER},
        )
        self.assertEqual(release[builder.PCV_MEMBER], baseline[builder.PCV_MEMBER])
        self.assertEqual(release["SKSE/Plugins/WorldOfDramaLlama.dll"], baseline["SKSE/Plugins/WorldOfDramaLlama.dll"])
        self.assertEqual(
            audit["departmentDwpkgChangedMembers"],
            ["checksums.sha256", "manifest.json", builder.SERVER_MANIFEST],
        )
        self.assertEqual(len(audit["unchangedInstallableMembers"]), 20)
        self.assertEqual(
            builder.P20._audit_component(release[builder.PCV_MEMBER], "pcv_companion", builder.PCV_VERSION),
            pcv,
        )

    def test_rejects_a_non_published_baseline(self):
        builder = _load_builder()
        baseline_bytes = _baseline_path().read_bytes()
        source_files = {
            builder.SERVER_MANIFEST: (ROOT / builder.SERVER_MANIFEST).read_bytes(),
            builder.UI_APP_SOURCE: (ROOT / builder.UI_APP_SOURCE).read_bytes(),
        }
        with self.assertRaisesRegex(builder.PackageError, "exact published prealpha.28"):
            builder.assemble_installable(baseline_bytes + b"x", source_files, b"pinned manifest")

    def test_refuses_runtime_owner_native_and_unapproved_ui_deltas(self):
        builder = _load_builder()
        for path in ("server/adapters/pcv.php", "native/src/Plugin.cpp", "pcv-companion/companion.php", "ui/app.css"):
            with self.subTest(path=path), self.assertRaisesRegex(builder.PackageError, "outside the prealpha.29 release scope"):
                builder.validate_source_diff_paths([path])
        builder.validate_source_diff_paths([
            "README.md",
            "docs/BUILD.md",
            "tasks/todo.md",
            "server/manifest.json",
            "packaging/public-source-allowlist.json",
            *builder.RELEASE_ALLOWLIST_ADDITIONS,
            builder.UI_APP_SOURCE,
            builder.UI_TEST_SOURCE,
        ])

    def test_clean_frozen_commit_build_and_audit_commands(self):
        builder = _load_builder()
        baseline_path = _baseline_path()
        source_commit = builder._git("rev-parse", "HEAD").decode("ascii").strip()
        with tempfile.TemporaryDirectory(prefix="department-prealpha29-") as temporary:
            output = Path(temporary) / "candidate"
            command = [
                sys.executable,
                "-B",
                str(BUILDER_PATH),
                "build",
                "--baseline-zip",
                str(baseline_path),
                "--source-commit",
                source_commit,
                "--output-dir",
                str(output),
            ]
            built = subprocess.run(command, cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(built.returncode, 0, built.stdout + built.stderr)

            audit = subprocess.run(command[:command.index("build")] + [
                "audit",
                *command[command.index("--baseline-zip"):],
            ], cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(audit.returncode, 0, audit.stdout + audit.stderr)
            files = {path.name for path in output.iterdir()}
            self.assertEqual(files, builder.OUTPUT_FILE_NAMES)
            checksums = (output / builder.CHECKSUMS_NAME).read_bytes()
            self.assertTrue(checksums.endswith(b"\n"))
            self.assertNotIn(b"\r", checksums)


if __name__ == "__main__":
    unittest.main()
