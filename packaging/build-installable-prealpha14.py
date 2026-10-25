#!/usr/bin/env python3
"""Build and audit the minimal prealpha.14 package and startup provenance."""

from __future__ import annotations

import argparse
import hashlib
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

VERSION = "0.1.0-prealpha.14"
ROOT = Path(__file__).resolve().parents[1]
BASELINE_SHA256 = "039f0b40afa9173b82a21ea7d26cbe727d153a3d830c964aba0e35769feb5d7d"
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"
APP_JS_MEMBER = "PrismaUI/views/WorldOfDramaLlama/app.js"
INDEX_HTML_MEMBER = "PrismaUI/views/WorldOfDramaLlama/index.html"
SOURCE_APP_JS = "ui/app.js"
SOURCE_INDEX_HTML = "ui/index.html"
UPDATED_MEMBERS = {DLL_MEMBER, APP_JS_MEMBER, INDEX_HTML_MEMBER}
NATIVE_BUILD_RECEIPT = "native-build-receipt.json"
NATIVE_SOURCE_BINDING = "native-source-binding.json"
STARTUP_CHECKS = "startup-checks.json"
ZIP_TIMESTAMP = (2026, 10, 25, 0, 0, 0)

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROVENANCE_ZIP = f"WorldOfDramaLlama-{VERSION}-startup-provenance.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROVENANCE_ZIP}
NATIVE_SOURCE_PATHS = {
    "native/CMakeLists.txt",
    "native/build.ps1",
    "native/deps.lock.json",
    "native/deps/PrismaUI_API.h",
    "native/src/ConnectionProtocol.cpp",
    "native/src/ConnectionProtocol.h",
    "native/src/Contract.cpp",
    "native/src/Contract.h",
    "native/src/HttpTransport.cpp",
    "native/src/HttpTransport.h",
    "native/src/NativePolicy.cpp",
    "native/src/NativePolicy.h",
    "native/src/PcvCompanionPolicy.cpp",
    "native/src/PcvCompanionPolicy.h",
    "native/src/Plugin.cpp",
}
REQUIRED_SOURCE_PATHS = {
    "README.md",
    "docs/VERIFICATION.md",
    "docs/superpowers/plans/2026-10-25-startup-handshake.md",
    "native/CMakeLists.txt",
    "native/build.ps1",
    "native/deps.lock.json",
    "native/deps/PrismaUI_API.h",
    *NATIVE_SOURCE_PATHS,
    "native/tests/NativeBoundaryTests.cpp",
    "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
    "packaging/build-installable-prealpha13.py",
    "packaging/build-installable-prealpha14.py",
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha14.py",
    SOURCE_APP_JS,
    SOURCE_INDEX_HTML,
    "ui/tests/startup-handshake.cjs",
    "ui/tests/ui-functional.cjs",
}
STARTUP_CHECK_STATUSES = {
    "native-build": "passed",
    "native-host-tests": "passed",
    "ui-startup-tests": "passed",
    "server-components": "unchanged",
    "skyrim-runtime": "not-performed",
}

_PREVIOUS_PATH = Path(__file__).with_name("build-installable-prealpha13.py")
_PREVIOUS_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha13", _PREVIOUS_PATH)
if _PREVIOUS_SPEC is None or _PREVIOUS_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.13 packaging helper")
PREVIOUS = importlib.util.module_from_spec(_PREVIOUS_SPEC)
_PREVIOUS_SPEC.loader.exec_module(PREVIOUS)
BASE = PREVIOUS.BASE_BUILDER
PackageError = BASE.PackageError
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_MO2_MEMBERS = set(PREVIOUS.MO2_FIXED_MEMBERS)
SERVER_PACKAGE_MEMBERS = {
    name for name in BASELINE_MO2_MEMBERS if name.startswith("CHIM/server-plugins/") and name.endswith(".dwpkg")
}
if len(BASELINE_MO2_MEMBERS) != 22 or len(SERVER_PACKAGE_MEMBERS) != 3:
    raise RuntimeError("prealpha.13 packaging contract does not contain the expected 22/3 members")


def _json_object(data: bytes, label: str) -> dict:
    return PREVIOUS._loads_object(data, label)


def _regular_file(path: Path, label: str) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise PackageError(f"{label} must be a regular file")
    data = path.read_bytes()
    if len(data) > 16 * 1024 * 1024:
        raise PackageError(f"{label} exceeds the 16 MiB input limit")
    return data


def _receipt(path: Path, expected_name: str) -> bytes:
    if path.name != expected_name:
        raise PackageError(f"receipt input must be named {expected_name}")
    return _regular_file(path, expected_name)


def _hex_sha(value: object, label: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-fA-F]{64}", value) is None:
        raise PackageError(f"{label} must be a SHA-256 hex digest")
    return value.lower()


def _source_commit(git: str, value: str) -> str:
    return BASE.canonical_source_commit(git, value)


def _expected_native_blobs(git: str, commit: str) -> dict[str, str]:
    return {
        path: BASE.git_output(git, ["rev-parse", "--verify", f"{commit}:{path}"]).decode("ascii").strip().lower()
        for path in sorted(NATIVE_SOURCE_PATHS)
    }


def _validate_receipts(
    git: str,
    commit: str,
    dll: bytes,
    build_bytes: bytes,
    binding_bytes: bytes,
    checks_bytes: bytes,
    server_files: dict[str, bytes],
) -> dict[str, dict]:
    build = _json_object(build_bytes, NATIVE_BUILD_RECEIPT)
    if build.get("schemaVersion") != 1 or build.get("status") != "native-plugin-and-host-tests-built":
        raise PackageError("native build receipt is not a successful schema-1 build")
    plugin = build.get("plugin")
    host_tests = build.get("hostTests")
    if (not isinstance(plugin, dict) or plugin.get("name") != "WorldOfDramaLlama.dll"
            or type(plugin.get("bytes")) is not int or plugin["bytes"] != len(dll)
            or _hex_sha(plugin.get("sha256"), "native build receipt plugin.sha256") != BASE.sha256(dll)
            or not isinstance(host_tests, dict) or host_tests.get("count") != 5
            or host_tests.get("result") != "100% tests passed, 0 tests failed out of 5"):
        raise PackageError("native build receipt does not identify this DLL and five passing host-test targets")

    binding = _json_object(binding_bytes, NATIVE_SOURCE_BINDING)
    if binding.get("schemaVersion") != 1 or binding.get("sourceCommit") != commit:
        raise PackageError("native source binding does not name the selected source commit")
    if _hex_sha(binding.get("dllSha256"), "native source binding dllSha256") != BASE.sha256(dll):
        raise PackageError("native source binding DLL hash differs")
    source_blobs = binding.get("sourceBlobs")
    expected_blobs = _expected_native_blobs(git, commit)
    if not isinstance(source_blobs, dict) or set(source_blobs) != set(NATIVE_SOURCE_PATHS):
        raise PackageError("native source binding paths differ from the fixed build-input list")
    for path, expected in expected_blobs.items():
        if source_blobs[path] != expected:
            raise PackageError(f"native source binding differs from current Git blob: {path}")

    checks = _json_object(checks_bytes, STARTUP_CHECKS)
    if checks.get("schemaVersion") != 1 or checks.get("sourceCommit") != commit:
        raise PackageError("startup checks receipt does not name the selected source commit")
    rows = checks.get("checks")
    if not isinstance(rows, list):
        raise PackageError("startup checks must be a list")
    actual = {}
    for row in rows:
        if not isinstance(row, dict) or set(row) != {"name", "status", "receiptSha256"}:
            raise PackageError("startup check rows must contain name, status and receiptSha256")
        name, status = row["name"], row["status"]
        if not isinstance(name, str) or name in actual or STARTUP_CHECK_STATUSES.get(name) != status:
            raise PackageError("startup check names or statuses do not match the required evidence boundary")
        receipt_hash = row["receiptSha256"]
        if name == "skyrim-runtime":
            if receipt_hash is not None:
                raise PackageError("unperformed Skyrim runtime must not claim a receipt hash")
        elif name in {"native-build", "native-host-tests", "server-components"}:
            if _hex_sha(receipt_hash, f"{name} receiptSha256") != _receipt_hash_for(
                name, build_bytes, server_files
            ):
                raise PackageError(f"{name} receipt hash does not match its bound evidence")
        else:
            _hex_sha(receipt_hash, f"{name} receiptSha256")
        actual[name] = status
    if actual != STARTUP_CHECK_STATUSES:
        raise PackageError("startup checks do not include every required evidence boundary")
    return {"native-build": build, "native-source-binding": binding, "startup-checks": checks}


def _server_bundle_hash(server_files: dict[str, bytes]) -> str:
    data = b"".join(path.encode("utf-8") + b"\x00" + server_files[path] for path in sorted(SERVER_PACKAGE_MEMBERS))
    return hashlib.sha256(data).hexdigest()


def _receipt_hash_for(name: str, build_bytes: bytes, server_files: dict[str, bytes]) -> str:
    if name in {"native-build", "native-host-tests"}:
        return BASE.sha256(build_bytes)
    if name == "server-components":
        return _server_bundle_hash(server_files)
    raise PackageError(f"unsupported bound startup check: {name}")


def _read_baseline(path: Path) -> tuple[bytes, dict[str, bytes]]:
    data = _regular_file(path, "prealpha.13 baseline ZIP")
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.13 archive")
    files = BASE.audit_mo2_bytes(data)
    if set(files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.13 baseline member list differs")
    return data, files


def _assembled_mod(base_files: dict[str, bytes], dll: bytes, app_js: bytes, index_html: bytes) -> dict[str, bytes]:
    files = dict(base_files)
    files[DLL_MEMBER] = dll
    files[APP_JS_MEMBER] = app_js
    files[INDEX_HTML_MEMBER] = index_html
    for path, old in base_files.items():
        if path not in UPDATED_MEMBERS and files[path] != old:
            raise PackageError(f"prealpha.14 changed an unrelated MO2 member: {path}")
    if any(files[path] == base_files[path] for path in UPDATED_MEMBERS):
        raise PackageError("prealpha.14 must replace its reviewed DLL and UI members")
    return files


def _provenance_zip(
    source_commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    dll: bytes,
    app_js: bytes,
    index_html: bytes,
    build_bytes: bytes,
    binding_bytes: bytes,
    checks_bytes: bytes,
    server_files: dict[str, bytes],
) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": source_commit,
        "baseline": {"version": "0.1.0-prealpha.13", "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "nativeDll": {"member": DLL_MEMBER, "sha256": BASE.sha256(dll)},
        "uiAppJs": {"member": APP_JS_MEMBER, "sha256": BASE.sha256(app_js)},
        "uiIndexHtml": {"member": INDEX_HTML_MEMBER, "sha256": BASE.sha256(index_html)},
        "receipts": {
            NATIVE_BUILD_RECEIPT: BASE.sha256(build_bytes),
            NATIVE_SOURCE_BINDING: BASE.sha256(binding_bytes),
            STARTUP_CHECKS: BASE.sha256(checks_bytes),
        },
        "serverPackages": {
            path: BASE.sha256(server_files[path]) for path in sorted(SERVER_PACKAGE_MEMBERS)
        },
    }
    return BASE.stable_zip_bytes({
        NATIVE_BUILD_RECEIPT: build_bytes,
        NATIVE_SOURCE_BINDING: binding_bytes,
        STARTUP_CHECKS: checks_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })



def _safe_output_dir(path: Path) -> Path:
    resolved = path.resolve()
    root = ROOT.resolve()
    if resolved == root or root in resolved.parents:
        raise PackageError("release output must be outside the source repository")
    if path.exists() or path.is_symlink():
        raise PackageError("release output directory must be new; refusing to overwrite it")
    if not resolved.parent.is_dir() or resolved.parent.is_symlink():
        raise PackageError("release output parent must be an existing regular directory")
    return resolved


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


def _inputs(args, git: str):
    commit = _source_commit(git, args.source_commit)
    baseline_data, baseline_files = _read_baseline(args.prealpha13_zip)
    dll = _regular_file(args.dll, "reviewed DLL")
    build_bytes = _receipt(args.native_build_receipt, NATIVE_BUILD_RECEIPT)
    binding_bytes = _receipt(args.native_source_binding, NATIVE_SOURCE_BINDING)
    checks_bytes = _receipt(args.startup_checks, STARTUP_CHECKS)
    _validate_receipts(git, commit, dll, build_bytes, binding_bytes, checks_bytes,
                       {path: baseline_files[path] for path in SERVER_PACKAGE_MEMBERS})
    allowlist = BASE.source_allowlist(git, commit)
    allowed = set(allowlist)
    missing = sorted(REQUIRED_SOURCE_PATHS - allowed)
    if missing:
        raise PackageError(f"public source allowlist is missing required paths: {', '.join(missing)}")
    app_js = BASE.git_blob(git, commit, SOURCE_APP_JS)
    index_html = BASE.git_blob(git, commit, SOURCE_INDEX_HTML)
    return commit, baseline_data, baseline_files, dll, build_bytes, binding_bytes, checks_bytes, allowlist, app_js, index_html


def audit_output(args) -> None:
    commit, baseline_data, baseline_files, dll, build_bytes, binding_bytes, checks_bytes, allowlist, app_js, index_html = _inputs(args, args.git)
    outputs = _expected_output_files(args.output_dir.resolve())
    mod_files = BASE.audit_mo2_bytes(outputs[OUTPUT_MOD_ZIP])
    if set(mod_files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.14 MO2 member set differs from prealpha.13")
    for path, old in baseline_files.items():
        if path not in UPDATED_MEMBERS and mod_files[path] != old:
            raise PackageError(f"prealpha.14 changed an unrelated MO2 member: {path}")
    if (mod_files[DLL_MEMBER] != dll or mod_files[APP_JS_MEMBER] != app_js
            or mod_files[INDEX_HTML_MEMBER] != index_html):
        raise PackageError("prealpha.14 DLL or UI bytes differ from reviewed/source inputs")
    if any(mod_files[path] != baseline_files[path] for path in SERVER_PACKAGE_MEMBERS):
        raise PackageError("a bundled server package changed from prealpha.13")

    expected_source = BASE.build_source_zip(args.git, commit, allowlist)
    BASE.require_canonical_bytes("prealpha.14 MO2 ZIP", outputs[OUTPUT_MOD_ZIP], BASE.stable_zip_bytes(mod_files))
    BASE.require_canonical_bytes("prealpha.14 source ZIP", outputs[OUTPUT_SOURCE_ZIP], expected_source)
    server_files = {path: baseline_files[path] for path in SERVER_PACKAGE_MEMBERS}
    expected_provenance = _provenance_zip(
        commit, outputs[OUTPUT_MOD_ZIP], expected_source, dll, app_js, index_html,
        build_bytes, binding_bytes, checks_bytes, server_files,
    )
    BASE.require_canonical_bytes("prealpha.14 startup provenance ZIP", outputs[OUTPUT_PROVENANCE_ZIP], expected_provenance)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {
        name: outputs[name] for name in OUTPUT_NAMES
    })


def build(args) -> None:
    commit, baseline_data, baseline_files, dll, build_bytes, binding_bytes, checks_bytes, allowlist, app_js, index_html = _inputs(args, args.git)
    output = _safe_output_dir(args.output_dir)
    _assembled_mod(baseline_files, dll, app_js, index_html)
    mod_zip = BASE.stable_zip_bytes(_assembled_mod(baseline_files, dll, app_js, index_html))
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    server_files = {path: baseline_files[path] for path in SERVER_PACKAGE_MEMBERS}
    provenance_zip = _provenance_zip(
        commit, mod_zip, source_zip, dll, app_js, index_html,
        build_bytes, binding_bytes, checks_bytes, server_files,
    )
    files = {
        OUTPUT_MOD_ZIP: mod_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PROVENANCE_ZIP: provenance_zip,
    }
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, files)
        audit_output(argparse.Namespace(
            git=args.git,
            source_commit=commit,
            prealpha13_zip=args.prealpha13_zip,
            dll=args.dll,
            native_build_receipt=args.native_build_receipt,
            native_source_binding=args.native_source_binding,
            startup_checks=args.startup_checks,
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
        sub.add_argument("--prealpha13-zip", required=True, type=Path)
        sub.add_argument("--dll", required=True, type=Path)
        sub.add_argument("--native-build-receipt", required=True, type=Path)
        sub.add_argument("--native-source-binding", required=True, type=Path)
        sub.add_argument("--startup-checks", required=True, type=Path)
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
