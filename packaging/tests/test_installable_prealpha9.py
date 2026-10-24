from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path
from unittest.mock import patch


BUILDER_PATH = Path(__file__).resolve().parents[1] / "build-installable-prealpha9.py"
SPEC = importlib.util.spec_from_file_location("installable_prealpha9", BUILDER_PATH)
assert SPEC is not None and SPEC.loader is not None
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


def valid_x64_pe() -> bytes:
    image = bytearray(0x80)
    image[:2] = b"MZ"
    image[0x3C:0x40] = (0x40).to_bytes(4, "little")
    image[0x40:0x44] = b"PE\0\0"
    image[0x44:0x46] = (0x8664).to_bytes(2, "little")
    return bytes(image)


class InstallablePrealpha9Tests(unittest.TestCase):
    def test_release_identity_uses_only_prealpha9_component_paths(self) -> None:
        self.assertEqual(BUILDER.VERSION, "0.1.0-prealpha.9")
        self.assertEqual(BUILDER.OUTPUT_MOD_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.9.zip")
        self.assertEqual(BUILDER.OUTPUT_SOURCE_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.9-source.zip")
        self.assertEqual(
            BUILDER.COMPONENT_FILENAMES,
            {
                "questionable_decisions": "questionable_decisions-0.1.0-prealpha.9.dwpkg",
                "pcv_companion": "pcv_companion-0.1.0-prealpha.9.dwpkg",
            },
        )
        self.assertIn("CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.9.dwpkg", BUILDER.MO2_FIXED_MEMBERS)
        self.assertIn("CHIM/server-plugins/pcv_companion/0.1.0-prealpha.9.dwpkg", BUILDER.MO2_FIXED_MEMBERS)
        self.assertEqual(len(BUILDER.MO2_FIXED_MEMBERS), 18)
        self.assertEqual(BUILDER.PUBLIC_CHECKSUM_NAMES, {BUILDER.OUTPUT_MOD_ZIP, BUILDER.OUTPUT_SOURCE_ZIP})
        self.assertFalse(any(name.endswith(".8.dwpkg") for name in BUILDER.MO2_FIXED_MEMBERS))
        self.assertIn("server/preprocessing.php", BUILDER.QD_SERVER_SOURCES)
        self.assertIn("README.md", BUILDER.REQUIRED_SOURCE_PATHS)
        self.assertIn("packaging/COMPANION-ONLY-TEST-PACKAGE.md", BUILDER.REQUIRED_SOURCE_PATHS)
        inner = BUILDER.json_bytes({
            "name": "questionable_decisions",
            "version": BUILDER.VERSION,
            "schema_version": 2,
            "config_url": BUILDER.PACKAGE_CONFIG_URL["questionable_decisions"],
        })
        payload = {"manifest.json": inner, "preprocessing.php": b"<?php\n",
                   "setup/pcv-overlay/source-manifest.json": b"{}\n"}
        payload.update({f"setup/pcv-overlay/{path}": b"overlay\n" for path in BUILDER.PCV_FILE_POSTIMAGES})
        package = BUILDER.build_component_bytes("questionable_decisions", payload)
        files = BUILDER.audit_component_bytes(package, "questionable_decisions")
        outer = json.loads(files["manifest.json"])
        inner_manifest = json.loads(files["server/manifest.json"])
        self.assertEqual(outer["schema_version"], 4)
        self.assertEqual(outer["version"], BUILDER.VERSION)
        self.assertEqual(inner_manifest["version"], BUILDER.VERSION)
        self.assertEqual(inner_manifest["schema_version"], 2)
        self.assertEqual(files["server/preprocessing.php"], b"<?php\n")

    def test_previous_release_dll_hashes_are_rejected(self) -> None:
        with patch.object(BUILDER.BASE_BUILDER, "sha256", return_value=BUILDER.BASE_BUILDER.OLD_DLL_SHA256):
            with self.assertRaisesRegex(BUILDER.PackageError, "prealpha.7"):
                BUILDER.validate_dll(valid_x64_pe())
        with patch.object(BUILDER.BASE_BUILDER, "sha256", return_value=BUILDER.PREALPHA8_DLL_SHA256):
            with self.assertRaisesRegex(BUILDER.PackageError, "prealpha.8"):
                BUILDER.validate_dll(valid_x64_pe())

    def test_allowlist_includes_new_builder_tests_and_server_sources(self) -> None:
        allowlist_path = Path(__file__).resolve().parents[1] / "public-source-allowlist.json"
        document = json.loads(allowlist_path.read_text(encoding="utf-8"))
        paths = [entry["path"] for entry in document["files"]]
        self.assertEqual(len(paths), len(set(paths)))
        self.assertLessEqual(BUILDER.REQUIRED_SOURCE_PATHS, set(paths))
        self.assertIn("packaging/build-installable-prealpha9.py", paths)
        self.assertIn("packaging/tests/test_installable_prealpha9.py", paths)
        self.assertIn("server/preprocessing.php", paths)
        self.assertIn("server/tests/onboarding/core-package-install.php", paths)
        self.assertIn("server/tests/onboarding/bootstrap-overlay.php", paths)
        self.assertIn("server/tests/onboarding/stock-ingress.php", paths)
        self.assertIn("server/tests/onboarding/connect.php", paths)
        self.assertIn("server/tests/onboarding/emit-connect-fixtures.php", paths)
        self.assertNotIn("CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.8.dwpkg", paths)
        guide = (Path(__file__).resolve().parents[1] / "COMPANION-ONLY-TEST-PACKAGE.md").read_text(encoding="utf-8")
        self.assertIn("prealpha.9", guide)
        self.assertIn("starts automatically", guide)
        self.assertIn("no browser approval step", guide)
        self.assertIn("No Linux command, pairing-token copy, or manual INI entry is part of this flow.", guide)
        self.assertIn("Install the complete supported Private Conversation 0.1.23 owner plugin separately", guide)
        self.assertNotRegex(guide, r"(?i)choose \*\*Connect to CHIM\*\*|choose \*\*Check connection\*\*|Set up Private Conversation|Approve the game there")


if __name__ == "__main__":
    unittest.main()
