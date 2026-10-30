#!/usr/bin/env python3
"""Build and audit the source-bound Department prealpha.28 release."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

import importlib.util


_PREVIOUS_PATH = Path(__file__).with_name("build-installable-prealpha27.py")
_PREVIOUS_SPEC = importlib.util.spec_from_file_location("_department_prealpha27_for_release28", _PREVIOUS_PATH)
if _PREVIOUS_SPEC is None or _PREVIOUS_SPEC.loader is None:
    raise RuntimeError("could not load the reviewed prealpha.27 package primitives")
PREVIOUS = importlib.util.module_from_spec(_PREVIOUS_SPEC)
_PREVIOUS_SPEC.loader.exec_module(PREVIOUS)

BASE = PREVIOUS.BASE
PCV = PREVIOUS.PCV
P26 = PCV.P26
P24 = PCV.P24
P20 = PCV.P20
ROOT = PREVIOUS.ROOT
PackageError = PREVIOUS.PackageError

VERSION = "0.1.0-prealpha.28"
BASELINE_VERSION = "0.1.0-prealpha.27"
PCV_VERSION = "0.1.0-prealpha.13"
BASELINE_SHA256 = "abb2fe3fb85859b26a26c251929a464e27c36d13179380e6ff491d15af2eb978"
BASELINE_SOURCE_COMMIT = "94b48fadbe53c33bc28b6bbe44c6a6ed5450ff35"
BASELINE_ALLOWLIST_SHA256 = "3a128751836d2b76b037acfcc61bcf36b8af31200175230e448bd6b3dfac005a"

SERVER_MANIFEST = "server/manifest.json"
ALLOWLIST_PATH = "packaging/public-source-allowlist.json"
NATIVE_BUILD_RECIPE = "native/build.ps1"
BASELINE_DPKG_MEMBER = PCV.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = PCV.PCV_MEMBER
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"
UI_APP_MEMBER = "PrismaUI/views/WorldOfDramaLlama/app.js"
UI_INDEX_MEMBER = "PrismaUI/views/WorldOfDramaLlama/index.html"
UI_APP_SOURCE = "ui/app.js"
UI_INDEX_SOURCE = "ui/index.html"
UI_STATIC_SOURCE_MEMBERS = {
    "ui/app.css": "PrismaUI/views/WorldOfDramaLlama/app.css",
    "ui/brainwash-editor.js": "PrismaUI/views/WorldOfDramaLlama/brainwash-editor.js",
    "ui/brainwash-editor.css": "PrismaUI/views/WorldOfDramaLlama/brainwash-editor.css",
}
EXPECTED_BASELINE_MEMBERS = frozenset(PCV.CANDIDATE_EXPECTED_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}

NATIVE_CLOSURE_PATHS = frozenset({
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
})
BOOTSTRAP_FIXTURE_SOURCE_PATHS = frozenset({
    "server/adapters/brainwash.php",
    "server/adapters/correspondence.php",
    "server/adapters/mp.php",
    "server/adapters/pcv.php",
    "server/adapters/runtime_support.php",
    "server/adapters/sas.php",
    "server/api.php",
    "server/lib/auth.php",
    "server/lib/dispatcher.php",
    "server/lib/journal.php",
    "server/lib/overlay_setup.php",
    "server/lib/protocol.php",
    "server/runtime.php",
    "server/tests/native-bootstrap/generate.php",
})

RELEASE_ALLOWLIST_ADDITIONS = (
    "docs/LETTER-RECOVERY.md",
    "docs/superpowers/plans/2026-10-30-letter-resume.md",
    "docs/superpowers/specs/2026-10-30-letter-resume-design.md",
    "packaging/build-installable-prealpha28.py",
    "packaging/tests/test_installable_prealpha28.py",
    "server/tests/correspondence/resume-contract.php",
)
DEPARTMENT_DPKG_SOURCE_CHANGES = frozenset({
    "server/adapters/correspondence.php",
    "server/lib/dispatcher.php",
    "server/lib/protocol.php",
    "server/runtime.php",
})
DEPARTMENT_DPKG_CHANGED_MEMBERS = frozenset({
    "manifest.json",
    "server/manifest.json",
    *DEPARTMENT_DPKG_SOURCE_CHANGES,
    "checksums.sha256",
})
DEPARTMENT_SERVER_SOURCE_PATHS = frozenset(P26.SERVER_SOURCE_PATHS) | {"server/globals.php"}
ACCEPTANCE_SOURCE_PATHS = frozenset({
    "native/src/Contract.cpp",
    "native/src/HttpTransport.cpp",
    "native/src/Plugin.cpp",
    "native/tests/ContractTests.cpp",
    "native/tests/NativeBoundaryTests.cpp",
    "native/tests/http-transport-tests.cpp",
    SERVER_MANIFEST,
    *DEPARTMENT_DPKG_SOURCE_CHANGES,
    "server/tests/dev23-pg/api_driver.php",
    "server/tests/dev30-pg/actual_pg.php",
    "server/tests/dev30-pg/cases.json",
    "server/tests/correspondence/resume-contract.php",
    UI_APP_SOURCE,
    UI_INDEX_SOURCE,
    *UI_STATIC_SOURCE_MEMBERS,
    "ui/tests/ui-functional.cjs",
})

REQUIRED_SOURCE_PATHS = frozenset({
    "README.md",
    "docs/BUILD.md",
    *RELEASE_ALLOWLIST_ADDITIONS,
    NATIVE_BUILD_RECIPE,
    SERVER_MANIFEST,
    ALLOWLIST_PATH,
    UI_APP_SOURCE,
    UI_INDEX_SOURCE,
    *NATIVE_CLOSURE_PATHS,
    *BOOTSTRAP_FIXTURE_SOURCE_PATHS,
    *DEPARTMENT_DPKG_SOURCE_CHANGES,
    *ACCEPTANCE_SOURCE_PATHS,
})
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-offline-proof.zip"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
CHECKSUMS_NAME = "SHA256SUMS.txt"
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME}
ZIP_TIMESTAMP = (2026, 10, 30, 0, 0, 0)

_HEX_SHA256 = re.compile(r"\A[a-f0-9]{64}\Z")
_FULL_COMMIT = re.compile(r"\A[a-f0-9]{40}\Z")
_PRIVATE_PATH = re.compile(r"(?:[A-Za-z]:[\\/]|/(?:var/tmp|home)/|\\\\Users\\)")


def _json_object(data: bytes, label: str) -> dict:
    try:
        value = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError(f"{label} is not valid UTF-8 JSON") from error
    if not isinstance(value, dict):
        raise PackageError(f"{label} must be a JSON object")
    return value


def _safe_json_input(data: bytes, label: str) -> dict:
    if _PRIVATE_PATH.search(data.decode("utf-8", "replace")):
        raise PackageError(f"{label} contains an absolute or machine-specific path")
    return _json_object(data, label)


def _require_sha256(value: object, label: str, *, lowercase: bool = True) -> str:
    pattern = _HEX_SHA256 if lowercase else re.compile(r"\A[A-Fa-f0-9]{64}\Z")
    if not isinstance(value, str) or pattern.fullmatch(value) is None:
        raise PackageError(f"{label} must be a SHA-256 digest")
    return value.lower()


def _source_sha256(source_files: dict[str, bytes], path: str, label: str) -> str:
    data = source_files.get(path)
    if not isinstance(data, bytes):
        raise PackageError(f"selected source is missing or not bytes: {path}")
    return hashlib.sha256(data).hexdigest()


def validate_native_source_binding(
    binding: dict,
    source_commit: str,
    source_files: dict[str, bytes],
    native_build_receipt_bytes: bytes,
    dll_bytes: bytes,
) -> dict:
    if (_FULL_COMMIT.fullmatch(source_commit) is None
            or binding.get("schemaVersion") != 1
            or binding.get("kind") != "department-native-source-binding"
            or binding.get("sourceCommit") != source_commit
            or binding.get("nativeVersion") != "0.1.18.0"):
        raise PackageError("native source binding does not identify the selected prealpha.28 source and version")

    for field, paths in (
        ("nativeClosure", NATIVE_CLOSURE_PATHS),
        ("bootstrapFixtureSourceHashes", BOOTSTRAP_FIXTURE_SOURCE_PATHS),
    ):
        hashes = binding.get(field)
        if not isinstance(hashes, dict) or set(hashes) != set(paths):
            raise PackageError(f"native source binding {field} differs from its fixed source closure")
        for path, digest in hashes.items():
            expected = _require_sha256(digest, f"native source binding hash for {path}")
            if _source_sha256(source_files, path, field) != expected:
                raise PackageError(f"native source binding source hash differs for {path}")

    for field, expected in (
        ("recipeSha256", _source_sha256(source_files, NATIVE_BUILD_RECIPE, "native build recipe")),
        ("recipeReceiptSha256", hashlib.sha256(native_build_receipt_bytes).hexdigest()),
        ("dllSha256", hashlib.sha256(dll_bytes).hexdigest()),
    ):
        actual = _require_sha256(binding.get(field), f"native source binding {field}")
        if actual != expected:
            raise PackageError(f"native source binding {field} differs from the selected build inputs")

    return {
        "sourceCommit": source_commit,
        "nativeVersion": "0.1.18.0",
        "nativeClosureCount": len(NATIVE_CLOSURE_PATHS),
        "nativeClosure": dict(sorted(binding["nativeClosure"].items())),
        "recipeSha256": binding["recipeSha256"],
        "bootstrapFixtureSourceHashes": dict(sorted(binding["bootstrapFixtureSourceHashes"].items())),
        "recipeReceiptSha256": binding["recipeReceiptSha256"],
        "dllSha256": binding["dllSha256"],
    }


def validate_native_build_receipt(receipt: dict, dll_bytes: bytes, source_files: dict[str, bytes]) -> dict:
    if receipt.get("schemaVersion") != 1 or receipt.get("status") != "native-plugin-and-host-tests-built":
        raise PackageError("native build receipt has an unsupported schema or status")
    lock = _json_object(source_files.get("native/deps.lock.json", b""), "native dependency lock")
    if lock.get("schemaVersion") != 1:
        raise PackageError("native dependency lock has an unsupported schema")

    toolchain = receipt.get("toolchain")
    locked_toolchain = lock.get("toolchain")
    if not isinstance(toolchain, dict) or not isinstance(locked_toolchain, dict):
        raise PackageError("native build receipt is missing the locked toolchain")
    for field in (
        "generator", "platform", "configuration", "msvcCompilerVersion",
        "msvcToolsVersion", "msvcPlatformToolset", "windowsSdkVersion",
    ):
        if toolchain.get(field) != locked_toolchain.get(field):
            raise PackageError(f"native build receipt differs from the locked toolchain: {field}")
    cmake_version = f"cmake version {locked_toolchain.get('cmakeVersion')}"
    if toolchain.get("cmakeVersion") not in {cmake_version, cmake_version + "-msvc11"}:
        raise PackageError("native build receipt differs from the locked CMake version")
    ctest_version = toolchain.get("ctestVersion")
    if not isinstance(ctest_version, str) or re.fullmatch(
        rf"ctest version {re.escape(str(locked_toolchain.get('cmakeVersion')))}(?:-[A-Za-z0-9.]+)?",
        ctest_version,
    ) is None:
        raise PackageError("native build receipt differs from the locked CTest version")

    inputs = receipt.get("inputs")
    if not isinstance(inputs, dict) or not str(inputs.get("phpVersion", "")).startswith("PHP 8.3.35 (cli)"):
        raise PackageError("native build receipt does not identify the required PHP CLI")
    locked_inputs = {
        "commonLib": {
            "version": lock["commonLib"]["version"],
            "commit": lock["commonLib"]["commit"],
            "archiveSha256": lock["commonLib"]["sha256"].lower(),
        },
        "fmt": {"version": lock["fmt"]["version"], "archiveSha256": lock["fmt"]["sha256"].lower()},
        "spdlog": {"version": lock["spdlog"]["version"], "archiveSha256": lock["spdlog"]["sha256"].lower()},
        "rapidcsv": {"version": lock["rapidcsv"]["version"], "archiveSha256": lock["rapidcsv"]["sha256"].lower()},
        "nlohmannJson": {"version": lock["nlohmannJson"]["version"], "sha256": lock["nlohmannJson"]["sha256"].lower()},
        "prismaUi": {
            "repository": lock["prismaUi"]["repository"],
            "commit": lock["prismaUi"]["commit"],
            "sha256": lock["prismaUi"]["sha256"].lower(),
        },
    }
    for name, expected in locked_inputs.items():
        actual = inputs.get(name)
        if not isinstance(actual, dict):
            raise PackageError(f"native build receipt is missing the locked dependency: {name}")
        for field, expected_value in expected.items():
            value = actual.get(field)
            if field in {"sha256", "archiveSha256"}:
                value = _require_sha256(value, f"native build receipt {name}.{field}", lowercase=False)
            if value != expected_value:
                raise PackageError(f"native build receipt differs from the locked dependency: {name}.{field}")

    fixtures = receipt.get("serverBootstrapFixtures")
    if not isinstance(fixtures, dict):
        raise PackageError("native build receipt is missing the actual PHP bootstrap fixtures")
    _require_sha256(fixtures.get("sha256"), "native bootstrap fixture hash", lowercase=False)
    fixture_sources = fixtures.get("sourceFiles")
    if (fixtures.get("caseNames") != [
            "unauthenticated_bootstrap",
            "authenticated_all_readiness_disabled",
            "authenticated_full_access",
            "authenticated_brainwash_only",
        ]
            or not isinstance(fixture_sources, list)
            or any(not isinstance(path, str) for path in fixture_sources)
            or set(fixture_sources) != set(BOOTSTRAP_FIXTURE_SOURCE_PATHS)
            or len(fixture_sources) != len(BOOTSTRAP_FIXTURE_SOURCE_PATHS)
            or fixtures.get("stateCleanup") != "removed"):
        raise PackageError("native build receipt differs from the exact PHP bootstrap fixture closure")

    host = receipt.get("hostTests")
    if (not isinstance(host, dict)
            or host.get("count") != 6
            or host.get("result") != "100% tests passed, 0 tests failed out of 6"):
        raise PackageError("native build receipt does not confirm all six host tests passed")

    plugin = receipt.get("plugin")
    if not isinstance(plugin, dict) or plugin.get("name") != "WorldOfDramaLlama.dll":
        raise PackageError("native build receipt does not identify the expected plugin DLL")
    if type(plugin.get("bytes")) is not int or plugin["bytes"] != len(dll_bytes):
        raise PackageError("native build receipt DLL bytes differ from the supplied DLL")
    plugin_sha256 = _require_sha256(plugin.get("sha256"), "native build receipt DLL hash", lowercase=False)
    if plugin_sha256 != hashlib.sha256(dll_bytes).hexdigest():
        raise PackageError("native build receipt DLL hash differs from the supplied DLL")
    if plugin.get("linkOptionsConfigured") is not True:
        raise PackageError("native build receipt does not confirm deterministic linker options")

    access = receipt.get("buildAccess")
    if (not isinstance(access, dict)
            or access.get("receiptOmitsLocalPaths") is not True
            or access.get("installedGameFilesRead") is not False
            or access.get("dllPathScanIsSeparateEvidence") is not True):
        raise PackageError("native build receipt has unexpected build-access claims")
    return {
        "toolchain": {field: toolchain[field] for field in (
            "cmakeVersion", "ctestVersion", "generator", "platform", "configuration",
            "msvcCompilerVersion", "msvcToolsVersion", "msvcPlatformToolset", "windowsSdkVersion",
        )},
        "inputs": locked_inputs,
        "serverBootstrapFixtures": {
            "sha256": fixtures["sha256"].lower(),
            "caseNames": list(fixtures["caseNames"]),
            "sourceFiles": sorted(fixtures["sourceFiles"]),
            "stateCleanup": "removed",
        },
        "hostTests": {"result": host["result"], "count": host["count"]},
        "plugin": {"name": plugin["name"], "bytes": plugin["bytes"], "sha256": plugin_sha256},
    }


def validate_native_pe_receipt(receipt: dict, source_commit: str, dll_bytes: bytes, parsed_pe: dict) -> dict:
    if (_FULL_COMMIT.fullmatch(source_commit) is None
            or receipt.get("schemaVersion") != 1
            or receipt.get("kind") != "department-native-pe-verification"
            or receipt.get("sourceCommit") != source_commit):
        raise PackageError("native PE verification receipt does not bind the selected source commit")
    dll_sha256 = hashlib.sha256(dll_bytes).hexdigest()
    if (_require_sha256(receipt.get("dllSha256"), "native PE receipt DLL hash") != dll_sha256
            or type(receipt.get("dllBytes")) is not int
            or receipt.get("dllBytes") != len(dll_bytes)):
        raise PackageError("native PE verification receipt differs from the supplied DLL")
    if (type(receipt.get("machine")) is not int
            or receipt.get("machine") != 34404
            or parsed_pe.get("machine") != 34404):
        raise PackageError("native PE verification receipt does not confirm x64")
    version = receipt.get("sksePluginVersion")
    if (not isinstance(version, list)
            or any(type(part) is not int for part in version)
            or version != [0, 1, 18, 0]
            or type(receipt.get("sksePluginVersionPacked")) is not int
            or receipt.get("sksePluginVersionPacked") != _SKSE_VERSION_PACKED
            or parsed_pe.get("sksePluginVersionPacked") != receipt.get("sksePluginVersionPacked")):
        raise PackageError("native PE verification receipt does not confirm SKSE version 0.1.18.0")
    if receipt.get("requiredExports") != list(_SKSE_EXPORTS) or not set(_SKSE_EXPORTS) <= set(parsed_pe.get("exports", set())):
        raise PackageError("native PE verification receipt does not bind the required plugin exports")
    if receipt.get("passed") is not True:
        raise PackageError("native PE verification receipt is not marked passed")
    return {
        "sourceCommit": source_commit,
        "dllSha256": dll_sha256,
        "dllBytes": len(dll_bytes),
        "machine": 34404,
        "sksePluginVersion": [0, 1, 18, 0],
        "sksePluginVersionPacked": _SKSE_VERSION_PACKED,
        "requiredExports": list(_SKSE_EXPORTS),
        "passed": True,
    }


def validate_native_evidence(
    source_binding_bytes: bytes,
    native_build_receipt_bytes: bytes,
    pe_verification_bytes: bytes,
    dll_bytes: bytes,
    source_commit: str,
    source_files: dict[str, bytes],
) -> dict:
    binding = _safe_json_input(source_binding_bytes, "native source binding receipt")
    build_receipt = _safe_json_input(native_build_receipt_bytes, "native build receipt")
    pe_receipt = _safe_json_input(pe_verification_bytes, "native PE verification receipt")
    source_binding = validate_native_source_binding(
        binding, source_commit, source_files, native_build_receipt_bytes, dll_bytes,
    )
    build_summary = validate_native_build_receipt(build_receipt, dll_bytes, source_files)
    pe_summary = validate_native_pe_receipt(pe_receipt, source_commit, dll_bytes, parse_native_pe(dll_bytes))
    return {
        "sourceBinding": source_binding,
        "buildReceiptSha256": hashlib.sha256(native_build_receipt_bytes).hexdigest(),
        "build": build_summary,
        "peVerification": pe_summary,
    }


def validate_acceptance_receipt(receipt: dict, source_commit: str, source_files: dict[str, bytes]) -> dict:
    expected_keys = {
        "schemaVersion", "kind", "sourceCommit", "sourceHashes", "ownerVersion", "ownerCommit",
        "ownerClosurePins", "checks", "ownerReview", "openScopedFindings", "limits", "evidenceHashes",
    }
    if (set(receipt) != expected_keys
            or receipt.get("schemaVersion") != 1
            or receipt.get("kind") != "department-letter-recovery-acceptance"
            or _FULL_COMMIT.fullmatch(source_commit) is None
            or receipt.get("sourceCommit") != source_commit):
        raise PackageError("root acceptance receipt does not identify the selected release source")

    source_hashes = receipt.get("sourceHashes")
    if not isinstance(source_hashes, dict) or set(source_hashes) != set(ACCEPTANCE_SOURCE_PATHS):
        raise PackageError("root acceptance receipt does not contain the exact runtime/native/UI source set")
    for path, digest in source_hashes.items():
        BASE.validate_member_name(path)
        if _require_sha256(digest, f"root acceptance source hash for {path}") != _source_sha256(source_files, path, "root acceptance"):
            raise PackageError(f"root acceptance source hash differs for {path}")

    if (receipt.get("ownerVersion") != P26.SF_OWNER_VERSION
            or receipt.get("ownerCommit") != P26.SF_OWNER_COMMIT):
        raise PackageError("root acceptance receipt does not bind the pinned Sealed Fates dev.30 owner")
    owner_pins = receipt.get("ownerClosurePins")
    adapter = source_files.get("server/adapters/correspondence.php")
    if not isinstance(owner_pins, dict) or not isinstance(adapter, bytes):
        raise PackageError("root acceptance receipt is missing the Sealed Fates closure pins")
    try:
        current_pins = P26.P25._literal_owner_closure_pins(adapter, P26.SF_OWNER_VERSION)
    except (PackageError, UnicodeDecodeError) as error:
        raise PackageError("selected correspondence adapter does not expose the pinned dev.30 closure") from error
    if set(owner_pins) != set(P26.BASELINE_OWNER_PATHS) or set(current_pins) != set(P26.BASELINE_OWNER_PATHS):
        raise PackageError("root acceptance receipt does not contain the exact twenty-file owner closure")
    normalized_pins = {
        path: _require_sha256(digest, f"root acceptance owner pin for {path}")
        for path, digest in owner_pins.items()
    }
    if normalized_pins != current_pins:
        raise PackageError("root acceptance owner pins differ from the selected correspondence adapter")

    checks = receipt.get("checks")
    if not isinstance(checks, dict):
        raise PackageError("root acceptance receipt is missing its focused runtime checks")
    for name in ("native", "ui", "postgres"):
        check = checks.get(name)
        if (not isinstance(check, dict)
                or check.get("status") != "passed"
                or type(check.get("cases")) is not int
                or check["cases"] < 1):
            raise PackageError(f"root acceptance {name} checks are not passed")
    if type(checks["postgres"].get("assertions")) is not int or checks["postgres"]["assertions"] < 1:
        raise PackageError("root acceptance PostgreSQL checks have no passing assertions")
    if receipt.get("ownerReview") != "accepted" or type(receipt.get("openScopedFindings")) is not int or receipt["openScopedFindings"] != 0:
        raise PackageError("root acceptance receipt is missing accepted owner review or has open findings")

    limits = receipt.get("limits")
    expected_limits = {name: False for name in (
        "live", "installedGame", "skyrim", "provider", "physicalDelivery", "mouseFix",
    )}
    if not isinstance(limits, dict) or limits != expected_limits:
        raise PackageError("root acceptance receipt overclaims live, provider, delivery, or Skyrim behavior")
    evidence_hashes = receipt.get("evidenceHashes")
    if not isinstance(evidence_hashes, dict) or not evidence_hashes:
        raise PackageError("root acceptance receipt has no evidence hashes")
    for name, digest in evidence_hashes.items():
        if not isinstance(name, str):
            raise PackageError("root acceptance evidence receipt name is malformed")
        BASE.validate_member_name(name)
        _require_sha256(digest, f"root acceptance evidence hash for {name}")

    return {
        "sourceCommit": source_commit,
        "sourceHashes": dict(sorted(source_hashes.items())),
        "ownerVersion": P26.SF_OWNER_VERSION,
        "ownerCommit": P26.SF_OWNER_COMMIT,
        "ownerClosurePins": dict(sorted(normalized_pins.items())),
        "checks": {
            "native": {"status": "passed", "cases": checks["native"]["cases"]},
            "ui": {"status": "passed", "cases": checks["ui"]["cases"]},
            "postgres": {
                "status": "passed",
                "cases": checks["postgres"]["cases"],
                "assertions": checks["postgres"]["assertions"],
            },
        },
        "ownerReview": "accepted",
        "openScopedFindings": 0,
        "limits": expected_limits,
        "evidenceHashes": dict(sorted(evidence_hashes.items())),
    }


def validate_allowlist_transition(
    release_bytes: bytes,
    expected_previous_sha256: str,
    baseline_bytes: bytes,
) -> dict:
    expected_previous_sha256 = _require_sha256(expected_previous_sha256, "prealpha.27 source allowlist hash")
    if hashlib.sha256(baseline_bytes).hexdigest() != expected_previous_sha256:
        raise PackageError("prealpha.27 source allowlist hash differs from the selected baseline commit")
    baseline = _json_object(baseline_bytes, "published prealpha.27 source allowlist")
    release = _json_object(release_bytes, "release source allowlist")
    if list(baseline) != ["schemaVersion", "kind", "files"] or list(release) != list(baseline):
        raise PackageError("source allowlist schema or top-level order changed")
    if any(release.get(key) != baseline.get(key) for key in ("schemaVersion", "kind")):
        raise PackageError("source allowlist schema differs from the published baseline")
    old_rows = baseline.get("files")
    rows = release.get("files")
    if not isinstance(old_rows, list) or not isinstance(rows, list):
        raise PackageError("source allowlist files must be arrays")
    if rows != old_rows + [{"path": path} for path in RELEASE_ALLOWLIST_ADDITIONS]:
        raise PackageError("source allowlist must preserve the old prefix and append only the exact release paths")
    paths = []
    for row in rows:
        if not isinstance(row, dict) or list(row) != ["path"] or not isinstance(row.get("path"), str):
            raise PackageError("source allowlist contains a malformed entry")
        BASE.validate_member_name(row["path"])
        paths.append(row["path"])
    if len(paths) != len(set(paths)):
        raise PackageError("source allowlist contains duplicate paths")

    lines = baseline_bytes.splitlines(keepends=True)
    if len(lines) < 7 or lines[-2].strip() != b"]" or lines[-1].strip() != b"}":
        raise PackageError("published source allowlist has unexpected JSON framing")
    entry_line = re.compile(rb'^    \{ "path": "([^"\\]+)" \}(,?)(\r\n|\n)\Z')
    previous_line = lines[-3]
    match = entry_line.fullmatch(previous_line)
    if match is None or match.group(2) or not old_rows:
        raise PackageError("published source allowlist has no exact final path row")
    if match.group(1).decode("utf-8") != old_rows[-1]["path"]:
        raise PackageError("published source allowlist final row differs from its parsed contents")
    newline = match.group(3)
    previous_with_comma = previous_line[:match.start(2)] + b"," + newline
    new_rows = [
        (f'    {{ "path": "{path}" }}' + ("," if index < len(RELEASE_ALLOWLIST_ADDITIONS) - 1 else "") + newline.decode("ascii")).encode("utf-8")
        for index, path in enumerate(RELEASE_ALLOWLIST_ADDITIONS)
    ]
    expected_bytes = b"".join(lines[:-3] + [previous_with_comma] + new_rows + lines[-2:])
    if release_bytes != expected_bytes:
        raise PackageError("source allowlist does not preserve its exact old prefix bytes")
    return {
        "previousPrefixSha256": expected_previous_sha256,
        "releaseSha256": hashlib.sha256(release_bytes).hexdigest(),
        "appendedPaths": list(RELEASE_ALLOWLIST_ADDITIONS),
    }


def validate_manifest_transition(old_bytes: bytes, release_bytes: bytes) -> dict:
    old = _json_object(old_bytes, "published prealpha.27 server manifest")
    release = _json_object(release_bytes, "release server manifest")
    expected = dict(old)
    if (old.get("version") != BASELINE_VERSION
            or release.get("version") != VERSION
            or list(release) != list(old)):
        raise PackageError("server manifest is not an exact version-only prealpha.27 to prealpha.28 change")
    expected["version"] = VERSION
    if release != expected or BASE.json_bytes(expected) != release_bytes:
        raise PackageError("server manifest is not an exact version-only prealpha.27 to prealpha.28 change")
    return {
        "baselineSha256": hashlib.sha256(old_bytes).hexdigest(),
        "releaseSha256": hashlib.sha256(release_bytes).hexdigest(),
    }


def _stable_zip_bytes(files: dict[str, bytes]) -> bytes:
    old_timestamp = BASE.ZIP_TIMESTAMP
    BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP
    try:
        return BASE.stable_zip_bytes(files)
    finally:
        BASE.ZIP_TIMESTAMP = old_timestamp


def _required_source(read_source, path: str) -> bytes:
    try:
        value = read_source(path)
    except KeyError as error:
        raise PackageError(f"selected source is missing: {path}") from error
    if not isinstance(value, bytes):
        raise PackageError(f"selected source is not bytes: {path}")
    return value


def _read_prealpha27_baseline(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if hashlib.sha256(data).hexdigest() != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact published prealpha.27 archive")
    files = BASE.read_zip_bytes(data, "published prealpha.27 ZIP")
    if set(files) != set(EXPECTED_BASELINE_MEMBERS) or len(files) != 22:
        raise PackageError("published prealpha.27 ZIP member set differs")
    department = P24._audit_department_component(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION)
    pcv = P20._audit_component(files[PCV_MEMBER], "pcv_companion", PCV_VERSION)
    return files, department, pcv


def _build_department_package(
    old_package_bytes: bytes,
    read_source,
    old_server_manifest: bytes,
) -> tuple[bytes, dict[str, bytes]]:
    old_package = P24._audit_department_component(old_package_bytes, BASELINE_VERSION)
    if old_package["server/manifest.json"] != old_server_manifest:
        raise PackageError("prealpha.27 DWPkg server manifest differs from its pinned source commit")
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.27 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION:
        raise PackageError("prealpha.27 Department outer manifest has the wrong version")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    server_manifest = _required_source(read_source, SERVER_MANIFEST)
    validate_manifest_transition(old_server_manifest, server_manifest)
    current[SERVER_MANIFEST] = server_manifest

    changed_sources = set()
    for path in sorted(DEPARTMENT_SERVER_SOURCE_PATHS - {SERVER_MANIFEST}):
        source = _required_source(read_source, path)
        if path in DEPARTMENT_DPKG_SOURCE_CHANGES:
            if source == old_package[path]:
                raise PackageError(f"expected Department source update is missing: {path}")
            changed_sources.add(path)
        elif source != old_package[path]:
            raise PackageError(f"Department server source changed outside the fixed prealpha.28 set: {path}")
        current[path] = source
    if changed_sources != set(DEPARTMENT_DPKG_SOURCE_CHANGES):
        raise PackageError("Department DWPkg server source delta differs from the fixed prealpha.28 set")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = _stable_zip_bytes(current)
    verified = P24._audit_department_component(package, VERSION)
    changed = {
        path for path in old_package.keys() | verified.keys()
        if old_package.get(path) != verified.get(path)
    }
    if changed != set(DEPARTMENT_DPKG_CHANGED_MEMBERS):
        unexpected = sorted(changed - set(DEPARTMENT_DPKG_CHANGED_MEMBERS))
        missing = sorted(set(DEPARTMENT_DPKG_CHANGED_MEMBERS) - changed)
        raise PackageError(
            f"Department DWPkg changed outside the fixed prealpha.28 delta; unexpected={unexpected}, missing={missing}"
        )
    return package, verified


def assemble_installable_files(
    baseline_files: dict[str, bytes],
    department_dwpkg: bytes,
    native_dll: bytes,
    ui_app: bytes,
    ui_index: bytes,
) -> dict[str, bytes]:
    if set(baseline_files) != set(EXPECTED_BASELINE_MEMBERS):
        raise PackageError("published prealpha.27 installable member set differs from its fixed contract")
    for content, label in (
        (department_dwpkg, "Department DWPkg"),
        (native_dll, "native DLL"),
        (ui_app, "UI app.js"),
        (ui_index, "UI index.html"),
    ):
        if not isinstance(content, bytes) or not content:
            raise PackageError(f"{label} is missing or not bytes")
    release = {path: value for path, value in baseline_files.items() if path != BASELINE_DPKG_MEMBER}
    release[DPKG_MEMBER] = department_dwpkg
    release[DLL_MEMBER] = native_dll
    release[UI_APP_MEMBER] = ui_app
    release[UI_INDEX_MEMBER] = ui_index
    if set(release) != set(EXPECTED_INSTALLABLE_MEMBERS):
        raise PackageError("prealpha.28 installable member set differs from its fixed contract")
    return release


_SKSE_EXPORTS = (
    "SKSEPlugin_Load",
    "SKSEPlugin_Version",
    "DQL_ValidateBootstrapResponse",
)
_SKSE_VERSION_PACKED = 65824


def parse_native_pe(data: bytes) -> dict:
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
    number_of_functions = u32(export + 20, "export directory")
    number_of_names = u32(export + 24, "export directory")
    if not number_of_functions or number_of_functions > 10000 or number_of_names > number_of_functions:
        raise PackageError("native DLL has an invalid export table")
    functions_rva = u32(export + 28, "export directory")
    names_rva = u32(export + 32, "export directory")
    ordinals_rva = u32(export + 36, "export directory")
    functions_offset = rva_offset(functions_rva, number_of_functions * 4, "export address table")
    names_offset = rva_offset(names_rva, number_of_names * 4, "export name table")
    ordinals_offset = rva_offset(ordinals_rva, number_of_names * 2, "export ordinal table")

    exports = set()
    version_rva = None
    for index in range(number_of_names):
        name_rva = u32(names_offset + index * 4, "export name table")
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
            ordinal = u16(ordinals_offset + index * 2, "export ordinal table")
            if ordinal >= number_of_functions:
                raise PackageError("native DLL version export has an invalid ordinal")
            version_rva = u32(functions_offset + ordinal * 4, "export address table")

    if version_rva is None:
        raise PackageError("native DLL is missing SKSEPlugin_Version")
    if export_rva <= version_rva < export_rva + export_size:
        raise PackageError("native DLL version export is unexpectedly forwarded")
    version_offset = rva_offset(version_rva, 8, "SKSE plugin version data")
    packed_version = u32(version_offset + 4, "SKSE plugin version data")
    if packed_version != _SKSE_VERSION_PACKED:
        raise PackageError("native DLL SKSEPlugin_Version is not 0.1.18.0")
    missing = set(_SKSE_EXPORTS) - exports
    if missing:
        raise PackageError(f"native DLL is missing required exports: {', '.join(sorted(missing))}")
    return {"machine": machine, "sksePluginVersionPacked": packed_version, "exports": exports}


def _build_source_zip(git: str, source_commit: str, allowlist: list[str]) -> bytes:
    old_timestamp = BASE.ZIP_TIMESTAMP
    BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP
    try:
        return BASE.build_source_zip(git, source_commit, allowlist)
    finally:
        BASE.ZIP_TIMESTAMP = old_timestamp


def build_release_proof_zip(
    release_audit: dict,
    runtime_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    native_evidence: dict,
    acceptance: dict,
) -> bytes:
    native_build = native_evidence["build"]
    native_pe = native_evidence["peVerification"]
    native_binding = native_evidence["sourceBinding"]
    summary = {
        "schemaVersion": 1,
        "kind": "department-prealpha28-offline-proof",
        "version": VERSION,
        "sourceCommit": release_audit["sourceCommit"],
        "runtime": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(runtime_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "nativeDll": {"member": DLL_MEMBER, "sha256": native_pe["dllSha256"], "version": "0.1.18.0"},
        "unchangedInstallableMembers": release_audit["unchangedInstallableMembers"],
        "nativeEvidence": {
            "sourceBinding": native_binding,
            "buildReceiptSha256": native_evidence["buildReceiptSha256"],
            "build": native_build,
            "peVerification": native_pe,
        },
        "acceptance": acceptance,
        "limits": release_audit["limits"],
    }
    provenance = {
        "schemaVersion": 1,
        "kind": "department-prealpha28-release-provenance",
        "version": VERSION,
        "sourceCommit": release_audit["sourceCommit"],
        "runtimeSha256": BASE.sha256(runtime_zip),
        "sourceArchiveSha256": BASE.sha256(source_zip),
        "departmentDwpkgSha256": BASE.sha256(department_package),
        "nativeDllSha256": native_pe["dllSha256"],
        "sourceAllowlistSha256": release_audit["sourceAllowlistSha256"],
        "nativeBuildReceiptSha256": native_evidence["buildReceiptSha256"],
        "rootAcceptanceSourceCommit": acceptance["sourceCommit"],
        "limits": release_audit["limits"],
    }
    files = {
        "offline-proof.json": BASE.json_bytes(summary),
        "native-build.json": BASE.json_bytes(native_build),
        "native-pe-verification.json": BASE.json_bytes(native_pe),
        "native-source-binding.json": BASE.json_bytes(native_binding),
        "root-acceptance.json": BASE.json_bytes(acceptance),
        "package-provenance.json": BASE.json_bytes(provenance),
    }
    return _stable_zip_bytes(files)


def build_release_assets(
    baseline_zip: bytes,
    native_dll: bytes,
    native_build_receipt_bytes: bytes,
    native_source_binding_bytes: bytes,
    native_pe_receipt_bytes: bytes,
    acceptance_receipt_bytes: bytes,
    source_commit: str,
    allowlist: list[str],
    source_files: dict[str, bytes],
    source_zip: bytes,
    old_manifest: bytes,
    baseline_allowlist: bytes,
) -> tuple[dict[str, bytes], dict]:
    if _FULL_COMMIT.fullmatch(source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    baseline_files, old_department, _old_pcv = _read_prealpha27_baseline(baseline_zip)
    if set(allowlist) != set(source_files) or len(allowlist) != len(set(allowlist)):
        raise PackageError("committed source bytes do not match the exact public allowlist")
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"source allowlist is missing required prealpha.28 files: {', '.join(missing)}")
    allowlist_summary = validate_allowlist_transition(
        source_files[ALLOWLIST_PATH], BASELINE_ALLOWLIST_SHA256, baseline_allowlist,
    )
    current_allowlist = _json_object(source_files[ALLOWLIST_PATH], "release source allowlist")
    if ([entry.get("path") for entry in current_allowlist.get("files", []) if isinstance(entry, dict)] != allowlist
            or any(not isinstance(entry, dict) or list(entry) != ["path"] for entry in current_allowlist["files"])
            or [entry["path"] for entry in current_allowlist["files"]] != allowlist):
        raise PackageError("selected source paths differ from the committed public allowlist")
    for path, content in source_files.items():
        BASE.validate_member_name(path)
        if not isinstance(content, bytes):
            raise PackageError(f"committed source is not bytes: {path}")
    source_zip_files = BASE.read_zip_bytes(source_zip, "prealpha.28 source ZIP")
    if set(source_zip_files) != set(allowlist) or any(source_zip_files[path] != source_files[path] for path in allowlist):
        raise PackageError("source ZIP does not match the exact selected committed source allowlist")
    BASE.require_canonical_bytes("prealpha.28 source ZIP", source_zip, _stable_zip_bytes(source_files))

    native_evidence = validate_native_evidence(
        native_source_binding_bytes,
        native_build_receipt_bytes,
        native_pe_receipt_bytes,
        native_dll,
        source_commit,
        source_files,
    )
    acceptance = validate_acceptance_receipt(
        _safe_json_input(acceptance_receipt_bytes, "root acceptance receipt"),
        source_commit,
        source_files,
    )

    def read_current(path: str) -> bytes:
        return _required_source(source_files.__getitem__, path)

    department_package, _department_files = _build_department_package(
        baseline_files[BASELINE_DPKG_MEMBER], read_current, old_manifest,
    )
    release_files = assemble_installable_files(
        baseline_files,
        department_package,
        native_dll,
        read_current(UI_APP_SOURCE),
        read_current(UI_INDEX_SOURCE),
    )
    if (native_dll == baseline_files[DLL_MEMBER]
            or release_files[UI_APP_MEMBER] == baseline_files[UI_APP_MEMBER]
            or release_files[UI_INDEX_MEMBER] == baseline_files[UI_INDEX_MEMBER]):
        raise PackageError("prealpha.28 must replace the native DLL and both changed UI payloads")
    unchanged = sorted(
        path for path in set(release_files) & set(baseline_files)
        if release_files[path] == baseline_files[path]
    )
    if len(unchanged) != 18:
        raise PackageError("prealpha.28 must preserve exactly eighteen prior installable payloads byte-for-byte")

    runtime_zip = _stable_zip_bytes(release_files)
    native_pe = native_evidence["peVerification"]
    release_audit = {
        "schemaVersion": 1,
        "kind": "department-prealpha28-source-bound-offline-release",
        "version": VERSION,
        "sourceCommit": source_commit,
        "baseline": {
            "version": BASELINE_VERSION,
            "sha256": BASELINE_SHA256,
            "sourceCommit": BASELINE_SOURCE_COMMIT,
        },
        "runtimeZipSha256": BASE.sha256(runtime_zip),
        "sourceArchiveSha256": BASE.sha256(source_zip),
        "sourceAllowlistSha256": BASE.sha256(source_files[ALLOWLIST_PATH]),
        "sourceFileCount": len(allowlist),
        "releaseSourceHashes": {
            path: BASE.sha256(source_files[path]) for path in sorted(REQUIRED_SOURCE_PATHS)
        },
        "allowlistTransition": allowlist_summary,
        "departmentDwpkgSha256": BASE.sha256(department_package),
        "departmentDwpkgChangedMembers": sorted(DEPARTMENT_DPKG_CHANGED_MEMBERS),
        "nativeDllSha256": native_pe["dllSha256"],
        "nativeVersion": "0.1.18.0",
        "uiAppSha256": BASE.sha256(source_files[UI_APP_SOURCE]),
        "uiIndexSha256": BASE.sha256(source_files[UI_INDEX_SOURCE]),
        "unchangedInstallableMembers": unchanged,
        "nativeEvidence": native_evidence,
        "acceptance": acceptance,
        "limits": {
            "sourceBindingAndPackageAssembly": True,
            "nativeBuildAndHostTests": True,
            "nativePeExportVerification": True,
            "focusedRuntimeAcceptance": True,
            "live": False,
            "installedGame": False,
            "skyrim": False,
            "provider": False,
            "physicalDelivery": False,
            "mouseFix": False,
            "published": False,
        },
    }
    proof_zip = build_release_proof_zip(
        release_audit, runtime_zip, source_zip, department_package, native_evidence, acceptance,
    )
    assets = {
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PROOF_ZIP: proof_zip,
    }
    assets[CHECKSUMS_NAME] = BASE.make_checksums(assets)
    if not assets[CHECKSUMS_NAME].endswith(b"\n") or b"\r" in assets[CHECKSUMS_NAME]:
        raise PackageError("release checksums must use canonical LF-only lines")
    return assets, release_audit


def _inputs(args):
    source_commit = BASE.canonical_source_commit(args.git, args.source_commit)
    PREVIOUS.require_clean_source_tree(args.git)
    baseline_zip = PREVIOUS._safe_input(args.prealpha27_zip, "published prealpha.27 ZIP")
    native_dll = PREVIOUS._safe_input(args.native_dll, "built native DLL")
    native_build_receipt = PREVIOUS._safe_input(args.native_build_receipt, "native build receipt")
    native_source_binding = PREVIOUS._safe_input(args.native_source_binding, "native source binding receipt")
    native_pe_receipt = PREVIOUS._safe_input(args.native_pe_receipt, "native PE verification receipt")
    acceptance_receipt = PREVIOUS._safe_input(args.acceptance_receipt, "root acceptance receipt")

    allowlist = BASE.source_allowlist(args.git, source_commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"source allowlist is missing prealpha.28 paths: {', '.join(missing)}")
    source_files = {
        path: BASE.committed_worktree_file(args.git, source_commit, set(allowlist), path)
        for path in allowlist
    }
    old_manifest = BASE.git_blob(args.git, BASELINE_SOURCE_COMMIT, SERVER_MANIFEST)
    baseline_allowlist = BASE.git_blob(args.git, BASELINE_SOURCE_COMMIT, ALLOWLIST_PATH)
    source_zip = _build_source_zip(args.git, source_commit, allowlist)
    return (
        source_commit,
        baseline_zip,
        native_dll,
        native_build_receipt,
        native_source_binding,
        native_pe_receipt,
        acceptance_receipt,
        allowlist,
        source_files,
        source_zip,
        old_manifest,
        baseline_allowlist,
    )


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("release output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if (set(path.name for path in entries) != OUTPUT_FILE_NAMES
            or any(path.is_symlink() or not path.is_file() for path in entries)):
        raise PackageError("release output must contain only the installable, source, offline-proof ZIPs, and checksums")
    return {path.name: path.read_bytes() for path in entries}


def audit_output(args) -> None:
    inputs = _inputs(args)
    commit, baseline_zip, dll, build_receipt, binding, pe_receipt, acceptance, allowed, sources, source_zip, old_manifest, baseline_allowlist = inputs
    expected, _release_audit = build_release_assets(
        baseline_zip, dll, build_receipt, binding, pe_receipt, acceptance,
        commit, allowed, sources, source_zip, old_manifest, baseline_allowlist,
    )
    actual = _expected_output_files(args.output_dir)
    for name in sorted(OUTPUT_FILE_NAMES):
        if actual[name] != expected[name]:
            raise PackageError(f"release output differs from the canonical source-bound build: {name}")
    BASE.audit_checksums_file(actual[CHECKSUMS_NAME], {name: actual[name] for name in OUTPUT_NAMES})


def _write_outputs(directory: Path, assets: dict[str, bytes]) -> None:
    if set(assets) != OUTPUT_FILE_NAMES:
        raise PackageError("prealpha.28 release output set is incomplete")
    if directory.is_symlink() or not directory.is_dir() or any(directory.iterdir()):
        raise PackageError("release staging directory is missing, unsafe, or not empty")
    for name, content in assets.items():
        BASE.validate_member_name(name)
        with (directory / name).open("xb") as stream:
            stream.write(content)


def build(args) -> None:
    inputs = _inputs(args)
    commit, baseline_zip, dll, build_receipt, binding, pe_receipt, acceptance, allowed, sources, source_zip, old_manifest, baseline_allowlist = inputs
    assets, _release_audit = build_release_assets(
        baseline_zip, dll, build_receipt, binding, pe_receipt, acceptance,
        commit, allowed, sources, source_zip, old_manifest, baseline_allowlist,
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
        audit_args = argparse.Namespace(**vars(args))
        audit_args.output_dir = stage
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
        sub.add_argument("--prealpha27-zip", required=True, type=Path)
        sub.add_argument("--native-dll", required=True, type=Path)
        sub.add_argument("--native-build-receipt", required=True, type=Path)
        sub.add_argument("--native-source-binding", required=True, type=Path)
        sub.add_argument("--native-pe-receipt", required=True, type=Path)
        sub.add_argument("--acceptance-receipt", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
        sub.add_argument("--git", default="git")
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    try:
        if args.command == "build":
            build(args)
            print(f"Built and audited prealpha.28 release: {args.output_dir.resolve()}")
        else:
            audit_output(args)
            print(f"Source-bound prealpha.28 release audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, zipfile.BadZipFile, ValueError, KeyError, TypeError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
