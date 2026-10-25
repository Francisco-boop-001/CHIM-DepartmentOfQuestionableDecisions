#!/usr/bin/env python3
"""Build and audit the prealpha.12 MO2 and developer-proof archives."""

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
from io import BytesIO
from pathlib import Path, PurePosixPath


VERSION = "0.1.0-prealpha.12"
ROOT = Path(__file__).resolve().parents[1]
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PAIRING_ZIP = f"WorldOfDramaLlama-{VERSION}-pairing.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
PUBLIC_CHECKSUM_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PAIRING_ZIP}
COMPONENT_DIR = "component-packages"
ZIP_TIMESTAMP = (2026, 10, 24, 0, 0, 0)

BASELINE_ZIP_SHA256 = "66a85ee031f98d7f65344a159fd24722a1fb927409010d9fc0019a485032d621"
BASE_DLL_SHA256 = "1451c64dc8285be897d6eeac8754980a0b90a3228f75c8780d2f5566aeacb4c9"
BASELINE_QD_VERSION = "0.1.0-prealpha.11"
BASELINE_QD_DESCRIPTION = "A small Skyrim-side Department for the affairs CHIM plugins leave behind."
BASELINE_QD_MEMBER = f"CHIM/server-plugins/questionable_decisions/{BASELINE_QD_VERSION}.dwpkg"
BASELINE_PCV_MEMBER = "CHIM/server-plugins/pcv_companion/0.1.0-prealpha.9.dwpkg"
BASELINE_BRAINWASH_MEMBER = "CHIM/server-plugins/brainwash/0.1.0-dev.2.dwpkg"
QD_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
APP_JS_MEMBER = "PrismaUI/views/WorldOfDramaLlama/app.js"
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"

_BASE_PATH = Path(__file__).with_name("build-installable-prealpha8.py")
_BASE_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha8", _BASE_PATH)
if _BASE_SPEC is None or _BASE_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.8 packaging helper")
BASE_BUILDER = importlib.util.module_from_spec(_BASE_SPEC)
_BASE_SPEC.loader.exec_module(BASE_BUILDER)
PackageError = BASE_BUILDER.PackageError

BASELINE_MO2_MEMBERS = {
    BASELINE_BRAINWASH_MEMBER,
    BASELINE_PCV_MEMBER,
    BASELINE_QD_MEMBER,
    "MALPRACTICE-MAKES-PERFECT-TEST.txt",
    "NOTICE/CHIM-Plugin-MIT-LICENSE.txt",
    "NOTICE/CommonLibSSE-NG-3.7.0-LICENSE.txt",
    "NOTICE/Department-nlohmann-json-3.11.3-MIT.txt",
    "NOTICE/PrismaUI-LICENSE.md",
    "NOTICE/fmt-10.2.1-LICENSE.txt",
    "NOTICE/rapidcsv-8.84-LICENSE.txt",
    "NOTICE/spdlog-1.13.0-LICENSE.txt",
    "PrismaUI/views/WorldOfDramaLlama/app.css",
    APP_JS_MEMBER,
    "PrismaUI/views/WorldOfDramaLlama/brainwash-editor.css",
    "PrismaUI/views/WorldOfDramaLlama/brainwash-editor.js",
    "PrismaUI/views/WorldOfDramaLlama/index.html",
    "SEQ/WorldOfDramaLlama.seq",
    DLL_MEMBER,
    "SKSE/Plugins/WorldOfDramaLlama.ini",
    "Scripts/WorldOfDramaLlamaMCM.pex",
    "Scripts/WorldOfDramaLlamaNative.pex",
    "WorldOfDramaLlama.esp",
}
MO2_FIXED_MEMBERS = (BASELINE_MO2_MEMBERS - {BASELINE_QD_MEMBER}) | {QD_MEMBER}

QD_SERVER_SOURCES = (
    "server/api.php",
    "server/connect.php",
    "server/index.php",
    "server/manifest.json",
    "server/preprocessing.php",
    "server/runtime.php",
    "server/adapters/brainwash.php",
    "server/adapters/correspondence.php",
    "server/adapters/mp.php",
    "server/adapters/pcv.php",
    "server/adapters/runtime_support.php",
    "server/adapters/sas.php",
    "server/lib/auth.php",
    "server/lib/connect.php",
    "server/lib/dispatcher.php",
    "server/lib/journal.php",
    "server/lib/overlay_setup.php",
    "server/lib/protocol.php",
    "server/tools/provision.php",
)
STAGE_MEMBERS = {
    "source-manifest.json",
    *BASE_BUILDER.PCV_FILE_POSTIMAGES,
}
QD_COMPONENT_SERVER_MEMBERS = {
    f"server/{path.removeprefix('server/')}" for path in QD_SERVER_SOURCES
} | {f"server/setup/pcv-overlay/{name}" for name in STAGE_MEMBERS}

PACKAGE_MANIFESTS = {
    "questionable_decisions": {
        "description": BASELINE_QD_DESCRIPTION,
        "inner_manifest": "server/manifest.json",
        "sources": QD_SERVER_SOURCES,
    },
}
MO2_COMPONENT_PATHS = {"questionable_decisions": QD_MEMBER}
COMPONENT_FILENAMES = {"questionable_decisions": f"questionable_decisions-{VERSION}.dwpkg"}
SOURCE_ALLOWLIST_PATH = "packaging/public-source-allowlist.json"

DEV23_PG_SOURCES = {
    f"server/tests/dev23-pg/{name}"
    for name in (
        "api_driver.php",
        "base.sql",
        "build-source-bindings.ps1",
        "cases.json",
        "companion_cases.php",
        "controller_switch.php",
        "coverage.json",
        "fixture_guard.php",
        "launch-dev23-test.ps1",
        "owner-runtime-pins.json",
        "owner_fixture.php",
        "pg_scenarios.php",
        "run-dev23-owner-facade.sh",
        "seed_unknown_receipt.php",
        "test_runner_contract.py",
        "verify-dev23-harness.ps1",
        "verify-owner-pins.ps1",
    )
}
PREALPHA11_SOURCE_GAPS = {
    "docs/BRAINWASH-IMPLEMENTATION.md",
    "docs/superpowers/plans/2026-10-24-automatic-chim-setup.md",
    "docs/superpowers/specs/2026-10-23-install-and-use-design.md",
    "server/adapters/brainwash.php",
    "server/tests/brainwash/department-contract.php",
    "server/tests/brainwash/installed-runtime.php",
    "ui/brainwash-editor.css",
    "ui/brainwash-editor.js",
    "ui/tests/brainwash.cjs",
}
REQUIRED_SOURCE_PATHS = {
    *BASE_BUILDER.REQUIRED_SOURCE_PATHS,
    *QD_SERVER_SOURCES,
    "packaging/build-installable-prealpha9.py",
    "packaging/tests/test_installable_prealpha9.py",
    "packaging/build-installable-prealpha12.py",
    "packaging/tests/test_installable_prealpha12.py",
    SOURCE_ALLOWLIST_PATH,
    "docs/DEV23-COMPATIBILITY.md",
    "docs/superpowers/plans/2026-10-24-sealed-fates-dev23-compatibility.md",
    "server/tests/correspondence/dev23-source-contract.php",
    *PREALPHA11_SOURCE_GAPS,
    *DEV23_PG_SOURCES,
}

BASE_BUILDER.VERSION = VERSION
BASE_BUILDER.ROOT = ROOT
BASE_BUILDER.OUTPUT_MOD_ZIP = OUTPUT_MOD_ZIP
BASE_BUILDER.OUTPUT_SOURCE_ZIP = OUTPUT_SOURCE_ZIP
BASE_BUILDER.PUBLIC_CHECKSUM_NAMES = PUBLIC_CHECKSUM_NAMES
BASE_BUILDER.COMPONENT_DIR = COMPONENT_DIR
BASE_BUILDER.CHECKSUMS_NAME = CHECKSUMS_NAME
BASE_BUILDER.ZIP_TIMESTAMP = ZIP_TIMESTAMP
BASE_BUILDER.QD_SERVER_SOURCES = QD_SERVER_SOURCES
BASE_BUILDER.PACKAGE_MANIFESTS = PACKAGE_MANIFESTS
BASE_BUILDER.MO2_COMPONENT_PATHS = MO2_COMPONENT_PATHS
BASE_BUILDER.COMPONENT_FILENAMES = COMPONENT_FILENAMES
BASE_BUILDER.MO2_FIXED_MEMBERS = MO2_FIXED_MEMBERS
BASE_BUILDER.REQUIRED_SOURCE_PATHS = REQUIRED_SOURCE_PATHS

RECEIPT_NAMES = {
    "department-owner-pairing.json",
    "source-readiness.json",
    "runtime-release.json",
    "runtime-input-freeze.json",
}
OWNER_VERSION = "0.1.0-dev.23"
FREEZE_SCHEMA = "department.dev20.runtime-source-bindings.v1"
EXPECTED_OWNER_CLOSURE = 19
EXPECTED_OWNER_PAYLOAD = 11
EXPECTED_OWNER_FULL_PAYLOAD = 30


def _object_without_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate JSON field")
        result[key] = value
    return result


def _invalid_json_constant(_value):
    raise ValueError("invalid JSON number")


def _loads_object(raw: bytes, label: str) -> dict:
    try:
        value = json.loads(
            raw.decode("utf-8"),
            object_pairs_hook=_object_without_duplicates,
            parse_constant=_invalid_json_constant,
        )
    except (UnicodeDecodeError, json.JSONDecodeError, ValueError) as error:
        raise BASE_BUILDER.PackageError(f"{label} is invalid JSON") from error
    if not isinstance(value, dict):
        raise BASE_BUILDER.PackageError(f"{label} must be a JSON object")
    return value


def _sha_value(value, label: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-fA-F]{64}", value) is None:
        raise BASE_BUILDER.PackageError(f"{label} is not a SHA-256 value")
    return value.lower()


def _safe_path(value, label: str) -> str:
    if not isinstance(value, str):
        raise BASE_BUILDER.PackageError(f"{label} is not a path")
    try:
        BASE_BUILDER.validate_member_name(value)
    except BASE_BUILDER.PackageError as error:
        raise BASE_BUILDER.PackageError(f"{label} is unsafe") from error
    return value


def _receipt_map(value, label: str) -> dict[str, dict[str, object]]:
    if not isinstance(value, dict) or not value:
        raise BASE_BUILDER.PackageError(f"{label} map is missing")
    result: dict[str, dict[str, object]] = {}
    for path, item in value.items():
        path = _safe_path(path, label)
        if not path.startswith("server/") or path == "server/":
            raise BASE_BUILDER.PackageError(f"{label} path is outside server/")
        if not isinstance(item, dict) or set(item) != {"bytes", "sha256"}:
            raise BASE_BUILDER.PackageError(f"{label} entry is malformed")
        if type(item["bytes"]) is not int or item["bytes"] < 0:
            raise BASE_BUILDER.PackageError(f"{label} byte count is invalid")
        result[path] = {"bytes": item["bytes"], "sha256": _sha_value(item["sha256"], label)}
    return result


def _sibling_reference(value, expected: str, label: str) -> None:
    if not isinstance(value, str) or value != expected:
        raise BASE_BUILDER.PackageError(f"{label} must reference {expected}")


def _safe_receipt_directory(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise BASE_BUILDER.PackageError("pairing directory is missing or unsafe")
    for parent in (directory, *directory.parents):
        if parent.is_symlink():
            raise BASE_BUILDER.PackageError("symlink in pairing directory path")
    children = list(directory.iterdir())
    if {item.name for item in children} != RECEIPT_NAMES or any(item.is_symlink() or not item.is_file() for item in children):
        raise BASE_BUILDER.PackageError("pairing directory must contain the exact four accepted receipts")
    return {item.name: item.read_bytes() for item in children}


def _verify_readiness(readiness: dict, owner_files: dict, department_files: dict) -> None:
    if (type(readiness.get("schemaVersion")) is not int or readiness["schemaVersion"] != 1
            or readiness.get("ready") is not True
            or readiness.get("errorReason") is not None):
        raise BASE_BUILDER.PackageError("Department source readiness did not pass")
    owner = readiness.get("owner")
    if not isinstance(owner, dict) or owner.get("version") != OWNER_VERSION:
        raise BASE_BUILDER.PackageError("source-readiness owner version differs")
    pins = owner.get("selectedClosurePins")
    if not isinstance(pins, list):
        raise BASE_BUILDER.PackageError("source-readiness owner closure is missing")
    pin_map = {}
    for pin in pins:
        if not isinstance(pin, dict) or pin.get("matches") is not True:
            raise BASE_BUILDER.PackageError("source-readiness owner pin did not pass")
        relative = _safe_path(pin.get("file"), "source-readiness owner path")
        if relative.startswith("server/"):
            raise BASE_BUILDER.PackageError("source-readiness owner path is invalid")
        expected = _sha_value(pin.get("expectedSha256"), "source-readiness owner hash")
        actual = _sha_value(pin.get("actualSha256"), "source-readiness owner hash")
        path = "server/" + relative
        if expected != actual or path in pin_map:
            raise BASE_BUILDER.PackageError("source-readiness owner closure differs")
        pin_map[path] = actual
    if pin_map != {path: item["sha256"] for path, item in owner_files.items()}:
        raise BASE_BUILDER.PackageError("source-readiness owner closure differs from pairing receipt")
    if _sha_value(owner.get("manifestSha256"), "source-readiness owner manifest hash") != pin_map.get("server/manifest.json"):
        raise BASE_BUILDER.PackageError("source-readiness owner manifest hash differs")

    department = readiness.get("department")
    if not isinstance(department, dict):
        raise BASE_BUILDER.PackageError("source-readiness Department identity is missing")
    for key, relative in (("protocol", "lib/protocol.php"), ("adapter", "adapters/correspondence.php")):
        pin = department.get(key)
        path = "server/" + relative
        if (not isinstance(pin, dict) or pin.get("file") != relative or path not in department_files
                or _sha_value(pin.get("sha256"), "source-readiness Department hash") != department_files[path]["sha256"]):
            raise BASE_BUILDER.PackageError("source-readiness Department hash differs")
def _verify_freeze(freeze: dict, owner_files: dict, department_files: dict) -> None:
    if freeze.get("schema") != FREEZE_SCHEMA or not isinstance(freeze.get("files"), list):
        raise BASE_BUILDER.PackageError("unsupported runtime input freeze")
    owner_group = {}
    department_group = {}
    seen = set()
    owner_payload_group = {}
    for entry in freeze["files"]:
        if not isinstance(entry, dict) or set(entry) != {"rootKey", "path", "bytes", "sha256"}:
            raise BASE_BUILDER.PackageError("runtime input freeze entry is malformed")
        root_key = entry["rootKey"]
        path = _safe_path(entry["path"], "runtime input freeze path")
        if root_key not in {"owner", "ownerPayload", "departmentServer", "test", "core", "runtime"}:
            raise BASE_BUILDER.PackageError("runtime input freeze root is invalid")
        identity = (root_key, path)
        if identity in seen:
            raise BASE_BUILDER.PackageError("runtime input freeze contains duplicate inputs")
        seen.add(identity)
        if type(entry["bytes"]) is not int or entry["bytes"] < 0:
            raise BASE_BUILDER.PackageError("runtime input freeze byte count is invalid")
        item = {"bytes": entry["bytes"], "sha256": _sha_value(entry["sha256"], "runtime input freeze hash")}
        if root_key == "owner":
            if not path.startswith("server/"):
                raise BASE_BUILDER.PackageError("selected owner freeze path is invalid")
            owner_group[path] = item
        elif root_key == "departmentServer":
            if path.startswith("server/"):
                raise BASE_BUILDER.PackageError("Department freeze path is invalid")
            department_group["server/" + path] = item
        elif root_key == "ownerPayload":
            if not path.startswith("server/"):
                raise BASE_BUILDER.PackageError("full owner payload freeze path is invalid")
            owner_payload_group[path] = item
    if type(freeze.get("ownerClosureCount")) is not int or freeze["ownerClosureCount"] != len(owner_files):
        raise BASE_BUILDER.PackageError("runtime input freeze owner closure count differs")
    if len(owner_payload_group) != EXPECTED_OWNER_PAYLOAD:
        raise BASE_BUILDER.PackageError("runtime input freeze owner payload count differs")
    if set(owner_group) & set(owner_payload_group) or len(owner_group) + len(owner_payload_group) != EXPECTED_OWNER_FULL_PAYLOAD:
        raise BASE_BUILDER.PackageError("runtime input freeze selected and full owner payload maps overlap")
    if owner_group != owner_files or department_group != department_files:
        raise BASE_BUILDER.PackageError("runtime input freeze source maps differ")


def _verify_behavior(behavior: dict, owner_files: dict, department_files: dict, freeze_hash: str) -> None:
    if (type(behavior.get("schemaVersion")) is not int or behavior["schemaVersion"] != 1
            or behavior.get("gate") != "runtimeRelease"
            or behavior.get("status") != "accepted" or behavior.get("currentCheckExecuted") is not True):
        raise BASE_BUILDER.PackageError("runtime behavior gate was not accepted")
    case_count = behavior.get("facadeCases")
    passed = behavior.get("passedCases")
    failed = behavior.get("failedCases")
    cases = behavior.get("cases")
    if (type(case_count) is not int or case_count <= 0 or type(passed) is not int or passed != case_count
            or type(failed) is not int or failed != 0 or not isinstance(cases, list) or len(cases) != case_count
            or any(not isinstance(case, dict) or case.get("status") != "passed"
                   or not isinstance(case.get("id"), str) or not case["id"].strip() for case in cases)):
        raise BASE_BUILDER.PackageError("runtime behavior cases did not all pass")
    ids = [case["id"] for case in cases]
    if len(ids) != len(set(ids)):
        raise BASE_BUILDER.PackageError("runtime behavior case IDs are duplicated")
    if behavior.get("ownerVersion") != OWNER_VERSION:
        raise BASE_BUILDER.PackageError("runtime behavior owner version differs")
    if _receipt_map(behavior.get("ownerFiles"), "runtime owner files") != owner_files:
        raise BASE_BUILDER.PackageError("runtime behavior owner source map differs")
    if _receipt_map(behavior.get("departmentFiles"), "runtime Department files") != department_files:
        raise BASE_BUILDER.PackageError("runtime behavior Department source map differs")
    for field in ("sourcePreservationPassed", "ownedPostgresStopVerified", "protectedBaselinesMatched"):
        if behavior.get(field) is not True:
            raise BASE_BUILDER.PackageError("runtime behavior preservation or cleanup did not pass")
    if type(behavior.get("providerCalls")) is not int or behavior["providerCalls"] != 0:
        raise BASE_BUILDER.PackageError("runtime behavior used a provider")
    if _sha_value(behavior.get("runtimeSourceManifestSha256"), "runtime source manifest hash") != freeze_hash:
        raise BASE_BUILDER.PackageError("runtime source manifest hash differs")


def _verify_pairing_receipts(receipts: dict[str, bytes], git_executable: str, commit: str, allowed_sources: set[str]) -> None:
    pairing = _loads_object(receipts["department-owner-pairing.json"], "pairing receipt")
    readiness = _loads_object(receipts["source-readiness.json"], "source-readiness receipt")
    behavior = _loads_object(receipts["runtime-release.json"], "runtime behavior receipt")
    freeze = _loads_object(receipts["runtime-input-freeze.json"], "runtime input freeze")
    if (type(pairing.get("schemaVersion")) is not int or pairing["schemaVersion"] != 1
            or pairing.get("kind") != "department-owner-pairing" or pairing.get("status") != "pass"):
        raise BASE_BUILDER.PackageError("pairing receipt did not pass")
    owner = pairing.get("owner")
    department = pairing.get("department")
    if not isinstance(owner, dict) or owner.get("version") != OWNER_VERSION or not isinstance(department, dict):
        raise BASE_BUILDER.PackageError("pairing owner or Department identity differs")
    owner_files = _receipt_map(owner.get("files"), "pairing owner files")
    department_files = _receipt_map(department.get("files"), "pairing Department files")
    if len(owner_files) != EXPECTED_OWNER_CLOSURE:
        raise BASE_BUILDER.PackageError("pairing selected owner closure count differs")
    if "server/manifest.json" not in owner_files:
        raise BASE_BUILDER.PackageError("pairing owner closure omits its manifest")
    for path, item in department_files.items():
        if path not in allowed_sources:
            raise BASE_BUILDER.PackageError(f"Department pairing source is not in public source allowlist: {path}")
        try:
            data = BASE_BUILDER.git_blob(git_executable, commit, path)
        except (BASE_BUILDER.PackageError, OSError, subprocess.SubprocessError) as error:
            raise BASE_BUILDER.PackageError(f"Department pairing source is absent from the frozen Git commit: {path}") from error
        if len(data) != item["bytes"] or BASE_BUILDER.sha256(data) != item["sha256"]:
            raise BASE_BUILDER.PackageError(f"Department pairing source differs from the frozen Git commit: {path}")
    for required in ("server/lib/protocol.php", "server/adapters/correspondence.php"):
        if required not in department_files:
            raise BASE_BUILDER.PackageError(f"pairing receipt omits {required}")

    readiness_ref = pairing.get("sourceReadiness")
    behavior_ref = pairing.get("behavior")
    if not isinstance(readiness_ref, dict) or not isinstance(behavior_ref, dict):
        raise BASE_BUILDER.PackageError("pairing receipt evidence references are missing")
    _sibling_reference(readiness_ref.get("receipt"), "source-readiness.json", "source-readiness reference")
    _sibling_reference(behavior_ref.get("receipt"), "runtime-release.json", "runtime behavior reference")
    _sibling_reference(behavior_ref.get("inputFreeze"), "runtime-input-freeze.json", "runtime input freeze reference")
    if _sha_value(readiness_ref.get("sha256"), "source-readiness receipt hash") != BASE_BUILDER.sha256(receipts["source-readiness.json"]):
        raise BASE_BUILDER.PackageError("stale source-readiness receipt hash")
    if _sha_value(behavior_ref.get("sha256"), "runtime behavior receipt hash") != BASE_BUILDER.sha256(receipts["runtime-release.json"]):
        raise BASE_BUILDER.PackageError("stale runtime behavior receipt hash")
    freeze_hash = BASE_BUILDER.sha256(receipts["runtime-input-freeze.json"])
    if _sha_value(behavior_ref.get("inputFreezeSha256"), "runtime input freeze hash") != freeze_hash:
        raise BASE_BUILDER.PackageError("stale runtime input freeze hash")

    _verify_readiness(readiness, owner_files, department_files)
    _verify_freeze(freeze, owner_files, department_files)
    _verify_behavior(behavior, owner_files, department_files, freeze_hash)


def build_pairing_zip(directory: Path, git_executable: str, commit: str, allowed_sources: set[str] | None = None) -> bytes:
    receipts = _safe_receipt_directory(directory)
    allowed = REQUIRED_SOURCE_PATHS if allowed_sources is None else allowed_sources
    _verify_pairing_receipts(receipts, git_executable, commit, set(allowed))
    return BASE_BUILDER.stable_zip_bytes(receipts)


def audit_pairing_zip(data: bytes) -> dict[str, bytes]:
    files = BASE_BUILDER.read_zip_bytes(data, "pairing proof ZIP")
    if set(files) != RECEIPT_NAMES:
        raise BASE_BUILDER.PackageError("pairing proof ZIP must contain the exact four accepted receipts")
    return files


def _verify_dpkg(data: bytes, version: str) -> dict[str, bytes]:
    files = BASE_BUILDER.read_zip_bytes(data, "Department DWPkg")
    if set(files) != {"manifest.json", "checksums.sha256", *QD_COMPONENT_SERVER_MEMBERS}:
        raise BASE_BUILDER.PackageError("Department DWPkg does not have the pinned server payload members")
    outer = _loads_object(files["manifest.json"], "Department DWPkg manifest")
    if (outer.get("schema_version") != 4 or outer.get("name") != "questionable_decisions"
            or outer.get("version") != version or outer.get("server") != {"mutable_paths": []}):
        raise BASE_BUILDER.PackageError("Department DWPkg outer identity or schema differs")
    inner = _loads_object(files["server/manifest.json"], "Department server manifest")
    if (inner.get("name") != "questionable_decisions" or inner.get("version") != version
            or inner.get("schema_version") != 2
            or inner.get("config_url") != "../ext/questionable_decisions/index.php"):
        raise BASE_BUILDER.PackageError("Department server manifest identity or schema differs")
    checksums = BASE_BUILDER.parse_checksums(files["checksums.sha256"], "Department DWPkg")
    payload = {name: content for name, content in files.items() if name != "checksums.sha256"}
    if set(checksums) != set(payload) or any(checksums[name] != BASE_BUILDER.sha256(content) for name, content in payload.items()):
        raise BASE_BUILDER.PackageError("Department DWPkg checksums do not match")
    return files


def audit_base_archive(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes]]:
    if BASE_BUILDER.sha256(data) != BASELINE_ZIP_SHA256:
        raise BASE_BUILDER.PackageError("base archive is not the verified prealpha.11 ZIP")
    files = BASE_BUILDER.read_zip_bytes(data, "prealpha.11 base archive")
    if set(files) != BASELINE_MO2_MEMBERS:
        raise BASE_BUILDER.PackageError("prealpha.11 base archive member list differs")
    if BASE_BUILDER.sha256(files[DLL_MEMBER]) != BASE_DLL_SHA256:
        raise BASE_BUILDER.PackageError("prealpha.11 base DLL hash differs")
    qd_files = _verify_dpkg(files[BASELINE_QD_MEMBER], BASELINE_QD_VERSION)
    return files, qd_files


def validate_dll(data: bytes) -> None:
    BASE_BUILDER.validate_dll(data)
    digest = BASE_BUILDER.sha256(data)
    if digest != BASE_DLL_SHA256:
        raise BASE_BUILDER.PackageError("prealpha.12 must reuse the exact prealpha.11 DLL")


def _compare_dpkg_update(previous: dict[str, bytes], current: dict[str, bytes]) -> None:
    if set(previous) != set(current):
        raise BASE_BUILDER.PackageError("prealpha.12 Department DWPkg payload paths differ from prealpha.11")
    old_outer = _loads_object(previous["manifest.json"], "prealpha.11 DWPkg manifest")
    new_outer = _loads_object(current["manifest.json"], "prealpha.12 DWPkg manifest")
    old_outer["version"] = VERSION
    if new_outer != old_outer:
        raise BASE_BUILDER.PackageError("prealpha.12 DWPkg changed more than its version")
    old_inner = _loads_object(previous["server/manifest.json"], "prealpha.11 server manifest")
    new_inner = _loads_object(current["server/manifest.json"], "prealpha.12 server manifest")
    old_inner["version"] = VERSION
    if new_inner != old_inner:
        raise BASE_BUILDER.PackageError("prealpha.12 server manifest changed more than its version")
    allowed_changes = {"manifest.json", "server/manifest.json", "server/adapters/correspondence.php", "checksums.sha256"}
    changed = {path for path in previous if previous[path] != current[path]}
    if not changed.issubset(allowed_changes) or "server/adapters/correspondence.php" not in changed:
        raise BASE_BUILDER.PackageError("prealpha.12 DWPkg changed paths outside the approved correspondence update")


def _assemble_mod(base_files: dict[str, bytes], department_package: bytes, app_js: bytes) -> dict[str, bytes]:
    files = dict(base_files)
    del files[BASELINE_QD_MEMBER]
    files[QD_MEMBER] = department_package
    files[APP_JS_MEMBER] = app_js
    expected = (BASELINE_MO2_MEMBERS - {BASELINE_QD_MEMBER}) | {QD_MEMBER}
    if set(files) != expected:
        raise BASE_BUILDER.PackageError("assembled prealpha.12 MO2 member list differs")
    for path in set(base_files) & set(files):
        if path not in {APP_JS_MEMBER} and base_files[path] != files[path]:
            raise BASE_BUILDER.PackageError(f"prealpha.12 changed an unrelated MO2 member: {path}")
    if base_files[APP_JS_MEMBER] == app_js:
        raise BASE_BUILDER.PackageError("prealpha.12 UI script did not change from prealpha.11")
    return files


def _read_package_source(git_executable: str, commit: str, allowed: set[str], relative: str) -> bytes:
    return BASE_BUILDER.committed_worktree_file(git_executable, commit, allowed, relative)


def _build_department_package(read_source, old_package: dict[str, bytes]) -> bytes:
    package = BASE_BUILDER.build_server_component("questionable_decisions", read_source)
    current = BASE_BUILDER.audit_component_bytes(package, "questionable_decisions")
    _compare_dpkg_update(old_package, current)
    return package


def _safe_output_dir(output: Path) -> Path:
    resolved = output.resolve()
    root = ROOT.resolve()
    if resolved == root or root in resolved.parents:
        raise BASE_BUILDER.PackageError("release output must be outside the source repository")
    if output.exists() or output.is_symlink():
        raise BASE_BUILDER.PackageError("output directory must be new; refusing to overwrite it")
    return resolved


def _write_outputs(directory: Path, artifacts: dict[str, bytes]) -> None:
    if set(artifacts) != PUBLIC_CHECKSUM_NAMES:
        raise BASE_BUILDER.PackageError("public release output list is incomplete")
    for name, data in artifacts.items():
        BASE_BUILDER.validate_member_name(name)
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE_BUILDER.make_checksums(artifacts))


def expected_output_files(output: Path) -> dict[str, bytes]:
    if output.is_symlink() or not output.is_dir():
        raise BASE_BUILDER.PackageError("release output directory is missing or unsafe")
    expected = {*PUBLIC_CHECKSUM_NAMES, CHECKSUMS_NAME}
    children = list(output.iterdir())
    if {item.name for item in children} != expected or any(item.is_symlink() or not item.is_file() for item in children):
        raise BASE_BUILDER.PackageError("release output must contain only the three public ZIPs and checksums")
    return {item.name: item.read_bytes() for item in children}


def audit_output(output: Path, git_executable: str, source_commit: str, base_zip: Path, pairing_dir: Path) -> None:
    commit = BASE_BUILDER.canonical_source_commit(git_executable, source_commit)
    allowlist = BASE_BUILDER.source_allowlist(git_executable, commit)
    allowed = set(allowlist)
    outputs = expected_output_files(output)
    base_data = base_zip.read_bytes()
    base_files, old_qd = audit_base_archive(base_data)
    mod_files = BASE_BUILDER.read_zip_bytes(outputs[OUTPUT_MOD_ZIP], "prealpha.12 MO2 archive")
    expected_mod_names = (BASELINE_MO2_MEMBERS - {BASELINE_QD_MEMBER}) | {QD_MEMBER}
    if set(mod_files) != expected_mod_names:
        raise BASE_BUILDER.PackageError("prealpha.12 MO2 member list differs")
    BASE_BUILDER.audit_mo2_bytes(outputs[OUTPUT_MOD_ZIP])
    for path in set(base_files) & set(mod_files):
        if path != APP_JS_MEMBER and base_files[path] != mod_files[path]:
            raise BASE_BUILDER.PackageError(f"prealpha.12 MO2 changed an unrelated member: {path}")
    app = _read_package_source(git_executable, commit, allowed, "ui/app.js")
    BASE_BUILDER.require_canonical_bytes("UI app.js", mod_files[APP_JS_MEMBER], app)
    validate_dll(mod_files[DLL_MEMBER])
    new_qd = _verify_dpkg(mod_files[QD_MEMBER], VERSION)
    _compare_dpkg_update(old_qd, new_qd)

    def read_source(relative: str) -> bytes:
        return _read_package_source(git_executable, commit, allowed, relative)

    canonical_qd = _build_department_package(read_source, old_qd)
    BASE_BUILDER.require_canonical_bytes("questionable_decisions DWPkg", mod_files[QD_MEMBER], canonical_qd)
    source_zip = BASE_BUILDER.build_source_zip(git_executable, commit, allowlist)
    BASE_BUILDER.require_canonical_bytes("source ZIP", outputs[OUTPUT_SOURCE_ZIP], source_zip)
    proof_zip = build_pairing_zip(pairing_dir, git_executable, commit, allowed)
    BASE_BUILDER.require_canonical_bytes("pairing proof ZIP", outputs[OUTPUT_PAIRING_ZIP], proof_zip)
    BASE_BUILDER.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in PUBLIC_CHECKSUM_NAMES})


def build(git_executable: str, source_commit: str, base_zip: Path, pairing_dir: Path, output: Path) -> None:
    commit = BASE_BUILDER.canonical_source_commit(git_executable, source_commit)
    allowlist = BASE_BUILDER.source_allowlist(git_executable, commit)
    allowed = set(allowlist)
    resolved_output = _safe_output_dir(output)
    if base_zip.is_symlink() or not base_zip.is_file():
        raise BASE_BUILDER.PackageError("verified prealpha.11 ZIP input must be a regular file")
    base_files, old_qd = audit_base_archive(base_zip.read_bytes())

    def read_source(relative: str) -> bytes:
        return _read_package_source(git_executable, commit, allowed, relative)

    department_package = _build_department_package(read_source, old_qd)
    mo2_files = _assemble_mod(base_files, department_package, read_source("ui/app.js"))
    mod_zip = BASE_BUILDER.stable_zip_bytes(mo2_files)
    source_zip = BASE_BUILDER.build_source_zip(git_executable, commit, allowlist)
    pairing_zip = build_pairing_zip(pairing_dir, git_executable, commit, allowed)
    artifacts = {
        OUTPUT_MOD_ZIP: mod_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PAIRING_ZIP: pairing_zip,
    }

    resolved_output.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{resolved_output.name}-", dir=resolved_output.parent))
    try:
        _write_outputs(stage, artifacts)
        audit_output(stage, git_executable, commit, base_zip, pairing_dir)
        os.rename(stage, resolved_output)
    except Exception:
        if stage.exists():
            shutil.rmtree(stage)
        raise


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    build_parser = subparsers.add_parser("build", help="build the MO2, source, and developer-proof ZIPs")
    build_parser.add_argument("--prealpha11-zip", required=True, type=Path, help="verified prealpha.11 MO2 ZIP")
    build_parser.add_argument("--pairing-dir", required=True, type=Path, help="directory with the four accepted pairing receipts")
    build_parser.add_argument("--source-commit", required=True, help="full commit ID at current HEAD")
    build_parser.add_argument("--output-dir", required=True, type=Path, help="new output directory outside the source repository")
    build_parser.add_argument("--git", default="git", help="git executable")
    audit_parser = subparsers.add_parser("audit", help="reopen and audit all three release ZIPs")
    audit_parser.add_argument("--prealpha11-zip", required=True, type=Path)
    audit_parser.add_argument("--pairing-dir", required=True, type=Path)
    audit_parser.add_argument("--source-commit", required=True, help="full commit ID at current HEAD")
    audit_parser.add_argument("--output-dir", required=True, type=Path)
    audit_parser.add_argument("--git", default="git", help="git executable")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    try:
        if args.command == "build":
            build(args.git, args.source_commit, args.prealpha11_zip, args.pairing_dir, args.output_dir)
            print(f"Built and audited: {args.output_dir.resolve()}")
        else:
            audit_output(args.output_dir.resolve(), args.git, args.source_commit, args.prealpha11_zip, args.pairing_dir)
            print(f"Audit passed: {args.output_dir.resolve()}")
    except (BASE_BUILDER.PackageError, OSError, subprocess.SubprocessError, zipfile.BadZipFile) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
