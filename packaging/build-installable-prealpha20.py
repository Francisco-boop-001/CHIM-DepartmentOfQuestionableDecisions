#!/usr/bin/env python3
"""Build and audit the paired prealpha.20 / PCV companion .11 candidate."""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

_P18_PATH = Path(__file__).with_name("build-installable-prealpha18.py")
_P18_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha18_for20", _P18_PATH)
if _P18_SPEC is None or _P18_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.18 packaging helper")
P18 = importlib.util.module_from_spec(_P18_SPEC)
_P18_SPEC.loader.exec_module(P18)

P15 = P18.P15
BASE = P18.BASE
PackageError = P18.PackageError
ROOT = P18.ROOT

VERSION = "0.1.0-prealpha.20"
PCV_VERSION = "0.1.0-prealpha.11"
OWNER_VERSION = "0.1.24"
OWNER_TAG = "private_conversation-v0.1.24"
# Pin the reviewed PCV owner revision; the paired package must not drift to another tag target.
OWNER_COMMIT = "ce0a998773237a9fc91f6914725a327d9e621718"
BASELINE_VERSION = "0.1.0-prealpha.19"
BASELINE_PCV_VERSION = "0.1.0-prealpha.10"
BASELINE_SHA256 = "d7481f55074025650628644df7e914ad78bc1f6cf1762faac3d3fac7a3e55a93"
BASELINE_ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 4)
DLL_SHA256 = P18.DLL_SHA256
ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 6)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = "CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.19.dwpkg"
BASELINE_PCV_MEMBER = "CHIM/server-plugins/pcv_companion/0.1.0-prealpha.10.dwpkg"
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = f"CHIM/server-plugins/pcv_companion/{PCV_VERSION}.dwpkg"
DLL_MEMBER = P18.DLL_MEMBER
INPUT_CHECKS = "paired-checks.json"
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-paired-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}

OWNER_SOURCE_MANIFEST = "packaging/companion-only/pcv-0.1.24-source-manifest.json"
OWNER_STAGE_DIRECTORY = "pcv-companion/private-conversation-v0.1.24/server"
OWNER_FILE_NAMES = (
    "installation_guard.php",
    "log.php",
    "manifest.json",
    "preprocessing.php",
    "reflection.php",
    "scope.php",
    "state.php",
)
STAGE_ROOT = P18.STAGE_ROOT
DEPARTMENT_ADAPTER = "server/adapters/pcv.php"
OVERLAY_SETUP = "server/lib/overlay_setup.php"

PROTECTED_PAYLOAD_PATHS = (
    "server/adapters/brainwash.php",
    "server/adapters/correspondence.php",
    "server/adapters/mp.php",
    "server/adapters/sas.php",
    "server/lib/protocol.php",
)
CHECK_ROWS = (
    ("ownerTimeouts", "pcv-owner-timeouts.json"),
    ("overlaidTimeouts", "pcv-overlay-timeouts.json"),
    ("overlayMigration", "overlay-migration-results.json"),
    ("pcvSourceContract", "pcv-source-contract.json"),
    ("otherPlugins", "other-plugin-compatibility.json"),
)
PROOF_LIMITS = {
    "isolatedTestOnly": True,
    "database": True,
    "http": False,
    "provider": False,
    "skyrim": False,
    "nativeBuild": False,
    "wholeRequestDeadline": False,
}

BASELINE_DEPARTMENT_MEMBER = P18.BASELINE_DEPARTMENT_MEMBER
BASELINE_PCV_BASE_MEMBER = P18.BASELINE_PCV_MEMBER
EXPECTED_BASELINE_MEMBERS = (
    set(P18.EXPECTED_BASELINE_MEMBERS)
    - {BASELINE_DEPARTMENT_MEMBER, BASELINE_PCV_BASE_MEMBER}
    | {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
)
SERVER_SOURCE_PATHS = tuple(P18.SERVER_SOURCE_PATHS)
PCV_COMPANION_SOURCES = tuple(P18.PCV_COMPANION_SOURCES)
DEPARTMENT_EXPECTED_MEMBERS = set(P18.DEPARTMENT_EXPECTED_MEMBERS)
PCV_EXPECTED_MEMBERS = set(P18.PCV_EXPECTED_MEMBERS)
PCV_OVERLAY_MEMBERS = {
    STAGE_ROOT + "source-manifest.json",
    *(STAGE_ROOT + name for name in OWNER_FILE_NAMES),
}
DEPARTMENT_EXPECTED_MEMBERS = (
    DEPARTMENT_EXPECTED_MEMBERS
    - P18.PCV_OVERLAY_MEMBERS
    | PCV_OVERLAY_MEMBERS
)
DEPARTMENT_ALLOWED_CHANGES = {
    "manifest.json",
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    OVERLAY_SETUP,
    "checksums.sha256",
    *PCV_OVERLAY_MEMBERS,
}
PCV_ALLOWED_CHANGES = {
    "manifest.json",
    "server/manifest.json",
    "server/companion.php",
    "server/preprocessing.php",
    "checksums.sha256",
}

SOURCE_HASH_PATHS = tuple(sorted({
    "packaging/build-installable-prealpha20.py",
    "packaging/companion-only/PCV-PROVENANCE.md",
    OWNER_SOURCE_MANIFEST,
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha20.py",
    "pcv-companion/companion.php",
    "pcv-companion/manifest.json",
    "pcv-companion/preprocessing.php",
    *(f"{OWNER_STAGE_DIRECTORY}/{name}" for name in OWNER_FILE_NAMES),
    DEPARTMENT_ADAPTER,
    OVERLAY_SETUP,
    "server/manifest.json",
    "server/tests/onboarding/bootstrap-overlay.php",
    "server/tests/onboarding/overlay.php",
    "server/tests/pcv/adapter.php",
    "server/tests/pcv/companion_owner_seam.php",
    "server/tests/pcv/companion_presence_reader.php",
    "server/tests/pcv/companion_store.php",
    "server/tests/pcv/owner_source_contract.php",
    "server/tests/pcv/runtime_support_limits.php",
}))
REQUIRED_SOURCE_PATHS = set(SOURCE_HASH_PATHS) | {
    "README.md",
    "docs/BUILD.md",
    "docs/PCV24-COMPATIBILITY.md",
    "docs/VERIFICATION.md",
    "docs/superpowers/plans/2026-10-26-pcv24-paired.md",
    "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
}

_HEX_SHA256 = re.compile(r"\A[a-f0-9]{64}\Z")
_HEX_COMMIT = re.compile(r"\A[0-9a-f]{40}\Z")


def _json_object(data: bytes, label: str) -> dict:
    def reject_duplicate_keys(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError(f"duplicate JSON key: {key}")
            result[key] = value
        return result

    try:
        value = json.loads(data.decode("utf-8"), object_pairs_hook=reject_duplicate_keys)
    except (UnicodeDecodeError, json.JSONDecodeError, ValueError) as error:
        raise PackageError(f"{label} is not valid unambiguous UTF-8 JSON") from error
    if not isinstance(value, dict):
        raise PackageError(f"{label} must be a JSON object")
    return value


def _require_sha256(value: object, label: str) -> str:
    if not isinstance(value, str) or _HEX_SHA256.fullmatch(value) is None:
        raise PackageError(f"{label} must be a lowercase SHA-256 digest")
    return value


def _owner_stage(read_source) -> tuple[bytes, dict[str, bytes], dict]:
    manifest_bytes = read_source(OWNER_SOURCE_MANIFEST)
    manifest = _json_object(manifest_bytes, "PCV 0.1.24 source manifest")
    if set(manifest) != {
        "schemaVersion", "upstream", "stagedSourceDirectory", "installDirectory", "files"
    }:
        raise PackageError("PCV 0.1.24 source manifest fields differ from the fixed schema")
    if (type(manifest.get("schemaVersion")) is not int or manifest["schemaVersion"] != 1
            or manifest.get("stagedSourceDirectory") != OWNER_STAGE_DIRECTORY
            or manifest.get("installDirectory") != "ext/private_conversation"):
        raise PackageError("PCV source manifest does not identify the approved 0.1.24 stage")
    upstream = manifest.get("upstream")
    if not isinstance(upstream, dict) or set(upstream) != {"version", "tag", "commit"}:
        raise PackageError("PCV source manifest upstream identity is malformed")
    if (upstream.get("version") != OWNER_VERSION or upstream.get("tag") != OWNER_TAG
            or not isinstance(upstream.get("commit"), str)
            or _HEX_COMMIT.fullmatch(upstream["commit"]) is None):
        raise PackageError("PCV source manifest does not identify the exact 0.1.24 owner")
    if OWNER_COMMIT is None or upstream["commit"] != OWNER_COMMIT:
        raise PackageError("the frozen PCV 0.1.24 owner commit is not pinned in this builder")

    entries = manifest.get("files")
    if (not isinstance(entries, list) or len(entries) != len(OWNER_FILE_NAMES)
            or [entry.get("path") for entry in entries if isinstance(entry, dict)] != list(OWNER_FILE_NAMES)):
        raise PackageError("PCV source manifest must list the exact ordered seven-file owner closure")
    stage_files: dict[str, bytes] = {}
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {
            "path", "baseSha256", "postimageSha256", "modified"
        }:
            raise PackageError("PCV source manifest file entry is malformed")
        relative = entry["path"]
        if relative not in OWNER_FILE_NAMES:
            raise PackageError("PCV source manifest contains an unapproved owner file")
        base_pin = _require_sha256(entry["baseSha256"], f"PCV base pin {relative}")
        postimage_pin = _require_sha256(entry["postimageSha256"], f"PCV postimage pin {relative}")
        if type(entry["modified"]) is not bool or entry["modified"] is not (base_pin != postimage_pin):
            raise PackageError(f"PCV modified flag does not match its BASE-to-POSTIMAGE pins: {relative}")
        data = read_source(f"{OWNER_STAGE_DIRECTORY}/{relative}")
        if BASE.sha256(data) != postimage_pin:
            raise PackageError(f"PCV staged postimage differs from its 0.1.24 source pin: {relative}")
        stage_files[relative] = data
    if set(stage_files) != set(OWNER_FILE_NAMES):
        raise PackageError("PCV source stage must contain exactly seven approved files")
    return manifest_bytes, stage_files, manifest


def _pin_maps(manifest: dict) -> tuple[dict[str, str], dict[str, str]]:
    entries = manifest["files"]
    return (
        {entry["path"]: entry["baseSha256"] for entry in entries},
        {entry["path"]: entry["postimageSha256"] for entry in entries},
    )


def _validate_paired_receipt(git: str, commit: str, data: bytes,
                             baseline_department: dict[str, bytes]) -> dict:
    receipt = _json_object(data, "paired checks receipt")
    if data != BASE.json_bytes(receipt):
        raise PackageError("paired checks receipt must use canonical UTF-8 JSON with LF and no BOM")
    if set(receipt) != {
        "schemaVersion", "kind", "sourceCommit", "baseline", "owner", "sourceHashes",
        "overlayMigration", "protectedPayload", "checks", "limits",
    }:
        raise PackageError("paired checks receipt fields differ from the fixed schema")
    if (type(receipt.get("schemaVersion")) is not int or receipt["schemaVersion"] != 1
            or receipt.get("kind") != "department-pcv-paired-prealpha20"
            or receipt.get("sourceCommit") != commit):
        raise PackageError("paired checks receipt does not identify the selected prealpha.20 source")
    if receipt.get("baseline") != {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256}:
        raise PackageError("paired checks receipt does not identify the exact prealpha.19 baseline")

    source_hashes = receipt["sourceHashes"]
    if not isinstance(source_hashes, dict) or set(source_hashes) != set(SOURCE_HASH_PATHS):
        raise PackageError("paired checks source hash paths differ from the fixed source closure")
    for path in SOURCE_HASH_PATHS:
        digest = _require_sha256(source_hashes[path], f"source hash {path}")
        if BASE.sha256(BASE.git_blob(git, commit, path)) != digest:
            raise PackageError(f"paired checks source hash differs from the selected commit: {path}")

    def read_committed(relative: str) -> bytes:
        return BASE.git_blob(git, commit, relative)

    manifest_bytes, _stage_files, manifest = _owner_stage(read_committed)
    owner = receipt["owner"]
    if not isinstance(owner, dict) or set(owner) != {
        "version", "tag", "commit", "manifestSha256", "basePins", "postimagePins"
    }:
        raise PackageError("paired checks owner identity is malformed")
    base_pins, postimage_pins = _pin_maps(manifest)
    expected_owner = {
        "version": OWNER_VERSION,
        "tag": OWNER_TAG,
        "commit": OWNER_COMMIT,
        "manifestSha256": BASE.sha256(manifest_bytes),
        "basePins": base_pins,
        "postimagePins": postimage_pins,
    }
    if owner != expected_owner or owner["commit"] != manifest["upstream"]["commit"]:
        raise PackageError("paired checks owner identity or exact BASE/POSTIMAGE pins differ from the source manifest")

    protected = receipt["protectedPayload"]
    if not isinstance(protected, dict) or set(protected) != set(PROTECTED_PAYLOAD_PATHS):
        raise PackageError("paired checks protected-payload path set differs from the fixed five-file contract")
    for path in PROTECTED_PAYLOAD_PATHS:
        digest = _require_sha256(protected[path], f"protected payload hash {path}")
        if path not in baseline_department or digest != BASE.sha256(baseline_department[path]):
            raise PackageError(f"protected payload hash does not match the prealpha.19 Department bytes: {path}")

    checks = receipt["checks"]
    if not isinstance(checks, list) or len(checks) != len(CHECK_ROWS):
        raise PackageError("paired checks receipt does not contain the exact five focused checks")
    for row, (name, evidence) in zip(checks, CHECK_ROWS):
        if not isinstance(row, dict) or set(row) != {"name", "status", "evidence", "receiptSha256"}:
            raise PackageError("paired check row is malformed")
        if row["name"] != name or row["status"] != "passed" or row["evidence"] != evidence:
            raise PackageError(f"paired check does not match its fixed evidence contract: {name}")
        _require_sha256(row["receiptSha256"], f"{name} receiptSha256")

    migration = receipt["overlayMigration"]
    if not isinstance(migration, dict) or set(migration) != {
        "legacyVersion", "activeVersion", "newManifestSha256", "receiptSha256"
    }:
        raise PackageError("paired overlay-migration binding is malformed")
    overlay_check = next(row for row in checks if row["name"] == "overlayMigration")
    if migration != {
        "legacyVersion": "0.1.23",
        "activeVersion": OWNER_VERSION,
        "newManifestSha256": BASE.sha256(manifest_bytes),
        "receiptSha256": overlay_check["receiptSha256"],
    }:
        raise PackageError("paired overlay-migration binding differs from the frozen source and migration receipt")

    limits = receipt["limits"]
    if (not isinstance(limits, dict) or set(limits) != set(PROOF_LIMITS)
            or any(type(value) is not bool for value in limits.values())
            or limits != PROOF_LIMITS):
        raise PackageError("paired checks claims behavior outside the recorded test limits")
    return receipt


def _audit_component(data: bytes, expected_name: str, expected_version: str) -> dict[str, bytes]:
    return P18._audit_component(data, expected_name, expected_version)


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.19 archive")
    files = BASE.read_zip_bytes(data, "prealpha.19 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.19 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.19 baseline DLL differs from the accepted native payload")
    department = _audit_component(files[BASELINE_DPKG_MEMBER], "questionable_decisions", BASELINE_VERSION)
    pcv = _audit_component(files[BASELINE_PCV_MEMBER], "pcv_companion", BASELINE_PCV_VERSION)
    if len(department) != 29 or len(pcv) != 6:
        raise PackageError("prealpha.19 baseline component member counts differ")
    return files, department, pcv


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.19 baseline ZIP")
    return _read_baseline_bytes(data)


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.19 Department outer manifest")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    for source_path in SERVER_SOURCE_PATHS:
        current["server/" + source_path.removeprefix("server/")] = read_source(source_path)

    source_manifest, stage_files, _manifest = _owner_stage(read_source)
    current[STAGE_ROOT + "source-manifest.json"] = source_manifest
    for name, data in stage_files.items():
        current[STAGE_ROOT + name] = data

    inner = _json_object(current["server/manifest.json"], "prealpha.20 Department server manifest")
    if inner.get("version") != VERSION:
        raise PackageError("committed Department server manifest does not name prealpha.20")
    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.19 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")

    payload = {path: data for path, data in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = _audit_component(package, "questionable_decisions", VERSION)
    P18._compare_component_update(
        old_package, verified, "questionable_decisions", VERSION, DEPARTMENT_ALLOWED_CHANGES
    )
    changed = {path for path in old_package if old_package[path] != verified[path]}
    required = {"manifest.json", "server/manifest.json", DEPARTMENT_ADAPTER, OVERLAY_SETUP, "checksums.sha256", STAGE_ROOT + "source-manifest.json"}
    if not required.issubset(changed) or not changed.issubset(DEPARTMENT_ALLOWED_CHANGES):
        raise PackageError("prealpha.20 Department DWPkg changed outside its fixed source and overlay closure")
    for path in PROTECTED_PAYLOAD_PATHS:
        if path not in old_package or old_package[path] != verified.get(path):
            raise PackageError(f"prealpha.20 changed a protected Department payload: {path}")
    return package, verified


def _build_pcv_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.10 PCV companion outer manifest")
    outer["version"] = PCV_VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    for source_path in PCV_COMPANION_SOURCES:
        current["server/" + source_path.rsplit("/", 1)[-1]] = read_source(source_path)

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.10 PCV companion server manifest")
    inner = _json_object(current["server/manifest.json"], "prealpha.11 PCV companion server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = PCV_VERSION
    if inner != expected_inner:
        raise PackageError("PCV companion server manifest changed beyond its version")

    payload = {path: data for path, data in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = _audit_component(package, "pcv_companion", PCV_VERSION)
    P18._compare_component_update(old_package, verified, "pcv_companion", PCV_VERSION, PCV_ALLOWED_CHANGES)
    changed = {path for path in old_package if old_package[path] != verified[path]}
    required = {"manifest.json", "server/manifest.json", "server/companion.php", "checksums.sha256"}
    if not required.issubset(changed) or not changed.issubset(PCV_ALLOWED_CHANGES):
        raise PackageError("prealpha.11 PCV DWPkg changed outside its fixed source closure")
    return package, verified


def _assemble_mod(base_files: dict[str, bytes], department_package: bytes, pcv_package: bytes) -> dict[str, bytes]:
    if set(base_files) != EXPECTED_BASELINE_MEMBERS:
        raise PackageError("prealpha.19 baseline member set differs")
    if department_package == base_files[BASELINE_DPKG_MEMBER] or pcv_package == base_files[BASELINE_PCV_MEMBER]:
        raise PackageError("prealpha.20 must replace both versioned server packages")
    files = {
        path: content for path, content in base_files.items()
        if path not in {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
    }
    files[DPKG_MEMBER] = department_package
    files[PCV_MEMBER] = pcv_package
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}) | {DPKG_MEMBER, PCV_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("assembled prealpha.20 MO2 member set differs from prealpha.19")
    if any(base_files[path] != files[path] for path in set(base_files) & set(files)):
        raise PackageError("prealpha.20 changed an unrelated prealpha.19 MO2 payload")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.20 changed the accepted native DLL bytes")
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.20 MO2 archive")
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}) | {DPKG_MEMBER, PCV_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("prealpha.20 MO2 member list differs from prealpha.19")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.20 DLL does not match the accepted prealpha.19 bytes")
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != BASE.NOTICE_FILES:
        raise PackageError("MO2 archive does not include exactly the seven required notices")
    BASE.audit_ini(files["SKSE/Plugins/WorldOfDramaLlama.ini"])
    _audit_component(files[DPKG_MEMBER], "questionable_decisions", VERSION)
    _audit_component(files[PCV_MEMBER], "pcv_companion", PCV_VERSION)
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.20 MO2 archive is not the canonical timestamped ZIP")
    return files


def _proof_zip(commit: str, mod_zip: bytes, source_zip: bytes, department_package: bytes,
               pcv_package: bytes, receipt_bytes: bytes, receipt: dict) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "pcvPackage": {"member": PCV_MEMBER, "sha256": BASE.sha256(pcv_package)},
        "owner": {"version": OWNER_VERSION, "tag": OWNER_TAG, "commit": receipt["owner"]["commit"],
                  "manifestSha256": receipt["owner"]["manifestSha256"]},
        "unchangedMo2Members": 20,
        "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
        "pairedChecks": {"name": INPUT_CHECKS, "sha256": BASE.sha256(receipt_bytes)},
        "scope": "isolated-paired-timeout-and-migration-checks",
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({INPUT_CHECKS: receipt_bytes, "package-provenance.json": BASE.json_bytes(provenance)})


def _read_receipt(path: Path) -> bytes:
    return P15._regular_file(path, "paired checks receipt")


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    baseline_files, old_department, old_pcv = _read_baseline(args.prealpha19_zip)
    receipt_bytes = _read_receipt(args.paired_checks)
    receipt = _validate_paired_receipt(args.git, commit, receipt_bytes, old_department)
    allowlist = BASE.source_allowlist(args.git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required candidate paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    return commit, baseline_files, old_department, old_pcv, receipt_bytes, receipt, allowlist, read_source


def _build_packages(old_department: dict[str, bytes], old_pcv: dict[str, bytes], read_source):
    department, verified_department = _build_department_package(old_department, read_source)
    pcv, verified_pcv = _build_pcv_package(old_pcv, read_source)
    return department, verified_department, pcv, verified_pcv

def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.20 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.20 output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(directory.iterdir())
    if ({item.name for item in entries} != expected
            or any(item.is_symlink() or not item.is_file() for item in entries)):
        raise PackageError("prealpha.20 output must contain only three ZIPs and SHA256SUMS.txt")
    return {item.name: item.read_bytes() for item in entries}


def audit_output(args) -> None:
    (commit, baseline_files, old_department, old_pcv, receipt_bytes, receipt,
     allowlist, read_source) = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    department_package, verified_department, pcv_package, verified_pcv = _build_packages(
        old_department, old_pcv, read_source
    )
    if verified_department != P18._audit_component(mod_files[DPKG_MEMBER], "questionable_decisions", VERSION):
        raise PackageError("prealpha.20 Department package differs from the canonical source assembly")
    if verified_pcv != P18._audit_component(mod_files[PCV_MEMBER], "pcv_companion", PCV_VERSION):
        raise PackageError("prealpha.11 PCV companion differs from the canonical source assembly")
    if mod_files != _assemble_mod(baseline_files, department_package, pcv_package):
        raise PackageError("prealpha.20 MO2 archive differs from the canonical two-package assembly")
    BASE.require_canonical_bytes("prealpha.20 source ZIP", outputs[OUTPUT_SOURCE_ZIP],
                                 BASE.build_source_zip(args.git, commit, allowlist))
    expected_proof = _proof_zip(commit, outputs[OUTPUT_MOD_ZIP], outputs[OUTPUT_SOURCE_ZIP],
                                department_package, pcv_package, receipt_bytes, receipt)
    BASE.require_canonical_bytes("prealpha.20 paired proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    (commit, baseline_files, old_department, old_pcv, receipt_bytes, receipt,
     allowlist, read_source) = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, _verified_department, pcv_package, _verified_pcv = _build_packages(
        old_department, old_pcv, read_source
    )
    mod_zip = BASE.stable_zip_bytes(_assemble_mod(baseline_files, department_package, pcv_package))
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _proof_zip(commit, mod_zip, source_zip, department_package, pcv_package, receipt_bytes, receipt)
    outputs = {OUTPUT_MOD_ZIP: mod_zip, OUTPUT_SOURCE_ZIP: source_zip, OUTPUT_PROOF_ZIP: proof_zip}
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, outputs)
        audit_args = argparse.Namespace(git=args.git, source_commit=commit,
                                        prealpha19_zip=args.prealpha19_zip,
                                        paired_checks=args.paired_checks, output_dir=stage)
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
        sub.add_argument("--prealpha19-zip", required=True, type=Path)
        sub.add_argument("--paired-checks", required=True, type=Path)
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
