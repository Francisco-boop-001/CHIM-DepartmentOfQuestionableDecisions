from __future__ import annotations

import hashlib
import importlib.util
import json
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha27.py"
RELEASE_INPUTS = ROOT.parent / "release-inputs"
BASELINE_ZIP = RELEASE_INPUTS / "WorldOfDramaLlama-0.1.0-prealpha.26.zip"
REVIEWED_RELEASE_ZIP = RELEASE_INPUTS / "WorldOfDramaLlama-0.1.0-prealpha.27.zip"


def _load_builder():
    if not BUILDER_PATH.is_file():
        return None
    spec = importlib.util.spec_from_file_location("department_installable_prealpha27", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("prealpha.27 release builder could not be loaded")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha27ReleaseBuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = _load_builder()
        self.assertIsNotNone(self.builder, "prealpha.27 release builder is missing")

    def _receipts(self):
        builder = self.builder
        audit = (RELEASE_INPUTS / "reviewed-candidate-audit.json").read_bytes()
        checks = (RELEASE_INPUTS / "reviewed-offline-checks.json").read_bytes()
        audit_doc, checks_doc = builder.validate_review_inputs(audit, checks)
        return audit, checks, audit_doc, checks_doc

    def test_contract_pins_exact_reviewed_candidate_and_output_set(self):
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.27")
        self.assertEqual(builder.PCV_VERSION, "0.1.0-prealpha.13")
        self.assertEqual(
            builder.ROOT_CANDIDATE_AUDIT_SHA256,
            "5bf0df9681aaf5970b65abe449103b8f7b1e2f287be51e73fe9965abe5f32df9",
        )
        self.assertEqual(
            builder.REVIEWED_OFFLINE_CHECKS_SHA256,
            "49612d44cbfd2549931e4c913274331702e06e49880c6c60b021e6c15ae4e4d9",
        )
        self.assertEqual(
            builder.REVIEWED_RUNTIME_ZIP_SHA256,
            "abb2fe3fb85859b26a26c251929a464e27c36d13179380e6ff491d15af2eb978",
        )
        self.assertEqual(
            builder.RELEASE_ALLOWLIST_ADDITIONS,
            (
                "docs/PCV25-RELEASE.md",
                "docs/superpowers/plans/2026-10-29-pcv25-release.md",
                "packaging/build-installable-prealpha27.py",
                "packaging/tests/test_installable_prealpha27.py",
            ),
        )
        self.assertEqual(
            builder.OUTPUT_NAMES,
            {
                "WorldOfDramaLlama-0.1.0-prealpha.27.zip",
                "WorldOfDramaLlama-0.1.0-prealpha.27-source.zip",
                "WorldOfDramaLlama-0.1.0-prealpha.27-offline-proof.zip",
            },
        )
        self.assertEqual(builder.CHECKSUMS_NAME, "SHA256SUMS.txt")

    def test_review_inputs_are_pinned_and_expose_only_the_accepted_offline_scope(self):
        builder = self.builder
        audit_bytes, checks_bytes, audit, checks = self._receipts()
        self.assertEqual(hashlib.sha256(audit_bytes).hexdigest(), builder.ROOT_CANDIDATE_AUDIT_SHA256)
        self.assertEqual(hashlib.sha256(checks_bytes).hexdigest(), builder.REVIEWED_OFFLINE_CHECKS_SHA256)
        self.assertEqual(audit["candidate"]["zipSha256"], builder.REVIEWED_RUNTIME_ZIP_SHA256)
        self.assertEqual(audit["candidate"]["departmentVersion"], builder.VERSION)
        self.assertEqual(audit["candidate"]["pcvCompanionVersion"], builder.PCV_VERSION)
        self.assertEqual(len(audit["sourceHashes"]), 25)
        self.assertEqual(checks["reviewedAuditSha256"], builder.ROOT_CANDIDATE_AUDIT_SHA256)
        self.assertEqual(checks["reviewedRuntimeZipSha256"], builder.REVIEWED_RUNTIME_ZIP_SHA256)
        self.assertEqual(
            checks["limits"]["authority"],
            "native-Windows-PHP-source-control-flow-and-package-only",
        )
        self.assertFalse(checks["limits"]["database"])
        self.assertFalse(checks["limits"]["http"])
        self.assertFalse(checks["limits"]["provider"])
        self.assertFalse(checks["limits"]["nativeBuild"])
        self.assertFalse(checks["limits"]["skyrim"])
        self.assertFalse(checks["limits"]["installedGame"])
        self.assertFalse(checks["limits"]["server"])

        tampered = checks_bytes + b"\n"
        with self.assertRaisesRegex(builder.PackageError, "offline checks receipt SHA-256"):
            builder.validate_review_inputs(audit_bytes, tampered)

    def test_manifest_and_allowlist_changes_are_exact_and_bound_to_old_receipt(self):
        builder = self.builder
        _audit_bytes, _checks_bytes, audit, _checks = self._receipts()
        old_manifest = builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, "server/manifest.json"
        )
        release_manifest = (ROOT / "server" / "manifest.json").read_bytes()
        self.assertEqual(
            hashlib.sha256(old_manifest).hexdigest(),
            audit["sourceHashes"]["server/manifest.json"],
        )
        builder.validate_manifest_transition(
            old_manifest,
            release_manifest,
            audit["sourceHashes"]["server/manifest.json"],
        )

        old_allowlist = builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, "packaging/public-source-allowlist.json"
        )
        release_allowlist = (ROOT / "packaging" / "public-source-allowlist.json").read_bytes()
        result = builder.validate_allowlist_transition(
            release_allowlist,
            audit["sourceHashes"]["packaging/public-source-allowlist.json"],
            old_allowlist,
        )
        self.assertEqual(result["appendedPaths"], list(builder.RELEASE_ALLOWLIST_ADDITIONS))
        self.assertEqual(
            result["previousPrefixSha256"],
            audit["sourceHashes"]["packaging/public-source-allowlist.json"],
        )

        manifest = json.loads(release_manifest)
        manifest["description"] += " unrelated"
        with self.assertRaisesRegex(builder.PackageError, "version-only"):
            builder.validate_manifest_transition(
                old_manifest,
                builder.BASE.json_bytes(manifest),
                audit["sourceHashes"]["server/manifest.json"],
            )

        doc = json.loads(release_allowlist)
        doc["files"].append({"path": "unreviewed/file.php"})
        with self.assertRaisesRegex(builder.PackageError, "exact release-only suffix"):
            builder.validate_allowlist_transition(
                builder.BASE.json_bytes(doc),
                audit["sourceHashes"]["packaging/public-source-allowlist.json"],
                old_allowlist,
            )

    def test_all_reviewed_source_hashes_match_except_the_two_guarded_metadata_paths(self):
        builder = self.builder
        _audit_bytes, _checks_bytes, audit, _checks = self._receipts()
        old_manifest = builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, "server/manifest.json"
        )
        baseline_allowlist = builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, "packaging/public-source-allowlist.json"
        )

        def read_current(path: str) -> bytes:
            return (ROOT / Path(path)).read_bytes()

        summary = builder.validate_original_source_closure(
            audit,
            read_current,
            old_manifest,
            baseline_allowlist,
        )
        self.assertEqual(summary["reviewedSourceCount"], 25)
        self.assertEqual(summary["exactSourceMatches"], 23)
        self.assertEqual(
            set(summary["metadataExceptions"]),
            {"server/manifest.json", "packaging/public-source-allowlist.json"},
        )

        changed = (ROOT / "server" / "adapters" / "pcv.php").read_bytes() + b"\n/* drift */\n"
        with self.assertRaisesRegex(builder.PackageError, "reviewed source hash differs"):
            builder.validate_original_source_closure(
                audit,
                lambda path: changed if path == "server/adapters/pcv.php" else read_current(path),
                old_manifest,
                baseline_allowlist,
            )

    def test_release_assets_reproduce_reviewed_runtime_and_include_only_safe_receipts(self):
        builder = self.builder
        audit_bytes, checks_bytes, audit, _checks = self._receipts()
        baseline_zip = BASELINE_ZIP.read_bytes()
        old_manifest = builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, "server/manifest.json"
        )
        baseline_allowlist = builder.BASE.git_blob(
            "git", builder.BASELINE_SOURCE_COMMIT, "packaging/public-source-allowlist.json"
        )
        allowlist_doc = json.loads((ROOT / "packaging" / "public-source-allowlist.json").read_bytes())
        allowlist = [entry["path"] for entry in allowlist_doc["files"]]
        source_bytes = {path: (ROOT / Path(path)).read_bytes() for path in allowlist}
        source_zip = builder.BASE.stable_zip_bytes(source_bytes)

        assets, release_audit = builder.build_release_assets(
            baseline_zip,
            audit_bytes,
            checks_bytes,
            "a" * 40,
            allowlist,
            source_bytes,
            source_zip,
            old_manifest,
            baseline_allowlist,
        )
        self.assertEqual(set(assets), builder.OUTPUT_NAMES | {builder.CHECKSUMS_NAME})
        self.assertEqual(assets[builder.OUTPUT_MOD_ZIP], REVIEWED_RELEASE_ZIP.read_bytes())
        self.assertEqual(hashlib.sha256(assets[builder.OUTPUT_MOD_ZIP]).hexdigest(), builder.REVIEWED_RUNTIME_ZIP_SHA256)
        builder.BASE.audit_checksums_file(
            assets[builder.CHECKSUMS_NAME],
            {name: assets[name] for name in builder.OUTPUT_NAMES},
        )

        proof = builder.BASE.read_zip_bytes(assets[builder.OUTPUT_PROOF_ZIP], "prealpha.27 offline proof")
        self.assertEqual(
            set(proof),
            {
                "offline-proof.json",
                "package-provenance.json",
                "reviewed-candidate-audit.json",
                "reviewed-offline-checks.json",
            },
        )
        self.assertEqual(proof["reviewed-candidate-audit.json"], audit_bytes)
        self.assertEqual(proof["reviewed-offline-checks.json"], checks_bytes)
        rendered = b"\n".join(proof.values())
        self.assertNotRegex(rendered.decode("utf-8"), r"[A-Za-z]:\\|/var/tmp/|\\\\Users\\\\")
        summary = json.loads(proof["offline-proof.json"])
        self.assertEqual(summary["sourceCommit"], "a" * 40)
        self.assertEqual(summary["runtimeZipSha256"], builder.REVIEWED_RUNTIME_ZIP_SHA256)
        self.assertEqual(summary["sourceArchiveSha256"], hashlib.sha256(assets[builder.OUTPUT_SOURCE_ZIP]).hexdigest())
        for limit in (
            "inputFreezeFixed",
            "loggerPersistence",
            "posixFilesystemSafety",
            "taggedNativePacketRecursiveOrdering",
        ):
            self.assertFalse(summary["limits"][limit], limit)
        self.assertEqual(release_audit["sourceCommit"], "a" * 40)
        self.assertEqual(release_audit["reviewedCandidateAuditSha256"], builder.ROOT_CANDIDATE_AUDIT_SHA256)

    def test_release_build_refuses_a_dirty_source_tree(self):
        builder = self.builder
        with mock.patch.object(builder.BASE, "git_output", return_value=b"?? untracked.txt\n"):
            with self.assertRaisesRegex(builder.PackageError, "clean source tree"):
                builder.require_clean_source_tree("git")


if __name__ == "__main__":
    unittest.main()
