from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import tempfile
import unittest
import zipfile
from io import BytesIO
from pathlib import Path
from unittest.mock import patch


BUILDER_PATH = Path(__file__).resolve().parents[1] / "build-installable-prealpha12.py"
SPEC = importlib.util.spec_from_file_location("installable_prealpha12", BUILDER_PATH)
assert SPEC is not None and SPEC.loader is not None
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def json_bytes(value: object) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode()


def map_entry(data: bytes) -> dict[str, object]:
    return {"bytes": len(data), "sha256": sha(data)}


def valid_x64_pe() -> bytes:
    image = bytearray(0x80)
    image[:2] = b"MZ"
    image[0x3C:0x40] = (0x40).to_bytes(4, "little")
    image[0x40:0x44] = b"PE\0\0"
    image[0x44:0x46] = (0x8664).to_bytes(2, "little")
    return bytes(image)


def accepted_receipts() -> tuple[dict[str, bytes], dict[str, bytes]]:
    owner_map: dict[str, dict[str, object]] = {}
    owner_data: dict[str, bytes] = {}
    for number in range(19):
        path = "server/manifest.json" if number == 0 else f"server/lib/owner-{number:02d}.php"
        data = f"owner source {number}\n".encode()
        owner_map[path] = map_entry(data)
        owner_data[path] = data

    department_map: dict[str, dict[str, object]] = {}
    department_data = {
        "server/lib/protocol.php": b"<?php // protocol\n",
        "server/adapters/correspondence.php": b"<?php // adapter\n",
    }
    for path, data in department_data.items():
        department_map[path] = map_entry(data)

    freeze_files = [
        {"rootKey": "owner", "path": path, **item}
        for path, item in owner_map.items()
    ] + [
        {"rootKey": "departmentServer", "path": path.removeprefix("server/"), **item}
        for path, item in department_map.items()
    ] + [
        {"rootKey": "ownerPayload", "path": f"server/full-{number:02d}.php", "bytes": 1, "sha256": "a" * 64}
        for number in range(11)
    ]
    freeze = {
        "schema": BUILDER.FREEZE_SCHEMA,
        "ownerClosureCount": 19,
        "files": freeze_files,
    }
    freeze_raw = json_bytes(freeze)

    behavior = {
        "schemaVersion": 1,
        "gate": "runtimeRelease",
        "status": "accepted",
        "currentCheckExecuted": True,
        "runId": "synthetic-test-run",
        "facadeCases": 1,
        "passedCases": 1,
        "failedCases": 0,
        "cases": [{"id": "one", "status": "passed"}],
        "ownerVersion": "0.1.0-dev.23",
        "ownerFiles": owner_map,
        "departmentFiles": department_map,
        "runtimeSourceManifestSha256": sha(freeze_raw),
        "sourcePreservationPassed": True,
        "ownedPostgresStopVerified": True,
        "testDistroStopVerified": False,
        "testDistroLifecycle": {
            "schemaVersion": 1,
            "distro": "DwemerAI4Skyrim3-test2",
            "initialState": "running",
            "finalState": "running",
            "startedByRun": False,
            "stopRequested": False,
            "preservationVerified": True,
        },
        "protectedBaselinesMatched": True,
        "providerCalls": 0,
    }
    behavior_raw = json_bytes(behavior)

    pins = [
        {
            "file": path.removeprefix("server/"),
            "expectedSha256": item["sha256"],
            "actualSha256": item["sha256"],
            "matches": True,
        }
        for path, item in owner_map.items()
    ]
    readiness = {
        "schemaVersion": 1,
        "ready": True,
        "errorReason": None,
        "owner": {
            "version": "0.1.0-dev.23",
            "manifestSha256": owner_map["server/manifest.json"]["sha256"],
            "selectedClosurePins": pins,
        },
        "department": {
            "protocol": {"file": "lib/protocol.php", "sha256": department_map["server/lib/protocol.php"]["sha256"]},
            "adapter": {"file": "adapters/correspondence.php", "sha256": department_map["server/adapters/correspondence.php"]["sha256"]},
        },
        "checks": {
            "adapterLoaded": True,
            "manifestReady": True,
            "sourceIdentityReady": True,
            "runtimeReady": True,
            "stagedClosureMatches": True,
        },
        "limits": {
            "sourceOnly": True,
            "database": False,
            "http": False,
            "skyrim": False,
            "ownerRuntimeLoaded": False,
        },
    }
    readiness_raw = json_bytes(readiness)
    pairing = {
        "schemaVersion": 1,
        "kind": "department-owner-pairing",
        "status": "pass",
        "owner": {"version": "0.1.0-dev.23", "files": owner_map},
        "department": {"files": department_map},
        "sourceReadiness": {"receipt": "source-readiness.json", "sha256": sha(readiness_raw)},
        "behavior": {
            "receipt": "runtime-release.json",
            "sha256": sha(behavior_raw),
            "inputFreeze": "runtime-input-freeze.json",
            "inputFreezeSha256": sha(freeze_raw),
        },
    }
    receipts = {
        "department-owner-pairing.json": json_bytes(pairing),
        "source-readiness.json": readiness_raw,
        "runtime-release.json": behavior_raw,
        "runtime-input-freeze.json": freeze_raw,
    }
    return receipts, department_data


class InstallablePrealpha12Tests(unittest.TestCase):
    def test_release_identity_and_allowlist_are_prealpha12_only(self) -> None:
        self.assertEqual(BUILDER.VERSION, "0.1.0-prealpha.12")
        self.assertEqual(BUILDER.OUTPUT_MOD_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.12.zip")
        self.assertEqual(BUILDER.OUTPUT_SOURCE_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.12-source.zip")
        self.assertEqual(BUILDER.OUTPUT_PAIRING_ZIP, "WorldOfDramaLlama-0.1.0-prealpha.12-pairing.zip")
        self.assertEqual(len(BUILDER.PUBLIC_CHECKSUM_NAMES), 3)
        self.assertIn("CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.12.dwpkg", BUILDER.MO2_FIXED_MEMBERS)
        self.assertIn("CHIM/server-plugins/pcv_companion/0.1.0-prealpha.9.dwpkg", BUILDER.MO2_FIXED_MEMBERS)
        self.assertNotIn("CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.11.dwpkg", BUILDER.MO2_FIXED_MEMBERS)
        self.assertIn("server/adapters/brainwash.php", BUILDER.QD_SERVER_SOURCES)
        self.assertIn("server/preprocessing.php", BUILDER.QD_SERVER_SOURCES)
        paths = {
            entry["path"]
            for entry in json.loads((BUILDER.ROOT / BUILDER.SOURCE_ALLOWLIST_PATH).read_text(encoding="utf-8"))["files"]
        }
        self.assertLessEqual(BUILDER.REQUIRED_SOURCE_PATHS, paths)
        self.assertLessEqual(BUILDER.PREALPHA11_SOURCE_GAPS, paths)
        self.assertEqual(len(BUILDER.DEV23_PG_SOURCES), 17)

    def test_pairing_zip_contains_exact_four_accepted_receipts_and_checksums(self) -> None:
        receipts, department_data = accepted_receipts()
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            for name, raw in receipts.items():
                (directory / name).write_bytes(raw)
            with patch.object(BUILDER.BASE_BUILDER, "git_blob", side_effect=lambda _git, _commit, path: department_data[path]):
                proof = BUILDER.build_pairing_zip(directory, "git", "a" * 40)
        files = BUILDER.BASE_BUILDER.read_zip_bytes(proof, "pairing proof ZIP")
        self.assertEqual(set(files), set(receipts))
        self.assertEqual(files, receipts)
        self.assertEqual(BUILDER.audit_pairing_zip(proof), receipts)

    def test_pairing_zip_rejects_bad_hashes_counts_and_extra_files(self) -> None:
        receipts, department_data = accepted_receipts()
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            for name, raw in receipts.items():
                (directory / name).write_bytes(raw)
            (directory / "raw-output.txt").write_text("must not be included", encoding="utf-8")
            with self.assertRaisesRegex(BUILDER.PackageError, "exact four"):
                BUILDER.build_pairing_zip(directory, "git", "a" * 40)
            (directory / "raw-output.txt").unlink()

            pairing = json.loads(receipts["department-owner-pairing.json"])
            pairing["sourceReadiness"]["sha256"] = "0" * 64
            (directory / "department-owner-pairing.json").write_bytes(json_bytes(pairing))
            with patch.object(BUILDER.BASE_BUILDER, "git_blob", side_effect=lambda _git, _commit, path: department_data[path]):
                with self.assertRaisesRegex(BUILDER.PackageError, "stale|hash"):
                    BUILDER.build_pairing_zip(directory, "git", "a" * 40)

            receipts, department_data = accepted_receipts()
            for name, raw in receipts.items():
                (directory / name).write_bytes(raw)
            with patch.object(BUILDER.BASE_BUILDER, "git_blob", side_effect=lambda _git, _commit, path: b"drifted Git bytes" if path.endswith("protocol.php") else department_data[path]):
                with self.assertRaisesRegex(BUILDER.PackageError, "frozen Git commit"):
                    BUILDER.build_pairing_zip(directory, "git", "a" * 40)

    def test_pairing_validation_does_not_count_full_owner_payload_as_selected_closure(self) -> None:
        receipts, department_data = accepted_receipts()
        freeze = json.loads(receipts["runtime-input-freeze.json"])
        freeze["ownerClosureCount"] = 49
        receipts["runtime-input-freeze.json"] = json_bytes(freeze)
        pairing = json.loads(receipts["department-owner-pairing.json"])
        pairing["behavior"]["inputFreezeSha256"] = sha(receipts["runtime-input-freeze.json"])
        receipts["department-owner-pairing.json"] = json_bytes(pairing)
        behavior = json.loads(receipts["runtime-release.json"])
        behavior["runtimeSourceManifestSha256"] = sha(receipts["runtime-input-freeze.json"])
        receipts["runtime-release.json"] = json_bytes(behavior)
        pairing["behavior"]["sha256"] = sha(receipts["runtime-release.json"])
        receipts["department-owner-pairing.json"] = json_bytes(pairing)
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            for name, raw in receipts.items():
                (directory / name).write_bytes(raw)
            with patch.object(BUILDER.BASE_BUILDER, "git_blob", side_effect=lambda _git, _commit, path: department_data[path]):
                with self.assertRaisesRegex(BUILDER.PackageError, "owner closure count"):
                    BUILDER.build_pairing_zip(directory, "git", "a" * 40)

    def test_baseline_zip_and_component_updates_are_strictly_pinned(self) -> None:
        with self.assertRaisesRegex(BUILDER.PackageError, "verified prealpha.11"):
            BUILDER.audit_base_archive(b"not the pinned base archive")
        self.assertEqual(len(BUILDER.BASELINE_MO2_MEMBERS), 22)
        self.assertEqual(BUILDER.BASE_DLL_SHA256, "1451c64dc8285be897d6eeac8754980a0b90a3228f75c8780d2f5566aeacb4c9")
        with patch.object(BUILDER.BASE_BUILDER, "sha256", return_value=BUILDER.BASE_DLL_SHA256):
            BUILDER.validate_dll(valid_x64_pe())
        for old_hash in {
            BUILDER.BASE_BUILDER.OLD_DLL_SHA256,
            "8c65d467cc3a733af204bb529f31ac5dec94b04ed4889f098069ad1b27bcb32f",
            "0000000000000000000000000000000000000000000000000000000000000000",
        }:
            with patch.object(BUILDER.BASE_BUILDER, "sha256", return_value=old_hash):
                with self.assertRaises(BUILDER.PackageError):
                    BUILDER.validate_dll(valid_x64_pe())

    def test_component_and_mo2_delta_are_limited_to_the_approved_paths(self) -> None:
        previous_sources = {
            path.removeprefix("server/"): f"old source {path}\n".encode()
            for path in BUILDER.QD_SERVER_SOURCES
        }
        previous_sources["manifest.json"] = BUILDER.BASE_BUILDER.json_bytes({
            "name": "questionable_decisions",
            "version": BUILDER.BASELINE_QD_VERSION,
            "schema_version": 2,
            "config_url": "../ext/questionable_decisions/index.php",
        })
        previous_sources["setup/pcv-overlay/source-manifest.json"] = b"{}\n"
        previous_sources.update({f"setup/pcv-overlay/{name}": b"overlay\n" for name in BUILDER.BASE_BUILDER.PCV_FILE_POSTIMAGES})
        current_sources = dict(previous_sources)
        current_sources["manifest.json"] = BUILDER.BASE_BUILDER.json_bytes({
            "name": "questionable_decisions",
            "version": BUILDER.VERSION,
            "schema_version": 2,
            "config_url": "../ext/questionable_decisions/index.php",
        })
        current_sources["adapters/correspondence.php"] = b"new correspondence adapter\n"
        with patch.object(BUILDER.BASE_BUILDER, "VERSION", BUILDER.BASELINE_QD_VERSION):
            previous = BUILDER.BASE_BUILDER.build_component_bytes("questionable_decisions", previous_sources)
        with patch.object(BUILDER.BASE_BUILDER, "VERSION", BUILDER.VERSION):
            current = BUILDER.BASE_BUILDER.build_component_bytes("questionable_decisions", current_sources)
        self.assertEqual(
            json.loads(BUILDER._verify_dpkg(previous, BUILDER.BASELINE_QD_VERSION)["manifest.json"])["description"],
            BUILDER.BASELINE_QD_DESCRIPTION,
        )
        BUILDER._compare_dpkg_update(
            BUILDER._verify_dpkg(previous, BUILDER.BASELINE_QD_VERSION),
            BUILDER._verify_dpkg(current, BUILDER.VERSION),
        )

        base_files = {path: path.encode() for path in BUILDER.BASELINE_MO2_MEMBERS}
        base_files[BUILDER.BASELINE_QD_MEMBER] = previous
        base_files[BUILDER.APP_JS_MEMBER] = b"old app\n"
        assembled = BUILDER._assemble_mod(base_files, current, b"new app\n")
        self.assertEqual(set(assembled), BUILDER.MO2_FIXED_MEMBERS)
        self.assertEqual(assembled[BUILDER.QD_MEMBER], current)
        self.assertEqual(assembled[BUILDER.APP_JS_MEMBER], b"new app\n")
        self.assertEqual(assembled[BUILDER.BASELINE_PCV_MEMBER], base_files[BUILDER.BASELINE_PCV_MEMBER])
        self.assertEqual(assembled[BUILDER.BASELINE_BRAINWASH_MEMBER], base_files[BUILDER.BASELINE_BRAINWASH_MEMBER])

        changed_sources = dict(current_sources)
        changed_sources["index.php"] = b"unexpected extra server change\n"
        with patch.object(BUILDER.BASE_BUILDER, "VERSION", BUILDER.VERSION):
            drifted = BUILDER.BASE_BUILDER.build_component_bytes("questionable_decisions", changed_sources)
        with self.assertRaisesRegex(BUILDER.PackageError, "changed paths"):
            BUILDER._compare_dpkg_update(
                BUILDER._verify_dpkg(previous, BUILDER.BASELINE_QD_VERSION),
                BUILDER._verify_dpkg(drifted, BUILDER.VERSION),
            )

    def test_actual_pinned_baseline_builds_current_component_with_only_approved_changes(self) -> None:
        baseline_path = os.environ.get("DEPARTMENT_PREALPHA11_BASELINE_ZIP")
        if not baseline_path:
            self.skipTest("set DEPARTMENT_PREALPHA11_BASELINE_ZIP to run the local release self-check")
        base_files, old_qd = BUILDER.audit_base_archive(Path(baseline_path).read_bytes())
        self.assertEqual(
            json.loads(old_qd["manifest.json"])["description"],
            BUILDER.BASELINE_QD_DESCRIPTION,
        )

        def read_working_source(relative: str) -> bytes:
            return BUILDER.ROOT.joinpath(*relative.split("/")).read_bytes()

        current = BUILDER._build_department_package(read_working_source, old_qd)
        BUILDER._compare_dpkg_update(old_qd, BUILDER._verify_dpkg(current, BUILDER.VERSION))
        self.assertEqual(BUILDER.BASE_BUILDER.sha256(base_files[BUILDER.DLL_MEMBER]), BUILDER.BASE_DLL_SHA256)

    def test_manifest_bump_keeps_the_plugin_contract(self) -> None:
        manifest = json.loads((BUILDER.ROOT / "server/manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["version"], BUILDER.VERSION)
        self.assertEqual(manifest["name"], "questionable_decisions")
        self.assertEqual(manifest["schema_version"], 2)
        self.assertEqual(manifest["config_url"], "../ext/questionable_decisions/index.php")


if __name__ == "__main__":
    unittest.main()
