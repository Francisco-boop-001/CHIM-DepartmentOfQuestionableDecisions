from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path


BUILDER_PATH = Path(__file__).resolve().parents[1] / "build-installable-prealpha8.py"
SPEC = importlib.util.spec_from_file_location("installable_prealpha8", BUILDER_PATH)
assert SPEC is not None and SPEC.loader is not None
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


def inner_manifest(name: str) -> bytes:
    return BUILDER.json_bytes({
        "name": name,
        "version": BUILDER.VERSION,
        "schema_version": 2,
        "config_url": BUILDER.PACKAGE_CONFIG_URL[name],
    })


def component_fixture(name: str) -> bytes:
    files = {"manifest.json": inner_manifest(name), "probe.php": b"<?php // package fixture\n"}
    if name == "questionable_decisions":
        files["setup/pcv-overlay/source-manifest.json"] = b"{}\n"
        for relative in BUILDER.PCV_FILE_POSTIMAGES:
            files[f"setup/pcv-overlay/{relative}"] = b"synthetic overlay fixture\n"
    return BUILDER.build_component_bytes(name, files)


class InstallablePrealpha8Tests(unittest.TestCase):
    def test_dwpkg_has_schema4_and_exact_checksum_coverage(self) -> None:
        package = component_fixture("questionable_decisions")
        files = BUILDER.audit_component_bytes(package, "questionable_decisions")
        outer = json.loads(files["manifest.json"])
        self.assertEqual(outer["schema_version"], 4)
        self.assertEqual(outer["server"]["mutable_paths"], [])
        self.assertEqual(
            set(BUILDER.parse_checksums(files["checksums.sha256"], "fixture")),
            set(files) - {"checksums.sha256"},
        )

    def test_dwpkg_refuses_mutable_paths(self) -> None:
        files = {
            "manifest.json": BUILDER.json_bytes({
                "schema_version": 4,
                "name": "pcv_companion",
                "version": BUILDER.VERSION,
                "server": {"mutable_paths": ["data"]},
            }),
            "server/manifest.json": inner_manifest("pcv_companion"),
        }
        files["checksums.sha256"] = BUILDER.make_checksums(files)
        with self.assertRaises(BUILDER.PackageError):
            BUILDER.audit_component_bytes(BUILDER.stable_zip_bytes(files), "pcv_companion")

    def test_dwpkg_refuses_missing_config_url(self) -> None:
        invalid = BUILDER.json_bytes({
            "name": "pcv_companion",
            "version": BUILDER.VERSION,
            "schema_version": 2,
        })
        with self.assertRaises(BUILDER.PackageError):
            BUILDER.validate_inner_manifest("pcv_companion", invalid)

    def test_valid_checksum_does_not_authorize_changed_php(self) -> None:
        expected = component_fixture("pcv_companion")
        changed_files = BUILDER.read_zip_bytes(expected, "fixture")
        changed_files["server/probe.php"] = b"<?php // changed but rechecksummed\n"
        changed_files["checksums.sha256"] = BUILDER.make_checksums(
            {name: data for name, data in changed_files.items() if name != "checksums.sha256"}
        )
        changed = BUILDER.stable_zip_bytes(changed_files)
        BUILDER.audit_component_bytes(changed, "pcv_companion")
        with self.assertRaises(BUILDER.PackageError):
            BUILDER.require_canonical_bytes("PCV PHP payload", changed, expected)

    def test_release_checksums_cover_only_the_two_public_zip_assets(self) -> None:
        assets = {
            BUILDER.OUTPUT_MOD_ZIP: b"synthetic mod ZIP",
            BUILDER.OUTPUT_SOURCE_ZIP: b"synthetic source ZIP",
        }
        manifest = BUILDER.make_checksums(assets)
        BUILDER.audit_checksums_file(manifest, assets)
        with self.assertRaises(BUILDER.PackageError):
            BUILDER.audit_checksums_file(
                manifest,
                {**assets, f"{BUILDER.COMPONENT_DIR}/audit-copy.dwpkg": b"component"},
            )

    def test_mo2_archive_is_mod_root_and_has_both_plugins(self) -> None:
        files = {name: b"fixture asset\n" for name in BUILDER.MO2_FIXED_MEMBERS}
        files[BUILDER.INI_MEMBER] = b"[Server]\nBaseUrl=\nPairToken=\n\n[Input]\nOpenKeyCode=-1\n"
        for component, member in BUILDER.MO2_COMPONENT_PATHS.items():
            files[member] = component_fixture(component)
        archive = BUILDER.build_mod_zip(files)
        audited = BUILDER.audit_mo2_bytes(archive)
        self.assertEqual(set(audited), BUILDER.MO2_FIXED_MEMBERS)
        self.assertIn("CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.8.dwpkg", audited)
        self.assertIn("CHIM/server-plugins/pcv_companion/0.1.0-prealpha.8.dwpkg", audited)
        self.assertFalse(any(name.startswith(("Data/", "Skyrim/")) for name in audited))

    def test_mo2_archive_rejects_nonblank_pairing_fields(self) -> None:
        files = {name: b"fixture asset\n" for name in BUILDER.MO2_FIXED_MEMBERS}
        files[BUILDER.INI_MEMBER] = b"[Server]\nBaseUrl=http://example\nPairToken=qd1_secret\n\n[Input]\nOpenKeyCode=-1\n"
        for component, member in BUILDER.MO2_COMPONENT_PATHS.items():
            files[member] = component_fixture(component)
        with self.assertRaises(BUILDER.PackageError):
            BUILDER.audit_mo2_bytes(BUILDER.stable_zip_bytes(files))

    def test_overlay_stage_sources_are_exactly_pinned(self) -> None:
        def read_source(relative: str) -> bytes:
            return (BUILDER.ROOT / Path(*relative.split("/"))).read_bytes()

        manifest, files = BUILDER.validate_overlay_stage(read_source)
        parsed = json.loads(manifest)
        self.assertEqual(parsed["upstream"], BUILDER.PCV_UPSTREAM)
        self.assertEqual(set(files), set(BUILDER.PCV_FILE_POSTIMAGES))


if __name__ == "__main__":
    unittest.main()
