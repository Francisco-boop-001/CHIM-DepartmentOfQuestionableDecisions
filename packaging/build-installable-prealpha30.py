#!/usr/bin/env python3
"""Build and audit the source-bound Department prealpha.30 release."""

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
from pathlib import Path


_PREVIOUS_PATH = Path(__file__).with_name("build-installable-prealpha29.py")
_PREVIOUS_SPEC = importlib.util.spec_from_file_location("_department_prealpha29_for_release30", _PREVIOUS_PATH)
if _PREVIOUS_SPEC is None or _PREVIOUS_SPEC.loader is None:
    raise RuntimeError("could not load the reviewed prealpha.29 package primitives")
PREVIOUS = importlib.util.module_from_spec(_PREVIOUS_SPEC)
_PREVIOUS_SPEC.loader.exec_module(PREVIOUS)

BASE = PREVIOUS.BASE
PCV = PREVIOUS.PCV
PackageError = PREVIOUS.PackageError
ROOT = Path(__file__).resolve().parents[1]

VERSION = "0.1.0-prealpha.30"
BASELINE_VERSION = "0.1.0-prealpha.29"
BASELINE_SHA256 = "eebf37d6eed330ab37f4b5e1ef064f23514286a6cf0e092e3ce8994ececbf48a"
BASELINE_SOURCE_COMMIT = "6f540338f9910b7e24127a961c41ceeda55a9547"
OWNER_VERSION = "0.1.0-dev.31"
OWNER_SOURCE_COMMIT = "e393bd5f9a1cd31a8d8d006a4356faddc9eb3212"
OWNER_DPKG_SHA256 = "7d17ec6cefd21540d01194f719bd69c9961f6d48b5a1bb35d9f86a9ae76f0777"
OWNER_SOURCE_CLOSURE_SHA256 = "4fa413b91e4ee12ab8c8c272b14afd2a8b4b0e04229ae61b3773519861daf9c7"
OWNER_HANDOFF_REFERENCE_SHA256 = "742a13eac8348b2a86e81f6a690cdce500522bc959d26d219e3fffa213f9970f"
OWNER_NEW_SOURCE_PATH = "preprocessing.php"

SERVER_MANIFEST = "server/manifest.json"
ADAPTER_SOURCE = "server/adapters/correspondence.php"
ALLOWLIST_PATH = "packaging/public-source-allowlist.json"
UI_APP_SOURCE = "ui/app.js"
UI_TEST_SOURCE = "ui/tests/ui-functional.cjs"
UI_APP_MEMBER = PREVIOUS.UI_APP_MEMBER
BASELINE_DPKG_MEMBER = PREVIOUS.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = PREVIOUS.PCV_MEMBER
PCV_VERSION = PREVIOUS.PCV_VERSION
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"
EXPECTED_BASELINE_MEMBERS = frozenset(PREVIOUS.EXPECTED_INSTALLABLE_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}

RELEASE_ALLOWLIST_ADDITIONS = (
    "docs/DEV31-COMPATIBILITY.md",
    "packaging/build-installable-prealpha30.py",
    "packaging/tests/test_installable_prealpha30.py",
    "server/tests/correspondence/dev31-adapter-interface.php",
    "server/tests/correspondence/dev31-source-contract.php",
    "server/tests/correspondence/fixtures/owner-dev31-handoff.json",
    "server/tests/dev31-pg/cases.json",
    "server/tests/dev31-pg/core-inputs.json",
    "server/tests/dev31-pg/generation_change_driver.php",
    "server/tests/dev31-pg/owner-pins.json",
)
ALLOWED_SOURCE_DIFF_PATHS = frozenset({
    "README.md",
    "docs/BUILD.md",
    "docs/DEV31-COMPATIBILITY.md",
    "packaging/public-source-allowlist.json",
    *RELEASE_ALLOWLIST_ADDITIONS,
    "server/manifest.json",
    ADAPTER_SOURCE,
    "server/tests/correspondence/dev23-source-contract.php",
    "server/tests/correspondence/dev30-adapter-interface.php",
    "server/tests/correspondence/resume-contract.php",
    "server/tests/dev30-pg/actual_pg.php",
    UI_APP_SOURCE,
    UI_TEST_SOURCE,
    "tasks/todo.md",
})

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
BUILD_AUDIT_NAME = "prealpha30-package-audit.json"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, BUILD_AUDIT_NAME}
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME}
_FULL_COMMIT = re.compile(r"\A[a-f0-9]{40}\Z")

OLD_OWNER_VERSIONS = (
    "0.1.0-dev.14", "0.1.0-dev.15", "0.1.0-dev.16", "0.1.0-dev.17",
    "0.1.0-dev.18", "0.1.0-dev.20", "0.1.0-dev.21", "0.1.0-dev.22",
    "0.1.0-dev.23", "0.1.0-dev.24", "0.1.0-dev.25", "0.1.0-dev.26",
    "0.1.0-dev.27", "0.1.0-dev.28", "0.1.0-dev.29", "0.1.0-dev.30",
)


_git = PREVIOUS._git
_git_blob = PREVIOUS._git_blob
_json_object = PREVIOUS._json_object
_allowlist_paths = PREVIOUS._allowlist_paths


def validate_source_diff_paths(changed_paths: list[str]) -> None:
    for path in changed_paths:
        if path not in ALLOWED_SOURCE_DIFF_PATHS:
            raise PackageError(f"source changed outside the prealpha.30 release scope: {path}")


def validate_manifest_transition(old_bytes: bytes, release_bytes: bytes) -> dict:
    old = _json_object(old_bytes, "published prealpha.29 server manifest")
    release = _json_object(release_bytes, "prealpha.30 server manifest")
    expected = dict(old)
    if (old.get("version") != BASELINE_VERSION
            or release.get("version") != VERSION
            or list(release) != list(old)):
        raise PackageError("server manifest is not a version-only prealpha.29 to prealpha.30 change")
    expected["version"] = VERSION
    if release != expected or BASE.json_bytes(expected) != release_bytes:
        raise PackageError("server manifest is not a version-only prealpha.29 to prealpha.30 change")
    return {"baselineSha256": BASE.sha256(old_bytes), "releaseSha256": BASE.sha256(release_bytes)}


def _parse_owner_pin_map(adapter_bytes: bytes, label: str) -> dict[str, dict[str, str]]:
    try:
        text = adapter_bytes.decode("utf-8")
    except UnicodeDecodeError as error:
        raise PackageError(f"{label} is not UTF-8") from error
    marker = re.search(r"const QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION = \[\r?\n", text)
    if marker is None:
        raise PackageError(f"{label} has no complete correspondence source pin table")
    table_end = re.search(r"\r?\n\];", text[marker.end():])
    if table_end is None:
        raise PackageError(f"{label} has no complete correspondence source pin table")
    lines = text[marker.end():marker.end() + table_end.start()].splitlines()
    result: dict[str, dict[str, str]] = {}
    index = 0
    while index < len(lines):
        if not lines[index].strip():
            index += 1
            continue
        header = re.fullmatch(r"    '([^']+)' => \[", lines[index])
        if header is None or header.group(1) in result:
            raise PackageError(f"{label} has a malformed or duplicate version entry")
        version = header.group(1)
        index += 1
        pins: dict[str, str] = {}
        while index < len(lines) and lines[index] != "    ],":
            pin = re.fullmatch(r"        '([^']+)' => '([a-f0-9]{64})',", lines[index])
            if pin is None or pin.group(1) in pins:
                raise PackageError(f"{label} has a malformed or duplicate pin for {version}")
            BASE.validate_member_name(pin.group(1))
            pins[pin.group(1)] = pin.group(2)
            index += 1
        if index >= len(lines) or not pins:
            raise PackageError(f"{label} has an incomplete pin entry for {version}")
        result[version] = pins
        index += 1
    return result


def _audit_public_owner_dwpkg(data: bytes) -> dict[str, bytes]:
    if BASE.sha256(data) != OWNER_DPKG_SHA256:
        raise PackageError("owner DWPkg is not the exact verified public Sealed Fates dev.31 package")
    files = BASE.read_zip_bytes(data, "public Sealed Fates dev.31 DWPkg")
    if "checksums.sha256" not in files:
        raise PackageError("public dev.31 DWPkg has no checksum closure")
    checksums = BASE.parse_checksums(files["checksums.sha256"], "public Sealed Fates dev.31 DWPkg")
    payload = {path: content for path, content in files.items() if path != "checksums.sha256"}
    if (set(checksums) != set(payload)
            or any(checksums[path] != BASE.sha256(content) for path, content in payload.items())):
        raise PackageError("public dev.31 DWPkg checksum closure is incomplete or mismatched")
    outer = _json_object(files.get("manifest.json", b""), "public dev.31 outer manifest")
    inner = _json_object(files.get("server/manifest.json", b""), "public dev.31 server manifest")
    if (outer.get("name") != "correspondence"
            or outer.get("version") != OWNER_VERSION
            or outer.get("schema_version") != 4
            or inner.get("name") != "correspondence"
            or inner.get("version") != OWNER_VERSION
            or inner.get("schema_version") != 2):
        raise PackageError("public dev.31 DWPkg manifests do not identify the pinned correspondence owner")
    return files


def _table_block(text: str) -> tuple[int, int, str]:
    marker = "const QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION = [\n"
    start = text.find(marker)
    close = text.find("\n];", start + len(marker)) if start >= 0 else -1
    if start < 0 or close < 0:
        raise PackageError("correspondence adapter has no complete source pin table")
    end = close + len("\n];")
    return start, end, text[start:end]


def _function_block(text: str, name: str) -> tuple[int, int, str]:
    marker = f"function {name}("
    start = text.find(marker)
    if start < 0:
        raise PackageError(f"correspondence adapter is missing {name}")
    end = text.find("\n}\n", start)
    if end < 0:
        raise PackageError(f"correspondence adapter has an incomplete {name}")
    end += 2
    return start, end, text[start:end]


def _replace_exact(text: str, current: str, expected: str, count: int, label: str) -> str:
    if text.count(current) != count:
        raise PackageError(f"correspondence adapter {label} change differs from the reviewed dev.31 scope")
    return text.replace(current, expected)


def validate_adapter_delta(old_adapter: bytes, release_adapter: bytes) -> None:
    try:
        old_text = old_adapter.decode("utf-8").replace("\r\n", "\n")
        new_text = release_adapter.decode("utf-8").replace("\r\n", "\n")
    except UnicodeDecodeError as error:
        raise PackageError("correspondence adapter source is not UTF-8") from error

    old_start, old_end, old_table = _table_block(old_text)
    new_start, new_end, new_table = _table_block(new_text)
    table_lines = new_table.splitlines(keepends=True)
    entry_start = next(
        (index for index, line in enumerate(table_lines)
         if line == f"    '{OWNER_VERSION}' => [\n"),
        None,
    )
    if entry_start is None:
        raise PackageError("correspondence adapter dev.31 map is missing")
    entry_end = next(
        (index for index in range(entry_start + 1, len(table_lines))
         if table_lines[index] == "    ],\n"),
        None,
    )
    if entry_end is None:
        raise PackageError("correspondence adapter dev.31 map is incomplete")
    stripped_table = "".join(table_lines[:entry_start] + table_lines[entry_end + 1:])
    if stripped_table != old_table:
        raise PackageError("correspondence adapter changed the prior source map beyond appending dev.31")
    new_text = new_text[:new_start] + stripped_table + new_text[new_end:]

    gate_names = (
        "qd_correspondence_version_uses_restore_fence",
        "qd_correspondence_version_supports_signed_unknown",
        "qd_correspondence_version_supports_operator_close",
        "qd_correspondence_version_supports_companion_scheduler",
    )
    for name in gate_names:
        old_start, old_end, old_function = _function_block(old_text, name)
        new_start, new_end, new_function = _function_block(new_text, name)
        expected = old_function.replace("], true);", ", '0.1.0-dev.31'], true);")
        if expected == old_function or new_function != expected:
            raise PackageError(f"correspondence adapter changed {name} beyond adding dev.31")
        new_text = new_text[:new_start] + old_function + new_text[new_end:]

    helper_start, helper_end, helper = _function_block(new_text, "qd_correspondence_version_supports_pending_resume")
    expected_helper = (
        "function qd_correspondence_version_supports_pending_resume(string $version): bool\n"
        "{\n"
        "    return in_array($version, ['0.1.0-dev.30', '0.1.0-dev.31'], true);\n"
        "}"
    )
    if helper != expected_helper or "qd_correspondence_version_supports_pending_resume(" in old_text:
        raise PackageError("correspondence adapter pending-resume helper differs from the strict dev.30/dev.31 gate")
    next_function = new_text.find("\nfunction ", helper_end)
    if next_function < 0:
        raise PackageError("correspondence adapter pending-resume helper is not followed by the existing runtime")
    new_text = new_text[:helper_start] + new_text[next_function + 1:]

    for current, expected, count, label in (
        (
            "if (!qd_correspondence_version_supports_pending_resume(qd_correspondence_loaded_source_version()) || !qd_correspondence_uses_restore_fence()) {",
            "if (qd_correspondence_loaded_source_version() !== '0.1.0-dev.30' || !qd_correspondence_uses_restore_fence()) {",
            2,
            "pending/resume version checks",
        ),
        (
            "!is_array($sourceIdentity) || !is_string($sourceIdentity['version'] ?? null) || !qd_correspondence_version_supports_pending_resume($sourceIdentity['version'])",
            "!is_array($sourceIdentity) || ($sourceIdentity['version'] ?? null) !== '0.1.0-dev.30'",
            1,
            "source identity check",
        ),
        (
            "return qd_correspondence_version_supports_pending_resume($version)",
            "return $version === '0.1.0-dev.30'",
            1,
            "resume eligibility check",
        ),
        (
            "$includeResumeMetadata && qd_correspondence_version_supports_pending_resume($version)",
            "$includeResumeMetadata && $version === '0.1.0-dev.30'",
            2,
            "resume metadata checks",
        ),
        (
            "!is_string($version) || !qd_correspondence_version_supports_pending_resume($version)",
            "!is_string($version) || $version !== '0.1.0-dev.30'",
            1,
            "pending binding check",
        ),
    ):
        new_text = _replace_exact(new_text, current, expected, count, label)

    if new_text != old_text:
        raise PackageError("correspondence adapter changed outside the dev.31 pin and capability gates")


def validate_owner_map_transition(old_adapter: bytes, release_adapter: bytes, owner_files: dict[str, bytes]) -> dict:
    old_map = _parse_owner_pin_map(old_adapter, "published prealpha.29 correspondence adapter")
    new_map = _parse_owner_pin_map(release_adapter, "prealpha.30 correspondence adapter")
    if tuple(old_map) != OLD_OWNER_VERSIONS:
        raise PackageError("published prealpha.29 adapter does not contain the fixed dev.14-dev.30 pin map")
    if tuple(new_map) != (*OLD_OWNER_VERSIONS, OWNER_VERSION):
        raise PackageError("prealpha.30 adapter must append only the dev.31 owner map")
    if any(new_map[version] != old_map[version] for version in OLD_OWNER_VERSIONS):
        raise PackageError("prealpha.30 changed a previously supported Sealed Fates pin map")
    expected_paths = tuple(old_map["0.1.0-dev.30"]) + (OWNER_NEW_SOURCE_PATH,)
    pins = new_map[OWNER_VERSION]
    if tuple(pins) != expected_paths or len(pins) != 21:
        raise PackageError("dev.31 map must contain the exact 20-file dev.30 closure plus preprocessing.php")

    pin_records = {}
    for path, digest in pins.items():
        content = owner_files.get(f"server/{path}")
        if not isinstance(content, bytes) or BASE.sha256(content) != digest:
            raise PackageError(f"dev.31 adapter pin differs from the actual public owner DWPkg: {path}")
        pin_records[path] = {"bytes": len(content), "sha256": digest}
    validate_adapter_delta(old_adapter, release_adapter)
    return {
        "preservedVersionCount": len(OLD_OWNER_VERSIONS),
        "addedVersion": OWNER_VERSION,
        "addedPinCount": len(pins),
        "pinClosureSha256": BASE.sha256(BASE.json_bytes(pin_records)),
        "ownerDwpkgSha256": OWNER_DPKG_SHA256,
        "ownerSourceCommit": OWNER_SOURCE_COMMIT,
        "ownerSourceClosureSha256": OWNER_SOURCE_CLOSURE_SHA256,
        "ownerPublicHandoffSha256Reference": OWNER_HANDOFF_REFERENCE_SHA256,
    }


_stable_zip_bytes = PREVIOUS._stable_zip_bytes


def _read_baseline(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact published prealpha.29 archive")
    files = BASE.read_zip_bytes(data, "published prealpha.29 ZIP")
    if set(files) != set(EXPECTED_BASELINE_MEMBERS) or len(files) != 22:
        raise PackageError("published prealpha.29 ZIP member set differs")
    department = PCV._audit_department(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION, pcv25=True)
    pcv = PREVIOUS.P20._audit_component(files[PCV_MEMBER], "pcv_companion", PCV_VERSION)
    return files, department, pcv


def _build_department_package(
    old_package: dict[str, bytes],
    old_server_manifest: bytes,
    release_server_manifest: bytes,
    release_adapter: bytes,
    owner_files: dict[str, bytes],
) -> tuple[bytes, dict[str, bytes], list[str], dict]:
    if old_package.get(SERVER_MANIFEST) != old_server_manifest:
        raise PackageError("prealpha.29 DWPkg server manifest differs from its pinned source commit")
    old_adapter = old_package.get(ADAPTER_SOURCE)
    if old_adapter != _git_blob(BASELINE_SOURCE_COMMIT, ADAPTER_SOURCE):
        raise PackageError("prealpha.29 DWPkg adapter differs from its pinned source commit")
    manifest_transition = validate_manifest_transition(old_server_manifest, release_server_manifest)
    owner_transition = validate_owner_map_transition(old_adapter, release_adapter, owner_files)

    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.29 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION or BASE.json_bytes(outer) != current["manifest.json"]:
        raise PackageError("prealpha.29 Department outer manifest has the wrong version or is not canonical JSON")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    current[SERVER_MANIFEST] = release_server_manifest
    current[ADAPTER_SOURCE] = release_adapter
    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)

    package = _stable_zip_bytes(current)
    verified = PCV._audit_department(package, VERSION, pcv25=True)
    changed = sorted(path for path in old_package if old_package[path] != verified.get(path))
    expected = ["checksums.sha256", "manifest.json", ADAPTER_SOURCE, SERVER_MANIFEST]
    if changed != expected:
        raise PackageError(f"Department DWPkg changed outside its manifests, correspondence adapter, and checksums: {changed}")
    if manifest_transition["releaseSha256"] != BASE.sha256(release_server_manifest):
        raise PackageError("Department server manifest transition receipt differs")
    return package, verified, changed, owner_transition


def assemble_installable(
    baseline_zip: bytes,
    source_files: dict[str, bytes],
    old_server_manifest: bytes,
    owner_dwpkg_bytes: bytes,
) -> tuple[bytes, dict]:
    baseline, old_department, old_pcv = _read_baseline(baseline_zip)
    for path in (SERVER_MANIFEST, ADAPTER_SOURCE, UI_APP_SOURCE):
        if not isinstance(source_files.get(path), bytes) or not source_files[path]:
            raise PackageError(f"committed release source is missing or empty: {path}")
    owner_files = _audit_public_owner_dwpkg(owner_dwpkg_bytes)
    department_package, _new_department, department_changes, owner_transition = _build_department_package(
        old_department,
        old_server_manifest,
        source_files[SERVER_MANIFEST],
        source_files[ADAPTER_SOURCE],
        owner_files,
    )
    release = {path: content for path, content in baseline.items() if path != BASELINE_DPKG_MEMBER}
    release[DPKG_MEMBER] = department_package
    release[UI_APP_MEMBER] = source_files[UI_APP_SOURCE]
    if set(release) != set(EXPECTED_INSTALLABLE_MEMBERS) or len(release) != 22:
        raise PackageError("prealpha.30 installable member set differs from its fixed contract")

    common = set(release) & set(baseline)
    changed_common = {path for path in common if release[path] != baseline[path]}
    if changed_common != {UI_APP_MEMBER}:
        raise PackageError("prealpha.30 must change only UI app.js among prior installable payloads")
    unchanged = sorted(path for path in common if release[path] == baseline[path])
    if len(unchanged) != 20:
        raise PackageError("prealpha.30 must preserve twenty prior installable payloads byte-for-byte")
    if old_pcv != PREVIOUS.P20._audit_component(release[PCV_MEMBER], "pcv_companion", PCV_VERSION):
        raise PackageError("prealpha.30 changed the pinned PCV companion package")
    if release[DLL_MEMBER] != baseline[DLL_MEMBER]:
        raise PackageError("prealpha.30 changed the native DLL")

    runtime_zip = _stable_zip_bytes(release)
    if BASE.read_zip_bytes(runtime_zip, "prealpha.30 installable ZIP") != release:
        raise PackageError("prealpha.30 installable ZIP did not round-trip exactly")
    audit = {
        "departmentDwpkgChangedMembers": department_changes,
        "changedInstallableMembers": sorted({BASELINE_DPKG_MEMBER, DPKG_MEMBER, UI_APP_MEMBER}),
        "unchangedInstallableMembers": unchanged,
        "pcvCompanionVersion": PCV_VERSION,
        "pcvCompanionSha256": BASE.sha256(release[PCV_MEMBER]),
        "nativeDllSha256": BASE.sha256(release[DLL_MEMBER]),
        "ownerPinTransition": owner_transition,
    }
    return runtime_zip, audit


def _validate_source_inputs(source_commit: str, source_files: dict[str, bytes], baseline_allowlist: bytes) -> tuple[list[str], dict]:
    if _FULL_COMMIT.fullmatch(source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    allowlist_bytes = source_files.get(ALLOWLIST_PATH)
    if allowlist_bytes is None:
        raise PackageError("committed source allowlist is missing")
    paths = _allowlist_paths(allowlist_bytes, "committed source allowlist")
    old_paths = _allowlist_paths(baseline_allowlist, "published prealpha.29 source allowlist")
    if paths != old_paths + list(RELEASE_ALLOWLIST_ADDITIONS):
        raise PackageError("prealpha.30 source allowlist must append only the declared release builder, tests, and compatibility note")
    if set(paths) != set(source_files):
        raise PackageError("source bytes do not match the exact committed public allowlist")
    return paths, validate_manifest_transition(
        _git_blob(BASELINE_SOURCE_COMMIT, SERVER_MANIFEST), source_files.get(SERVER_MANIFEST, b"")
    )


def _validate_source_diff(source_commit: str) -> None:
    changed = _git("diff", "--name-only", "--no-renames", BASELINE_SOURCE_COMMIT, source_commit, "--")
    validate_source_diff_paths(changed.decode("utf-8").splitlines())


def _build_assets(
    baseline_zip: bytes,
    owner_dwpkg: bytes,
    source_commit: str,
    source_files: dict[str, bytes],
    baseline_allowlist: bytes,
    old_server_manifest: bytes,
) -> dict[str, bytes]:
    paths, manifest_audit = _validate_source_inputs(source_commit, source_files, baseline_allowlist)
    _validate_source_diff(source_commit)
    if source_files[SERVER_MANIFEST] != _git_blob(source_commit, SERVER_MANIFEST):
        raise PackageError("prealpha.30 server manifest differs from the selected source commit")
    source_zip = _stable_zip_bytes(source_files)
    runtime_zip, installable_audit = assemble_installable(
        baseline_zip, source_files, old_server_manifest, owner_dwpkg
    )
    owner = installable_audit["ownerPinTransition"]
    receipt = {
        "schemaVersion": 1,
        "kind": "department-prealpha30-source-bound-package",
        "version": VERSION,
        "sourceCommit": source_commit,
        "baseline": {
            "version": BASELINE_VERSION,
            "sha256": BASELINE_SHA256,
            "sourceCommit": BASELINE_SOURCE_COMMIT,
        },
        "owner": {
            "version": OWNER_VERSION,
            "sourceCommit": OWNER_SOURCE_COMMIT,
            "dwpkgSha256": owner["ownerDwpkgSha256"],
            "pinCount": owner["addedPinCount"],
            "preservedDepartmentMapVersionCount": owner["preservedVersionCount"],
            "sourceClosureSha256": owner["ownerSourceClosureSha256"],
            "publicHandoffSha256Reference": owner["ownerPublicHandoffSha256Reference"],
            "departmentPinClosureSha256": owner["pinClosureSha256"],
        },
        "installableSha256": BASE.sha256(runtime_zip),
        "sourceArchiveSha256": BASE.sha256(source_zip),
        "sourceAllowlistSha256": BASE.sha256(source_files[ALLOWLIST_PATH]),
        "sourceFileCount": len(paths),
        "serverManifestTransition": manifest_audit,
        **installable_audit,
        "limits": {
            "sourceAndPackageAssembly": True,
            "publicOwnerDwpkgAndPinsVerified": True,
            "serverDatabaseIntegration": False,
            "http": False,
            "provider": False,
            "installedGame": False,
            "skyrim": False,
            "mouseFix": False,
            "published": False,
        },
    }
    receipt_bytes = BASE.json_bytes(receipt)
    checksums = BASE.make_checksums({
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        BUILD_AUDIT_NAME: receipt_bytes,
    })
    if not checksums.endswith(b"\n") or b"\r" in checksums:
        raise PackageError("release checksums must use canonical LF-only lines")
    return {
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        CHECKSUMS_NAME: checksums,
        BUILD_AUDIT_NAME: receipt_bytes,
    }


def _safe_input(path: Path, label: str) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise PackageError(f"{label} is missing or unsafe")
    return path.read_bytes()


def _inputs(args) -> tuple[str, bytes, bytes, dict[str, bytes], bytes, bytes]:
    if _FULL_COMMIT.fullmatch(args.source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    head = _git("rev-parse", "HEAD").decode("ascii").strip()
    if args.source_commit != head:
        raise PackageError("release source commit must be the current frozen checkout HEAD")
    dirty = _git("status", "--porcelain", "--untracked-files=normal").decode("utf-8").strip()
    if dirty:
        raise PackageError("source checkout must be clean before packaging")
    baseline_zip = _safe_input(args.baseline_zip, "published prealpha.29 ZIP")
    owner_dwpkg = _safe_input(args.owner_dwpkg, "verified public dev.31 DWPkg")
    allowlist = _git_blob(args.source_commit, ALLOWLIST_PATH)
    paths = _allowlist_paths(allowlist, "committed source allowlist")
    source_files = {path: _git_blob(args.source_commit, path) for path in paths}
    baseline_allowlist = _git_blob(BASELINE_SOURCE_COMMIT, ALLOWLIST_PATH)
    old_server_manifest = _git_blob(BASELINE_SOURCE_COMMIT, SERVER_MANIFEST)
    return args.source_commit, baseline_zip, owner_dwpkg, source_files, baseline_allowlist, old_server_manifest


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("release output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if (set(path.name for path in entries) != OUTPUT_FILE_NAMES
            or any(path.is_symlink() or not path.is_file() for path in entries)):
        raise PackageError("release output must contain only the installable, source, checksums, and package audit")
    return {path.name: path.read_bytes() for path in entries}


def audit_output(args) -> None:
    commit, baseline_zip, owner_dwpkg, source_files, baseline_allowlist, old_manifest = _inputs(args)
    expected = _build_assets(
        baseline_zip, owner_dwpkg, commit, source_files, baseline_allowlist, old_manifest
    )
    actual = _expected_output_files(args.output_dir)
    for name in sorted(OUTPUT_FILE_NAMES):
        if actual[name] != expected[name]:
            raise PackageError(f"release output differs from the canonical source-bound build: {name}")
    BASE.audit_checksums_file(actual[CHECKSUMS_NAME], {name: actual[name] for name in OUTPUT_NAMES})


def _write_outputs(directory: Path, assets: dict[str, bytes]) -> None:
    if set(assets) != OUTPUT_FILE_NAMES or directory.is_symlink() or not directory.is_dir() or any(directory.iterdir()):
        raise PackageError("release staging directory is unsafe, not empty, or has an incomplete output set")
    for name, content in assets.items():
        BASE.validate_member_name(name)
        with (directory / name).open("xb") as stream:
            stream.write(content)


def build(args) -> None:
    commit, baseline_zip, owner_dwpkg, source_files, baseline_allowlist, old_manifest = _inputs(args)
    assets = _build_assets(
        baseline_zip, owner_dwpkg, commit, source_files, baseline_allowlist, old_manifest
    )
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    parent = output.parent.resolve()
    root = ROOT.resolve()
    if parent == root or root in parent.parents or output.resolve() == root or root in output.resolve().parents:
        raise PackageError("release output directory must be outside the source repository")
    if output.parent.is_symlink():
        raise PackageError("release output parent must not be a symlink")
    output.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, assets)
        staged = _expected_output_files(stage)
        for name in sorted(OUTPUT_FILE_NAMES):
            if staged[name] != assets[name]:
                raise PackageError(f"staged output differs from the canonical build: {name}")
        BASE.audit_checksums_file(staged[CHECKSUMS_NAME], {name: staged[name] for name in OUTPUT_NAMES})
        os.rename(stage, output)
    except Exception:
        if stage.exists():
            shutil.rmtree(stage)
        raise


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for command in ("build", "audit"):
        sub = commands.add_parser(command)
        sub.add_argument("--baseline-zip", required=True, type=Path)
        sub.add_argument("--owner-dwpkg", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        if args.command == "build":
            build(args)
            print(f"Built and source-audited prealpha.30 package: {args.output_dir.resolve()}")
        else:
            audit_output(args)
            print(f"Source-bound prealpha.30 package audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, ValueError, KeyError, TypeError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
