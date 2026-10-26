from __future__ import annotations

import copy
import importlib.util
import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "build-installable-prealpha17.py"
SPEC = importlib.util.spec_from_file_location("department_prealpha17", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load prealpha.17 package builder")
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class CompatibilityReceiptTests(unittest.TestCase):
    def setUp(self) -> None:
        self.commit = "a" * 40
        self.adapter = (BUILDER.ROOT / BUILDER.DEPARTMENT_ADAPTER).read_bytes()
        self.protocol = (BUILDER.ROOT / BUILDER.DEPARTMENT_PROTOCOL).read_bytes()
        self.pins = BUILDER._literal_owner_closure_pins(self.adapter)
        self.blobs = {
            BUILDER.DEPARTMENT_ADAPTER: self.adapter,
            BUILDER.DEPARTMENT_PROTOCOL: self.protocol,
        }
        self.receipt = {
            "schemaVersion": 1,
            "sourceCommit": self.commit,
            "ownerVersion": BUILDER.OWNER_VERSION,
            "ownerCommit": BUILDER.OWNER_COMMIT,
            "ownerClosurePins": dict(self.pins),
            "adapterSha256": BUILDER.BASE.sha256(self.adapter),
            "protocolSha256": BUILDER.BASE.sha256(self.protocol),
            "checks": [
                {"name": name, "status": "passed", "receiptSha256": "3" * 64}
                for name in sorted(BUILDER.CHECK_NAMES)
            ],
            "limits": dict(BUILDER.PROOF_LIMITS),
        }

    def _validate(self, receipt: dict | None = None) -> None:
        data = json.dumps(receipt if receipt is not None else self.receipt).encode("utf-8")
        with patch.object(
            BUILDER.BASE,
            "git_blob",
            side_effect=lambda _git, _commit, path: self.blobs[path],
        ):
            BUILDER.P15._validate_compatibility_receipt("git", self.commit, data)

    def test_dev25_map_retains_exact_dev24_execution_pins(self) -> None:
        dev24 = BUILDER._DEV24_LITERAL_OWNER_CLOSURE_PINS(self.adapter)
        self.assertEqual(len(self.pins), 19)
        self.assertEqual(
            {path: digest for path, digest in self.pins.items() if path != "manifest.json"},
            {path: digest for path, digest in dev24.items() if path != "manifest.json"},
        )
        self.assertNotEqual(self.pins["manifest.json"], dev24["manifest.json"])

    def test_accepts_source_bound_receipt_with_exact_four_checks(self) -> None:
        self._validate()

    def test_rejects_wrong_source_owner_pins_checks_or_scope(self) -> None:
        wrong_source = copy.deepcopy(self.receipt)
        wrong_source["sourceCommit"] = "b" * 40
        wrong_owner = copy.deepcopy(self.receipt)
        wrong_owner["ownerCommit"] = "b" * 40
        wrong_execution_pin = copy.deepcopy(self.receipt)
        wrong_execution_pin["ownerClosurePins"]["lib/runtime.php"] = "4" * 64
        missing_check = copy.deepcopy(self.receipt)
        missing_check["checks"].pop()
        broad_scope = copy.deepcopy(self.receipt)
        broad_scope["limits"]["http"] = True

        for receipt in (wrong_source, wrong_owner, wrong_execution_pin, missing_check, broad_scope):
            with self.subTest(receipt=receipt):
                with self.assertRaises(BUILDER.PackageError):
                    self._validate(receipt)

    def test_rejects_a_dev25_pin_map_that_changes_a_dev24_execution_pin(self) -> None:
        adapter_text = self.adapter.decode("utf-8")
        start = adapter_text.index("    '0.1.0-dev.25' => [")
        end = adapter_text.index("    ],", start) + len("    ],")
        dev25_map = adapter_text[start:end]
        changed_map = dev25_map.replace(
            self.pins["lib/runtime.php"], "0" * 64, 1
        )
        changed = (adapter_text[:start] + changed_map + adapter_text[end:]).encode("utf-8")
        with self.assertRaisesRegex(BUILDER.PackageError, "execution-file pins differ"):
            BUILDER._literal_owner_closure_pins(changed)


class PackageDeltaTests(unittest.TestCase):
    def test_baseline_reader_uses_pinned_prealpha16_members_and_embedded_prealpha15(self) -> None:
        baseline_bytes = b"verified baseline bytes"
        files = {path: b"member" for path in BUILDER.BASELINE_MO2_MEMBERS}
        department = {"manifest.json": b"prealpha.15"}
        with (
            patch.object(BUILDER.P15, "_regular_file", return_value=baseline_bytes),
            patch.object(BUILDER.BASE, "sha256", return_value=BUILDER.BASELINE_SHA256),
            patch.object(BUILDER.BASE, "read_zip_bytes", return_value=files) as read_zip,
            patch.object(
                BUILDER.BASE,
                "audit_mo2_bytes",
                side_effect=AssertionError("prealpha.16 baseline must not use the prealpha.13 MO2 auditor"),
            ),
            patch.object(BUILDER.P15.LEGACY, "_verify_dpkg", return_value=department) as verify_dpkg,
        ):
            result = BUILDER._read_baseline(Path("prealpha16.zip"))

        self.assertEqual(result, (baseline_bytes, files, department))
        read_zip.assert_called_once_with(baseline_bytes, "prealpha.16 baseline ZIP")
        verify_dpkg.assert_called_once_with(files[BUILDER.BASELINE_DPKG_MEMBER], "0.1.0-prealpha.15")

    def test_baseline_paths_are_prealpha16_with_embedded_prealpha15_package(self) -> None:
        self.assertEqual(BUILDER.BASELINE_VERSION, "0.1.0-prealpha.16")
        self.assertEqual(BUILDER.BASELINE_DPKG_VERSION, "0.1.0-prealpha.15")
        self.assertEqual(
            BUILDER.BASELINE_DPKG_MEMBER,
            "CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.15.dwpkg",
        )
        self.assertEqual(
            BUILDER.DPKG_MEMBER,
            "CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.17.dwpkg",
        )
        self.assertIn(BUILDER.BASELINE_DPKG_MEMBER, BUILDER.SERVER_PACKAGE_MEMBERS)
        self.assertNotIn("CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.13.dwpkg", BUILDER.SERVER_PACKAGE_MEMBERS)

    def test_inherited_release_engine_uses_only_prealpha17_output_names(self) -> None:
        self.assertEqual(BUILDER.P15.OUTPUT_MOD_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.17.zip")
        self.assertEqual(BUILDER.P15.OUTPUT_SOURCE_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.17-source.zip")
        self.assertEqual(BUILDER.P15.OUTPUT_PROOF_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.17-compatibility-proof.zip")
        self.assertEqual(
            BUILDER.P15.OUTPUT_NAMES,
            {BUILDER.OUTPUT_MOD_ZIP, BUILDER.OUTPUT_SOURCE_ZIP, BUILDER.OUTPUT_PROOF_ZIP},
        )

    def test_public_source_allowlist_contains_new_candidate_sources(self) -> None:
        allowlist = json.loads(
            (BUILDER.ROOT / "packaging/public-source-allowlist.json").read_text(encoding="utf-8")
        )
        paths = {row["path"] for row in allowlist["files"]}
        self.assertTrue(
            {
                "docs/DEV25-COMPATIBILITY.md",
                "packaging/build-installable-prealpha17.py",
                "packaging/tests/test_installable_prealpha17.py",
            }.issubset(paths)
        )

    def test_only_department_dwpkg_path_changes_in_the_22_member_mod(self) -> None:
        baseline = {path: f"baseline:{path}".encode("utf-8") for path in BUILDER.BASELINE_MO2_MEMBERS}
        output = BUILDER.P15._assemble_mod(baseline, b"prealpha.17 Department DWPkg")

        self.assertEqual(len(output), 22)
        self.assertEqual(set(output) - set(baseline), {BUILDER.DPKG_MEMBER})
        self.assertEqual(set(baseline) - set(output), {BUILDER.BASELINE_DPKG_MEMBER})
        self.assertEqual(
            {
                path for path in set(baseline) & set(output)
                if baseline[path] != output[path]
            },
            set(),
        )

    def test_department_dpkg_delta_is_limited_to_manifests_adapter_and_checksums(self) -> None:
        old_outer = {
            "schema_version": 4,
            "name": "questionable_decisions",
            "version": BUILDER.BASELINE_DPKG_VERSION,
            "server": {"mutable_paths": []},
        }
        old_inner = {
            "name": "questionable_decisions",
            "version": BUILDER.BASELINE_DPKG_VERSION,
            "schema_version": 2,
            "description": "unchanged",
            "status": "development_candidate",
            "config_url": "../ext/questionable_decisions/index.php",
            "server_compatibility_reference": "same",
        }
        old = {
            "manifest.json": BUILDER.BASE.json_bytes(old_outer),
            "server/manifest.json": BUILDER.BASE.json_bytes(old_inner),
            BUILDER.DEPARTMENT_ADAPTER: b"prealpha.15 adapter",
            "checksums.sha256": b"prealpha.15 checksums",
            "server/runtime.php": b"preserved runtime",
        }
        current = dict(old)
        current["manifest.json"] = BUILDER.BASE.json_bytes(dict(old_outer, version=BUILDER.VERSION))
        current["server/manifest.json"] = BUILDER.BASE.json_bytes(dict(old_inner, version=BUILDER.VERSION))
        current[BUILDER.DEPARTMENT_ADAPTER] = b"prealpha.17 adapter"
        current["checksums.sha256"] = b"prealpha.17 checksums"

        BUILDER.P15._compare_dpkg_update(old, current)
        current["server/runtime.php"] = b"unexpected change"
        with self.assertRaisesRegex(BUILDER.PackageError, "must change only"):
            BUILDER.P15._compare_dpkg_update(old, current)

    def test_version_and_provenance_keep_package_and_baseline_versions_aligned(self) -> None:
        source_receipt = {
            "ownerVersion": BUILDER.OWNER_VERSION,
            "ownerCommit": BUILDER.OWNER_COMMIT,
            "ownerClosurePins": self_fake_pins(),
        }
        proof = BUILDER._provenance_zip(
            "c" * 40,
            b"installable",
            b"source",
            b"department package",
            b"compatibility checks",
            source_receipt,
        )
        with zipfile.ZipFile(io.BytesIO(proof)) as archive:
            provenance = json.loads(archive.read("package-provenance.json"))
        self.assertEqual(provenance["version"], BUILDER.VERSION)
        self.assertEqual(provenance["baseline"]["version"], BUILDER.BASELINE_VERSION)
        self.assertEqual(provenance["owner"]["version"], BUILDER.OWNER_VERSION)


def self_fake_pins() -> dict[str, str]:
    return {"manifest.json": "1" * 64, **{f"lib/f{i}.php": f"{i:064x}" for i in range(1, 19)}}


if __name__ == "__main__":
    unittest.main()
