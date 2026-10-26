#!/usr/bin/env python3
"""Build and audit prealpha.16 against the exact prealpha.15 package and receipts."""

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


VERSION = "0.1.0-prealpha.16"
ROOT = Path(__file__).resolve().parents[1]
BASELINE_SHA256 = "89675842fffde483998f7f127d6276841c461283ecb228de08cfb0e5954d2b09"
GENERATOR_PATH = "server/tests/native-bootstrap/generate.php"
FIXTURE_PATH = "native-evidence/server-bootstrap-fixtures.json"
BOOTSTRAP_RECEIPT_NAMES = {
    "phpFixtures": "server-bootstrap-fixtures.json",
    "phpGenerator": "php-generator-receipt.json",
    "nativeBuild": "native-build-receipt.json",
    "nativeBinding": "native-bootstrap-binding.json",
    "dllBootstrap": "dll-bootstrap-results.json",
}
CASE_NAMES = (
    "unauthenticated_bootstrap",
    "authenticated_all_readiness_disabled",
    "authenticated_full_access",
    "authenticated_brainwash_only",
)
SERVER_SOURCE_PATHS = (
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
    GENERATOR_PATH,
)
DLL_LIMITS = {"skyrim": False, "http": False, "live": False}
ZIP_TIMESTAMP = (2026, 10, 25, 0, 0, 0)

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-bootstrap-proof.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}

_PREVIOUS_PATH = Path(__file__).with_name("build-installable-prealpha15.py")
_PREVIOUS_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha15", _PREVIOUS_PATH)
if _PREVIOUS_SPEC is None or _PREVIOUS_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.15 packaging helper")
PREVIOUS = importlib.util.module_from_spec(_PREVIOUS_SPEC)
_PREVIOUS_SPEC.loader.exec_module(PREVIOUS)
BASE = PREVIOUS.BASE
PackageError = PREVIOUS.PackageError
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

DLL_MEMBER = PREVIOUS.PREVIOUS.DLL_MEMBER
APP_JS_MEMBER = PREVIOUS.PREVIOUS.APP_JS_MEMBER
NATIVE_SOURCE_PATHS = set(PREVIOUS.PREVIOUS.NATIVE_SOURCE_PATHS)
NATIVE_TEST_SOURCE = "native/tests/ServerBootstrapTests.cpp"
BASELINE_MO2_MEMBERS = (
    set(PREVIOUS.BASELINE_MO2_MEMBERS)
    - {PREVIOUS.BASELINE_DPKG_MEMBER}
    | {PREVIOUS.DPKG_MEMBER}
)
SERVER_PACKAGE_MEMBERS = {
    path for path in BASELINE_MO2_MEMBERS
    if path.startswith("CHIM/server-plugins/") and path.endswith(".dwpkg")
}
if len(BASELINE_MO2_MEMBERS) != 22 or len(SERVER_PACKAGE_MEMBERS) != 3 or len(NATIVE_SOURCE_PATHS) != 15:
    raise RuntimeError("prealpha.15 baseline or fixed packaging inputs differ from the release contract")

REQUIRED_SOURCE_PATHS = (
    set(PREVIOUS.REQUIRED_SOURCE_PATHS)
    | {
        "README.md",
        "docs/BUILD.md",
        "docs/VERIFICATION.md",
        "docs/superpowers/plans/2026-10-25-bootstrap-validator.md",
        "native/tests/ServerBootstrapTests.cpp",
        "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
        "packaging/build-installable-prealpha16.py",
        "packaging/public-source-allowlist.json",
        "packaging/tests/test_installable_prealpha16.py",
        "ui/app.js",
        "ui/tests/startup-handshake.cjs",
        *SERVER_SOURCE_PATHS,
    }
)


def _json_object(data: bytes, label: str) -> dict:
    return PREVIOUS._json_object(data, label)


def _regular_file(path: Path, label: str, limit: int = 64 * 1024 * 1024) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise PackageError(f"{label} must be a regular file")
    data = path.read_bytes()
    if len(data) > limit:
        raise PackageError(f"{label} exceeds the {limit} byte input limit")
    return data


def _hex_sha(value: object, label: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-fA-F]{64}", value) is None:
        raise PackageError(f"{label} must be a SHA-256 hex digest")
    return value.lower()


def _git_blob_id(git: str, commit: str, path: str) -> str:
    try:
        value = BASE.git_output(git, ["rev-parse", "--verify", f"{commit}:{path}"]).decode("ascii").strip().lower()
    except (OSError, subprocess.SubprocessError, UnicodeDecodeError) as error:
        raise PackageError(f"could not resolve committed Git blob: {path}") from error
    if re.fullmatch(r"[0-9a-f]{40,64}", value) is None:
        raise PackageError(f"Git returned a malformed blob id for {path}")
    return value


def _expected_blob_map(git: str, commit: str, paths: set[str] | tuple[str, ...]) -> dict[str, str]:
    return {path: _git_blob_id(git, commit, path) for path in sorted(paths)}


def _read_baseline(path: Path) -> tuple[bytes, dict[str, bytes]]:
    data = _regular_file(path, "prealpha.15 baseline ZIP")
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.15 archive")
    files = PREVIOUS._read_installable(data)
    if set(files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.15 baseline member list differs")
    if {name for name in files if name.endswith(".dwpkg") and name.startswith("CHIM/server-plugins/")} != SERVER_PACKAGE_MEMBERS:
        raise PackageError("prealpha.15 baseline does not contain the exact three pinned server packages")
    return data, files


def _validate_fixture(data: bytes) -> dict:
    fixture = _json_object(data, "PHP bootstrap fixtures")
    if set(fixture) != {"schemaVersion", "sourceFiles", "stateCleanup", "cases"}:
        raise PackageError("PHP bootstrap fixture fields differ from schema version 1")
    if type(fixture["schemaVersion"]) is not int or fixture["schemaVersion"] != 1:
        raise PackageError("PHP bootstrap fixtures must use schema version 1")
    if fixture["stateCleanup"] != "removed":
        raise PackageError("PHP bootstrap fixture generation did not remove its isolated state")
    if fixture["sourceFiles"] != list(SERVER_SOURCE_PATHS):
        raise PackageError("PHP bootstrap fixture source list differs from the fixed API source set")
    cases = fixture["cases"]
    if not isinstance(cases, list) or len(cases) != len(CASE_NAMES):
        raise PackageError("PHP bootstrap fixture must contain exactly four actual cases")
    expected_flags = ((False, False), (True, False), (True, True), (True, True))
    for index, (case, name, flags) in enumerate(zip(cases, CASE_NAMES, expected_flags, strict=True)):
        if not isinstance(case, dict) or set(case) != {
            "name", "requestBody", "responseStatus", "responseBody", "authenticated", "brainwash",
        }:
            raise PackageError(f"PHP bootstrap case {index} fields differ from the fixed schema")
        if case["name"] != name or (case["authenticated"], case["brainwash"]) != flags:
            raise PackageError(f"PHP bootstrap case order or expectation metadata differs: {name}")
        if not isinstance(case["requestBody"], str) or not isinstance(case["responseBody"], str):
            raise PackageError(f"PHP bootstrap case bodies must be serialized JSON strings: {name}")
        if type(case["responseStatus"]) is not int or not 200 <= case["responseStatus"] < 300:
            raise PackageError(f"PHP bootstrap case status is not successful: {name}")
        if type(case["authenticated"]) is not bool or type(case["brainwash"]) is not bool:
            raise PackageError(f"PHP bootstrap expectation flags must be booleans: {name}")
        try:
            request = json.loads(case["requestBody"])
            response = json.loads(case["responseBody"])
        except json.JSONDecodeError as error:
            raise PackageError(f"PHP bootstrap case contains invalid serialized JSON: {name}") from error
        if not isinstance(request, dict) or not isinstance(response, dict) or response.get("ok") is not True:
            raise PackageError(f"PHP bootstrap case does not contain a request and successful response: {name}")
        response_data = response.get("data")
        if (not isinstance(response_data, dict)
                or response_data.get("authenticated") is not case["authenticated"]
                or not isinstance(response_data.get("access"), dict)
                or response_data["access"].get("brainwash") is not case["brainwash"]):
            raise PackageError(f"PHP bootstrap case response disagrees with its expected flags: {name}")
    return fixture


def _validate_php_generator_receipt(data: bytes, fixture_bytes: bytes, fixture: dict,
                                    git: str, commit: str) -> dict:
    receipt = _json_object(data, "PHP generator receipt")
    expected_fields = {
        "schemaVersion", "phpVersion", "generatorPath", "generatorSha256", "fixturePath",
        "fixtureSha256", "sourceFiles", "cases", "stateCleanup", "negativeOutputPath",
    }
    if set(receipt) != expected_fields or type(receipt["schemaVersion"]) is not int or receipt["schemaVersion"] != 1:
        raise PackageError("PHP generator receipt fields differ from schema version 1")
    if (receipt["phpVersion"] != "8.3.35" or receipt["generatorPath"] != GENERATOR_PATH
            or receipt["fixturePath"] != FIXTURE_PATH or receipt["stateCleanup"] != "removed"):
        raise PackageError("PHP generator receipt does not identify the pinned generator, runtime, fixture or cleanup")
    fixture_sha = BASE.sha256(fixture_bytes)
    if _hex_sha(receipt["fixtureSha256"], "PHP fixture SHA-256") != fixture_sha:
        raise PackageError("PHP generator receipt fixture hash differs from the actual fixture bytes")
    if receipt["cases"] != list(CASE_NAMES):
        raise PackageError("PHP generator receipt case IDs differ from the four actual fixture cases")

    generator_bytes = BASE.git_blob(git, commit, GENERATOR_PATH)
    generator_sha = BASE.sha256(generator_bytes)
    if _hex_sha(receipt["generatorSha256"], "PHP generator SHA-256") != generator_sha:
        raise PackageError("PHP generator receipt hash differs from the selected source commit")
    if _hex_sha(receipt["generatorSha256"], "PHP generator SHA-256") != BASE.sha256(
            _regular_file(ROOT / GENERATOR_PATH, "committed PHP bootstrap generator")):
        raise PackageError("working PHP generator bytes differ from the selected source commit")

    source_rows = receipt["sourceFiles"]
    if not isinstance(source_rows, list) or len(source_rows) != len(SERVER_SOURCE_PATHS):
        raise PackageError("PHP generator receipt must bind every fixed production source")
    actual_sources = {}
    for row in source_rows:
        if not isinstance(row, dict) or set(row) != {"path", "sha256"}:
            raise PackageError("PHP generator receipt source rows are malformed")
        path = row["path"]
        if not isinstance(path, str) or path in actual_sources:
            raise PackageError("PHP generator receipt contains duplicate or malformed source paths")
        actual_sources[path] = _hex_sha(row["sha256"], f"PHP source hash {path}")
    if list(actual_sources) != fixture["sourceFiles"]:
        raise PackageError("PHP generator receipt source paths differ from the fixture source list")
    for path, actual_sha in actual_sources.items():
        committed = BASE.git_blob(git, commit, path)
        if actual_sha != BASE.sha256(committed):
            raise PackageError(f"PHP generator receipt source hash differs from the selected source commit: {path}")
        if actual_sha != BASE.sha256(_regular_file(ROOT / path, f"committed PHP source {path}")):
            raise PackageError(f"working PHP source bytes differ from the selected source commit: {path}")

    negative = receipt["negativeOutputPath"]
    if (not isinstance(negative, dict) or set(negative) != {
            "exitCode", "reason", "outputCreated", "stateRootCreated",
    } or type(negative["exitCode"]) is not int or negative["exitCode"] == 0
            or negative["reason"] != "output_path_invalid"
            or negative["outputCreated"] is not False or negative["stateRootCreated"] is not False):
        raise PackageError("PHP generator receipt lacks the passed invalid-output-path cleanup check")
    return receipt


def _validate_native_build_receipt(data: bytes, fixture_bytes: bytes, dll: bytes) -> dict:
    receipt = _json_object(data, "native build receipt")
    if set(receipt) != {
        "schemaVersion", "status", "toolchain", "inputs", "serverBootstrapFixtures",
        "hostTests", "plugin", "buildAccess",
    }:
        raise PackageError("native build receipt fields differ from the versioned build schema")
    if (type(receipt.get("schemaVersion")) is not int or receipt.get("schemaVersion") != 1
            or receipt.get("status") != "native-plugin-and-host-tests-built"):
        raise PackageError("native build receipt is not a successful schema-1 build")
    if not isinstance(receipt["toolchain"], dict):
        raise PackageError("native build receipt is missing its pinned toolchain record")
    inputs = receipt.get("inputs")
    if not isinstance(inputs, dict) or not isinstance(inputs.get("phpVersion"), str) or not re.match(
            r"^PHP 8\.3\.35 \(cli\)(?:\b|$)", inputs["phpVersion"]):
        raise PackageError("native build receipt does not identify the pinned PHP 8.3.35 CLI")
    plugin = receipt.get("plugin")
    if (not isinstance(plugin, dict) or plugin.get("name") != "WorldOfDramaLlama.dll"
            or type(plugin.get("bytes")) is not int or plugin["bytes"] != len(dll)
            or _hex_sha(plugin.get("sha256"), "native build receipt plugin SHA-256") != BASE.sha256(dll)
            or plugin.get("linkOptionsConfigured") is not True):
        raise PackageError("native build receipt does not identify this deterministic DLL")
    host_tests = receipt.get("hostTests")
    if (not isinstance(host_tests, dict) or set(host_tests) != {"result", "count"}
            or type(host_tests.get("count")) is not int
            or host_tests["count"] != 6
            or host_tests.get("result") != "100% tests passed, 0 tests failed out of 6"):
        raise PackageError("native build receipt must confirm all six CTest targets passed")
    fixtures = receipt.get("serverBootstrapFixtures")
    if (not isinstance(fixtures, dict) or set(fixtures) != {"sha256", "caseNames", "sourceFiles", "stateCleanup"}
            or _hex_sha(fixtures.get("sha256"), "native fixture SHA-256")
            != BASE.sha256(fixture_bytes) or fixtures.get("caseNames") != list(CASE_NAMES)
            or fixtures.get("sourceFiles") != list(SERVER_SOURCE_PATHS)
            or fixtures.get("stateCleanup") != "removed"):
        raise PackageError("native build receipt does not bind the four PHP fixtures and their source inputs")
    if receipt["buildAccess"] != {
        "receiptOmitsLocalPaths": True,
        "installedGameFilesRead": False,
        "dllPathScanIsSeparateEvidence": True,
    }:
        raise PackageError("native build receipt exceeds its offline source-build boundary")
    return receipt


def _validate_dll_bootstrap_receipt(data: bytes, dll: bytes, fixture_bytes: bytes) -> dict:
    receipt = _json_object(data, "DLL bootstrap results receipt")
    if set(receipt) != {
        "schemaVersion", "status", "dllSha256", "fixtureSha256", "caseNames", "negativeCases", "limits",
    }:
        raise PackageError("DLL bootstrap receipt fields differ from schema version 1")
    if type(receipt["schemaVersion"]) is not int or receipt["schemaVersion"] != 1 or receipt["status"] != "passed":
        raise PackageError("final DLL bootstrap run did not pass")
    if (_hex_sha(receipt["dllSha256"], "DLL bootstrap DLL SHA-256") != BASE.sha256(dll)
            or _hex_sha(receipt["fixtureSha256"], "DLL bootstrap fixture SHA-256") != BASE.sha256(fixture_bytes)
            or receipt["caseNames"] != list(CASE_NAMES)):
        raise PackageError("final DLL bootstrap run does not bind this DLL and all four case IDs")
    if type(receipt["negativeCases"]) is not int or receipt["negativeCases"] <= 0:
        raise PackageError("final DLL bootstrap run must include at least one rejected negative case")
    if receipt["limits"] != DLL_LIMITS:
        raise PackageError("DLL bootstrap receipt claims tests outside the offline DLL boundary")
    return receipt


def _validate_cross_boundary_binding(data: bytes, git: str, commit: str, dll: bytes,
                                     fixture_bytes: bytes, php_receipt_bytes: bytes,
                                     native_receipt_bytes: bytes, dll_receipt_bytes: bytes) -> dict:
    binding = _json_object(data, "native bootstrap binding receipt")
    expected_fields = {
        "schemaVersion", "sourceCommit", "baselineSha256", "dllSha256", "nativeBuildReceiptSha256",
        "phpGeneratorReceiptSha256", "fixtureSha256", "nativeTestTarget", "nativeTestResult",
        "caseNames", "nativeSourceBlobs", "serverSourceBlobs", "dllBootstrapReceiptSha256",
        "nativeTestSourceSha256",
    }
    if set(binding) != expected_fields or type(binding["schemaVersion"]) is not int or binding["schemaVersion"] != 1:
        raise PackageError("cross-boundary receipt fields differ from the approved schema version 1")
    if binding["sourceCommit"] != commit:
        raise PackageError("cross-boundary receipt does not name the selected source commit")
    if _hex_sha(binding["baselineSha256"], "cross-boundary baseline SHA-256") != BASELINE_SHA256:
        raise PackageError("cross-boundary receipt does not bind the verified prealpha.15 baseline")
    for field, actual, label in (
        ("dllSha256", dll, "DLL"),
        ("nativeBuildReceiptSha256", native_receipt_bytes, "native build receipt"),
        ("phpGeneratorReceiptSha256", php_receipt_bytes, "PHP generator receipt"),
        ("fixtureSha256", fixture_bytes, "PHP bootstrap fixture"),
        ("dllBootstrapReceiptSha256", dll_receipt_bytes, "DLL bootstrap results receipt"),
    ):
        if _hex_sha(binding[field], f"cross-boundary {field}") != BASE.sha256(actual):
            raise PackageError(f"cross-boundary receipt {label} hash differs from the supplied bytes")
    if (binding["nativeTestTarget"] != "dql_server_bootstrap_tests"
            or binding["nativeTestResult"] != "passed" or binding["caseNames"] != list(CASE_NAMES)):
        raise PackageError("cross-boundary receipt does not bind the passing native target and four actual cases")

    native_blobs = binding["nativeSourceBlobs"]
    expected_native = _expected_blob_map(git, commit, NATIVE_SOURCE_PATHS)
    if not isinstance(native_blobs, dict) or set(native_blobs) != set(NATIVE_SOURCE_PATHS):
        raise PackageError("native source binding paths differ from the fixed 15 build-input list")
    for path, expected in expected_native.items():
        if native_blobs[path] != expected:
            raise PackageError(f"native source binding differs from the selected Git blob: {path}")

    server_paths = set(SERVER_SOURCE_PATHS)
    server_blobs = binding["serverSourceBlobs"]
    expected_server = _expected_blob_map(git, commit, server_paths)
    if not isinstance(server_blobs, dict) or set(server_blobs) != server_paths:
        raise PackageError("server source binding paths differ from the generator and exact fixture source set")
    for path, expected in expected_server.items():
        if server_blobs[path] != expected:
            raise PackageError(f"server source binding differs from the selected Git blob: {path}")

    test_source = BASE.sha256(BASE.git_blob(git, commit, NATIVE_TEST_SOURCE))
    if _hex_sha(binding["nativeTestSourceSha256"], "native test source SHA-256") != test_source:
        raise PackageError("cross-boundary receipt does not bind the native PHP fixture test source")
    return binding


def _collect_inputs(args, git: str) -> dict:
    commit = BASE.canonical_source_commit(git, args.source_commit)
    _, baseline_files = _read_baseline(args.prealpha15_zip)
    dll = _regular_file(args.dll, "final release DLL")
    fixture_bytes = _regular_file(args.php_fixtures, "final PHP bootstrap fixtures")
    php_receipt_bytes = _regular_file(args.php_generator_receipt, "final PHP generator receipt")
    native_receipt_bytes = _regular_file(args.native_build_receipt, "native build receipt")
    binding_bytes = _regular_file(args.native_bootstrap_binding, "native bootstrap binding receipt")
    dll_receipt_bytes = _regular_file(args.dll_bootstrap_receipt, "DLL bootstrap results receipt")

    fixture = _validate_fixture(fixture_bytes)
    php_receipt = _validate_php_generator_receipt(
        php_receipt_bytes, fixture_bytes, fixture, git, commit,
    )
    native_receipt = _validate_native_build_receipt(native_receipt_bytes, fixture_bytes, dll)
    dll_receipt = _validate_dll_bootstrap_receipt(dll_receipt_bytes, dll, fixture_bytes)
    binding = _validate_cross_boundary_binding(
        binding_bytes, git, commit, dll, fixture_bytes, php_receipt_bytes,
        native_receipt_bytes, dll_receipt_bytes,
    )

    allowlist = BASE.source_allowlist(git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(git, commit, allowed, relative)

    app_js = read_source("ui/app.js")
    if app_js == baseline_files[APP_JS_MEMBER]:
        raise PackageError("prealpha.16 UI app.js must differ from the pinned prealpha.15 baseline")
    for path in SERVER_PACKAGE_MEMBERS:
        if path not in baseline_files:
            raise PackageError(f"prealpha.15 baseline is missing a server package member: {path}")

    evidence = {
        BOOTSTRAP_RECEIPT_NAMES["phpFixtures"]: fixture_bytes,
        BOOTSTRAP_RECEIPT_NAMES["phpGenerator"]: php_receipt_bytes,
        BOOTSTRAP_RECEIPT_NAMES["nativeBuild"]: native_receipt_bytes,
        BOOTSTRAP_RECEIPT_NAMES["nativeBinding"]: binding_bytes,
        BOOTSTRAP_RECEIPT_NAMES["dllBootstrap"]: dll_receipt_bytes,
    }
    return {
        "commit": commit,
        "baselineFiles": baseline_files,
        "dll": dll,
        "appJs": app_js,
        "allowlist": allowlist,
        "readSource": read_source,
        "fixture": fixture,
        "phpReceipt": php_receipt,
        "nativeReceipt": native_receipt,
        "dllReceipt": dll_receipt,
        "binding": binding,
        "evidence": evidence,
    }


def _assemble_mod(baseline_files: dict[str, bytes], dll: bytes, app_js: bytes) -> dict[str, bytes]:
    if set(baseline_files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.15 baseline member list differs")
    files = dict(baseline_files)
    files[DLL_MEMBER] = dll
    files[APP_JS_MEMBER] = app_js
    changed = {path for path in files if files[path] != baseline_files[path]}
    if changed != {DLL_MEMBER, APP_JS_MEMBER}:
        raise PackageError("prealpha.16 must change only the DLL and app.js inside the MO2 archive")
    if any(files[path] != baseline_files[path] for path in SERVER_PACKAGE_MEMBERS):
        raise PackageError("prealpha.16 must preserve all three prealpha.15 server DWPkg files byte-for-byte")
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = PREVIOUS._read_installable(data)
    if set(files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.16 MO2 member list differs from the 22-member prealpha.15 baseline")
    return files


def _proof_zip(commit: str, mod_zip: bytes, source_zip: bytes, evidence: dict[str, bytes],
               binding: dict, php_receipt: dict, native_receipt: dict, dll_receipt: dict) -> bytes:
    mod_members = _read_installable(mod_zip)
    provenance = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": PREVIOUS.VERSION, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "serverPackages": {
            "version": PREVIOUS.VERSION,
            "members": sorted(SERVER_PACKAGE_MEMBERS),
            "sha256": {path: BASE.sha256(mod_members[path]) for path in sorted(SERVER_PACKAGE_MEMBERS)},
            "changed": False,
        },
        "installableChanges": {
            DLL_MEMBER: BASE.sha256(mod_members[DLL_MEMBER]),
            APP_JS_MEMBER: BASE.sha256(mod_members[APP_JS_MEMBER]),
        },
        "bootstrap": {
            "fixtureCases": list(CASE_NAMES),
            "nativeTestTarget": binding["nativeTestTarget"],
            "nativeCTestCount": native_receipt["hostTests"]["count"],
            "dllCasesPassed": list(dll_receipt["caseNames"]),
            "dllNegativeCases": dll_receipt["negativeCases"],
        },
        "evidence": {name: BASE.sha256(data) for name, data in sorted(evidence.items())},
        "limits": DLL_LIMITS,
    }
    archive_files = dict(evidence)
    archive_files["package-provenance.json"] = BASE.json_bytes(provenance)
    return BASE.stable_zip_bytes(archive_files)


def _write_outputs(directory: Path, files: dict[str, bytes]) -> None:
    if set(files) != OUTPUT_NAMES:
        raise PackageError("release output set is incomplete")
    for name, data in files.items():
        BASE.validate_member_name(name)
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(files))


def _expected_output_files(path: Path) -> dict[str, bytes]:
    if path.is_symlink() or not path.is_dir():
        raise PackageError("release output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(path.iterdir())
    if ({item.name for item in entries} != expected
            or any(item.is_symlink() or not item.is_file() for item in entries)):
        raise PackageError("release output must contain only the three ZIPs and checksums")
    return {item.name: item.read_bytes() for item in entries}


def audit_output(args) -> None:
    inputs = _collect_inputs(args, args.git)
    outputs = _expected_output_files(args.output_dir.resolve())
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    expected_mod = _assemble_mod(inputs["baselineFiles"], inputs["dll"], inputs["appJs"])
    BASE.require_canonical_bytes("prealpha.16 MO2 ZIP", outputs[OUTPUT_MOD_ZIP], BASE.stable_zip_bytes(expected_mod))

    expected_source = BASE.build_source_zip(args.git, inputs["commit"], inputs["allowlist"])
    BASE.require_canonical_bytes("prealpha.16 source ZIP", outputs[OUTPUT_SOURCE_ZIP], expected_source)
    expected_proof = _proof_zip(
        inputs["commit"], outputs[OUTPUT_MOD_ZIP], expected_source, inputs["evidence"],
        inputs["binding"], inputs["phpReceipt"], inputs["nativeReceipt"], inputs["dllReceipt"],
    )
    BASE.require_canonical_bytes("prealpha.16 bootstrap proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    inputs = _collect_inputs(args, args.git)
    output = PREVIOUS.PREVIOUS._safe_output_dir(args.output_dir)
    mod_files = _assemble_mod(inputs["baselineFiles"], inputs["dll"], inputs["appJs"])
    mod_zip = BASE.stable_zip_bytes(mod_files)
    source_zip = BASE.build_source_zip(args.git, inputs["commit"], inputs["allowlist"])
    proof_zip = _proof_zip(
        inputs["commit"], mod_zip, source_zip, inputs["evidence"], inputs["binding"],
        inputs["phpReceipt"], inputs["nativeReceipt"], inputs["dllReceipt"],
    )
    files = {
        OUTPUT_MOD_ZIP: mod_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PROOF_ZIP: proof_zip,
    }
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, files)
        audit_output(argparse.Namespace(
            git=args.git,
            source_commit=inputs["commit"],
            prealpha15_zip=args.prealpha15_zip,
            dll=args.dll,
            php_fixtures=args.php_fixtures,
            php_generator_receipt=args.php_generator_receipt,
            native_build_receipt=args.native_build_receipt,
            native_bootstrap_binding=args.native_bootstrap_binding,
            dll_bootstrap_receipt=args.dll_bootstrap_receipt,
            output_dir=stage,
        ))
        os.rename(stage, output)
    except Exception:
        if stage.exists():
            shutil.rmtree(stage)
        raise


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    for command in ("build", "audit"):
        sub = subparsers.add_parser(command)
        sub.add_argument("--prealpha15-zip", required=True, type=Path)
        sub.add_argument("--dll", required=True, type=Path)
        sub.add_argument("--php-fixtures", required=True, type=Path)
        sub.add_argument("--php-generator-receipt", required=True, type=Path)
        sub.add_argument("--native-build-receipt", required=True, type=Path)
        sub.add_argument("--native-bootstrap-binding", required=True, type=Path)
        sub.add_argument("--dll-bootstrap-receipt", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
        sub.add_argument("--git", default="git")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
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
