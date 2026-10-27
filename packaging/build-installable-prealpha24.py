#!/usr/bin/env python3
"""Build and audit the offline prealpha.24 candidate from published prealpha.23."""

from __future__ import annotations

import argparse
import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path


_P23_PATH = Path(__file__).with_name("build-installable-prealpha23.py")
_P23_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha23_for24", _P23_PATH)
if _P23_SPEC is None or _P23_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.23 packaging helper")
P23 = importlib.util.module_from_spec(_P23_SPEC)
_P23_SPEC.loader.exec_module(P23)

P20 = P23.P20
P15 = P23.P15
BASE = P23.BASE
PackageError = P23.PackageError
ROOT = P23.ROOT

VERSION = "0.1.0-prealpha.24"
PCV_VERSION = "0.1.0-prealpha.12"
BASELINE_VERSION = P23.VERSION
BASELINE_SHA256 = "1d2cc2aaf1472dcfdc7dc6d979ae29d748ff3687bee1f0d05a85f7a390b9cf28"
BASELINE_ZIP_TIMESTAMP = P23.ZIP_TIMESTAMP
ZIP_TIMESTAMP = (2026, 10, 27, 0, 0, 14)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = P23.DPKG_MEMBER
BASELINE_PCV_MEMBER = P23.PCV_MEMBER
BASELINE_PCV_VERSION = "0.1.0-prealpha.11"
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = f"CHIM/server-plugins/pcv_companion/{PCV_VERSION}.dwpkg"
MMP_MEMBER = P23.MMP_MEMBER
DLL_MEMBER = P23.DLL_MEMBER
EXPECTED_BASELINE_MEMBERS = set(P23.EXPECTED_INSTALLABLE_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (
    EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
    | {DPKG_MEMBER, PCV_MEMBER}
)

SERVER_SOURCE_PATHS = tuple(P23.SERVER_SOURCE_PATHS)
DEPARTMENT_EXPECTED_MEMBERS = set(P23.DEPARTMENT_EXPECTED_MEMBERS)
PCV_EXPECTED_MEMBERS = set(P20.PCV_EXPECTED_MEMBERS)
STAGE_ROOT = P20.STAGE_ROOT
OWNER_SOURCE_MANIFEST = P20.OWNER_SOURCE_MANIFEST
OWNER_STAGE_DIRECTORY = P20.OWNER_STAGE_DIRECTORY
OWNER_FILE_NAMES = tuple(P20.OWNER_FILE_NAMES)
PCV_OWNER_VERSION = P20.OWNER_VERSION
PCV_OWNER_TAG = P20.OWNER_TAG
PCV_OWNER_COMMIT = P20.OWNER_COMMIT
SF_OWNER_VERSION = "0.1.0-dev.28"
OWNER_MANIFEST_SHA256 = "4dad1a9ce578d75fe3b85c72c39608cd4e50a89564258fd2c76a73e7a6ecddb6"
DEPARTMENT_ADAPTER = "server/adapters/correspondence.php"
OVERLAY_SETUP = "server/lib/overlay_setup.php"
OVERLAY_MANIFEST_MEMBER = STAGE_ROOT + "source-manifest.json"
ACTIVE_OVERLAY_PREPROCESSING_MEMBER = STAGE_ROOT + "preprocessing.php"
TEMPLATE_OVERLAY_PREPROCESSING_MEMBER = STAGE_ROOT + "preprocessing.php.template"
OVERLAY_STAGED_NAME_BY_OWNER = {"preprocessing.php": "preprocessing.php.template"}
OVERLAY_EXPECTED_STAGE_MEMBERS = {
    OVERLAY_MANIFEST_MEMBER,
    *(STAGE_ROOT + OVERLAY_STAGED_NAME_BY_OWNER.get(name, name) for name in OWNER_FILE_NAMES),
}
DEPARTMENT_EXPECTED_MEMBERS = (
    DEPARTMENT_EXPECTED_MEMBERS - {ACTIVE_OVERLAY_PREPROCESSING_MEMBER}
    | {TEMPLATE_OVERLAY_PREPROCESSING_MEMBER}
)

DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    OVERLAY_SETUP,
    OVERLAY_MANIFEST_MEMBER,
    ACTIVE_OVERLAY_PREPROCESSING_MEMBER,
    TEMPLATE_OVERLAY_PREPROCESSING_MEMBER,
    "checksums.sha256",
}
PCV_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/companion.php",
    "checksums.sha256",
}

INPUT_OFFLINE_CHECKS = "offline-checks.json"
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-offline-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}

OFFLINE_CHECK_NAMES = {
    "soloOwnerSeam",
    "recursiveHookLayout",
    "overlayUpgradeRollback",
    "nativeGoldCounts",
    "nativeBinaryAudit",
    "fiveOwnerCompatibility",
}
PROOF_LIMITS = {
    "server": False,
    "database": False,
    "http": False,
    "provider": False,
    "skyrim": False,
    "installedGame": False,
    "live": False,
}
NATIVE_VERSION = "0.1.17.0"
NATIVE_BUILD_SOURCE_PATHS = {
    "native/src/ConnectionProtocol.cpp",
    "native/deps/PrismaUI_API.h",
    "native/tests/fixtures/README.md",
    "native/tests/fixtures/Plugin-LICENSE",
    "native/tests/fixtures/PlayerConversationRoutingPolicy.h",
    "native/tests/fixtures/php-companion-json-fixtures.json",
    "native/tests/ServerBootstrapTests.cpp",
    "native/tests/PcvCompanionPolicyTests.cpp",
    "native/tests/NativeBoundaryTests.cpp",
    "native/tests/http-transport-tests.cpp",
    "native/tests/emit-pcv-companion-fixtures.php",
    "native/tests/ContractTests.cpp",
    "native/CMakeLists.txt",
    "native/tests/ConnectionProtocolTests.cpp",
    "native/src/Plugin.cpp",
    "native/src/PcvCompanionPolicy.h",
    "native/src/PcvCompanionPolicy.cpp",
    "native/src/NativePolicy.h",
    "native/src/NativePolicy.cpp",
    "native/src/HttpTransport.h",
    "native/src/HttpTransport.cpp",
    "native/src/GoldInventory.h",
    "native/src/Contract.h",
    "native/src/Contract.cpp",
    "native/src/ConnectionProtocol.h",
    "native/tests/Build-Recipe.Tests.ps1",
    "native/deps.lock.json",
}

# Exact runtime and native build inputs summarized by offline-checks.json.
SOURCE_HASH_PATHS = {
    DEPARTMENT_ADAPTER,
    OVERLAY_SETUP,
    OWNER_SOURCE_MANIFEST,
    "server/manifest.json",
    "pcv-companion/companion.php",
    "pcv-companion/manifest.json",
    "native/CMakeLists.txt",
    "native/deps.lock.json",
    "native/src/Plugin.cpp",
    "native/src/GoldInventory.h",
}

REQUIRED_SOURCE_PATHS = set(P23.REQUIRED_SOURCE_PATHS) | {
    "README.md",
    "docs/BUILD.md",
    "docs/DEV28-COMPATIBILITY.md",
    "docs/VERIFICATION.md",
    "packaging/build-installable-prealpha24.py",
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha24.py",
    "server/tests/correspondence/dev23-source-contract.php",
    "server/tests/correspondence/dev28-source-contract.php",
    "server/tests/onboarding/overlay.php",
    "server/tests/pcv/companion_owner_seam.php",
}
REQUIRED_SOURCE_PATHS |= NATIVE_BUILD_SOURCE_PATHS


def _json_object(data: bytes, label: str) -> dict:
    return P23._json_object(data, label)


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError) as error:
        raise PackageError(f"required prealpha.24 source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required prealpha.24 source is not bytes: {path}")
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
        raise PackageError(f"public source allowlist omits offline-bound source files: {', '.join(missing_hashes)}")
    return allowed


def _receipt_sha256(value: object, label: str) -> str:
    if not isinstance(value, str) or len(value) != 64 or any(char not in "0123456789abcdefABCDEF" for char in value):
        raise PackageError(f"{label} must be a SHA-256 digest")
    return value.lower()


def _validate_native_build_receipt(receipt_bytes: bytes, commit: str, git: str, allowlist: set[str], dll_bytes: bytes) -> dict:
    receipt = _json_object(receipt_bytes, "public native build receipt")
    if type(receipt.get("schemaVersion")) is not int or receipt["schemaVersion"] != 1:
        raise PackageError("native build receipt must use schema version 1")
    if receipt.get("status") != "native-offline-plugin-and-focused-test-built":
        raise PackageError("native build receipt does not report a completed offline build")

    source_tree = receipt.get("sourceTree")
    if not isinstance(source_tree, dict) or set(source_tree) != {
        "algorithm", "canonicalManifest", "sha256", "fileCount", "files",
    }:
        raise PackageError("native build receipt is missing its source tree")
    files = source_tree.get("files")
    if (
        type(source_tree.get("fileCount")) is not int
        or source_tree["fileCount"] != len(NATIVE_BUILD_SOURCE_PATHS)
        or not isinstance(files, list)
        or len(files) != len(NATIVE_BUILD_SOURCE_PATHS)
    ):
        raise PackageError("native build receipt must bind the exact 27-file native source tree")

    source_hashes: dict[str, str] = {}
    for row in files:
        if not isinstance(row, dict) or set(row) != {"path", "sha256"}:
            raise PackageError("native build source entry fields differ from the fixed path/hash schema")
        path = row["path"]
        if not isinstance(path, str):
            raise PackageError("native build source path is not a string")
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError("native build receipt contains an unsafe source path") from error
        if path in source_hashes or path not in NATIVE_BUILD_SOURCE_PATHS:
            raise PackageError("native build receipt source paths are duplicate or outside the fixed 27-file tree")
        if path not in allowlist:
            raise PackageError(f"native build source is not in the public source allowlist: {path}")
        digest = _receipt_sha256(row["sha256"], f"native build source hash {path}")
        try:
            source = BASE.git_blob(git, commit, path)
        except (KeyError, OSError, subprocess.SubprocessError) as error:
            raise PackageError(f"native build source is unavailable in the selected commit: {path}") from error
        if not isinstance(source, bytes) or BASE.sha256(source) != digest:
            raise PackageError(f"native build source hash differs from the selected commit: {path}")
        source_hashes[path] = digest
    if set(source_hashes) != NATIVE_BUILD_SOURCE_PATHS:
        raise PackageError("native build receipt source paths do not close the exact 27-file native tree")

    if (
        source_tree["algorithm"] != "SHA-256"
        or source_tree["canonicalManifest"]
        != "UTF-8 relative-path|lowercase-file-SHA256 lines sorted by relative path; LF with final LF"
    ):
        raise PackageError("native build source-tree canonical format differs from the fixed receipt contract")
    canonical = "".join(
        f"{path}|{source_hashes[path]}\n"
        for path in sorted(source_hashes)
    ).encode("utf-8")
    if _receipt_sha256(source_tree["sha256"], "native build source-tree hash") != BASE.sha256(canonical):
        raise PackageError("native build source-tree digest differs from its 27 committed source hashes")

    plugin = receipt.get("outputs", {}).get("plugin") if isinstance(receipt.get("outputs"), dict) else None
    if not isinstance(plugin, dict):
        raise PackageError("native build receipt is missing outputs.plugin")
    if (
        plugin.get("version") != NATIVE_VERSION
        or type(plugin.get("bytes")) is not int
        or plugin["bytes"] != len(dll_bytes)
        or _receipt_sha256(plugin.get("sha256"), "native DLL receipt hash") != BASE.sha256(dll_bytes)
    ):
        raise PackageError("native build receipt DLL version, byte count, or hash differs from the supplied binary")
    if "path" in plugin:
        try:
            BASE.validate_member_name(plugin["path"])
        except (PackageError, TypeError) as error:
            raise PackageError("native build receipt DLL path is unsafe") from error

    regression = receipt.get("regression")
    green = regression.get("green") if isinstance(regression, dict) else None
    if (
        not isinstance(green, dict)
        or green.get("result") != "passed"
        or type(green.get("passed")) is not int
        or green["passed"] != 1
        or type(green.get("failed")) is not int
        or green["failed"] != 0
    ):
        raise PackageError("native build receipt does not bind the green native regression")
    lock = receipt.get("dependencyLock")
    if not isinstance(lock, dict) or lock.get("path") != "native/deps.lock.json" or set(lock) != {"path", "sha256", "contents"}:
        raise PackageError("native build receipt is missing the pinned dependency lock")
    if _receipt_sha256(lock.get("sha256"), "native dependency-lock hash") != source_hashes["native/deps.lock.json"]:
        raise PackageError("native dependency-lock hash differs from the committed native source")
    lock_bytes = BASE.git_blob(git, commit, "native/deps.lock.json")
    if lock.get("contents") != _json_object(lock_bytes, "committed native dependency lock"):
        raise PackageError("native build dependency-lock contents differ from the selected source commit")
    if not isinstance(receipt.get("toolchain"), dict) or not receipt["toolchain"]:
        raise PackageError("native build receipt is missing its toolchain version binding")
    return receipt


def _overlay_mapping(manifest_bytes: bytes) -> dict[str, tuple[str, dict]]:
    manifest = _json_object(manifest_bytes, "PCV overlay source manifest")
    if set(manifest) != {"schemaVersion", "upstream", "stagedSourceDirectory", "installDirectory", "files"}:
        raise PackageError("PCV overlay source manifest fields differ from the fixed schema")
    if (
        type(manifest["schemaVersion"]) is not int
        or manifest["schemaVersion"] != 1
        or manifest["upstream"] != {"version": PCV_OWNER_VERSION, "tag": PCV_OWNER_TAG, "commit": PCV_OWNER_COMMIT}
        or manifest["stagedSourceDirectory"] != OWNER_STAGE_DIRECTORY
        or manifest["installDirectory"] != "ext/private_conversation"
        or not isinstance(manifest["files"], list)
        or len(manifest["files"]) != len(OWNER_FILE_NAMES)
    ):
        raise PackageError("PCV overlay manifest differs from the frozen owner source contract")

    mapping: dict[str, tuple[str, dict]] = {}
    staged_paths: set[str] = set()
    for expected_name, row in zip(OWNER_FILE_NAMES, manifest["files"]):
        if not isinstance(row, dict) or list(row) != ["path", "stagedPath", "baseSha256", "postimageSha256", "modified"]:
            raise PackageError("PCV overlay manifest entry fields differ from the staged-path schema")
        owner_path = row["path"]
        staged_path = row["stagedPath"]
        if (
            owner_path != expected_name
            or staged_path != OVERLAY_STAGED_NAME_BY_OWNER.get(owner_path, owner_path)
            or staged_path in staged_paths
        ):
            raise PackageError("PCV overlay staged paths differ from the exact seven-file mapping")
        try:
            BASE.validate_member_name(owner_path)
            BASE.validate_member_name(staged_path)
        except PackageError as error:
            raise PackageError("PCV overlay manifest contains an unsafe staged path") from error
        base_hash = P23._require_sha256(row["baseSha256"], f"PCV overlay base hash {owner_path}")
        postimage_hash = P23._require_sha256(row["postimageSha256"], f"PCV overlay postimage hash {owner_path}")
        if type(row["modified"]) is not bool or row["modified"] == (base_hash == postimage_hash):
            raise PackageError(f"PCV overlay modified flag disagrees with its hashes: {owner_path}")
        mapping[owner_path] = (staged_path, row)
        staged_paths.add(staged_path)
    if staged_paths != {OVERLAY_STAGED_NAME_BY_OWNER.get(name, name) for name in OWNER_FILE_NAMES}:
        raise PackageError("PCV overlay staged payload set differs from the fixed seven-file mapping")
    return mapping


def _validate_overlay_stage(manifest_bytes: bytes, staged_files: dict[str, bytes]) -> None:
    mapping = _overlay_mapping(manifest_bytes)
    expected_paths = {staged for staged, _row in mapping.values()}
    if set(staged_files) != expected_paths or "preprocessing.php" in staged_files:
        raise PackageError("Department overlay stage must contain the exact template-safe payload set")
    for owner_path, (staged_path, row) in mapping.items():
        if BASE.sha256(staged_files[staged_path]) != row["postimageSha256"]:
            raise PackageError(f"Department overlay staged bytes differ from the owner postimage: {owner_path}")


def _read_overlay_source(read_source) -> tuple[bytes, dict[str, bytes]]:
    manifest_bytes = _required_source(read_source, OWNER_SOURCE_MANIFEST)
    mapping = _overlay_mapping(manifest_bytes)
    staged_files = {
        staged_path: _required_source(read_source, f"{OWNER_STAGE_DIRECTORY}/{owner_path}")
        for owner_path, (staged_path, _row) in mapping.items()
    }
    _validate_overlay_stage(manifest_bytes, staged_files)
    return manifest_bytes, staged_files


def _audit_department_component(data: bytes, expected_version: str) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.24 Department DWPkg")
    if set(files) != DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("Department DWPkg member set differs from the template-safe schema-4 contract")
    checksums = BASE.parse_checksums(files["checksums.sha256"], "prealpha.24 Department DWPkg")
    payload = {path: content for path, content in files.items() if path != "checksums.sha256"}
    if set(checksums) != set(payload) or any(checksums[path] != BASE.sha256(content) for path, content in payload.items()):
        raise PackageError("prealpha.24 Department DWPkg checksum closure is incomplete or mismatched")
    P20.P18._component_manifest(files, "questionable_decisions", expected_version)
    stage = {path for path in files if path.startswith(STAGE_ROOT)}
    if stage != OVERLAY_EXPECTED_STAGE_MEMBERS or ACTIVE_OVERLAY_PREPROCESSING_MEMBER in files:
        raise PackageError("Department DWPkg contains an active PCV preprocessing hook or an incomplete overlay stage")
    staged_files = {
        path.removeprefix(STAGE_ROOT): content
        for path, content in files.items()
        if path.startswith(STAGE_ROOT) and path != OVERLAY_MANIFEST_MEMBER
    }
    _validate_overlay_stage(files[OVERLAY_MANIFEST_MEMBER], staged_files)
    return files


def _validate_dev28_adapter(old_adapter: bytes, new_adapter: bytes) -> dict[str, str]:
    old_pins = P23.P22._literal_owner_closure_pins(old_adapter, "0.1.0-dev.27")
    new_pins = P23.P22._literal_owner_closure_pins(new_adapter, SF_OWNER_VERSION)
    if set(old_pins) != set(new_pins):
        raise PackageError("dev.28 adapter pin paths differ from the retained dev.27 closure")
    changed = {path for path in new_pins if old_pins[path] != new_pins[path]}
    if changed != {"manifest.json"}:
        raise PackageError("dev.28 adapter pin delta must change only the owner manifest")
    if new_pins["manifest.json"] != OWNER_MANIFEST_SHA256:
        raise PackageError("dev.28 adapter does not pin the exact frozen owner manifest")
    return new_pins


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.23 archive")
    files = BASE.read_zip_bytes(data, "prealpha.23 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.23 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != P23.DLL_SHA256:
        raise PackageError("prealpha.23 baseline DLL differs from the pinned published bytes")
    department = P23.P21._audit_department_component(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION)
    pcv = P20._audit_component(files[BASELINE_PCV_MEMBER], "pcv_companion", BASELINE_PCV_VERSION)
    mmp = P23._read_brainwash_component(files[MMP_MEMBER], P23.MMP_VERSION)
    if len(department) != 30 or len(pcv) != 6 or len(mmp) != 12:
        raise PackageError("prealpha.23 baseline component member counts differ")
    return files, department, pcv, mmp


def _read_baseline(path: Path):
    data = P15._regular_file(path, "prealpha.23 baseline ZIP")
    return _read_baseline_bytes(data)


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    if set(old_package) != P23.DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("prealpha.23 Department member set differs from its fixed contract")
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.23 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION:
        raise PackageError("prealpha.23 Department outer manifest has the wrong baseline version")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    for source_path in SERVER_SOURCE_PATHS:
        current["server/" + source_path.removeprefix("server/")] = _required_source(read_source, source_path)
    _validate_dev28_adapter(old_package[DEPARTMENT_ADAPTER], current[DEPARTMENT_ADAPTER])
    source_globals = _required_source(read_source, "server/globals.php")
    if source_globals != old_package["server/globals.php"]:
        raise PackageError("selected source changes the prealpha.23 Department globals bytes")

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.23 Department server manifest")
    inner = _json_object(current["server/manifest.json"], "prealpha.24 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")

    manifest_bytes, staged_files = _read_overlay_source(read_source)
    current[OVERLAY_MANIFEST_MEMBER] = manifest_bytes
    if current.pop(ACTIVE_OVERLAY_PREPROCESSING_MEMBER, None) is None:
        raise PackageError("prealpha.23 Department package is missing its legacy staged preprocessing.php")
    for name, content in staged_files.items():
        current[STAGE_ROOT + name] = content

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = _audit_department_component(package, VERSION)
    changed = {path for path in old_package.keys() | verified.keys() if old_package.get(path) != verified.get(path)}
    if changed != DEPARTMENT_UPDATED_MEMBERS:
        unexpected = ", ".join(sorted(changed - DEPARTMENT_UPDATED_MEMBERS))
        missing = ", ".join(sorted(DEPARTMENT_UPDATED_MEMBERS - changed))
        raise PackageError(f"Department DWPkg changed outside the fixed prealpha.24 delta; unexpected=[{unexpected}] missing=[{missing}]")
    return package, verified


def _build_pcv_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    if set(old_package) != PCV_EXPECTED_MEMBERS:
        raise PackageError("prealpha.23 PCV companion member set differs from its fixed contract")
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.11 PCV companion outer manifest")
    if outer.get("version") != BASELINE_PCV_VERSION:
        raise PackageError("prealpha.11 PCV companion outer manifest has the wrong baseline version")
    outer["version"] = PCV_VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    for source_path in P20.PCV_COMPANION_SOURCES:
        current["server/" + source_path.rsplit("/", 1)[-1]] = _required_source(read_source, source_path)

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.11 PCV companion server manifest")
    inner = _json_object(current["server/manifest.json"], "prealpha.12 PCV companion server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = PCV_VERSION
    if inner != expected_inner:
        raise PackageError("PCV companion server manifest changed beyond its version")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = P20._audit_component(package, "pcv_companion", PCV_VERSION)
    changed = {path for path in old_package if old_package[path] != verified[path]}
    if changed != PCV_UPDATED_MEMBERS:
        raise PackageError("PCV companion DWPkg changed outside the fixed prealpha.12 projection delta")
    return package, verified


def _assert_mod_delta(baseline_files: dict[str, bytes], files: dict[str, bytes], dll_bytes: bytes) -> None:
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.24 MO2 member set differs from the fixed 22-member layout")
    if files.get(DLL_MEMBER) != dll_bytes or BASE.sha256(dll_bytes) == P23.DLL_SHA256:
        raise PackageError("prealpha.24 MO2 DLL does not match a new native payload")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    common = set(baseline_files) & set(files)
    changed = {path for path in common if baseline_files[path] != files[path]}
    if changed != {DLL_MEMBER}:
        raise PackageError("prealpha.24 changed an unrelated MO2 payload")
    if sum(baseline_files[path] == files[path] for path in common) != 19:
        raise PackageError("prealpha.24 must preserve exactly 19 byte-identical MO2 payloads")


def _assemble_mod(
    baseline_files: dict[str, bytes],
    department_package: bytes,
    pcv_package: bytes,
    dll_bytes: bytes,
    dll_sha256: str,
) -> dict[str, bytes]:
    if set(baseline_files) != EXPECTED_BASELINE_MEMBERS or len(baseline_files) != 22:
        raise PackageError("prealpha.23 baseline member set differs")
    if BASE.sha256(dll_bytes) != dll_sha256 or dll_sha256 == P23.DLL_SHA256:
        raise PackageError("native DLL bytes do not match the offline receipt or are not a new payload")
    if department_package == baseline_files[BASELINE_DPKG_MEMBER] or pcv_package == baseline_files[BASELINE_PCV_MEMBER]:
        raise PackageError("prealpha.24 must replace both versioned server packages")
    files = {
        path: content for path, content in baseline_files.items()
        if path not in {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
    }
    files[DPKG_MEMBER] = department_package
    files[PCV_MEMBER] = pcv_package
    files[DLL_MEMBER] = dll_bytes
    _assert_mod_delta(baseline_files, files, dll_bytes)
    return files


def _read_installable(data: bytes, dll_sha256: str) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.24 MO2 archive")
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.24 MO2 member list differs from the fixed 22-member layout")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != dll_sha256 or dll_sha256 == P23.DLL_SHA256:
        raise PackageError("prealpha.24 DLL differs from the offline receipt or the new native payload")
    _audit_department_component(files[DPKG_MEMBER], VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", PCV_VERSION)
    if BASE.sha256(files[MMP_MEMBER]) != P23.MMP_PACKAGE_SHA256:
        raise PackageError("prealpha.24 changed the accepted Malpractice Makes Perfect dev.4 package")
    P23._read_brainwash_component(files[MMP_MEMBER], P23.MMP_VERSION)
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != BASE.NOTICE_FILES:
        raise PackageError("prealpha.24 MO2 archive does not contain exactly the seven required notices")
    BASE.audit_ini(files["SKSE/Plugins/WorldOfDramaLlama.ini"])
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.24 MO2 archive is not the canonical timestamped ZIP")
    return files


def _read_json_file(path: Path, label: str) -> tuple[bytes, dict]:
    data = P15._regular_file(path, label)
    if len(data) > 1024 * 1024:
        raise PackageError(f"{label} exceeds 1 MiB")
    value = _json_object(data, label)
    if data != BASE.json_bytes(value):
        raise PackageError(f"{label} must use canonical UTF-8 JSON with LF and no BOM")
    return data, value


def _validate_offline_checks(
    receipt: dict,
    commit: str,
    git: str,
    allowlist: set[str],
    dll_bytes: bytes,
    native_build_receipt: bytes,
) -> dict:
    _validate_native_build_receipt(native_build_receipt, commit, git, allowlist, dll_bytes)
    if set(receipt) != {"schemaVersion", "kind", "sourceCommit", "checks", "sourceFiles", "native", "receiptHashes", "limits"}:
        raise PackageError("offline checks receipt fields differ from the fixed schema")
    if type(receipt["schemaVersion"]) is not int or receipt["schemaVersion"] != 1:
        raise PackageError("offline checks receipt must use schema version 1")
    if receipt["kind"] != "department-offline-repair-checks" or receipt["sourceCommit"] != commit:
        raise PackageError("offline checks receipt does not identify the selected frozen source commit")
    checks = receipt["checks"]
    if (
        not isinstance(checks, dict)
        or set(checks) != OFFLINE_CHECK_NAMES
        or any(type(value) is not bool or value is not True for value in checks.values())
    ):
        raise PackageError("offline checks receipt must pass every fixed offline gate")
    source_files = receipt["sourceFiles"]
    if not isinstance(source_files, dict) or set(source_files) != SOURCE_HASH_PATHS:
        raise PackageError("offline source hashes differ from the fixed runtime and native input set")
    for path, digest in source_files.items():
        P23._require_sha256(digest, f"offline source hash {path}")
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError(f"offline source hash path is unsafe: {path}") from error
        if path not in allowlist:
            raise PackageError(f"offline-bound source is not in the public source allowlist: {path}")
        try:
            source = BASE.git_blob(git, commit, path)
        except (KeyError, OSError, subprocess.SubprocessError) as error:
            raise PackageError(f"offline-bound source is unavailable in the selected commit: {path}") from error
        if not isinstance(source, bytes) or BASE.sha256(source) != digest:
            raise PackageError(f"offline source hash differs from the selected commit: {path}")

    native = receipt["native"]
    if not isinstance(native, dict) or set(native) != {"sha256", "version", "buildReceiptSha256"}:
        raise PackageError("offline native binding fields differ from the fixed schema")
    native_sha256 = P23._require_sha256(native["sha256"], "offline native DLL hash")
    if native["version"] != NATIVE_VERSION or native_sha256 != BASE.sha256(dll_bytes):
        raise PackageError("offline native version or DLL hash differs from the supplied binary")
    if P23._require_sha256(native["buildReceiptSha256"], "native build receipt hash") != BASE.sha256(native_build_receipt):
        raise PackageError("native build receipt differs from the offline binding")

    receipt_hashes = receipt["receiptHashes"]
    if not isinstance(receipt_hashes, dict) or set(receipt_hashes) != OFFLINE_CHECK_NAMES:
        raise PackageError("offline receipt hashes differ from the fixed six-check set")
    for name, digest in receipt_hashes.items():
        P23._require_sha256(digest, f"offline receipt hash {name}")

    limits = receipt["limits"]
    if (
        not isinstance(limits, dict)
        or set(limits) != set(PROOF_LIMITS)
        or any(type(value) is not bool for value in limits.values())
        or limits != PROOF_LIMITS
    ):
        raise PackageError("offline checks receipt claims behavior outside the fixed offline limits")
    return receipt


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    baseline_files, old_department, old_pcv, _old_mmp = _read_baseline(args.prealpha23_zip)
    dll_bytes = P15._regular_file(args.native_dll, "native 1.17.0 DLL")
    native_build_receipt = P15._regular_file(args.native_build_receipt, "native build receipt")
    offline_bytes, receipt = _read_json_file(args.offline_checks, INPUT_OFFLINE_CHECKS)
    allowlist = BASE.source_allowlist(args.git, commit)
    allowed = _validate_source_allowlist(allowlist)
    _validate_offline_checks(receipt, commit, args.git, allowed, dll_bytes, native_build_receipt)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    return commit, baseline_files, old_department, old_pcv, dll_bytes, native_build_receipt, offline_bytes, receipt, allowlist, read_source


def _proof_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    pcv_package: bytes,
    dll_bytes: bytes,
    native_build_receipt: bytes,
    offline_bytes: bytes,
    receipt: dict,
) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "kind": "department-offline-package-provenance",
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "pcvCompanion": {"member": PCV_MEMBER, "sha256": BASE.sha256(pcv_package)},
        "nativeDll": {
            "member": DLL_MEMBER,
            "version": NATIVE_VERSION,
            "sha256": BASE.sha256(dll_bytes),
            "buildReceiptSha256": BASE.sha256(native_build_receipt),
        },
        "nativeBuildReceipt": {
            "name": "native-build-receipt.json",
            "sha256": BASE.sha256(native_build_receipt),
        },
        "offlineChecks": {
            "name": INPUT_OFFLINE_CHECKS,
            "sha256": BASE.sha256(offline_bytes),
            "checks": receipt["checks"],
            "sourceFiles": receipt["sourceFiles"],
            "receiptHashes": receipt["receiptHashes"],
            "limits": receipt["limits"],
        },
        "departmentDpkgDelta": sorted(DEPARTMENT_UPDATED_MEMBERS),
        "pcvCompanionDpkgDelta": sorted(PCV_UPDATED_MEMBERS),
        "changedMo2Members": sorted({BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER, DPKG_MEMBER, PCV_MEMBER, DLL_MEMBER}),
        "unchangedMo2Members": 19,
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        INPUT_OFFLINE_CHECKS: offline_bytes,
        "native-build-receipt.json": native_build_receipt,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.24 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.24 output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(directory.iterdir())
    if {entry.name for entry in entries} != expected or any(entry.is_symlink() or not entry.is_file() for entry in entries):
        raise PackageError("prealpha.24 output must contain only three ZIPs and SHA256SUMS.txt")
    return {entry.name: entry.read_bytes() for entry in entries}


def audit_output(args) -> None:
    (
        commit, baseline_files, old_department, old_pcv, dll_bytes, native_build_receipt,
        offline_bytes, receipt, allowlist, read_source,
    ) = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP], receipt["native"]["sha256"])
    department_package, verified_department = _build_department_package(old_department, read_source)
    pcv_package, verified_pcv = _build_pcv_package(old_pcv, read_source)
    if verified_department != _audit_department_component(mod_files[DPKG_MEMBER], VERSION):
        raise PackageError("prealpha.24 Department package differs from the canonical committed source assembly")
    if verified_pcv != P20._audit_component(mod_files[PCV_MEMBER], "pcv_companion", PCV_VERSION):
        raise PackageError("prealpha.24 PCV companion package differs from the canonical committed source assembly")
    expected_mod = _assemble_mod(baseline_files, department_package, pcv_package, dll_bytes, receipt["native"]["sha256"])
    if mod_files != expected_mod:
        raise PackageError("prealpha.24 MO2 archive differs from the canonical three-payload assembly")
    _assert_mod_delta(baseline_files, mod_files, dll_bytes)
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    BASE.require_canonical_bytes("prealpha.24 source ZIP", outputs[OUTPUT_SOURCE_ZIP], source_zip)
    expected_proof = _proof_zip(
        commit, outputs[OUTPUT_MOD_ZIP], outputs[OUTPUT_SOURCE_ZIP], department_package, pcv_package,
        dll_bytes, native_build_receipt, offline_bytes, receipt,
    )
    BASE.require_canonical_bytes("prealpha.24 offline proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    (
        commit, baseline_files, old_department, old_pcv, dll_bytes, native_build_receipt,
        offline_bytes, receipt, allowlist, read_source,
    ) = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, _department = _build_department_package(old_department, read_source)
    pcv_package, _pcv = _build_pcv_package(old_pcv, read_source)
    mod_zip = BASE.stable_zip_bytes(
        _assemble_mod(baseline_files, department_package, pcv_package, dll_bytes, receipt["native"]["sha256"])
    )
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _proof_zip(
        commit, mod_zip, source_zip, department_package, pcv_package, dll_bytes,
        native_build_receipt, offline_bytes, receipt,
    )
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
            prealpha23_zip=args.prealpha23_zip,
            offline_checks=args.offline_checks,
            native_dll=args.native_dll,
            native_build_receipt=args.native_build_receipt,
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
        sub.add_argument("--prealpha23-zip", required=True, type=Path)
        sub.add_argument("--offline-checks", required=True, type=Path)
        sub.add_argument("--native-dll", required=True, type=Path)
        sub.add_argument("--native-build-receipt", required=True, type=Path)
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
