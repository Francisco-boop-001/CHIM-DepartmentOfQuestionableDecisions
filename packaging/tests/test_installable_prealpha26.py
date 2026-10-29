from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha26.py"
PREVIOUS_TEST_PATH = ROOT / "packaging" / "tests" / "test_installable_prealpha25.py"


def _load(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise AssertionError(f"cannot load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _owner_adapter(version: str, pins: dict[str, str]) -> bytes:
    entries = "\n".join(f"        '{path}' => '{digest}'," for path, digest in pins.items())
    return f"    '{version}' => [\n{entries}\n    ],\n".encode("utf-8")


def _department_fixture(builder):
    previous_tests = _load(PREVIOUS_TEST_PATH, "prealpha25_fixture_helpers")
    previous = builder.P25
    old24, source24, _pins29 = previous_tests._department_fixture(previous)
    _package25, old25 = previous._build_department_package(old24, source24.__getitem__)

    pins29 = previous._literal_owner_closure_pins(
        old25[builder.DEPARTMENT_ADAPTER], builder.BASELINE_OWNER_VERSION,
    )
    pins30 = dict(pins29)
    pins30.update(builder.OWNER_CHANGED_CLOSURE_PINS)
    source = {
        path: old25["server/" + path.removeprefix("server/")]
        for path in builder.SERVER_SOURCE_PATHS
    }
    source[builder.DEPARTMENT_ADAPTER] = (
        old25[builder.DEPARTMENT_ADAPTER]
        + _owner_adapter(builder.SF_OWNER_VERSION, pins30)
    )
    inner = json.loads(old25["server/manifest.json"])
    inner["version"] = builder.VERSION
    source["server/manifest.json"] = builder.BASE.json_bytes(inner)
    for path in builder.SOURCE_HASH_PATHS:
        source.setdefault(path, f"source:{path}".encode("utf-8"))
    return old25, source, pins29, pins30


def _pg_receipt_fixture(builder, source_paths, case_ids):
    old, source, _pins29, pins30 = _department_fixture(builder)
    for path in source_paths:
        source.setdefault(path, f"source:{path}".encode("utf-8"))
    identity = {
        "postgresMajor": 15,
        "systemIdentifier": "7312849000123456789",
        "dataDirectory": "/var/tmp/department-dev23-20261028T000000Z-a1b2c3d4/postgres/data",
        "role": "dwemer",
        "socketDirectory": "/var/tmp/department-dev23-20261028T000000Z-a1b2c3d4/socket",
        "port": 5432,
        "listenAddresses": "127.0.0.1",
    }
    cases = [{"id": case_id, "status": "passed", "assertions": 3} for case_id in case_ids]
    source[builder.CASES_MANIFEST] = builder.BASE.json_bytes({
        "schemaVersion": 1,
        "kind": "department-dev30-actual-pg-cases",
        "ownerVersion": builder.SF_OWNER_VERSION,
        "ownerCommit": builder.SF_OWNER_COMMIT,
        "ownerClosureFiles": 20,
        "cases": [
            {"id": case_id, "authority": authority, "behavior": f"fixture behavior for {case_id}"}
            for case_id, authority in zip(case_ids, builder.DEV30_PG_AUTHORITIES, strict=True)
        ],
        "sourceFiles": list(builder.DEV30_PG_SOURCE_FILES),
        "retainedSourceOnlyControls": list(builder.RETAINED_SOURCE_ONLY_CONTROLS),
    })
    source_hashes = {path: builder.BASE.sha256(source[path]) for path in source_paths}
    result = {
        "schemaVersion": 1,
        "kind": "department-dev30-actual-pg",
        "status": "passed",
        "sourceCommit": "a" * 40,
        "distro": "DwemerAI4Skyrim3-test2",
        "privateNetworkNamespace": True,
        "ownerVersion": builder.SF_OWNER_VERSION,
        "ownerCommit": builder.SF_OWNER_COMMIT,
        "ownerClosurePins": pins30,
        "sourceHashes": source_hashes,
        "networkEvidenceSha256": "e" * 64,
        "databaseIdentity": identity,
        "socketOnly": False,
        "privateLoopbackOnly": True,
        "cases": cases,
        "counts": {"cases": len(cases), "passed": len(cases), "failed": 0, "assertions": 3 * len(cases)},
        "limits": dict(builder.PROOF_LIMITS),
    }
    shutdown = {
        "schemaVersion": 1,
        "kind": "department-dev30-pg-shutdown",
        "sourceCommit": result["sourceCommit"],
        "databaseIdentity": identity,
        "pid": 1234,
        "stopped": True,
        "scratchDisposition": "removed",
    }
    return old, source, result, shutdown


class Prealpha26BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = _load(BUILDER_PATH, "department_prealpha26")

    def test_contract_pins_exact_public_baseline_and_three_owner_hash_changes(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.26")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.25")
        self.assertEqual(builder.BASELINE_SHA256, "52569ac52dfcf1814edcabfa1e86f2c730fb83b0cec359e86a5811408348f666")
        self.assertEqual(builder.BASELINE_SOURCE_COMMIT, "224d2bb473b86e6087a9711d6a42d716e46d9ba0")
        self.assertEqual(builder.SF_OWNER_VERSION, "0.1.0-dev.30")
        self.assertEqual(builder.SF_OWNER_COMMIT, "1eb58d999698002f8aeaa05b21464f7608717794")
        self.assertEqual(builder.OWNER_CHANGED_CLOSURE_PINS, {
            "manifest.json": "f72c181f2a3c7d893d205a9b6e648ac5e4ca03e81fa1423d43ceb7db084d772f",
            "lib/runtime.php": "ffb9a97a4f2549bfd7251389d65fd916beb01a734f557ece794f81efbde45153",
            "lib/companion.php": "ce3c2c0c40c10f23d60e1604dafc38901832be8ef528b53b515269d42ce82e32",
        })
        self.assertEqual(builder.DEV30_PG_CASE_IDS, (
            "adapter.unavailable_player_read_recovery",
            "adapter.manual_compose_scheduler_no_dispatch",
            "adapter.request_id_repeat_no_resend",
            "adapter.unknown_partial_reconcile_no_resend",
            "adapter.forwarded_utf8_detail_roundtrip",
            "adapter.restore_pending_refusal_recovery",
            "owner.bgl_unreadable_defer_recover",
            "owner.player_unavailable_defer_recover",
            "owner.form_identity_unavailable_defer_recover",
            "owner.player_identity_mismatch_cancel",
            "owner.actor_identity_mismatch_cancel",
            "owner.form_identity_mismatch_cancel",
            "owner.explicit_optout_cancel",
            "owner.confirmed_dead_cancel",
            "owner.episode_change_cancel",
            "owner.restore_epoch_change_reject",
        ))
        expected_pg_sources = (
            "server/api.php",
            "server/runtime.php",
            "server/adapters/runtime_support.php",
            "server/adapters/mp.php",
            "server/adapters/sas.php",
            "server/adapters/brainwash.php",
            "server/adapters/pcv.php",
            "server/adapters/correspondence.php",
            "server/lib/protocol.php",
            "server/lib/auth.php",
            "server/lib/dispatcher.php",
            "server/lib/journal.php",
            "server/lib/overlay_setup.php",
            "server/tests/dev30-pg/actual_pg.php",
            "server/tests/dev30-pg/cases.json",
            "server/tests/dev23-pg/api_driver.php",
            "server/tests/dev23-pg/fixture_guard.php",
            "server/tests/dev23-pg/owner_fixture.php",
            "server/tests/dev23-pg/companion_cases.php",
            "server/tests/dev23-pg/seed_unknown_receipt.php",
            "server/tests/dev23-pg/base.sql",
        )
        self.assertEqual(builder.DEV30_PG_SOURCE_FILES, expected_pg_sources)
        self.assertEqual(builder.SOURCE_HASH_PATHS, set(expected_pg_sources))
        self.assertEqual(builder.EXPECTED_SERVER_SOURCE_CHANGES, {
            "server/manifest.json",
            "server/adapters/correspondence.php",
            "server/tests/correspondence/dev23-source-contract.php",
            "server/tests/correspondence/dev30-source-contract.php",
            "server/tests/correspondence/dev30-adapter-interface.php",
            "server/tests/dev30-pg/actual_pg.php",
            "server/tests/dev30-pg/cases.json",
        })
        self.assertEqual(builder.PROOF_LIMITS, {
            "database": True, "http": False, "provider": False, "native": False,
            "skyrim": False, "installedGame": False, "live": False,
        })
        self.assertGreater(builder.ZIP_TIMESTAMP, builder.BASELINE_ZIP_TIMESTAMP)
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.EXPECTED_INSTALLABLE_MEMBERS), 22)
        self.assertEqual(builder.DEPARTMENT_UPDATED_MEMBERS, {
            "manifest.json", "server/manifest.json", builder.DEPARTMENT_ADAPTER, "checksums.sha256",
        })

    def test_dev30_retains_dev29_twenty_path_closure_and_changes_only_three_fixed_pins(self) -> None:
        builder = self.builder
        old, source, pins29, pins30 = _department_fixture(builder)
        parsed = builder._validate_dev30_adapter(
            old[builder.DEPARTMENT_ADAPTER], source[builder.DEPARTMENT_ADAPTER],
        )
        self.assertEqual(parsed, pins30)
        self.assertEqual(set(parsed), set(pins29))
        changed = {path for path in pins29 if pins29[path] != parsed[path]}
        self.assertEqual(changed, set(builder.OWNER_CHANGED_CLOSURE_PINS))

    def test_baseline_rejects_any_bytes_other_than_the_published_prealpha25_archive(self) -> None:
        with self.assertRaisesRegex(self.builder.PackageError, "exact verified prealpha.25 archive"):
            self.builder._read_baseline_bytes(b"not the published archive")

    def test_department_package_changes_only_the_four_department_members(self) -> None:
        builder = self.builder
        old, source, _pins29, _pins30 = _department_fixture(builder)
        package, files = builder._build_department_package(old, source.__getitem__)
        self.assertEqual(set(files), builder.DEPARTMENT_EXPECTED_MEMBERS)
        self.assertEqual(builder.P25.P24._audit_department_component(package, builder.VERSION), files)
        changed = {path for path in old.keys() | files.keys() if old.get(path) != files.get(path)}
        self.assertEqual(changed, builder.DEPARTMENT_UPDATED_MEMBERS)
        self.assertEqual(
            builder.BASE.parse_checksums(files["checksums.sha256"], "prealpha.26 fixture"),
            {path: builder.BASE.sha256(data) for path, data in files.items() if path != "checksums.sha256"},
        )

    def test_installable_replacement_preserves_all_other_twenty_one_payloads(self) -> None:
        builder = self.builder
        baseline = {path: f"payload:{path}".encode("utf-8") for path in builder.EXPECTED_BASELINE_MEMBERS}
        old_dpkg = baseline[builder.BASELINE_DPKG_MEMBER]
        result = builder._assemble_mod(baseline, b"new Department package")
        self.assertEqual(set(result), builder.EXPECTED_INSTALLABLE_MEMBERS)
        common = set(baseline) & set(result)
        self.assertEqual(len(common), 21)
        self.assertTrue(all(baseline[path] == result[path] for path in common))
        self.assertEqual(baseline[builder.BASELINE_DPKG_MEMBER], old_dpkg)

    def test_source_allowlist_requires_all_candidate_and_proof_inputs(self) -> None:
        builder = self.builder
        paths = sorted(builder.REQUIRED_SOURCE_PATHS | builder.SOURCE_HASH_PATHS)
        self.assertEqual(builder._validate_source_allowlist(paths), set(paths))
        for required in (
            "docs/DEV30-COMPATIBILITY.md",
            "docs/superpowers/plans/2026-10-28-sealed-fates-dev30.md",
            "server/tests/correspondence/dev30-source-contract.php",
            "server/tests/correspondence/dev30-adapter-interface.php",
            builder.CASES_MANIFEST,
        ):
            with self.subTest(path=required), self.assertRaisesRegex(builder.PackageError, "missing required candidate paths"):
                builder._validate_source_allowlist([path for path in paths if path != required])
        with self.assertRaisesRegex(builder.PackageError, "duplicate paths"):
            builder._validate_source_allowlist(paths + [paths[0]])

    def test_public_allowlist_and_committed_case_manifest_cover_real_inputs(self) -> None:
        builder = self.builder
        allowlist = json.loads((ROOT / "packaging" / "public-source-allowlist.json").read_text(encoding="utf-8"))
        allowed = builder._validate_source_allowlist([entry["path"] for entry in allowlist["files"]])
        self.assertTrue(builder.SOURCE_HASH_PATHS <= allowed)
        builder._validate_dev30_case_manifest((ROOT / builder.CASES_MANIFEST).read_bytes())
        missing_inputs = [path for path in builder.DEV30_PG_SOURCE_FILES if not (ROOT / path).is_file()]
        self.assertEqual(missing_inputs, [])

    def test_source_delta_rejects_native_ui_and_unrelated_server_changes(self) -> None:
        builder = self.builder
        expected = sorted(builder.EXPECTED_SERVER_SOURCE_CHANGES)
        raw = b"\0".join(path.encode("utf-8") for path in expected) + b"\0"
        with mock.patch.object(builder.BASE, "git_output", return_value=raw) as source_diff:
            builder._validate_selected_source_delta("git", "d" * 40)
        source_diff.assert_called_once_with("git", [
            "diff", "--no-renames", "--name-only", "-z", builder.BASELINE_SOURCE_COMMIT, "d" * 40,
        ])
        with mock.patch.object(builder.BASE, "git_output", return_value=raw + b"native/src/Plugin.cpp\0"):
            with self.assertRaisesRegex(builder.PackageError, "native/UI source changed"):
                builder._validate_selected_source_delta("git", "d" * 40)
        with mock.patch.object(builder.BASE, "git_output", return_value=raw + b"ui/views/app.js\0"):
            with self.assertRaisesRegex(builder.PackageError, "native/UI source changed"):
                builder._validate_selected_source_delta("git", "d" * 40)
        with mock.patch.object(builder.BASE, "git_output", return_value=raw + b"server/unrelated.php\0"):
            with self.assertRaisesRegex(builder.PackageError, "server source delta differs"):
                builder._validate_selected_source_delta("git", "d" * 40)

    def test_pg_proof_binds_receipts_and_exposes_only_the_safe_summary(self) -> None:
        builder = self.builder
        pg_paths = builder.DEV30_PG_SOURCE_PATHS
        source_paths = set(builder.SOURCE_HASH_PATHS) | set(pg_paths)
        case_ids = builder.DEV30_PG_CASE_IDS
        old, source, result, shutdown = _pg_receipt_fixture(builder, source_paths, case_ids)
        commit = result["sourceCommit"]
        blobs = {path: source[path] for path in source_paths}
        allowlist = set(builder.REQUIRED_SOURCE_PATHS) | source_paths
        with (
            mock.patch.object(builder, "SOURCE_HASH_PATHS", source_paths),
            mock.patch.object(builder, "DEV30_PG_SOURCE_PATHS", pg_paths),
            mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]),
        ):
            summary = builder._validate_actual_pg_evidence(
                result, shutdown, builder.BASE.json_bytes(result), builder.BASE.json_bytes(shutdown),
                commit, "git", allowlist, old,
            )
        self.assertEqual(summary["postgres"]["major"], 15)
        self.assertEqual(summary["postgres"]["engine"], "PostgreSQL")
        self.assertTrue(summary["postgres"]["privateNetworkNamespace"])
        self.assertFalse(summary["postgres"]["socketOnly"])
        self.assertTrue(summary["postgres"]["privateLoopbackOnly"])
        self.assertEqual(summary["postgres"]["listenAddresses"], "127.0.0.1")
        self.assertEqual(summary["postgres"]["networkEvidenceSha256"], result["networkEvidenceSha256"])
        self.assertEqual(summary["postgres"]["caseIds"], list(case_ids))
        self.assertEqual(summary["postgres"]["counts"], result["counts"])
        self.assertEqual(summary["receiptHashes"][builder.INPUT_PG_RESULT], builder.BASE.sha256(builder.BASE.json_bytes(result)))
        self.assertEqual(summary["receiptHashes"][builder.INPUT_PG_SHUTDOWN], builder.BASE.sha256(builder.BASE.json_bytes(shutdown)))
        rendered = json.dumps(summary)
        for private_field in (
            "systemIdentifier", "dataDirectory", "socketDirectory", "role", "port", "pid",
            "networkNamespace", "markerNetworkNamespace", "initialNetworkNamespace", "ipv4Routes", "ipv6Routes",
        ):
            self.assertNotIn(f'"{private_field}":', rendered)
        proof_zip = builder._proof_zip(
            commit, b"installable", b"source archive", b"Department DWPkg",
            builder.BASE.json_bytes(summary), summary,
        )
        members = builder.BASE.read_zip_bytes(proof_zip, "prealpha.26 proof fixture")
        self.assertEqual(set(members), {builder.OUTPUT_PROOF_SUMMARY, "package-provenance.json"})
        provenance = json.loads(members["package-provenance.json"].decode("utf-8"))
        self.assertEqual(provenance["testProof"], {
            "name": builder.OUTPUT_PROOF_SUMMARY,
            "sha256": builder.BASE.sha256(members[builder.OUTPUT_PROOF_SUMMARY]),
        })
        public_bytes = b"\n".join(members.values())
        for private_value in (
            b"/var/tmp/department-dev23-20261028T000000Z-a1b2c3d4/postgres/data",
            b"/var/tmp/department-dev23-20261028T000000Z-a1b2c3d4/socket",
            b"dwemer", b"7312849000123456789",
        ):
            self.assertNotIn(private_value, public_bytes)

    def test_pg_proof_rejects_source_owner_case_identity_and_shutdown_drift(self) -> None:
        builder = self.builder
        pg_paths = builder.DEV30_PG_SOURCE_PATHS
        source_paths = set(builder.SOURCE_HASH_PATHS) | set(pg_paths)
        case_ids = builder.DEV30_PG_CASE_IDS
        old, source, good_result, good_shutdown = _pg_receipt_fixture(builder, source_paths, case_ids)
        blobs = {path: source[path] for path in source_paths}
        allowlist = set(builder.REQUIRED_SOURCE_PATHS) | source_paths
        with (
            mock.patch.object(builder, "SOURCE_HASH_PATHS", source_paths),
            mock.patch.object(builder, "DEV30_PG_SOURCE_PATHS", pg_paths),
            mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: blobs[path]),
        ):
            mutations = [
                ("sourceCommit", lambda r, s: r.update({"sourceCommit": "b" * 40}), "selected source commit"),
                ("distro", lambda r, s: r.update({"distro": "another-test-server"}), "not bound to private DwemerAI TEST2"),
                ("namespace", lambda r, s: r.update({"privateNetworkNamespace": False}), "not bound to private DwemerAI TEST2"),
                ("loopbackNamespace", lambda r, s: r.update({"privateLoopbackOnly": False}), "not bound to private loopback-only networking"),
                ("networkEvidenceMissing", lambda r, s: r.pop("networkEvidenceSha256"), "result fields differ"),
                ("networkEvidenceInvalid", lambda r, s: r.update({"networkEvidenceSha256": "E" * 64}), "network evidence hash must be a lowercase SHA-256 digest"),
                ("engine", lambda r, s: r["databaseIdentity"].update({"postgresMajor": 16}), "not PostgreSQL 15"),
                ("role", lambda r, s: r["databaseIdentity"].update({"role": "other"}), "fixed TEST2 application role and port"),
                ("port", lambda r, s: r["databaseIdentity"].update({"port": 5433}), "fixed TEST2 application role and port"),
                ("rawEvidence", lambda r, s: r.update({"rawLogs": "PRIVATE"}), "result fields differ"),
                ("source", lambda r, s: r["sourceHashes"].update({builder.DEPARTMENT_ADAPTER: "0" * 64}), "source hash differs"),
                ("owner", lambda r, s: r["ownerClosurePins"].update({"lib/runtime.php": "0" * 64}), "owner pin map differs"),
                ("case", lambda r, s: r["cases"][0].update({"status": "failed"}), "case is missing, reordered, or failed"),
                ("counts", lambda r, s: r["counts"].update({"failed": 1}), "counts do not match"),
                ("httpClaim", lambda r, s: r["limits"].update({"http": True}), "claims unsupported behavior"),
                ("noDatabaseProof", lambda r, s: r["limits"].update({"database": False}), "claims unsupported behavior"),
                ("tcpTransport", lambda r, s: r.update({"socketOnly": True}), "private loopback-only PostgreSQL access"),
                ("listen", lambda r, s: r["databaseIdentity"].update({"listenAddresses": "0.0.0.0"}), "private loopback-only PostgreSQL access"),
                ("dataDirectory", lambda r, s: r["databaseIdentity"].update({"dataDirectory": "/var/tmp/department-dev23-20261028T000000Z-a1b2c3d4/other/data"}), "fixed TEST2 data and socket directory layout"),
                ("socketDirectory", lambda r, s: r["databaseIdentity"].update({"socketDirectory": "/var/tmp/department-dev23-20261028T000000Z-bad12345/socket"}), "fixed TEST2 data and socket directory layout"),
                ("shutdownIdentity", lambda r, s: s["databaseIdentity"].update({"systemIdentifier": "changed"}), "shutdown identity differs"),
                ("shutdown", lambda r, s: s.update({"stopped": False}), "does not prove the owned process stopped"),
                ("scratch", lambda r, s: s.update({"scratchDisposition": "retained"}), "scratch cleanup is not verified"),
            ]
            for name, mutate, message in mutations:
                with self.subTest(name=name):
                    result = json.loads(json.dumps(good_result))
                    shutdown = json.loads(json.dumps(good_shutdown))
                    mutate(result, shutdown)
                    with self.assertRaisesRegex(builder.PackageError, message):
                        builder._validate_actual_pg_evidence(
                            result, shutdown, builder.BASE.json_bytes(result), builder.BASE.json_bytes(shutdown),
                            good_result["sourceCommit"], "git", allowlist, old,
                        )

            bad_manifest = json.loads(source[builder.CASES_MANIFEST].decode("utf-8"))
            bad_manifest["sourceFiles"].pop()
            bad_manifest_bytes = builder.BASE.json_bytes(bad_manifest)
            bad_result = json.loads(json.dumps(good_result))
            bad_result["sourceHashes"][builder.CASES_MANIFEST] = builder.BASE.sha256(bad_manifest_bytes)
            bad_blobs = dict(blobs)
            bad_blobs[builder.CASES_MANIFEST] = bad_manifest_bytes
            with mock.patch.object(builder.BASE, "git_blob", side_effect=lambda _git, _commit, path: bad_blobs[path]):
                with self.assertRaisesRegex(builder.PackageError, "case manifest source files differ"):
                    builder._validate_actual_pg_evidence(
                        bad_result, good_shutdown, builder.BASE.json_bytes(bad_result), builder.BASE.json_bytes(good_shutdown),
                        good_result["sourceCommit"], "git", allowlist, old,
                    )


if __name__ == "__main__":
    unittest.main()
