#!/usr/bin/env python3
"""Build and audit the prealpha.26 Department package with source-bound TEST2 PostgreSQL proof."""

from __future__ import annotations

import argparse
import importlib.util
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path


_P25_PATH = Path(__file__).with_name("build-installable-prealpha25.py")
_P25_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha25_for26", _P25_PATH)
if _P25_SPEC is None or _P25_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.25 packaging helper")
P25 = importlib.util.module_from_spec(_P25_SPEC)
_P25_SPEC.loader.exec_module(P25)

P24 = P25.P24
P23 = P25.P23
P20 = P25.P20
P15 = P25.P15
BASE = P25.BASE
PackageError = P25.PackageError
ROOT = P25.ROOT

VERSION = "0.1.0-prealpha.26"
BASELINE_VERSION = P25.VERSION
BASELINE_SHA256 = "52569ac52dfcf1814edcabfa1e86f2c730fb83b0cec359e86a5811408348f666"
BASELINE_SOURCE_COMMIT = "224d2bb473b86e6087a9711d6a42d716e46d9ba0"
BASELINE_ZIP_TIMESTAMP = P25.ZIP_TIMESTAMP
ZIP_TIMESTAMP = (2026, 10, 28, 0, 0, 2)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = P25.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
DEPARTMENT_ADAPTER = "server/adapters/correspondence.php"
PCV_MEMBER = P25.PCV_MEMBER
MMP_MEMBER = P25.MMP_MEMBER
DLL_MEMBER = P25.DLL_MEMBER
DLL_SHA256 = P25.DLL_SHA256
MMP_SHA256 = P25.MMP_SHA256
EXPECTED_BASELINE_MEMBERS = set(P25.EXPECTED_INSTALLABLE_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
DEPARTMENT_EXPECTED_MEMBERS = set(P25.DEPARTMENT_EXPECTED_MEMBERS)
DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    "checksums.sha256",
}

BASELINE_OWNER_VERSION = P25.SF_OWNER_VERSION
SF_OWNER_VERSION = "0.1.0-dev.30"
SF_OWNER_COMMIT = "1eb58d999698002f8aeaa05b21464f7608717794"
OWNER_CHANGED_CLOSURE_PINS = {
    "manifest.json": "f72c181f2a3c7d893d205a9b6e648ac5e4ca03e81fa1423d43ceb7db084d772f",
    "lib/runtime.php": "ffb9a97a4f2549bfd7251389d65fd916beb01a734f557ece794f81efbde45153",
    "lib/companion.php": "ce3c2c0c40c10f23d60e1604dafc38901832be8ef528b53b515269d42ce82e32",
}
BASELINE_OWNER_PATHS = frozenset(P25.DEV28_OWNER_PATHS | {P25.NEW_OWNER_MIGRATION})

CASES_MANIFEST = "server/tests/dev30-pg/cases.json"
DEV30_PG_SOURCE_FILES = (
    "server/api.php",
    "server/runtime.php",
    "server/adapters/runtime_support.php",
    "server/adapters/mp.php",
    "server/adapters/sas.php",
    "server/adapters/brainwash.php",
    "server/adapters/pcv.php",
    DEPARTMENT_ADAPTER,
    "server/lib/protocol.php",
    "server/lib/auth.php",
    "server/lib/dispatcher.php",
    "server/lib/journal.php",
    "server/lib/overlay_setup.php",
    "server/tests/dev30-pg/actual_pg.php",
    CASES_MANIFEST,
    "server/tests/dev23-pg/api_driver.php",
    "server/tests/dev23-pg/fixture_guard.php",
    "server/tests/dev23-pg/owner_fixture.php",
    "server/tests/dev23-pg/companion_cases.php",
    "server/tests/dev23-pg/seed_unknown_receipt.php",
    "server/tests/dev23-pg/base.sql",
)
DEV30_PG_SOURCE_PATHS = frozenset(DEV30_PG_SOURCE_FILES)
DEV30_SHARED_TEST_PATHS = frozenset({
    "server/tests/correspondence/dev23-source-contract.php",
    "server/tests/correspondence/dev30-source-contract.php",
    "server/tests/correspondence/dev30-adapter-interface.php",
})
DEV30_PG_NEW_SOURCE_PATHS = frozenset({
    "server/tests/dev30-pg/actual_pg.php",
    CASES_MANIFEST,
})
DEV30_PG_CASE_IDS = (
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
)
DEV30_PG_AUTHORITIES = (
    "department-api",
    "department-api+owner-service",
    "department-api",
    "department-api+owner-service",
    "department-api+owner-service",
    "department-api",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
    "owner-service",
)
RETAINED_SOURCE_ONLY_CONTROLS = (
    "restore_init_marker_repair_and_overlap",
    "negative_and_overshoot",
    "signed_unknown_partial_receipt",
)
SERVER_SOURCE_PATHS = tuple(P25.SERVER_SOURCE_PATHS)
EXPECTED_SERVER_SOURCE_CHANGES = frozenset({
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    *DEV30_SHARED_TEST_PATHS,
    *DEV30_PG_NEW_SOURCE_PATHS,
})
SOURCE_HASH_PATHS = set(DEV30_PG_SOURCE_PATHS)

INPUT_PG_RESULT = "actual-pg-result.json"
INPUT_PG_SHUTDOWN = "actual-pg-shutdown.json"
OUTPUT_PROOF_SUMMARY = "test-proof.json"
PG_DATA_DIRECTORY_RE = re.compile(
    r"\A/var/tmp/department-dev23-([0-9]{8}T[0-9]{6}Z-[a-f0-9]{8})/postgres/data\Z"
)
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-test-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
PROOF_LIMITS = {
    "database": True,
    "http": False,
    "provider": False,
    "native": False,
    "skyrim": False,
    "installedGame": False,
    "live": False,
}
REQUIRED_SOURCE_PATHS = {
    "README.md",
    "docs/BUILD.md",
    "docs/DEV30-COMPATIBILITY.md",
    "docs/superpowers/plans/2026-10-28-sealed-fates-dev30.md",
    "packaging/public-source-allowlist.json",
    "packaging/build-installable-prealpha26.py",
    "packaging/tests/test_installable_prealpha26.py",
    "server/manifest.json",
    *SOURCE_HASH_PATHS,
    *DEV30_SHARED_TEST_PATHS,
}
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME}


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError) as error:
        raise PackageError(f"required prealpha.26 source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required prealpha.26 source is not bytes: {path}")
    return data


def _validate_source_allowlist(allowlist: list[str]) -> set[str]:
    allowed = set(allowlist)
    if len(allowed) != len(allowlist):
        raise PackageError("public source allowlist contains duplicate paths")
    missing = sorted(REQUIRED_SOURCE_PATHS - allowed)
    if missing:
        raise PackageError(f"public source allowlist is missing required candidate paths: {', '.join(missing)}")
    missing_hashes = sorted(SOURCE_HASH_PATHS - allowed)
    if missing_hashes:
        raise PackageError(f"public source allowlist omits TEST2-bound source files: {', '.join(missing_hashes)}")
    return allowed


def _validate_dev30_adapter(old_adapter: bytes, new_adapter: bytes) -> dict[str, str]:
    old_pins = P25._literal_owner_closure_pins(old_adapter, BASELINE_OWNER_VERSION)
    new_pins = P25._literal_owner_closure_pins(new_adapter, SF_OWNER_VERSION)
    if set(old_pins) != BASELINE_OWNER_PATHS:
        raise PackageError("prealpha.25 adapter does not contain the exact dev.29 owner closure")
    if set(new_pins) != set(old_pins):
        raise PackageError("dev.30 adapter must retain the exact dev.29 twenty-file closure")
    changed = {path for path in old_pins if old_pins[path] != new_pins[path]}
    if changed != set(OWNER_CHANGED_CLOSURE_PINS):
        raise PackageError("dev.30 adapter pin delta differs from the three fixed owner changes")
    if any(new_pins[path] != digest for path, digest in OWNER_CHANGED_CLOSURE_PINS.items()):
        raise PackageError("dev.30 adapter does not pin the exact frozen changed owner hashes")
    if any(old_pins[path] != digest for path, digest in P25.OWNER_CHANGED_CLOSURE_PINS.items()):
        raise PackageError("prealpha.25 adapter does not retain the frozen dev.29 changed owner hashes")
    return new_pins


def _validate_selected_source_delta(git: str, commit: str) -> None:
    raw = BASE.git_output(git, [
        "diff", "--no-renames", "--name-only", "-z", BASELINE_SOURCE_COMMIT, commit,
    ])
    changed = {path.decode("utf-8") for path in raw.split(b"\0") if path}
    if any(path.startswith(("native/", "ui/")) for path in changed):
        raise PackageError("native/UI source changed from immutable prealpha.25 source")
    server_changes = {path for path in changed if path.startswith("server/")}
    if server_changes != EXPECTED_SERVER_SOURCE_CHANGES:
        missing = sorted(EXPECTED_SERVER_SOURCE_CHANGES - server_changes)
        unexpected = sorted(server_changes - EXPECTED_SERVER_SOURCE_CHANGES)
        raise PackageError(
            f"Department server source delta differs from the fixed dev.30 adapter-and-test set; missing={missing}, unexpected={unexpected}"
        )


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.25 archive")
    files = BASE.read_zip_bytes(data, "prealpha.25 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.25 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.25 baseline DLL differs from the pinned published bytes")
    if BASE.sha256(files[MMP_MEMBER]) != MMP_SHA256:
        raise PackageError("prealpha.25 baseline MMP dev.4 package differs from the pinned published bytes")
    department = P24._audit_department_component(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", P24.PCV_VERSION)
    P23._read_brainwash_component(files[MMP_MEMBER], P23.MMP_VERSION)
    return files, department


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes]]:
    return _read_baseline_bytes(P15._regular_file(path, "prealpha.25 baseline ZIP"))


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    if set(old_package) != DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("prealpha.25 Department member set differs from its fixed contract")
    current = dict(old_package)
    outer = P25._json_object(current["manifest.json"], "prealpha.25 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION:
        raise PackageError("prealpha.25 Department outer manifest has the wrong baseline version")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    current[DEPARTMENT_ADAPTER] = _required_source(read_source, DEPARTMENT_ADAPTER)
    _validate_dev30_adapter(old_package[DEPARTMENT_ADAPTER], current[DEPARTMENT_ADAPTER])
    current["server/manifest.json"] = _required_source(read_source, "server/manifest.json")
    for source_path in SERVER_SOURCE_PATHS:
        if source_path in {DEPARTMENT_ADAPTER, "server/manifest.json"}:
            continue
        member = "server/" + source_path.removeprefix("server/")
        source = _required_source(read_source, source_path)
        if old_package.get(member) != source:
            raise PackageError(f"Department source must remain unchanged from prealpha.25: {source_path}")

    old_inner = P25._json_object(old_package["server/manifest.json"], "prealpha.25 Department server manifest")
    inner = P25._json_object(current["server/manifest.json"], "prealpha.26 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = P24._audit_department_component(package, VERSION)
    changed = {path for path in old_package.keys() | verified.keys() if old_package.get(path) != verified.get(path)}
    if changed != DEPARTMENT_UPDATED_MEMBERS:
        unexpected = ", ".join(sorted(changed - DEPARTMENT_UPDATED_MEMBERS))
        missing = ", ".join(sorted(DEPARTMENT_UPDATED_MEMBERS - changed))
        raise PackageError(f"Department DWPkg changed outside the fixed prealpha.26 delta; unexpected=[{unexpected}] missing=[{missing}]")
    return package, verified


def _assert_mod_delta(baseline_files: dict[str, bytes], files: dict[str, bytes]) -> None:
    if set(baseline_files) != EXPECTED_BASELINE_MEMBERS or len(baseline_files) != 22:
        raise PackageError("prealpha.25 baseline member set differs")
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.26 MO2 member set differs from the fixed 22-member layout")
    common = set(baseline_files) & set(files)
    if common != EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER} or len(common) != 21:
        raise PackageError("prealpha.26 must replace only the versioned Department DWPkg")
    if any(baseline_files[path] != files[path] for path in common):
        raise PackageError("prealpha.26 changed an unrelated MO2 payload")


def _assemble_mod(baseline_files: dict[str, bytes], department_package: bytes) -> dict[str, bytes]:
    if department_package == baseline_files.get(BASELINE_DPKG_MEMBER):
        raise PackageError("prealpha.26 must replace the prealpha.25 Department package")
    files = {path: data for path, data in baseline_files.items() if path != BASELINE_DPKG_MEMBER}
    files[DPKG_MEMBER] = department_package
    _assert_mod_delta(baseline_files, files)
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.26 MO2 archive")
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.26 MO2 member list differs from the fixed 22-member layout")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.26 changed the accepted native DLL bytes")
    if BASE.sha256(files[MMP_MEMBER]) != MMP_SHA256:
        raise PackageError("prealpha.26 changed the accepted MMP dev.4 package")
    P24._audit_department_component(files[DPKG_MEMBER], VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", P24.PCV_VERSION)
    P23._read_brainwash_component(files[MMP_MEMBER], P23.MMP_VERSION)
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.26 MO2 archive is not the canonical timestamped ZIP")
    return files


def _read_json_file(path: Path, label: str) -> tuple[bytes, dict]:
    data = P15._regular_file(path, label)
    if len(data) > 1024 * 1024:
        raise PackageError(f"{label} exceeds 1 MiB")
    value = P25._json_object(data, label)
    if data != BASE.json_bytes(value):
        raise PackageError(f"{label} must use canonical UTF-8 JSON with LF and no BOM")
    return data, value


def _sha256(value: object, label: str) -> str:
    if not isinstance(value, str) or len(value) != 64 or any(char not in "0123456789abcdef" for char in value):
        raise PackageError(f"{label} must be a lowercase SHA-256 digest")
    return value


def _validate_source_hashes(source_hashes: object, commit: str, git: str, allowlist: set[str]) -> dict[str, bytes]:
    if not isinstance(source_hashes, dict) or set(source_hashes) != SOURCE_HASH_PATHS:
        raise PackageError("TEST2 source hashes differ from the fixed Department adapter/test input set")
    committed: dict[str, bytes] = {}
    for path, digest in source_hashes.items():
        _sha256(digest, f"TEST2 source hash {path}")
        if path not in allowlist:
            raise PackageError(f"TEST2-bound source is not in the public source allowlist: {path}")
        try:
            data = BASE.git_blob(git, commit, path)
        except (KeyError, OSError, subprocess.SubprocessError) as error:
            raise PackageError(f"TEST2-bound source is unavailable in the selected commit: {path}") from error
        if not isinstance(data, bytes) or BASE.sha256(data) != digest:
            raise PackageError(f"TEST2 source hash differs from the selected commit: {path}")
        committed[path] = data
    return committed


def _validate_dev30_case_manifest(data: bytes) -> None:
    manifest = P25._json_object(data, "dev.30 PostgreSQL case manifest")
    fields = {
        "schemaVersion", "kind", "ownerVersion", "ownerCommit", "ownerClosureFiles",
        "cases", "sourceFiles", "retainedSourceOnlyControls",
    }
    if set(manifest) != fields:
        raise PackageError("dev.30 PostgreSQL case manifest fields differ from the fixed schema")
    if type(manifest["schemaVersion"]) is not int or manifest["schemaVersion"] != 1:
        raise PackageError("dev.30 PostgreSQL case manifest must use schema version 1")
    if manifest["kind"] != "department-dev30-actual-pg-cases":
        raise PackageError("dev.30 PostgreSQL case manifest kind differs")
    if manifest["ownerVersion"] != SF_OWNER_VERSION or manifest["ownerCommit"] != SF_OWNER_COMMIT:
        raise PackageError("dev.30 PostgreSQL case manifest owner differs from the frozen pin")
    if type(manifest["ownerClosureFiles"]) is not int or manifest["ownerClosureFiles"] != 20:
        raise PackageError("dev.30 PostgreSQL case manifest must bind the exact 20-file owner closure")
    cases = manifest["cases"]
    if not isinstance(cases, list) or len(cases) != len(DEV30_PG_CASE_IDS):
        raise PackageError("dev.30 PostgreSQL case manifest does not contain the fixed 16 cases")
    for case, case_id, authority in zip(cases, DEV30_PG_CASE_IDS, DEV30_PG_AUTHORITIES, strict=True):
        if not isinstance(case, dict) or set(case) != {"id", "authority", "behavior"}:
            raise PackageError("dev.30 PostgreSQL case manifest entry fields differ")
        if case["id"] != case_id or case["authority"] != authority:
            raise PackageError("dev.30 PostgreSQL case manifest order or authority differs")
        if not isinstance(case["behavior"], str) or not case["behavior"]:
            raise PackageError("dev.30 PostgreSQL case manifest behavior is missing")
    source_files = manifest["sourceFiles"]
    if (
        not isinstance(source_files, list)
        or len(source_files) != len(DEV30_PG_SOURCE_FILES)
        or any(not isinstance(path, str) for path in source_files)
        or len(set(source_files)) != len(source_files)
        or set(source_files) != DEV30_PG_SOURCE_PATHS
    ):
        raise PackageError("dev.30 PostgreSQL case manifest source files differ from the fixed invoked inputs")
    if manifest["retainedSourceOnlyControls"] != list(RETAINED_SOURCE_ONLY_CONTROLS):
        raise PackageError("dev.30 PostgreSQL case manifest reclassifies retained source-only controls")


def _validate_actual_pg_evidence(
    result: dict,
    shutdown: dict,
    result_bytes: bytes,
    shutdown_bytes: bytes,
    commit: str,
    git: str,
    allowlist: set[str],
    old_department: dict[str, bytes],
) -> dict:
    if not DEV30_PG_SOURCE_PATHS or not DEV30_PG_CASE_IDS:
        raise PackageError("dev.30 PostgreSQL source and case contract is not fixed")
    result_fields = {
        "schemaVersion", "kind", "status", "sourceCommit", "distro", "privateNetworkNamespace",
        "ownerVersion", "ownerCommit", "ownerClosurePins", "sourceHashes", "databaseIdentity",
        "socketOnly", "privateLoopbackOnly", "networkEvidenceSha256", "cases", "counts", "limits",
    }
    if set(result) != result_fields:
        raise PackageError("actual PostgreSQL result fields differ from the fixed schema")
    if type(result["schemaVersion"]) is not int or result["schemaVersion"] != 1:
        raise PackageError("actual PostgreSQL result must use schema version 1")
    if result["kind"] != "department-dev30-actual-pg" or result["status"] != "passed":
        raise PackageError("actual PostgreSQL result is not the required passing dev.30 receipt")
    if result["sourceCommit"] != commit:
        raise PackageError("actual PostgreSQL result does not identify the selected source commit")
    if result["distro"] != "DwemerAI4Skyrim3-test2" or type(result["privateNetworkNamespace"]) is not bool or result["privateNetworkNamespace"] is not True:
        raise PackageError("actual PostgreSQL result is not bound to private DwemerAI TEST2")
    if type(result["privateLoopbackOnly"]) is not bool or result["privateLoopbackOnly"] is not True:
        raise PackageError("actual PostgreSQL result is not bound to private loopback-only networking")
    _sha256(result["networkEvidenceSha256"], "network evidence hash")
    if result["ownerVersion"] != SF_OWNER_VERSION or result["ownerCommit"] != SF_OWNER_COMMIT:
        raise PackageError("actual PostgreSQL owner identity differs from frozen dev.30")

    committed = _validate_source_hashes(result["sourceHashes"], commit, git, allowlist)
    _validate_dev30_case_manifest(committed[CASES_MANIFEST])
    adapter = committed.get(DEPARTMENT_ADAPTER)
    if adapter is None:
        raise PackageError("TEST2 source hashes omit the candidate correspondence adapter")
    pins = result["ownerClosurePins"]
    adapter_pins = _validate_dev30_adapter(old_department[DEPARTMENT_ADAPTER], adapter)
    if not isinstance(pins, dict) or pins != adapter_pins:
        raise PackageError("actual PostgreSQL owner pin map differs from the committed dev.30 adapter")

    identity_fields = {"postgresMajor", "systemIdentifier", "dataDirectory", "role", "socketDirectory", "port", "listenAddresses"}
    identity = result["databaseIdentity"]
    if not isinstance(identity, dict) or set(identity) != identity_fields:
        raise PackageError("actual PostgreSQL database identity fields differ from the fixed schema")
    if type(identity["postgresMajor"]) is not int or identity["postgresMajor"] != 15:
        raise PackageError("actual database is not PostgreSQL 15")
    for field in {"systemIdentifier", "dataDirectory", "role", "socketDirectory"}:
        if not isinstance(identity[field], str) or not identity[field]:
            raise PackageError(f"actual PostgreSQL identity {field} is missing")
    data_match = PG_DATA_DIRECTORY_RE.fullmatch(identity["dataDirectory"])
    if data_match is None or identity["socketDirectory"] != f"/var/tmp/department-dev23-{data_match.group(1)}/socket":
        raise PackageError("actual PostgreSQL identity differs from the fixed TEST2 data and socket directory layout")
    if type(identity["port"]) is not int or identity["port"] != 5432 or identity["role"] != "dwemer":
        raise PackageError("actual PostgreSQL identity differs from the fixed TEST2 application role and port")
    if (
        identity["listenAddresses"] != "127.0.0.1"
        or type(result["socketOnly"]) is not bool or result["socketOnly"] is not False
    ):
        raise PackageError("actual PostgreSQL receipt does not prove private loopback-only PostgreSQL access")

    cases = result["cases"]
    if not isinstance(cases, list) or len(cases) != len(DEV30_PG_CASE_IDS):
        raise PackageError("actual PostgreSQL case list differs from the fixed dev.30 cases")
    assertions = 0
    for expected_id, case in zip(DEV30_PG_CASE_IDS, cases, strict=True):
        if not isinstance(case, dict) or set(case) != {"id", "status", "assertions"}:
            raise PackageError("actual PostgreSQL case fields differ from the fixed schema")
        if case["id"] != expected_id or case["status"] != "passed":
            raise PackageError("actual PostgreSQL case is missing, reordered, or failed")
        if type(case["assertions"]) is not int or case["assertions"] < 1:
            raise PackageError("actual PostgreSQL case assertion count must be positive")
        assertions += case["assertions"]
    expected_counts = {"cases": len(cases), "passed": len(cases), "failed": 0, "assertions": assertions}
    counts = result["counts"]
    if (
        not isinstance(counts, dict)
        or set(counts) != set(expected_counts)
        or any(type(value) is not int for value in counts.values())
        or counts != expected_counts
    ):
        raise PackageError("actual PostgreSQL counts do not match the ordered passing case list")
    if not isinstance(result["limits"], dict) or set(result["limits"]) != set(PROOF_LIMITS):
        raise PackageError("actual PostgreSQL proof limits differ from the fixed schema")
    if any(type(value) is not bool for value in result["limits"].values()) or result["limits"] != PROOF_LIMITS:
        raise PackageError("actual PostgreSQL receipt claims unsupported behavior")

    shutdown_fields = {"schemaVersion", "kind", "sourceCommit", "databaseIdentity", "pid", "stopped", "scratchDisposition"}
    if set(shutdown) != shutdown_fields:
        raise PackageError("actual PostgreSQL shutdown fields differ from the fixed schema")
    if type(shutdown["schemaVersion"]) is not int or shutdown["schemaVersion"] != 1:
        raise PackageError("actual PostgreSQL shutdown receipt must use schema version 1")
    if shutdown["kind"] != "department-dev30-pg-shutdown" or shutdown["sourceCommit"] != commit:
        raise PackageError("actual PostgreSQL shutdown receipt does not identify the selected source commit")
    if shutdown["databaseIdentity"] != identity:
        raise PackageError("actual PostgreSQL shutdown identity differs from the tested database")
    if type(shutdown["pid"]) is not int or shutdown["pid"] <= 0 or type(shutdown["stopped"]) is not bool or not shutdown["stopped"]:
        raise PackageError("actual PostgreSQL shutdown does not prove the owned process stopped")
    if shutdown["scratchDisposition"] != "removed":
        raise PackageError("actual PostgreSQL scratch cleanup is not verified as removed")

    return {
        "schemaVersion": 1,
        "kind": "department-test-proof",
        "sourceCommit": commit,
        "ownerVersion": SF_OWNER_VERSION,
        "ownerCommit": SF_OWNER_COMMIT,
        "ownerClosurePins": adapter_pins,
        "sourceHashes": result["sourceHashes"],
        "postgres": {
            "distro": result["distro"],
            "engine": "PostgreSQL",
            "major": 15,
            "privateNetworkNamespace": True,
            "privateLoopbackOnly": True,
            "socketOnly": False,
            "listenAddresses": "127.0.0.1",
            "networkEvidenceSha256": result["networkEvidenceSha256"],
            "status": "passed",
            "caseIds": [case["id"] for case in cases],
            "counts": counts,
        },
        "receiptHashes": {
            INPUT_PG_RESULT: BASE.sha256(result_bytes),
            INPUT_PG_SHUTDOWN: BASE.sha256(shutdown_bytes),
        },
        "limits": PROOF_LIMITS,
    }


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    _validate_selected_source_delta(args.git, commit)
    baseline_files, old_department = _read_baseline(args.prealpha25_zip)
    result_bytes, result = _read_json_file(args.actual_pg_result, INPUT_PG_RESULT)
    shutdown_bytes, shutdown = _read_json_file(args.actual_pg_shutdown, INPUT_PG_SHUTDOWN)
    allowed = _validate_source_allowlist(BASE.source_allowlist(args.git, commit))
    summary = _validate_actual_pg_evidence(
        result, shutdown, result_bytes, shutdown_bytes, commit, args.git, allowed, old_department,
    )

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    return commit, baseline_files, old_department, summary, allowed, read_source


def _proof_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    proof_bytes: bytes,
    proof: dict,
) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "kind": "department-test-proof-package-provenance",
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sourceCommit": BASELINE_SOURCE_COMMIT, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "retainedPayloadPins": {
            "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
            "pcvCompanion": {"member": PCV_MEMBER, "version": P24.PCV_VERSION},
            "mmp": {"member": MMP_MEMBER, "version": P23.MMP_VERSION, "sha256": MMP_SHA256},
        },
        "departmentDpkgDelta": sorted(DEPARTMENT_UPDATED_MEMBERS),
        "changedMo2Members": sorted({BASELINE_DPKG_MEMBER, DPKG_MEMBER}),
        "unchangedMo2Members": 21,
        "owner": {
            "version": proof["ownerVersion"],
            "commit": proof["ownerCommit"],
            "closurePins": proof["ownerClosurePins"],
        },
        "testProof": {
            "name": OUTPUT_PROOF_SUMMARY,
            "sha256": BASE.sha256(proof_bytes),
        },
    }
    return BASE.stable_zip_bytes({
        OUTPUT_PROOF_SUMMARY: proof_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.26 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.26 output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if {entry.name for entry in entries} != OUTPUT_FILE_NAMES or any(entry.is_symlink() or not entry.is_file() for entry in entries):
        raise PackageError("prealpha.26 output must contain only three ZIPs and SHA256SUMS.txt")
    return {entry.name: entry.read_bytes() for entry in entries}


def audit_output(args) -> None:
    commit, baseline_files, old_department, proof, allowed, read_source = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    department_package, verified_department = _build_department_package(old_department, read_source)
    if verified_department != P24._audit_department_component(mod_files[DPKG_MEMBER], VERSION):
        raise PackageError("prealpha.26 Department package differs from the committed source assembly")
    if mod_files != _assemble_mod(baseline_files, department_package):
        raise PackageError("prealpha.26 MO2 archive differs from the canonical Department-only assembly")

    source_zip = BASE.build_source_zip(args.git, commit, allowed)
    BASE.require_canonical_bytes("prealpha.26 source ZIP", outputs[OUTPUT_SOURCE_ZIP], source_zip)
    proof_bytes = BASE.json_bytes(proof)
    expected_proof = _proof_zip(commit, outputs[OUTPUT_MOD_ZIP], outputs[OUTPUT_SOURCE_ZIP], department_package, proof_bytes, proof)
    BASE.require_canonical_bytes("prealpha.26 TEST2 proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    commit, baseline_files, old_department, proof, allowed, read_source = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, _department = _build_department_package(old_department, read_source)
    mod_zip = BASE.stable_zip_bytes(_assemble_mod(baseline_files, department_package))
    source_zip = BASE.build_source_zip(args.git, commit, allowed)
    proof_bytes = BASE.json_bytes(proof)
    proof_zip = _proof_zip(commit, mod_zip, source_zip, department_package, proof_bytes, proof)
    outputs = {OUTPUT_MOD_ZIP: mod_zip, OUTPUT_SOURCE_ZIP: source_zip, OUTPUT_PROOF_ZIP: proof_zip}
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, outputs)
        audit_args = argparse.Namespace(
            git=args.git,
            source_commit=commit,
            prealpha25_zip=args.prealpha25_zip,
            actual_pg_result=args.actual_pg_result,
            actual_pg_shutdown=args.actual_pg_shutdown,
            output_dir=stage,
        )
        audit_output(audit_args)
        os.rename(stage, output)
    except Exception:
        if stage.exists():
            shutil.rmtree(stage)
        raise


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    for command in ("build", "audit"):
        sub = subparsers.add_parser(command)
        sub.add_argument("--prealpha25-zip", required=True, type=Path)
        sub.add_argument("--actual-pg-result", required=True, type=Path)
        sub.add_argument("--actual-pg-shutdown", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
        sub.add_argument("--git", default="git")
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        if args.command == "build":
            build(args)
            print(f"Built and audited: {args.output_dir.resolve()}")
        else:
            audit_output(args)
            print(f"Audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, zipfile.BadZipFile, ValueError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
