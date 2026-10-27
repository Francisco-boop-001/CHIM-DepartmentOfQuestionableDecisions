#!/usr/bin/env python3
"""Build and audit prealpha.23 from the exact published prealpha.22 installable."""

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


_P22_PATH = Path(__file__).with_name("build-installable-prealpha22.py")
_P22_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha22_for23", _P22_PATH)
if _P22_SPEC is None or _P22_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.22 packaging helper")
P22 = importlib.util.module_from_spec(_P22_SPEC)
_P22_SPEC.loader.exec_module(P22)

P21 = P22.P21
P20 = P22.P20
P15 = P22.P15
BASE = P22.BASE
PackageError = P22.PackageError
ROOT = P22.ROOT

VERSION = "0.1.0-prealpha.23"
BASELINE_VERSION = P22.VERSION
BASELINE_SHA256 = "688c6bd761031902d5017a6593b2ff50e13a191f154d7950708adefdffe94bfd"
BASELINE_ZIP_TIMESTAMP = P22.ZIP_TIMESTAMP
ZIP_TIMESTAMP = (2026, 10, 27, 0, 0, 12)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = P22.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
BASELINE_MMP_VERSION = "0.1.0-dev.3"
MMP_VERSION = "0.1.0-dev.4"
BASELINE_MMP_MEMBER = f"CHIM/server-plugins/brainwash/{BASELINE_MMP_VERSION}.dwpkg"
MMP_MEMBER = f"CHIM/server-plugins/brainwash/{MMP_VERSION}.dwpkg"
BRAINWASH_CONFIG_URL = "../ext/brainwash/index.php"
# Set only after the owner candidate is approved and its complete DWPkg is pinned.
MMP_PACKAGE_SHA256: str | None = "9f1d8221954a293116b5bc7c202f13c4eb2062982d3fdabeb9134dfa0e05cec8"
PCV_MEMBER = P22.PCV_MEMBER
DLL_MEMBER = P22.DLL_MEMBER
DLL_SHA256 = P22.DLL_SHA256
EXPECTED_BASELINE_MEMBERS = (
    set(P22.EXPECTED_BASELINE_MEMBERS)
    - {P22.BASELINE_DPKG_MEMBER}
    | {P22.DPKG_MEMBER}
)
EXPECTED_INSTALLABLE_MEMBERS = (
    EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER, BASELINE_MMP_MEMBER}
    | {DPKG_MEMBER, MMP_MEMBER}
)
DEPARTMENT_EXPECTED_MEMBERS = set(P22.DEPARTMENT_EXPECTED_MEMBERS)
PCV_EXPECTED_MEMBERS = set(P22.PCV_EXPECTED_MEMBERS)
SERVER_SOURCE_PATHS = tuple(P22.SERVER_SOURCE_PATHS)
DEPARTMENT_SOURCE_UPDATES = {
    "server/adapters/runtime_support.php",
    "server/adapters/pcv.php",
    "server/adapters/mp.php",
    "server/adapters/brainwash.php",
}
DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    *DEPARTMENT_SOURCE_UPDATES,
    "checksums.sha256",
}

INPUT_HTTP_RECEIPT = "normal-chim-http.json"
INPUT_NATIVE_RECEIPT = "native-wire.json"
INPUT_ACCEPTANCE_RECEIPT = "compatibility-checks.json"
PROOF_INPUT_NAMES = {
    INPUT_ACCEPTANCE_RECEIPT,
    INPUT_HTTP_RECEIPT,
    INPUT_NATIVE_RECEIPT,
}
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-normal-chim-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}

REQUIRED_SOURCE_PATHS = set(P22.REQUIRED_SOURCE_PATHS) | {
    "README.md",
    "docs/BUILD.md",
    "docs/NORMAL-CHIM-COMPATIBILITY.md",
    "docs/VERIFICATION.md",
    "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
    "packaging/build-installable-prealpha23.py",
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha23.py",
    "server/tests/pcv/core_main_guard.php",
    "ui/app.js",
}
UI_SOURCE_PATH = "ui/app.js"
UI_MEMBER_SUFFIX = ("PrismaUI", "views", "WorldOfDramaLlama", "app.js")

HOST_MAIN_SHA256 = "374085e7190495e5372d8604f1d8ecbe7bbe7fda8ec76a238eb7b81fd19a08ec"
SHARMAT_COMMIT = "d6c6da595eb961f338245d6e4d044fe6e86279f0"
HTTP_SOURCE_HASH_PATHS = {
    "server/adapters/runtime_support.php",
    "server/adapters/pcv.php",
    "server/adapters/mp.php",
    "server/adapters/brainwash.php",
}
OWNER_VERSIONS = {
    "pcv": "0.1.24",
    "mp": "0.1.30",
    "sas": "0.1.12",
    "correspondence": "0.1.0-dev.27",
    "brainwash": MMP_VERSION,
}
HTTP_CHECK_NAMES = {
    "ordinaryNoPas",
    "noActiveProfile",
    "noCoreCharacterId",
    "automaticClaimApproved",
    "unauthenticatedBootstrap",
    "authenticatedAllFive",
    "pcvRead",
    "mpRead",
    "sasRead",
    "correspondenceRead",
    "brainwashRead",
    "managedIsolation",
    "pendingRefused",
    "ambiguousRefused",
    "corruptManagedRefused",
    "staleContextRefused",
    "restoreFenceRefused",
}
HTTP_LIMITS = {
    "fixtureDatabase": True,
    "existingCampaignDatabase": False,
    "http": True,
    "provider": False,
    "skyrim": False,
    "live": False,
    "installedCoreMutation": False,
}
NATIVE_CHECK_NAMES = {
    "unauthenticatedAccepted",
    "authenticatedAllFiveAccepted",
    "invalidResponseRefused",
    "uiConsumerAccepted",
}
AGGREGATE_CHECK_NAMES = {"rootReview", "targetedTests", "ownerCompatibility"}


def _json_object(data: bytes, label: str) -> dict:
    return P22._json_object(data, label)


def _require_sha256(value: object, label: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[a-f0-9]{64}", value) is None:
        raise PackageError(f"{label} must be a lowercase SHA-256 digest")
    return value


def _ui_app_member(files) -> str:
    candidates = [
        path for path in files
        if tuple(path.split("/"))[-len(UI_MEMBER_SUFFIX):] == UI_MEMBER_SUFFIX
    ]
    if len(candidates) != 1:
        raise PackageError("prealpha.22 ZIP must contain exactly one Department PrismaUI app.js member")
    return candidates[0]


def _read_brainwash_component(data: bytes, version: str) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, f"Brainwash {version} DWPkg")
    if "manifest.json" not in files or "server/manifest.json" not in files or "checksums.sha256" not in files:
        raise PackageError("Brainwash DWPkg is missing its manifests or checksum closure")
    checksums = BASE.parse_checksums(files["checksums.sha256"], f"Brainwash {version} DWPkg")
    payload = {path: content for path, content in files.items() if path != "checksums.sha256"}
    if set(checksums) != set(payload) or any(
        checksums[path] != BASE.sha256(content) for path, content in payload.items()
    ):
        raise PackageError("Brainwash DWPkg checksum closure is incomplete or mismatched")
    outer = _json_object(files["manifest.json"], f"Brainwash {version} outer manifest")
    inner = _json_object(files["server/manifest.json"], f"Brainwash {version} server manifest")
    if (
        type(outer.get("schema_version")) is not int
        or outer.get("schema_version") != 4
        or outer.get("name") != "brainwash"
        or outer.get("version") != version
        or not isinstance(outer.get("server"), dict)
        or outer["server"].get("mutable_paths") != []
    ):
        raise PackageError(f"Brainwash {version} outer identity/version mismatch")
    if (
        type(inner.get("schema_version")) is not int
        or inner.get("schema_version") != 2
        or inner.get("name") != "brainwash"
        or inner.get("version") != version
        or inner.get("config_url") != BRAINWASH_CONFIG_URL
    ):
        raise PackageError(f"Brainwash {version} server identity/version mismatch")
    return files


def _brainwash_server_tree_hash(data: bytes) -> str:
    files = _read_brainwash_component(data, MMP_VERSION)
    server_files = {
        path.removeprefix("server/"): content
        for path, content in files.items()
        if path.startswith("server/")
    }
    if not server_files:
        raise PackageError("Brainwash dev.4 DWPkg has no server source tree")
    framed = b"".join(
        path.encode("utf-8") + b"\0" + BASE.sha256(content).encode("ascii") + b"\n"
        for path, content in sorted(server_files.items())
    )
    return BASE.sha256(framed)


def _read_approved_mmp(path: Path) -> bytes:
    if MMP_PACKAGE_SHA256 is None:
        raise PackageError("Malpractice Makes Perfect dev.4 candidate approval and package pin are pending")
    data = P15._regular_file(path, "approved Malpractice Makes Perfect dev.4 DWPkg")
    if BASE.sha256(data) != MMP_PACKAGE_SHA256:
        raise PackageError("Malpractice Makes Perfect DWPkg differs from the approved dev.4 package pin")
    _read_brainwash_component(data, MMP_VERSION)
    return data


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.22 archive")
    files = BASE.read_zip_bytes(data, "prealpha.22 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.22 baseline member list differs")
    _ui_app_member(files)
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.22 baseline DLL differs from the accepted native payload")
    department = P21._audit_department_component(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION)
    pcv = P20._audit_component(files[PCV_MEMBER], "pcv_companion", P22.P21.BASELINE_PCV_VERSION)
    mmp = _read_brainwash_component(files[BASELINE_MMP_MEMBER], BASELINE_MMP_VERSION)
    if len(department) != 30 or len(pcv) != 6 or len(mmp) != 12:
        raise PackageError("prealpha.22 baseline component member counts differ")
    return files, department, pcv, mmp


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.22 baseline ZIP")
    return _read_baseline_bytes(data)


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError) as error:
        raise PackageError(f"required prealpha.23 source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required prealpha.23 source is not bytes: {path}")
    return data


def _assert_mod_delta(
    baseline_files: dict[str, bytes], mod_files: dict[str, bytes], ui_app_bytes: bytes
) -> str:
    if set(mod_files) != EXPECTED_INSTALLABLE_MEMBERS or len(mod_files) != 22:
        raise PackageError("prealpha.23 MO2 member set differs from the fixed 22-member layout")
    ui_member = _ui_app_member(baseline_files)
    if not isinstance(ui_app_bytes, bytes) or not ui_app_bytes:
        raise PackageError("selected committed ui/app.js source is missing or empty")
    if mod_files.get(ui_member) != ui_app_bytes:
        raise PackageError("prealpha.23 UI app.js differs from the selected committed source")
    if baseline_files[ui_member] == ui_app_bytes:
        raise PackageError("prealpha.23 UI app.js did not change from prealpha.22")
    common = set(baseline_files) & set(mod_files)
    changed_common = {
        path for path in common if baseline_files[path] != mod_files[path]
    }
    if changed_common != {ui_member}:
        raise PackageError("prealpha.23 changed an unrelated MO2 payload beyond the UI app.js update")
    unchanged = {path for path in common if baseline_files[path] == mod_files[path]}
    if len(unchanged) != 19:
        raise PackageError("prealpha.23 must preserve exactly 19 byte-identical MO2 members")
    return ui_member


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.22 Department outer manifest")
    old_outer = dict(outer)
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    for source_path in SERVER_SOURCE_PATHS:
        member = "server/" + source_path.removeprefix("server/")
        current[member] = _required_source(read_source, source_path)

    source_globals = _required_source(read_source, "server/globals.php")
    if source_globals != old_package["server/globals.php"]:
        raise PackageError("selected source changes the prealpha.22 Department globals bytes")

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.22 Department server manifest")
    inner_bytes = current["server/manifest.json"]
    inner = _json_object(inner_bytes, "prealpha.23 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = P21._audit_department_component(package, VERSION)
    new_outer, _new_inner = P21.P18._component_manifest(verified, "questionable_decisions", VERSION)
    old_outer["version"] = VERSION
    if old_outer != new_outer:
        raise PackageError("Department outer manifest changed beyond its version")

    changed = {path for path in old_package.keys() | verified.keys() if old_package.get(path) != verified.get(path)}
    if changed != DEPARTMENT_UPDATED_MEMBERS:
        raise PackageError("Department DWPkg changed outside the fixed prealpha.23 runtime delta")
    return package, verified


def _assemble_mod(
    base_files: dict[str, bytes],
    department_package: bytes,
    mmp_package: bytes,
    ui_app_bytes: bytes,
) -> dict[str, bytes]:
    if MMP_PACKAGE_SHA256 is None:
        raise PackageError("Malpractice Makes Perfect dev.4 candidate approval and package pin are pending")
    if BASE.sha256(mmp_package) != MMP_PACKAGE_SHA256:
        raise PackageError("Malpractice Makes Perfect DWPkg differs from the approved dev.4 package pin")
    if set(base_files) != EXPECTED_BASELINE_MEMBERS or len(base_files) != 22:
        raise PackageError("prealpha.22 baseline member set differs")
    if department_package == base_files[BASELINE_DPKG_MEMBER]:
        raise PackageError("prealpha.23 must replace the Department DWPkg")
    if mmp_package == base_files[BASELINE_MMP_MEMBER]:
        raise PackageError("prealpha.23 must replace Brainwash dev.3 with the approved dev.4 DWPkg")
    _read_brainwash_component(mmp_package, MMP_VERSION)
    files = {
        path: data for path, data in base_files.items()
        if path not in {BASELINE_DPKG_MEMBER, BASELINE_MMP_MEMBER}
    }
    files[DPKG_MEMBER] = department_package
    files[MMP_MEMBER] = mmp_package
    ui_member = _ui_app_member(base_files)
    files[ui_member] = ui_app_bytes
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("assembled prealpha.23 MO2 member set differs from the fixed 22-member layout")
    _assert_mod_delta(base_files, files, ui_app_bytes)
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.23 changed the accepted native DLL bytes")
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    if MMP_PACKAGE_SHA256 is None:
        raise PackageError("Malpractice Makes Perfect dev.4 candidate approval and package pin are pending")
    files = BASE.read_zip_bytes(data, "prealpha.23 MO2 archive")
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.23 MO2 member list differs from the fixed 22-member layout")
    _ui_app_member(files)
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.23 DLL does not match the accepted prealpha.22 bytes")
    P21._audit_department_component(files[DPKG_MEMBER], VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", P22.P21.BASELINE_PCV_VERSION)
    if BASE.sha256(files[MMP_MEMBER]) != MMP_PACKAGE_SHA256:
        raise PackageError("prealpha.23 Brainwash DWPkg differs from the approved dev.4 package pin")
    _read_brainwash_component(files[MMP_MEMBER], MMP_VERSION)
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.23 MO2 archive is not the canonical timestamped ZIP")
    return files


def _read_proof_inputs(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("normal CHIM proof directory is missing or unsafe")
    entries = list(directory.iterdir())
    if (
        {entry.name for entry in entries} != PROOF_INPUT_NAMES
        or any(entry.is_symlink() or not entry.is_file() for entry in entries)
    ):
        raise PackageError("normal CHIM proof directory must contain only the three fixed receipt files")
    if any(entry.stat().st_size > 1024 * 1024 for entry in entries):
        raise PackageError("normal CHIM sanitized receipt files must not exceed 1 MiB")
    files = {entry.name: entry.read_bytes() for entry in entries}
    for name, data in files.items():
        receipt = _json_object(data, name)
        if data != BASE.json_bytes(receipt):
            raise PackageError(f"{name} must use canonical UTF-8 JSON with LF and no BOM")
    return files


def _validate_acceptance_receipts(
    proof_files: dict[str, bytes], commit: str, git: str, mmp_package: bytes
) -> dict:
    if set(proof_files) != PROOF_INPUT_NAMES:
        raise PackageError("normal CHIM proof files differ from the fixed three-receipt contract")

    http_bytes = proof_files[INPUT_HTTP_RECEIPT]
    native_bytes = proof_files[INPUT_NATIVE_RECEIPT]
    aggregate_bytes = proof_files[INPUT_ACCEPTANCE_RECEIPT]
    http = _json_object(http_bytes, INPUT_HTTP_RECEIPT)
    native = _json_object(native_bytes, INPUT_NATIVE_RECEIPT)
    aggregate = _json_object(aggregate_bytes, INPUT_ACCEPTANCE_RECEIPT)
    for name, data, receipt in (
        (INPUT_HTTP_RECEIPT, http_bytes, http),
        (INPUT_NATIVE_RECEIPT, native_bytes, native),
        (INPUT_ACCEPTANCE_RECEIPT, aggregate_bytes, aggregate),
    ):
        if data != BASE.json_bytes(receipt):
            raise PackageError(f"{name} must use canonical UTF-8 JSON with LF and no BOM")

    http_fields = {
        "schemaVersion",
        "departmentSourceHashes",
        "hostMainSha256",
        "sharmatCommit",
        "ownerVersions",
        "ownerSourceTreeHashes",
        "checks",
        "rawEvidenceSha256",
        "limits",
    }
    if set(http) != http_fields:
        raise PackageError("normal CHIM HTTP receipt fields differ from the fixed schema")
    if type(http["schemaVersion"]) is not int or http["schemaVersion"] != 1:
        raise PackageError("normal CHIM HTTP receipt must use schema version 1")
    if http["hostMainSha256"] != HOST_MAIN_SHA256 or http["sharmatCommit"] != SHARMAT_COMMIT:
        raise PackageError("normal CHIM HTTP receipt differs from the captured host or frozen SHARMAT source")

    source_hashes = http["departmentSourceHashes"]
    if not isinstance(source_hashes, dict) or set(source_hashes) != HTTP_SOURCE_HASH_PATHS:
        raise PackageError("normal CHIM HTTP receipt must bind the exact four changed Department sources")
    for path, digest in source_hashes.items():
        _require_sha256(digest, f"Department source hash {path}")
        try:
            committed = BASE.git_blob(git, commit, path)
        except (KeyError, OSError, subprocess.SubprocessError) as error:
            raise PackageError(f"normal CHIM HTTP source hash path is unavailable in the selected commit: {path}") from error
        if not isinstance(committed, bytes):
            raise PackageError(f"normal CHIM HTTP source hash path is not bytes in the selected commit: {path}")
        if BASE.sha256(committed) != digest:
            raise PackageError(f"normal CHIM HTTP receipt source hash differs from the selected commit: {path}")

    if not isinstance(http["ownerVersions"], dict) or http["ownerVersions"] != OWNER_VERSIONS:
        raise PackageError("normal CHIM HTTP receipt owner versions differ from the fixed five-owner set")
    owner_hashes = http["ownerSourceTreeHashes"]
    if not isinstance(owner_hashes, dict) or set(owner_hashes) != set(OWNER_VERSIONS):
        raise PackageError("normal CHIM HTTP receipt must bind exactly five owner source-tree hashes")
    for name, digest in owner_hashes.items():
        _require_sha256(digest, f"{name} owner source-tree hash")
    if owner_hashes["brainwash"] != _brainwash_server_tree_hash(mmp_package):
        raise PackageError("normal CHIM HTTP Brainwash owner tree hash differs from the approved dev.4 DWPkg server tree")

    checks = http["checks"]
    if (
        not isinstance(checks, dict)
        or set(checks) != HTTP_CHECK_NAMES
        or any(type(value) is not bool or value is not True for value in checks.values())
    ):
        raise PackageError("normal CHIM HTTP receipt must pass every fixed normal-mode and owner-read check")
    _require_sha256(http["rawEvidenceSha256"], "normal CHIM HTTP raw evidence hash")
    limits = http["limits"]
    if (
        not isinstance(limits, dict)
        or set(limits) != set(HTTP_LIMITS)
        or any(type(value) is not bool for value in limits.values())
        or limits != HTTP_LIMITS
    ):
        raise PackageError("normal CHIM HTTP receipt claims evidence outside the fixed test limits")

    native_fields = {"schemaVersion", "dllSha256", "uiAppSha256", "checks", "rawEvidenceSha256"}
    if set(native) != native_fields:
        raise PackageError("native-wire receipt fields differ from the fixed schema")
    if type(native["schemaVersion"]) is not int or native["schemaVersion"] != 1:
        raise PackageError("native-wire receipt must use schema version 1")
    if _require_sha256(native["dllSha256"], "native-wire DLL hash") != DLL_SHA256:
        raise PackageError("native-wire receipt DLL differs from the unchanged prealpha.22 payload")
    try:
        committed_ui_app = BASE.git_blob(git, commit, UI_SOURCE_PATH)
    except (KeyError, OSError, subprocess.SubprocessError) as error:
        raise PackageError("selected committed UI app source is unavailable") from error
    if not isinstance(committed_ui_app, bytes):
        raise PackageError("selected committed UI app source is not bytes")
    if _require_sha256(native["uiAppSha256"], "native-wire UI app hash") != BASE.sha256(committed_ui_app):
        raise PackageError("native-wire receipt UI app hash differs from the selected committed source")
    native_checks = native["checks"]
    if (
        not isinstance(native_checks, dict)
        or set(native_checks) != NATIVE_CHECK_NAMES
        or any(type(value) is not bool or value is not True for value in native_checks.values())
    ):
        raise PackageError("native-wire receipt must pass every fixed validator and UI-consumer check")
    _require_sha256(native["rawEvidenceSha256"], "native-wire raw evidence hash")

    aggregate_fields = {
        "schemaVersion",
        "sourceCommit",
        "httpSummarySha256",
        "nativeSummarySha256",
        "checks",
    }
    if set(aggregate) != aggregate_fields:
        raise PackageError("normal CHIM aggregate receipt fields differ from the fixed schema")
    if type(aggregate["schemaVersion"]) is not int or aggregate["schemaVersion"] != 1:
        raise PackageError("normal CHIM aggregate receipt must use schema version 1")
    if aggregate["sourceCommit"] != commit:
        raise PackageError("normal CHIM aggregate receipt does not name the selected Department source commit")
    if _require_sha256(aggregate["httpSummarySha256"], "HTTP summary hash") != BASE.sha256(http_bytes):
        raise PackageError("normal CHIM aggregate receipt does not bind the exact HTTP summary")
    if _require_sha256(aggregate["nativeSummarySha256"], "native summary hash") != BASE.sha256(native_bytes):
        raise PackageError("normal CHIM aggregate receipt does not bind the exact native summary")
    aggregate_checks = aggregate["checks"]
    if (
        not isinstance(aggregate_checks, dict)
        or set(aggregate_checks) != AGGREGATE_CHECK_NAMES
        or any(type(value) is not bool or value is not True for value in aggregate_checks.values())
    ):
        raise PackageError("normal CHIM aggregate receipt must pass root review and focused compatibility gates")
    return aggregate


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    baseline_files, old_department, old_pcv, _old_mmp = _read_baseline(args.prealpha22_zip)
    mmp_package = _read_approved_mmp(args.mmp_dwpkg)
    proof_files = _read_proof_inputs(args.normal_chim_proof_dir)
    receipt = _validate_acceptance_receipts(proof_files, commit, args.git, mmp_package)
    allowlist = BASE.source_allowlist(args.git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required candidate paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    ui_app_bytes = _required_source(read_source, UI_SOURCE_PATH)
    return commit, baseline_files, old_department, old_pcv, mmp_package, proof_files, receipt, allowlist, read_source, ui_app_bytes


def _proof_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    mmp_package: bytes,
    ui_app_bytes: bytes,
    proof_files: dict[str, bytes],
    receipt: dict,
) -> bytes:
    http = _json_object(proof_files[INPUT_HTTP_RECEIPT], INPUT_HTTP_RECEIPT)
    native = _json_object(proof_files[INPUT_NATIVE_RECEIPT], INPUT_NATIVE_RECEIPT)
    provenance = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "brainwashPackage": {"member": MMP_MEMBER, "sha256": BASE.sha256(mmp_package)},
        "uiApp": {"member": _ui_app_member(EXPECTED_BASELINE_MEMBERS), "sha256": BASE.sha256(ui_app_bytes)},
        "proofReceipts": {
            name: BASE.sha256(data) for name, data in sorted(proof_files.items())
        },
        "acceptance": {
            "sourceCommit": receipt["sourceCommit"],
            "checks": receipt["checks"],
            "httpChecks": http["checks"],
            "nativeChecks": native["checks"],
            "httpSummarySha256": receipt["httpSummarySha256"],
            "nativeSummarySha256": receipt["nativeSummarySha256"],
            "limits": http["limits"],
        },
        "departmentDpkgDelta": sorted(DEPARTMENT_UPDATED_MEMBERS),
        "unchangedMo2Members": 19,
        "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
    }
    return BASE.stable_zip_bytes({
        **proof_files,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.23 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.23 output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(directory.iterdir())
    if ({entry.name for entry in entries} != expected
            or any(entry.is_symlink() or not entry.is_file() for entry in entries)):
        raise PackageError("prealpha.23 output must contain only three ZIPs and SHA256SUMS.txt")
    return {entry.name: entry.read_bytes() for entry in entries}


def audit_output(args) -> None:
    (
        commit, baseline_files, old_department, _old_pcv, mmp_package,
        proof_files, receipt, allowlist, read_source, ui_app_bytes,
    ) = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    _assert_mod_delta(baseline_files, mod_files, ui_app_bytes)
    department_package, verified_department = _build_department_package(old_department, read_source)
    if verified_department != P21._audit_department_component(mod_files[DPKG_MEMBER], VERSION):
        raise PackageError("prealpha.23 Department package differs from the canonical source assembly")
    if mod_files != _assemble_mod(baseline_files, department_package, mmp_package, ui_app_bytes):
        raise PackageError("prealpha.23 MO2 archive differs from the canonical two-package assembly")
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    BASE.require_canonical_bytes("prealpha.23 source ZIP", outputs[OUTPUT_SOURCE_ZIP], source_zip)
    expected_proof = _proof_zip(
        commit,
        outputs[OUTPUT_MOD_ZIP],
        outputs[OUTPUT_SOURCE_ZIP],
        department_package,
        mmp_package,
        ui_app_bytes,
        proof_files,
        receipt,
    )
    BASE.require_canonical_bytes("prealpha.23 normal CHIM proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    (
        commit, baseline_files, old_department, _old_pcv, mmp_package,
        proof_files, receipt, allowlist, read_source, ui_app_bytes,
    ) = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, _verified_department = _build_department_package(old_department, read_source)
    mod_zip = BASE.stable_zip_bytes(_assemble_mod(baseline_files, department_package, mmp_package, ui_app_bytes))
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _proof_zip(commit, mod_zip, source_zip, department_package, mmp_package, ui_app_bytes, proof_files, receipt)
    outputs = {
        OUTPUT_MOD_ZIP: mod_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PROOF_ZIP: proof_zip,
    }
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, outputs)
        audit_args = argparse.Namespace(
            git=args.git,
            source_commit=commit,
            prealpha22_zip=args.prealpha22_zip,
            mmp_dwpkg=args.mmp_dwpkg,
            normal_chim_proof_dir=args.normal_chim_proof_dir,
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
        sub.add_argument("--prealpha22-zip", required=True, type=Path)
        sub.add_argument("--mmp-dwpkg", required=True, type=Path)
        sub.add_argument("--normal-chim-proof-dir", required=True, type=Path)
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
