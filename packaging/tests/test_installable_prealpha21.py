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

ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = ROOT / "packaging" / "build-installable-prealpha21.py"


def load_builder():
    if not BUILDER_PATH.is_file():
        raise AssertionError("the prealpha.21 builder has not been implemented")
    spec = importlib.util.spec_from_file_location("department_prealpha21", BUILDER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("cannot load the prealpha.21 builder")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Prealpha21BuilderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.builder = load_builder()

    def test_candidate_contract_is_single_package_delta_with_later_timestamp(self) -> None:
        builder = self.builder
        self.assertEqual(builder.VERSION, "0.1.0-prealpha.21")
        self.assertEqual(builder.BASELINE_VERSION, "0.1.0-prealpha.20")
        self.assertEqual(builder.BASELINE_PCV_VERSION, "0.1.0-prealpha.11")
        self.assertEqual(
            builder.BASELINE_SHA256,
            "2a60c5ac9d012c7ee30d6286b876ec3d117566ea1374ff2e9801bed34416b720",
        )
        self.assertEqual(builder.BASELINE_ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 6))
        self.assertEqual(builder.ZIP_TIMESTAMP, (2026, 10, 26, 0, 0, 8))
        self.assertEqual(len(builder.EXPECTED_BASELINE_MEMBERS), 22)
        self.assertEqual(len(builder.BASELINE_DEPARTMENT_MEMBERS), 29)
        self.assertEqual(len(builder.DEPARTMENT_EXPECTED_MEMBERS), 30)
        self.assertEqual(len(builder.PCV_EXPECTED_MEMBERS), 6)
        self.assertEqual(builder.DEPARTMENT_REQUIRED_CHANGES, {
            "manifest.json",
            "server/manifest.json",
            "server/globals.php",
            "checksums.sha256",
        })
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["version"], builder.VERSION)

    def test_build_adds_globals_and_preserves_all_other_installable_payloads(self) -> None:
        builder = self.builder
        baseline = self._synthetic_baseline(builder)
        with patch.object(builder.BASE, "sha256", side_effect=self._fixture_sha(builder, baseline)):
            base_files, old_department, old_pcv = builder._read_baseline_bytes(baseline)
            source = self._department_sources(builder, old_department)
            department_zip, department = builder._build_department_package(old_department, source.__getitem__)
            assembled = builder._assemble_mod(base_files, department_zip)
            self.assertEqual(len(assembled), 22)
            self.assertEqual(set(base_files) - set(assembled), {builder.BASELINE_DPKG_MEMBER})
            self.assertEqual(set(assembled) - set(base_files), {builder.DPKG_MEMBER})
            unchanged = set(base_files) & set(assembled)
            self.assertEqual(len(unchanged), 21)
            self.assertTrue(all(base_files[path] == assembled[path] for path in unchanged))
            self.assertEqual(assembled[builder.BASELINE_PCV_MEMBER], base_files[builder.BASELINE_PCV_MEMBER])
            self.assertEqual(department["server/globals.php"], b"<?php // reserved ingress hook\n")
            self.assertEqual(
                builder._audit_department_component(department_zip, builder.VERSION), department
            )
            installable = builder.BASE.stable_zip_bytes(assembled)
            self.assertEqual(builder._read_installable(installable), assembled)
            with zipfile.ZipFile(io.BytesIO(installable)) as archive:
                self.assertEqual(archive.getinfo(builder.DPKG_MEMBER).date_time, builder.ZIP_TIMESTAMP)
            self.assertEqual(len(old_pcv), 6)

    def test_missing_globals_and_unexpected_server_changes_fail_closed(self) -> None:
        builder = self.builder
        previous = self._department_component(builder, builder.BASELINE_VERSION)
        source = self._department_sources(builder, previous)
        missing = dict(source)
        del missing["server/globals.php"]
        with self.assertRaises(builder.PackageError):
            builder._build_department_package(previous, missing.__getitem__)

        unexpected = dict(source)
        unexpected["server/adapters/mp.php"] = b"unreviewed Mind Poisoning adapter"
        with self.assertRaisesRegex(builder.PackageError, "outside"):
            builder._build_department_package(previous, unexpected.__getitem__)

    def test_version_manifest_drift_and_bad_package_checksum_are_rejected(self) -> None:
        builder = self.builder
        previous = self._department_component(builder, builder.BASELINE_VERSION)
        source = self._department_sources(builder, previous)
        inner = json.loads(source["server/manifest.json"].decode("utf-8"))
        inner["description"] = "unreviewed manifest drift"
        source["server/manifest.json"] = builder.BASE.json_bytes(inner)
        with self.assertRaisesRegex(builder.PackageError, "manifest"):
            builder._build_department_package(previous, source.__getitem__)

        valid_source = self._department_sources(builder, previous)
        package, _ = builder._build_department_package(previous, valid_source.__getitem__)
        broken = builder.BASE.read_zip_bytes(package, "fixture")
        broken["checksums.sha256"] = b"0" * 64 + b"  server/globals.php\n"
        with self.assertRaisesRegex(builder.PackageError, "checksum"):
            builder._audit_department_component(builder.BASE.stable_zip_bytes(broken), builder.VERSION)

    def test_sharmat_receipt_binds_sources_and_all_three_passing_evidence_files(self) -> None:
        builder = self.builder
        receipt, evidence, source = self._receipt_fixture(builder)
        accepted = builder._validate_sharmat_checks(
            builder.BASE.json_bytes(receipt), evidence, source.__getitem__
        )
        self.assertEqual(accepted["status"], "passed")
        self.assertEqual(set(accepted["checks"]), {"ingress", "compatibility", "focused"})

        wrong_dll = dict(evidence)
        ingress = json.loads(wrong_dll["ingress-verification.json"].decode("utf-8"))
        ingress["nativeDllSha256"] = "0" * 64
        wrong_dll["ingress-verification.json"] = builder.BASE.json_bytes(ingress)
        wrong_dll_receipt = copy.deepcopy(receipt)
        wrong_dll_receipt["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
            wrong_dll["ingress-verification.json"]
        )
        with self.assertRaisesRegex(builder.PackageError, "accepted prealpha.20 DLL"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(wrong_dll_receipt), wrong_dll, source.__getitem__
            )

        no_http = dict(evidence)
        ingress = json.loads(no_http["ingress-verification.json"].decode("utf-8"))
        ingress["http"] = False
        no_http["ingress-verification.json"] = builder.BASE.json_bytes(ingress)
        no_http_receipt = copy.deepcopy(receipt)
        no_http_receipt["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
            no_http["ingress-verification.json"]
        )
        with self.assertRaisesRegex(builder.PackageError, "HTTP and native-validator"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(no_http_receipt), no_http, source.__getitem__
            )

        missing_global = copy.deepcopy(receipt)
        ingress = json.loads(evidence["ingress-verification.json"].decode("utf-8"))
        del ingress["sourceHashes"]["server/globals.php"]
        evidence_without_global = dict(evidence)
        evidence_without_global["ingress-verification.json"] = builder.BASE.json_bytes(ingress)
        missing_global["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
            evidence_without_global["ingress-verification.json"]
        )
        with self.assertRaisesRegex(builder.PackageError, "globals"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(missing_global), evidence_without_global, source.__getitem__
            )

        tampered = dict(evidence)
        tampered["focused-checks.json"] += b" "
        with self.assertRaisesRegex(builder.PackageError, "hash"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(receipt), tampered, source.__getitem__
            )

    def test_sharmat_receipt_rejects_unknown_fields_and_unverified_full_host_claims(self) -> None:
        builder = self.builder
        receipt, evidence, source = self._receipt_fixture(builder)

        unknown_aggregate = dict(receipt)
        unknown_aggregate["fixtureToken"] = "must not enter release proof"
        with self.assertRaisesRegex(builder.PackageError, "fields differ"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(unknown_aggregate), evidence, source.__getitem__
            )

        unknown_evidence = dict(evidence)
        ingress = json.loads(unknown_evidence["ingress-verification.json"].decode("utf-8"))
        ingress["fixtureToken"] = "must not enter release proof"
        unknown_evidence["ingress-verification.json"] = builder.BASE.json_bytes(ingress)
        changed = copy.deepcopy(receipt)
        changed["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
            unknown_evidence["ingress-verification.json"]
        )
        with self.assertRaisesRegex(builder.PackageError, "fields differ"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(changed), unknown_evidence, source.__getitem__
            )

        unverified_aggregate = dict(receipt)
        unverified_aggregate["fullHostMain"] = False
        with self.assertRaisesRegex(builder.PackageError, "fullHostMain"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(unverified_aggregate), evidence, source.__getitem__
            )

        unverified_ingress = dict(evidence)
        ingress = json.loads(unverified_ingress["ingress-verification.json"].decode("utf-8"))
        ingress["fullHostMain"] = False
        unverified_ingress["ingress-verification.json"] = builder.BASE.json_bytes(ingress)
        changed = copy.deepcopy(receipt)
        changed["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
            unverified_ingress["ingress-verification.json"]
        )
        with self.assertRaisesRegex(builder.PackageError, "fullHostMain"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(changed), unverified_ingress, source.__getitem__
            )

        nonboolean_limit = copy.deepcopy(receipt)
        nonboolean_limit["limits"]["http"] = 1
        with self.assertRaisesRegex(builder.PackageError, "proof limits"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(nonboolean_limit), evidence, source.__getitem__
            )

    def test_compatibility_receipt_requires_all_exact_ready_owners(self) -> None:
        builder = self.builder
        receipt, evidence, source = self._receipt_fixture(builder)

        for mutation, message in (
            (lambda owners: owners.pop("sas"), "owner set"),
            (lambda owners: owners["pcv"].update(ready=False), "not ready"),
            (lambda owners: owners["mp"].update(version="0.0.0"), "version"),
        ):
            changed_evidence = dict(evidence)
            compatibility = json.loads(changed_evidence["compatibility.json"].decode("utf-8"))
            mutation(compatibility["owners"])
            changed_evidence["compatibility.json"] = builder.BASE.json_bytes(compatibility)
            changed_receipt = copy.deepcopy(receipt)
            changed_receipt["checks"]["compatibility"]["evidenceSha256"] = builder.BASE.sha256(
                changed_evidence["compatibility.json"]
            )
            with self.subTest(message=message), self.assertRaisesRegex(builder.PackageError, message):
                builder._validate_sharmat_checks(
                    builder.BASE.json_bytes(changed_receipt), changed_evidence, source.__getitem__
                )

    def test_evidence_metadata_rejects_false_checks_unsafe_names_and_noncanonical_json(self) -> None:
        builder = self.builder
        receipt, evidence, source = self._receipt_fixture(builder)

        for field, value, message in (
            ("checks", {"ingressHttp": False}, "checks"),
            ("limits", {"isolatedTestOnly": 1}, "limits"),
            ("rawEvidence", {"../../credentials.json": "a" * 64}, "rawEvidence"),
        ):
            changed_evidence = dict(evidence)
            ingress = json.loads(changed_evidence["ingress-verification.json"].decode("utf-8"))
            ingress[field] = value
            changed_evidence["ingress-verification.json"] = builder.BASE.json_bytes(ingress)
            changed_receipt = copy.deepcopy(receipt)
            changed_receipt["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
                changed_evidence["ingress-verification.json"]
            )
            with self.subTest(field=field), self.assertRaisesRegex(builder.PackageError, message):
                builder._validate_sharmat_checks(
                    builder.BASE.json_bytes(changed_receipt), changed_evidence, source.__getitem__
                )

        changed_evidence = dict(evidence)
        ingress = json.loads(changed_evidence["ingress-verification.json"].decode("utf-8"))
        changed_evidence["ingress-verification.json"] = json.dumps(
            ingress, indent=2, sort_keys=True
        ).encode("utf-8")
        changed_receipt = copy.deepcopy(receipt)
        changed_receipt["checks"]["ingress"]["evidenceSha256"] = builder.BASE.sha256(
            changed_evidence["ingress-verification.json"]
        )
        with self.assertRaisesRegex(builder.PackageError, "canonical"):
            builder._validate_sharmat_checks(
                builder.BASE.json_bytes(changed_receipt), changed_evidence, source.__getitem__
            )

    def test_public_allowlist_includes_release_sources_but_excludes_evidence(self) -> None:
        builder = self.builder
        raw = (ROOT / "packaging" / "public-source-allowlist.json").read_bytes()
        document = json.loads(raw.decode("utf-8"))
        paths = {entry["path"] for entry in document["files"]}
        self.assertTrue(builder.REQUIRED_SOURCE_PATHS.issubset(paths))
        self.assertIn("server/globals.php", paths)
        self.assertIn("server/tests/onboarding/stock-ingress.php", paths)
        self.assertFalse(any(path.startswith("artifacts/") for path in paths))
        self.assertFalse(any("ingress-verification.json" in path for path in paths))

    def test_checks_directory_ignores_unrelated_raw_files_and_proof_is_explicit(self) -> None:
        builder = self.builder
        receipt, evidence, _source = self._receipt_fixture(builder)
        receipt_bytes = builder.BASE.json_bytes(receipt)
        raw_private_fixture = b"synthetic credential fixture must stay outside the proof"
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / builder.INPUT_CHECKS).write_bytes(receipt_bytes)
            for name, data in evidence.items():
                (directory / name).write_bytes(data)
            (directory / "raw-runtime-fixture.bin").write_bytes(raw_private_fixture)
            (directory / "synthetic-credentials.json").write_bytes(raw_private_fixture)
            loaded_receipt, loaded_evidence = builder._read_check_inputs(directory)
            self.assertEqual(loaded_receipt, receipt_bytes)
            self.assertEqual(loaded_evidence, evidence)

        proof = builder._proof_zip(
            "c" * 40,
            b"installable",
            b"source archive",
            b"Department package",
            receipt_bytes,
            receipt,
            evidence,
        )
        proof_files = builder.BASE.read_zip_bytes(proof, "proof fixture")
        self.assertEqual(
            set(proof_files),
            {
                builder.INPUT_CHECKS,
                *builder.EVIDENCE_FILES.values(),
                "package-provenance.json",
            },
        )
        self.assertFalse(any(raw_private_fixture in data for data in proof_files.values()))

    @classmethod
    def _receipt_fixture(cls, builder):
        source = {path: ("committed:" + path).encode("utf-8") for path in builder.SOURCE_HASH_PATHS}
        hashes = {path: builder.BASE.sha256(data) for path, data in source.items()}
        evidence_paths = {
            "ingress": {
                "server/globals.php",
                "server/lib/connect.php",
                "server/preprocessing.php",
            },
            "compatibility": set(builder.COMPATIBILITY_EVIDENCE_PATHS),
            "focused": {
                "server/globals.php",
                "server/tests/onboarding/stock-ingress.php",
            },
        }
        names = {
            "ingress": "ingress-verification.json",
            "compatibility": "compatibility.json",
            "focused": "focused-checks.json",
        }
        evidence = {}
        for name, paths in evidence_paths.items():
            row = {
                "schemaVersion": 1,
                "status": "passed",
                "sourceHashes": {path: hashes[path] for path in sorted(paths)},
                "checks": {name + "Probe": True},
                "limits": {key: value for key, value in builder.PROOF_LIMITS.items()},
                "rawEvidence": {name + "-probe.json": "a" * 64},
            }
            if name == "ingress":
                row.update({
                    "http": True,
                    "nativeValidator": True,
                    "nativeDllSha256": builder.DLL_SHA256,
                    "fullHostMain": True,
                    "hostCommit": "0ec590b42c8087860138bb4e9aab470049ff9a2b",
                    "sharmatCommit": "d6c6da595eb961f338245d6e4d044fe6e86279f0",
                })
            if name == "compatibility":
                row.update({
                    "owners": {
                        "pcv": {"version": "0.1.24", "ready": True},
                        "mp": {"version": "0.1.30", "ready": True},
                        "sas": {"version": "0.1.12", "ready": True},
                        "correspondence": {"version": "0.1.0-dev.26", "ready": True},
                        "brainwash": {"version": "0.1.0-dev.3", "ready": True},
                    },
                    "protectedSourceCount": 75,
                })
            evidence[names[name]] = builder.BASE.json_bytes(row)
        receipt = {
            "schemaVersion": 1,
            "status": "passed",
            "sourceHashes": hashes,
            "checks": {
                name: {
                    "status": "passed",
                    "evidenceFile": names[name],
                    "evidenceSha256": builder.BASE.sha256(evidence[names[name]]),
                }
                for name in names
            },
            "limits": dict(builder.PROOF_LIMITS),
            "fullHostMain": True,
        }
        return receipt, evidence, source

    @classmethod
    def _server_manifest(cls, builder, name: str, version: str) -> bytes:
        return builder.BASE.json_bytes({
            "schema_version": 2,
            "name": name,
            "version": version,
            "description": "test component",
            "config_url": builder.BASE.PACKAGE_CONFIG_URL[name],
        })

    @classmethod
    def _component(cls, builder, name: str, version: str) -> dict[str, bytes]:
        expected = (
            builder.P20.DEPARTMENT_EXPECTED_MEMBERS
            if name == "questionable_decisions"
            else builder.PCV_EXPECTED_MEMBERS
        )
        files = {path: ("fixture:" + path).encode("utf-8") for path in expected}
        files["manifest.json"] = builder.BASE.json_bytes({
            "schema_version": 4,
            "name": name,
            "version": version,
            "description": "test component",
            "server": {"mutable_paths": []},
        })
        files["server/manifest.json"] = cls._server_manifest(builder, name, version)
        files["checksums.sha256"] = builder.BASE.make_checksums(
            {path: data for path, data in files.items() if path != "checksums.sha256"}
        )
        return files

    @classmethod
    def _department_component(cls, builder, version: str) -> dict[str, bytes]:
        return cls._component(builder, "questionable_decisions", version)

    @classmethod
    def _synthetic_baseline_files(cls, builder) -> dict[str, bytes]:
        files = {
            path: ("baseline:" + path).encode("utf-8")
            for path in builder.EXPECTED_BASELINE_MEMBERS
        }
        files[builder.DLL_MEMBER] = b"synthetic DLL bytes"
        files[builder.BASELINE_DPKG_MEMBER] = builder.BASE.stable_zip_bytes(
            cls._department_component(builder, builder.BASELINE_VERSION)
        )
        files[builder.BASELINE_PCV_MEMBER] = builder.BASE.stable_zip_bytes(
            cls._component(builder, "pcv_companion", builder.BASELINE_PCV_VERSION)
        )
        return files

    @classmethod
    def _synthetic_baseline(cls, builder) -> bytes:
        with patch.object(builder.BASE, "ZIP_TIMESTAMP", builder.BASELINE_ZIP_TIMESTAMP):
            return builder.BASE.stable_zip_bytes(cls._synthetic_baseline_files(builder))

    @classmethod
    def _department_sources(cls, builder, previous: dict[str, bytes]) -> dict[str, bytes]:
        source = {
            path: previous["server/" + path.removeprefix("server/")]
            for path in builder.SERVER_SOURCE_PATHS
        }
        inner = json.loads(source["server/manifest.json"].decode("utf-8"))
        inner["version"] = builder.VERSION
        source["server/manifest.json"] = builder.BASE.json_bytes(inner)
        source["server/globals.php"] = b"<?php // reserved ingress hook\n"
        return source

    @classmethod
    def _fixture_sha(cls, builder, baseline: bytes):
        real_sha = builder.BASE.sha256

        def fixture_sha(data: bytes) -> str:
            if data == baseline:
                return builder.BASELINE_SHA256
            if data == b"synthetic DLL bytes":
                return builder.DLL_SHA256
            return real_sha(data)

        return fixture_sha


if __name__ == "__main__":
    unittest.main()
