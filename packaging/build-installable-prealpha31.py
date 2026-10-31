#!/usr/bin/env python3
"""Build and audit the source-bound Department prealpha.31 release."""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import shutil
import struct
import subprocess
import sys
import tempfile
from pathlib import Path


def _load_builder(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"could not load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


PREVIOUS = _load_builder(
    "_department_prealpha30_for_release31", Path(__file__).with_name("build-installable-prealpha30.py")
)
P28 = _load_builder(
    "_department_prealpha28_native_auditor", Path(__file__).with_name("build-installable-prealpha28.py")
)

BASE = PREVIOUS.BASE
PCV = PREVIOUS.PCV
P20 = PREVIOUS.PREVIOUS.P20
PackageError = PREVIOUS.PackageError
ROOT = PREVIOUS.ROOT

VERSION = "0.1.0-prealpha.31"
BASELINE_VERSION = "0.1.0-prealpha.30"
BASELINE_SHA256 = "d8ae8a87cea4f18e41f723a7ea404e5cc16fbb2d1fed5491bde3ffd9d90f0d47"
BASELINE_SOURCE_COMMIT = "f4d654ccaf46f8b4d8fc2b12a984e460645ba5de"
NATIVE_VERSION = "0.1.19.0"
NATIVE_VERSION_PACKED = 65840

SERVER_MANIFEST = PREVIOUS.SERVER_MANIFEST
ALLOWLIST_PATH = PREVIOUS.ALLOWLIST_PATH
UI_APP_SOURCE = PREVIOUS.UI_APP_SOURCE
UI_TEST_SOURCE = PREVIOUS.UI_TEST_SOURCE
UI_APP_MEMBER = PREVIOUS.UI_APP_MEMBER
BASELINE_DPKG_MEMBER = PREVIOUS.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = PREVIOUS.PCV_MEMBER
PCV_VERSION = PREVIOUS.PCV_VERSION
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"
NATIVE_BUILD_RECIPE = P28.NATIVE_BUILD_RECIPE
NATIVE_PLUGIN_SOURCE = "native/src/Plugin.cpp"
NATIVE_CLOSURE_PATHS = P28.NATIVE_CLOSURE_PATHS
BOOTSTRAP_FIXTURE_SOURCE_PATHS = P28.BOOTSTRAP_FIXTURE_SOURCE_PATHS
EXPECTED_BASELINE_MEMBERS = frozenset(PREVIOUS.EXPECTED_INSTALLABLE_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
RELEASE_ALLOWLIST_ADDITIONS = (
    "docs/REPAIR31.md",
    "packaging/build-installable-prealpha31.py",
    "packaging/tests/test_installable_prealpha31.py",
)
ALLOWED_SOURCE_DIFF_PATHS = frozenset({
    "README.md",
    "docs/BUILD.md",
    "docs/REPAIR31.md",
    ALLOWLIST_PATH,
    *RELEASE_ALLOWLIST_ADDITIONS,
    SERVER_MANIFEST,
    "native/CMakeLists.txt",
    "native/src/Plugin.cpp",
    "native/tests/NativeBoundaryTests.cpp",
    UI_APP_SOURCE,
    UI_TEST_SOURCE,
    "tasks/todo.md",
})

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
BUILD_AUDIT_NAME = "prealpha31-package-audit.json"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, BUILD_AUDIT_NAME}
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME}
FULL_COMMIT = re.compile(r"\A[a-f0-9]{40}\Z")
SKSE_EXPORTS = P28._SKSE_EXPORTS


def validate_source_diff_paths(changed_paths: list[str]) -> None:
    for path in changed_paths:
        if path not in ALLOWED_SOURCE_DIFF_PATHS:
            raise PackageError(f"source changed outside the prealpha.31 release scope: {path}")


def validate_manifest_transition(old_bytes: bytes, release_bytes: bytes) -> dict:
    old = PREVIOUS._json_object(old_bytes, "published prealpha.30 server manifest")
    release = PREVIOUS._json_object(release_bytes, "prealpha.31 server manifest")
    expected = dict(old)
    if (old.get("version") != BASELINE_VERSION
            or release.get("version") != VERSION
            or list(release) != list(old)):
        raise PackageError("server manifest is not a version-only prealpha.30 to prealpha.31 change")
    expected["version"] = VERSION
    if release != expected or BASE.json_bytes(expected) != release_bytes:
        raise PackageError("server manifest is not a version-only prealpha.30 to prealpha.31 change")
    return {"baselineSha256": BASE.sha256(old_bytes), "releaseSha256": BASE.sha256(release_bytes)}


def validate_ui_app_delta(old_bytes: bytes, release_bytes: bytes) -> None:
    old = old_bytes.decode("utf-8").replace("\r\n", "\n")
    release = release_bytes.decode("utf-8").replace("\r\n", "\n")
    current = (
        "        const scanComplete = pending.nextCursor === null && !pending.limited;\n"
        "        if (scanComplete && state.mail.currentOperationId && !discoveredIds.has(state.mail.currentOperationId)) {\n"
        "          state.mail.currentOperationId = null;\n"
        "          state.mail.operationDraft = null;\n"
        "          state.mail.composeLocked = false;\n"
        "          state.mail.reviewed = false;\n"
        "          $('letter-review-summary').hidden = true;\n"
        "        }\n"
        "        state.mail.pendingReadDiscoveredIds = discoveredIds;\n"
        "        state.mail.operations = scanComplete ? operations.filter((operation) => discoveredIds.has(operation.operationId)) : operations;"
    )
    expected = (
        "        state.mail.pendingReadDiscoveredIds = discoveredIds;\n"
        "        state.mail.operations = operations;"
    )
    if old.count(current) != 1 or old.replace(current, expected, 1) != release:
        raise PackageError("prealpha.31 UI source changed outside preserving omitted current letter operations")


def validate_native_plugin_delta(old_bytes: bytes, release_bytes: bytes) -> None:
    old = old_bytes.decode("utf-8").replace("\r\n", "\n")
    release = release_bytes.decode("utf-8").replace("\r\n", "\n")
    replacements = (
        (
            'std::string_view("busy"), std::string_view("connection_secure_storage_failed"),',
            'std::string_view("actor_unavailable"), std::string_view("busy"), std::string_view("connection_secure_storage_failed"),',
            1,
            "safe native error codes",
        ),
        (
            'RespondPreDispatchError(request, "stale_context", "The selected managed actor is no longer available.");',
            'RespondPreDispatchError(request, "actor_unavailable", "The selected actor is no longer eligible. Refresh the cast and try again.");',
            1,
            "stale managed actor response",
        ),
        (
            'RespondPreDispatchError(pending.request, "stale_context", "The selected actor could not be matched to the fresh CHIM roster.");',
            'RespondPreDispatchError(pending.request, "actor_unavailable", "The selected actor is no longer eligible. Refresh the cast and try again.");',
            1,
            "stale fresh-roster response",
        ),
        (
            'SKSEPluginInfo(.Version = "0.1.18.0"_v,',
            'SKSEPluginInfo(.Version = "0.1.19.0"_v,',
            1,
            "native version",
        ),
        (
            'LogEvent("startup", "version_0_1_0_18");',
            'LogEvent("startup", "version_0_1_0_19");',
            1,
            "native startup version",
        ),
    )
    expected = old
    for current, replacement, count, label in replacements:
        if expected.count(current) != count:
            raise PackageError(f"native plugin {label} source change differs from the reviewed repair")
        expected = expected.replace(current, replacement)
    if expected != release:
        raise PackageError("native plugin source changed beyond the actor-unavailable repair and version bump")


def _source_hash(source_files: dict[str, bytes], path: str, label: str) -> str:
    content = source_files.get(path)
    if not isinstance(content, bytes):
        raise PackageError(f"{label} source is missing from the selected commit: {path}")
    return BASE.sha256(content)


def validate_native_source_binding(
    binding: dict,
    source_commit: str,
    source_files: dict[str, bytes],
    build_receipt_bytes: bytes,
    dll_bytes: bytes,
) -> dict:
    if (type(binding.get("schemaVersion")) is not int
            or binding["schemaVersion"] != 1
            or binding.get("kind") != "department-native-source-binding"
            or binding.get("sourceCommit") != source_commit
            or binding.get("nativeVersion") != NATIVE_VERSION):
        raise PackageError("native source binding does not identify the selected prealpha.31 source and version")

    for field, paths in (
        ("nativeClosure", NATIVE_CLOSURE_PATHS),
        ("bootstrapFixtureSourceHashes", BOOTSTRAP_FIXTURE_SOURCE_PATHS),
    ):
        hashes = binding.get(field)
        if not isinstance(hashes, dict) or set(hashes) != set(paths):
            raise PackageError(f"native source binding {field} differs from its fixed source closure")
        for path, digest in hashes.items():
            expected = P28._require_sha256(digest, f"native source binding hash for {path}")
            if _source_hash(source_files, path, field) != expected:
                raise PackageError(f"native source binding source hash differs for {path}")

    for field, expected in (
        ("recipeSha256", _source_hash(source_files, NATIVE_BUILD_RECIPE, "native build recipe")),
        ("recipeReceiptSha256", BASE.sha256(build_receipt_bytes)),
        ("dllSha256", BASE.sha256(dll_bytes)),
    ):
        actual = P28._require_sha256(binding.get(field), f"native source binding {field}")
        if actual != expected:
            raise PackageError(f"native source binding {field} differs from the selected build inputs")

    return {
        "sourceCommit": source_commit,
        "nativeVersion": NATIVE_VERSION,
        "nativeClosureCount": len(NATIVE_CLOSURE_PATHS),
        "nativeClosure": dict(sorted(binding["nativeClosure"].items())),
        "recipeSha256": binding["recipeSha256"],
        "bootstrapFixtureSourceHashes": dict(sorted(binding["bootstrapFixtureSourceHashes"].items())),
        "recipeReceiptSha256": binding["recipeReceiptSha256"],
        "dllSha256": binding["dllSha256"],
    }


def parse_native_pe(data: bytes) -> dict:
    """Read SKSE version/export metadata from the actual x64 DLL."""

    def require_range(offset: int, size: int, label: str) -> None:
        if offset < 0 or size < 0 or offset + size > len(data):
            raise PackageError(f"native DLL has a truncated PE {label}")

    def u16(offset: int, label: str) -> int:
        require_range(offset, 2, label)
        return struct.unpack_from("<H", data, offset)[0]

    def u32(offset: int, label: str) -> int:
        require_range(offset, 4, label)
        return struct.unpack_from("<I", data, offset)[0]

    if data[:2] != b"MZ" or len(data) < 0x40:
        raise PackageError("native DLL is not a PE image")
    pe_offset = u32(0x3C, "header")
    require_range(pe_offset, 24, "header")
    if data[pe_offset:pe_offset + 4] != b"PE\0\0":
        raise PackageError("native DLL is not a PE image")

    coff = pe_offset + 4
    machine = u16(coff, "COFF header")
    if machine != 0x8664:
        raise PackageError("native DLL is not an x64 PE image")
    section_count = u16(coff + 2, "COFF header")
    optional_size = u16(coff + 16, "COFF header")
    optional = coff + 20
    require_range(optional, optional_size, "optional header")
    if u16(optional, "optional header") != 0x20B or optional_size < 120:
        raise PackageError("native DLL does not use a complete PE32+ optional header")

    export_rva = u32(optional + 112, "export directory")
    export_size = u32(optional + 116, "export directory")
    if not export_rva or export_size < 40:
        raise PackageError("native DLL has no usable export directory")
    section_table = optional + optional_size
    require_range(section_table, section_count * 40, "section table")
    sections = []
    for index in range(section_count):
        offset = section_table + index * 40
        virtual_size, virtual_address, raw_size, raw_offset = struct.unpack_from("<IIII", data, offset + 8)
        sections.append((virtual_address, max(virtual_size, raw_size), raw_size, raw_offset))

    def rva_offset(rva: int, size: int, label: str) -> int:
        for virtual_address, span, raw_size, raw_offset in sections:
            delta = rva - virtual_address
            if 0 <= delta and delta + size <= raw_size and delta < span:
                offset = raw_offset + delta
                require_range(offset, size, label)
                return offset
        raise PackageError(f"native DLL PE {label} RVA is outside file-backed sections")

    export = rva_offset(export_rva, 40, "export directory")
    function_count = u32(export + 20, "export directory")
    name_count = u32(export + 24, "export directory")
    if not function_count or function_count > 10000 or name_count > function_count:
        raise PackageError("native DLL has an invalid export table")
    functions = rva_offset(u32(export + 28, "export directory"), function_count * 4, "export address table")
    names = rva_offset(u32(export + 32, "export directory"), name_count * 4, "export name table")
    ordinals = rva_offset(u32(export + 36, "export directory"), name_count * 2, "export ordinal table")

    exports = set()
    version_rva = None
    for index in range(name_count):
        name_rva = u32(names + index * 4, "export name table")
        name_offset = rva_offset(name_rva, 1, "export name")
        end = data.find(b"\0", name_offset, min(len(data), name_offset + 256))
        if end < 0:
            raise PackageError("native DLL has an unterminated export name")
        try:
            name = data[name_offset:end].decode("ascii")
        except UnicodeDecodeError as error:
            raise PackageError("native DLL has a non-ASCII export name") from error
        exports.add(name)
        if name == "SKSEPlugin_Version":
            ordinal = u16(ordinals + index * 2, "export ordinal table")
            if ordinal >= function_count:
                raise PackageError("native DLL version export has an invalid ordinal")
            version_rva = u32(functions + ordinal * 4, "export address table")

    if version_rva is None:
        raise PackageError("native DLL is missing SKSEPlugin_Version")
    if export_rva <= version_rva < export_rva + export_size:
        raise PackageError("native DLL version export is unexpectedly forwarded")
    version_offset = rva_offset(version_rva, 8, "SKSE plugin version data")
    data_version = u32(version_offset, "SKSE plugin version data")
    if data_version != 1:
        raise PackageError("native DLL SKSEPlugin_Version data schema is not version 1")
    packed_version = u32(version_offset + 4, "SKSE plugin version data")
    if packed_version != NATIVE_VERSION_PACKED:
        raise PackageError("native DLL SKSEPlugin_Version is not 0.1.19.0")
    missing = set(SKSE_EXPORTS) - exports
    if missing:
        raise PackageError(f"native DLL is missing required exports: {', '.join(sorted(missing))}")
    return {
        "machine": machine,
        "versionDataVersion": data_version,
        "versionDataOffset": version_offset,
        "sksePluginVersionPacked": packed_version,
        "exports": exports,
    }


def validate_native_pe_receipt(receipt: dict, source_commit: str, dll_bytes: bytes, parsed_pe: dict) -> dict:
    if (FULL_COMMIT.fullmatch(source_commit) is None
            or type(receipt.get("schemaVersion")) is not int
            or receipt.get("schemaVersion") != 1
            or receipt.get("kind") != "department-native-pe-verification"
            or receipt.get("sourceCommit") != source_commit):
        raise PackageError("native PE verification receipt does not bind the selected source commit")
    dll_sha256 = BASE.sha256(dll_bytes)
    if (P28._require_sha256(receipt.get("dllSha256"), "native PE receipt DLL hash") != dll_sha256
            or type(receipt.get("dllBytes")) is not int
            or receipt.get("dllBytes") != len(dll_bytes)):
        raise PackageError("native PE verification receipt differs from the supplied DLL")
    if (type(receipt.get("machine")) is not int
            or receipt.get("machine") != 34404
            or parsed_pe.get("machine") != 34404):
        raise PackageError("native PE verification receipt does not confirm x64")
    if parsed_pe.get("versionDataVersion") != 1:
        raise PackageError("native DLL SKSEPlugin_Version data schema is not version 1")
    version = receipt.get("sksePluginVersion")
    if (not isinstance(version, list)
            or any(type(part) is not int for part in version)
            or version != [0, 1, 19, 0]
            or type(receipt.get("sksePluginVersionPacked")) is not int
            or receipt.get("sksePluginVersionPacked") != NATIVE_VERSION_PACKED
            or parsed_pe.get("sksePluginVersionPacked") != NATIVE_VERSION_PACKED):
        raise PackageError("native PE verification receipt does not confirm SKSE version 0.1.19.0")
    if (receipt.get("requiredExports") != list(SKSE_EXPORTS)
            or not set(SKSE_EXPORTS) <= set(parsed_pe.get("exports", set()))):
        raise PackageError("native PE verification receipt does not bind the required plugin exports")
    if receipt.get("passed") is not True:
        raise PackageError("native PE verification receipt is not marked passed")
    return {
        "sourceCommit": source_commit,
        "dllSha256": dll_sha256,
        "dllBytes": len(dll_bytes),
        "machine": 34404,
        "sksePluginVersion": [0, 1, 19, 0],
        "sksePluginVersionPacked": NATIVE_VERSION_PACKED,
        "requiredExports": list(SKSE_EXPORTS),
        "passed": True,
    }


def validate_native_evidence(
    dll_bytes: bytes,
    build_receipt_bytes: bytes,
    source_binding_bytes: bytes,
    pe_receipt_bytes: bytes,
    source_commit: str,
    source_files: dict[str, bytes],
) -> dict:
    try:
        plugin_source = source_files[NATIVE_PLUGIN_SOURCE].decode("utf-8")
    except (KeyError, UnicodeDecodeError) as error:
        raise PackageError("native plugin source is missing or not UTF-8") from error
    if (plugin_source.count('SKSEPluginInfo(.Version = "0.1.19.0"_v,') != 1
            or plugin_source.count('LogEvent("startup", "version_0_1_0_19")') != 1):
        raise PackageError("selected native source does not identify version 0.1.19.0")
    binding = P28._safe_json_input(source_binding_bytes, "native source binding receipt")
    build_receipt = P28._safe_json_input(build_receipt_bytes, "native build receipt")
    pe_receipt = P28._safe_json_input(pe_receipt_bytes, "native PE verification receipt")
    source_binding = validate_native_source_binding(
        binding, source_commit, source_files, build_receipt_bytes, dll_bytes,
    )
    build_summary = P28.validate_native_build_receipt(build_receipt, dll_bytes, source_files)
    pe_summary = validate_native_pe_receipt(pe_receipt, source_commit, dll_bytes, parse_native_pe(dll_bytes))
    return {
        "sourceBinding": source_binding,
        "buildReceiptSha256": BASE.sha256(build_receipt_bytes),
        "build": build_summary,
        "peVerification": pe_summary,
    }


def _read_baseline(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact published prealpha.30 archive")
    files = BASE.read_zip_bytes(data, "published prealpha.30 ZIP")
    if set(files) != set(EXPECTED_BASELINE_MEMBERS) or len(files) != 22:
        raise PackageError("published prealpha.30 ZIP member set differs")
    department = PCV._audit_department(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION, pcv25=True)
    pcv = P20._audit_component(files[PCV_MEMBER], "pcv_companion", PCV_VERSION)
    return files, department, pcv


def _build_department_package(
    old_package: dict[str, bytes],
    old_server_manifest: bytes,
    release_server_manifest: bytes,
) -> tuple[bytes, dict[str, bytes], list[str], dict]:
    if old_package.get(SERVER_MANIFEST) != old_server_manifest:
        raise PackageError("prealpha.30 DWPkg server manifest differs from its pinned source commit")
    manifest_transition = validate_manifest_transition(old_server_manifest, release_server_manifest)
    current = dict(old_package)
    outer = PREVIOUS._json_object(current["manifest.json"], "prealpha.30 Department outer manifest")
    if (outer.get("version") != BASELINE_VERSION
            or BASE.json_bytes(outer) != current["manifest.json"]):
        raise PackageError("prealpha.30 Department outer manifest has the wrong version or is not canonical JSON")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    current[SERVER_MANIFEST] = release_server_manifest
    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)

    package = PREVIOUS._stable_zip_bytes(current)
    verified = PCV._audit_department(package, VERSION, pcv25=True)
    changed = sorted(path for path in old_package if old_package[path] != verified.get(path))
    expected = ["checksums.sha256", "manifest.json", SERVER_MANIFEST]
    if changed != expected:
        raise PackageError(f"Department DWPkg changed outside its two version manifests and checksums: {changed}")
    if manifest_transition["releaseSha256"] != BASE.sha256(release_server_manifest):
        raise PackageError("server manifest transition receipt differs")
    return package, verified, changed, manifest_transition


def assemble_installable(
    baseline_zip: bytes,
    source_files: dict[str, bytes],
    old_server_manifest: bytes,
    dll_bytes: bytes,
    native_build_receipt_bytes: bytes,
    native_source_binding_bytes: bytes,
    native_pe_receipt_bytes: bytes,
    source_commit: str,
) -> tuple[bytes, dict]:
    baseline, old_department, old_pcv = _read_baseline(baseline_zip)
    for path in (SERVER_MANIFEST, UI_APP_SOURCE):
        if not isinstance(source_files.get(path), bytes) or not source_files[path]:
            raise PackageError(f"committed release source is missing or empty: {path}")
    manifest_transition = validate_manifest_transition(old_server_manifest, source_files[SERVER_MANIFEST])
    native = validate_native_evidence(
        dll_bytes, native_build_receipt_bytes, native_source_binding_bytes,
        native_pe_receipt_bytes, source_commit, source_files,
    )
    department_package, _new_department, department_changes, _manifest = _build_department_package(
        old_department, old_server_manifest, source_files[SERVER_MANIFEST]
    )
    release = {path: content for path, content in baseline.items() if path != BASELINE_DPKG_MEMBER}
    release[DPKG_MEMBER] = department_package
    release[UI_APP_MEMBER] = source_files[UI_APP_SOURCE]
    release[DLL_MEMBER] = dll_bytes
    if set(release) != set(EXPECTED_INSTALLABLE_MEMBERS) or len(release) != 22:
        raise PackageError("prealpha.31 installable member set differs from its fixed contract")

    common = set(release) & set(baseline)
    changed_common = {path for path in common if release[path] != baseline[path]}
    if changed_common != {UI_APP_MEMBER, DLL_MEMBER}:
        raise PackageError("prealpha.31 must change only UI app.js and its own native DLL among prior payloads")
    unchanged = sorted(path for path in common if release[path] == baseline[path])
    if len(unchanged) != 19:
        raise PackageError("prealpha.31 must preserve nineteen prior installable payloads byte-for-byte")
    if old_pcv != P20._audit_component(release[PCV_MEMBER], "pcv_companion", PCV_VERSION):
        raise PackageError("prealpha.31 changed the pinned PCV companion package")

    runtime_zip = PREVIOUS._stable_zip_bytes(release)
    if BASE.read_zip_bytes(runtime_zip, "prealpha.31 installable ZIP") != release:
        raise PackageError("prealpha.31 installable ZIP did not round-trip exactly")
    audit = {
        "departmentDwpkgChangedMembers": department_changes,
        "changedInstallableMembers": sorted({BASELINE_DPKG_MEMBER, DPKG_MEMBER, UI_APP_MEMBER, DLL_MEMBER}),
        "unchangedInstallableMembers": unchanged,
        "pcvCompanionVersion": PCV_VERSION,
        "pcvCompanionSha256": BASE.sha256(release[PCV_MEMBER]),
        "nativeDll": {
            "member": DLL_MEMBER,
            "version": NATIVE_VERSION,
            "sha256": BASE.sha256(release[DLL_MEMBER]),
            "bytes": len(dll_bytes),
        },
        "nativeEvidence": native,
        "serverManifestTransition": manifest_transition,
    }
    return runtime_zip, audit


def _validate_source_inputs(
    source_commit: str,
    source_files: dict[str, bytes],
    baseline_allowlist: bytes,
) -> tuple[list[str], dict]:
    if FULL_COMMIT.fullmatch(source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    allowlist_bytes = source_files.get(ALLOWLIST_PATH)
    if allowlist_bytes is None:
        raise PackageError("committed source allowlist is missing")
    paths = PREVIOUS._allowlist_paths(allowlist_bytes, "committed source allowlist")
    old_paths = PREVIOUS._allowlist_paths(baseline_allowlist, "published prealpha.30 source allowlist")
    if paths != old_paths + list(RELEASE_ALLOWLIST_ADDITIONS):
        raise PackageError("prealpha.31 source allowlist must append only the repair note, builder, and focused test")
    if set(paths) != set(source_files):
        raise PackageError("source bytes do not match the exact committed public allowlist")
    validate_ui_app_delta(
        PREVIOUS._git_blob(BASELINE_SOURCE_COMMIT, UI_APP_SOURCE), source_files[UI_APP_SOURCE]
    )
    validate_native_plugin_delta(
        PREVIOUS._git_blob(BASELINE_SOURCE_COMMIT, NATIVE_PLUGIN_SOURCE),
        source_files[NATIVE_PLUGIN_SOURCE],
    )
    missing_native = sorted(NATIVE_CLOSURE_PATHS - set(paths))
    if missing_native:
        raise PackageError(f"public source allowlist omits native build inputs: {', '.join(missing_native)}")
    return paths, validate_manifest_transition(
        PREVIOUS._git_blob(BASELINE_SOURCE_COMMIT, SERVER_MANIFEST), source_files.get(SERVER_MANIFEST, b"")
    )


def _validate_source_diff(source_commit: str) -> None:
    changed = PREVIOUS._git("diff", "--name-only", "--no-renames", BASELINE_SOURCE_COMMIT, source_commit, "--")
    validate_source_diff_paths(changed.decode("utf-8").splitlines())


def _build_assets(
    baseline_zip: bytes,
    source_commit: str,
    source_files: dict[str, bytes],
    baseline_allowlist: bytes,
    old_server_manifest: bytes,
    dll_bytes: bytes,
    native_build_receipt_bytes: bytes,
    native_source_binding_bytes: bytes,
    native_pe_receipt_bytes: bytes,
) -> dict[str, bytes]:
    paths, manifest_audit = _validate_source_inputs(source_commit, source_files, baseline_allowlist)
    _validate_source_diff(source_commit)
    for path in (SERVER_MANIFEST, UI_APP_SOURCE, NATIVE_BUILD_RECIPE):
        if source_files[path] != PREVIOUS._git_blob(source_commit, path):
            raise PackageError(f"prealpha.31 source differs from the selected source commit: {path}")
    source_zip = PREVIOUS._stable_zip_bytes(source_files)
    runtime_zip, installable_audit = assemble_installable(
        baseline_zip, source_files, old_server_manifest, dll_bytes,
        native_build_receipt_bytes, native_source_binding_bytes,
        native_pe_receipt_bytes, source_commit,
    )
    receipt = {
        "schemaVersion": 1,
        "kind": "department-prealpha31-source-bound-package",
        "version": VERSION,
        "sourceCommit": source_commit,
        "baseline": {
            "version": BASELINE_VERSION,
            "sha256": BASELINE_SHA256,
            "sourceCommit": BASELINE_SOURCE_COMMIT,
        },
        "installableSha256": BASE.sha256(runtime_zip),
        "sourceArchiveSha256": BASE.sha256(source_zip),
        "sourceAllowlistSha256": BASE.sha256(source_files[ALLOWLIST_PATH]),
        "sourceFileCount": len(paths),
        "serverManifestTransition": manifest_audit,
        **installable_audit,
        "limits": {
            "sourceAndPackageAssembly": True,
            "nativeSourceBuildAndHostTests": True,
            "nativePeVersionAndExports": True,
            "serverDatabase": False,
            "http": False,
            "provider": False,
            "physicalDelivery": False,
            "installedGame": False,
            "skyrim": False,
            "mouseControl": False,
            "published": False,
        },
    }
    audit_bytes = BASE.json_bytes(receipt)
    checksums = BASE.make_checksums({
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        BUILD_AUDIT_NAME: audit_bytes,
    })
    if not checksums.endswith(b"\n") or b"\r" in checksums:
        raise PackageError("release checksums must use canonical LF-only lines")
    return {
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        CHECKSUMS_NAME: checksums,
        BUILD_AUDIT_NAME: audit_bytes,
    }


def _safe_input(path: Path, label: str) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise PackageError(f"{label} is missing or unsafe")
    return path.read_bytes()


def _inputs(args):
    if FULL_COMMIT.fullmatch(args.source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    head = PREVIOUS._git("rev-parse", "HEAD").decode("ascii").strip()
    if args.source_commit != head:
        raise PackageError("release source commit must be the current frozen checkout HEAD")
    dirty = PREVIOUS._git("status", "--porcelain", "--untracked-files=normal").decode("utf-8").strip()
    if dirty:
        raise PackageError("source checkout must be clean before packaging")

    baseline_zip = _safe_input(args.baseline_zip, "published prealpha.30 ZIP")
    dll_bytes = _safe_input(args.native_dll, "built native 0.1.19.0 DLL")
    build_receipt_bytes = _safe_input(args.native_build_receipt, "native build receipt")
    source_binding_bytes = _safe_input(args.native_source_binding, "native source binding receipt")
    pe_receipt_bytes = _safe_input(args.native_pe_receipt, "native PE verification receipt")
    allowlist_bytes = PREVIOUS._git_blob(args.source_commit, ALLOWLIST_PATH)
    paths = PREVIOUS._allowlist_paths(allowlist_bytes, "committed source allowlist")
    source_files = {path: PREVIOUS._git_blob(args.source_commit, path) for path in paths}
    baseline_allowlist = PREVIOUS._git_blob(BASELINE_SOURCE_COMMIT, ALLOWLIST_PATH)
    old_server_manifest = PREVIOUS._git_blob(BASELINE_SOURCE_COMMIT, SERVER_MANIFEST)
    return (
        baseline_zip, args.source_commit, source_files, baseline_allowlist, old_server_manifest,
        dll_bytes, build_receipt_bytes, source_binding_bytes, pe_receipt_bytes,
    )


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("release output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if (set(path.name for path in entries) != OUTPUT_FILE_NAMES
            or any(path.is_symlink() or not path.is_file() for path in entries)):
        raise PackageError("release output must contain only the installable, source, checksums, and package audit")
    return {path.name: path.read_bytes() for path in entries}


def audit_output(args) -> None:
    inputs = _inputs(args)
    expected = _build_assets(*inputs)
    actual = _expected_output_files(args.output_dir)
    for name in sorted(OUTPUT_FILE_NAMES):
        if actual[name] != expected[name]:
            raise PackageError(f"release output differs from the canonical source-bound build: {name}")
    BASE.audit_checksums_file(actual[CHECKSUMS_NAME], {name: actual[name] for name in OUTPUT_NAMES})


def _write_outputs(directory: Path, assets: dict[str, bytes]) -> None:
    if (set(assets) != OUTPUT_FILE_NAMES or directory.is_symlink()
            or not directory.is_dir() or any(directory.iterdir())):
        raise PackageError("release staging directory is unsafe, not empty, or has an incomplete output set")
    for name, content in assets.items():
        BASE.validate_member_name(name)
        with (directory / name).open("xb") as stream:
            stream.write(content)


def build(args) -> None:
    inputs = _inputs(args)
    assets = _build_assets(*inputs)
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
        sub.add_argument("--native-dll", required=True, type=Path)
        sub.add_argument("--native-build-receipt", required=True, type=Path)
        sub.add_argument("--native-source-binding", required=True, type=Path)
        sub.add_argument("--native-pe-receipt", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        if args.command == "build":
            build(args)
            print(f"Built and source-audited prealpha.31 package: {args.output_dir.resolve()}")
        else:
            audit_output(args)
            print(f"Source-bound prealpha.31 package audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, ValueError, KeyError, TypeError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
