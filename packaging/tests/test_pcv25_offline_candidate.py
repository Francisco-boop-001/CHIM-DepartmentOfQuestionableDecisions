from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = REPO_ROOT / "packaging" / "build-pcv25-offline-candidate.py"
BASELINE_PATH = (
    REPO_ROOT.parent.parent
    / "artifacts"
    / "department-sf30-20261028"
    / "public-download"
    / "WorldOfDramaLlama-0.1.0-prealpha.26.zip"
)
BASELINE_SHA256 = "f402cfb4e8db58c956dfae1e771c1f6773960a3cdbe5909a31942890cf3729ff"
EXPECTED_OWNER_BASE_PINS = {
    "installation_guard.php": "082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d",
    "log.php": "5ce07e9b834dba51ea5552d923c3a83f97dd3fa5dce7131db773d16813612a8c",
    "manifest.json": "198a242e4e85cd418aca588fd7583e1643437ffe069c3eaa6070b53d2eff4a3c",
    "preprocessing.php": "5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c",
    "reflection.php": "d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540",
    "scope.php": "75a2d61d9f223bae2d5bef32d1d2bbd88cf15dc4764f8759f8d763c236c1af2f",
    "state.php": "49c2b53466572015e77117653739966377267b5b50ec4514155e53256bca7361",
}
EXPECTED_OWNER_POST_PINS = {
    "installation_guard.php": "082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d",
    "log.php": "5ce07e9b834dba51ea5552d923c3a83f97dd3fa5dce7131db773d16813612a8c",
    "manifest.json": "198a242e4e85cd418aca588fd7583e1643437ffe069c3eaa6070b53d2eff4a3c",
    "preprocessing.php": "01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950",
    "reflection.php": "d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540",
    "scope.php": "6eaa8d09d423a7364dfd26934b56794fa2cb51cacd26950e753f499989181e33",
    "state.php": "b64277236a520b5464a26becc9c7d15dce233fdf2e3f4c99201c568da22e16d6",
}
EXPECTED_ALLOWLIST_ADDITIONS = (
    "docs/PCV25-OFFLINE-CANDIDATE.md",
    "docs/superpowers/plans/2026-10-29-pcv25-offline.md",
    "packaging/build-pcv25-offline-candidate.py",
    "packaging/companion-only/PCV25-PROVENANCE.md",
    "packaging/companion-only/pcv-0.1.25-source-manifest.json",
    "packaging/tests/test_pcv25_offline_candidate.py",
    "pcv-companion/private-conversation-v0.1.25/server/installation_guard.php",
    "pcv-companion/private-conversation-v0.1.25/server/log.php",
    "pcv-companion/private-conversation-v0.1.25/server/manifest.json",
    "pcv-companion/private-conversation-v0.1.25/server/preprocessing.php",
    "pcv-companion/private-conversation-v0.1.25/server/reflection.php",
    "pcv-companion/private-conversation-v0.1.25/server/scope.php",
    "pcv-companion/private-conversation-v0.1.25/server/state.php",
    "server/tests/pcv25/overlay_upgrade.php",
    "server/tests/pcv25/source_contract.php",
    "server/tests/pcv25/owner_loaders.php",
    "server/tests/pcv25/recursive_loader_orders.php",
)


def load_builder():
    if not BUILDER_PATH.is_file():
        return None
    spec = importlib.util.spec_from_file_location("pcv25_offline_candidate", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("candidate builder module could not be loaded")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Pcv25OfflineCandidateTests(unittest.TestCase):
    def _builder_and_candidate(self):
        builder = load_builder()
        self.assertIsNotNone(builder, "PCV25 offline candidate builder is missing")
        baseline = BASELINE_PATH.read_bytes()
        self.assertEqual(builder.BASE.sha256(baseline), BASELINE_SHA256)
        sources = {
            path: (builder.ROOT / path).read_bytes()
            for path in builder.CANDIDATE_SOURCE_PATHS
        }
        candidate, audit = builder.build_candidate_bytes(baseline, sources.__getitem__)
        return builder, baseline, sources, candidate, audit

    def test_pin_hash_maps_are_order_independent_but_reject_duplicate_paths(self):
        builder = load_builder()
        self.assertIsNotNone(builder, "PCV25 offline candidate builder is missing")
        rows = [
            f"    '{name}' => '{EXPECTED_OWNER_POST_PINS[name]}',"
            for name in reversed(builder.OWNER_FILE_NAMES)
        ]
        source = "const TEST_PINS = [\n" + "\n".join(rows) + "\n];\n"
        self.assertEqual(
            builder._php_hash_array(source, "TEST_PINS"),
            EXPECTED_OWNER_POST_PINS,
        )

        duplicate_source = source.replace(
            rows[-1], rows[-1] + "\n" + rows[-1], 1
        )
        with self.assertRaises(builder.PackageError):
            builder._php_hash_array(duplicate_source, "TEST_PINS")

    def test_exact_pcv13_and_department27_candidate_preserves_other_payloads(self):
        builder, baseline, sources, candidate, audit = self._builder_and_candidate()
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.27")
        self.assertEqual(builder.PCV_VERSION, "0.1.0-prealpha.13")
        self.assertEqual(audit["baseline"]["sha256"], BASELINE_SHA256)

        old_mod = builder.BASE.read_zip_bytes(baseline, "published prealpha.26 ZIP")
        new_mod = builder.BASE.read_zip_bytes(candidate, "PCV25 offline candidate ZIP")
        self.assertEqual(
            set(new_mod),
            (set(old_mod) - {builder.BASELINE_DPKG_MEMBER, builder.BASELINE_PCV_MEMBER})
            | {builder.DPKG_MEMBER, builder.PCV_MEMBER},
        )
        common = set(old_mod) & set(new_mod)
        self.assertEqual(len(common), 20)
        self.assertTrue(all(old_mod[path] == new_mod[path] for path in common))

        old_department = builder.P24._audit_department_component(
            old_mod[builder.BASELINE_DPKG_MEMBER], builder.BASELINE_VERSION
        )
        department = builder.audit_department_candidate(new_mod[builder.DPKG_MEMBER])
        old_pcv = builder.P20._audit_component(
            old_mod[builder.BASELINE_PCV_MEMBER], "pcv_companion", builder.BASELINE_PCV_VERSION
        )
        pcv = builder.P20._audit_component(
            new_mod[builder.PCV_MEMBER], "pcv_companion", builder.PCV_VERSION
        )

        self.assertEqual(
            {path for path in old_department.keys() | department.keys()
             if old_department.get(path) != department.get(path)},
            builder.DEPARTMENT_CHANGED_MEMBERS,
        )
        self.assertEqual(
            {path for path in old_pcv.keys() | pcv.keys() if old_pcv.get(path) != pcv.get(path)},
            builder.PCV_CHANGED_MEMBERS,
        )
        self.assertNotIn(builder.ACTIVE_OVERLAY_PREPROCESSING_MEMBER, department)
        self.assertEqual(set(department), set(old_department))
        self.assertEqual(
            {path for path in department if path.startswith(builder.PACKAGED_STAGE_ROOT)},
            builder.PACKAGED_STAGE_MEMBERS,
        )
        self.assertNotIn(builder.ACTIVE_OVERLAY_PREPROCESSING_MEMBER, department)
        self.assertFalse(any(
            path.startswith("pcv-companion/private-conversation-")
            for path in department
        ))
        self.assertEqual(
            sources["server/manifest.json"],
            old_department["server/manifest.json"],
        )
        self.assertEqual(
            json.loads(department["server/manifest.json"])["version"],
            "0.1.0-prealpha.27",
        )
        self.assertEqual(
            json.loads(department["manifest.json"])["version"],
            "0.1.0-prealpha.27",
        )
        self.assertEqual(
            json.loads(pcv["manifest.json"])["version"],
            "0.1.0-prealpha.13",
        )

    def test_owner_source_manifest_is_bound_to_frozen_pcv25_base_and_postimages(self):
        builder, _baseline, sources, _candidate, _audit = self._builder_and_candidate()
        manifest = json.loads(sources[builder.OWNER_SOURCE_MANIFEST])
        self.assertEqual(builder.OWNER_POSTIMAGE_PINS, EXPECTED_OWNER_POST_PINS)
        self.assertEqual(manifest["upstream"], {
            "version": "0.1.25",
            "tag": "private_conversation-v0.1.25",
            "commit": "886a54967db7c6c08725c8bec8854ea3919ea532",
        })
        entries = manifest["files"]
        self.assertEqual([row["path"] for row in entries], list(builder.OWNER_FILE_NAMES))
        self.assertEqual(
            {row["path"]: row["baseSha256"] for row in entries},
            EXPECTED_OWNER_BASE_PINS,
        )
        self.assertEqual(
            {row["path"]: row["postimageSha256"] for row in entries},
            EXPECTED_OWNER_POST_PINS,
        )
        self.assertEqual(
            {row["path"]: row["modified"] for row in entries},
            {name: EXPECTED_OWNER_BASE_PINS[name] != builder.OWNER_POSTIMAGE_PINS[name]
             for name in builder.OWNER_FILE_NAMES},
        )

    def test_candidate_rejects_owner_stage_tampering_and_old_or_mixed_pins(self):
        builder, baseline, sources, _candidate, _audit = self._builder_and_candidate()

        stage_path = f"{builder.OWNER_STAGE_DIRECTORY}/scope.php"
        tampered_stage = dict(sources)
        tampered_stage[stage_path] += b"\n/* tampered */\n"
        with self.assertRaises(builder.PackageError):
            builder.build_candidate_bytes(baseline, tampered_stage.__getitem__)

        tampered_manifest = dict(sources)
        manifest = json.loads(tampered_manifest[builder.OWNER_SOURCE_MANIFEST])
        manifest["upstream"]["version"] = "0.1.24"
        tampered_manifest[builder.OWNER_SOURCE_MANIFEST] = builder.BASE.json_bytes(manifest)
        with self.assertRaises(builder.PackageError):
            builder.build_candidate_bytes(baseline, tampered_manifest.__getitem__)

        for path, marker in (
            ("server/adapters/pcv.php", b"QD_PCV_EXPECTED_VERSION"),
            ("pcv-companion/companion.php", b"PCV_COMPANION_OWNER_VERSION"),
            ("server/lib/overlay_setup.php", b"QD_PCV_OVERLAY_VERSION"),
        ):
            with self.subTest(path=path):
                altered = dict(sources)
                self.assertIn(marker, altered[path])
                altered[path] = altered[path].replace(marker, marker + b"_TAMPERED", 1)
                with self.assertRaises(builder.PackageError):
                    builder.build_candidate_bytes(baseline, altered.__getitem__)

        with self.assertRaises(builder.PackageError):
            builder.build_candidate_bytes(baseline + b"\n", sources.__getitem__)

    def test_public_allowlist_preserves_the_baseline_and_appends_only_candidate_paths(self):
        builder = load_builder()
        self.assertIsNotNone(builder, "PCV25 offline candidate builder is missing")
        baseline_bytes = builder.BASE.git_blob("git", builder.BASELINE_SOURCE_COMMIT, "packaging/public-source-allowlist.json")
        baseline = json.loads(baseline_bytes)
        candidate = json.loads(
            (builder.ROOT / "packaging" / "public-source-allowlist.json").read_text(encoding="utf-8")
        )
        self.assertEqual(
            {key: value for key, value in candidate.items() if key != "files"},
            {key: value for key, value in baseline.items() if key != "files"},
        )
        baseline_rows = baseline["files"]
        candidate_rows = candidate["files"]
        self.assertEqual(candidate_rows[:len(baseline_rows)], baseline_rows)
        self.assertEqual(
            candidate_rows[len(baseline_rows):],
            [{"path": path} for path in EXPECTED_ALLOWLIST_ADDITIONS],
        )
        paths = [entry["path"] for entry in candidate_rows]
        self.assertEqual(len(paths), len(set(paths)))
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS.issubset(set(paths)))
        self.assertTrue(builder.CANDIDATE_SOURCE_PATHS.issubset(set(paths)))


if __name__ == "__main__":
    unittest.main()
