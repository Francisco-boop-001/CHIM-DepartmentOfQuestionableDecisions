#!/usr/bin/env python3
"""Build and audit prealpha.15 from the exact prealpha.14 installable."""

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

VERSION = "0.1.0-prealpha.15"
OWNER_VERSION = "0.1.0-dev.24"
OWNER_COMMIT = "80821db59d3e1c299bee56b4496cf20a144984fd"
ROOT = Path(__file__).resolve().parents[1]
BASELINE_SHA256 = "1a68afa602760860e9a7b9be5c1b234264b9668f340367b5f0f1f7f90be529a6"
INPUT_COMPATIBILITY = "compatibility-checks.json"
DEPARTMENT_ADAPTER = "server/adapters/correspondence.php"
DEPARTMENT_PROTOCOL = "server/lib/protocol.php"
ZIP_TIMESTAMP = (2026, 10, 25, 0, 0, 0)

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-compatibility-proof.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
CHECK_NAMES = {
    "dev24SourceContract",
    "dev23Regression",
    "pairingReadiness",
    "protectedPayload",
}
PROOF_LIMITS = {"sourceOnly": True, "database": False, "http": False, "skyrim": False}
UPDATED_DPKG_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    "checksums.sha256",
}

_PREVIOUS_PATH = Path(__file__).with_name("build-installable-prealpha14.py")
_PREVIOUS_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha14", _PREVIOUS_PATH)
if _PREVIOUS_SPEC is None or _PREVIOUS_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.14 packaging helper")
PREVIOUS = importlib.util.module_from_spec(_PREVIOUS_SPEC)
_PREVIOUS_SPEC.loader.exec_module(PREVIOUS)
LEGACY = PREVIOUS.PREVIOUS
BASE = PREVIOUS.BASE
PackageError = BASE.PackageError
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_MO2_MEMBERS = set(PREVIOUS.BASELINE_MO2_MEMBERS)
BASELINE_DPKG_MEMBER = next(
    path for path in BASELINE_MO2_MEMBERS
    if path.startswith("CHIM/server-plugins/questionable_decisions/") and path.endswith(".dwpkg")
)
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
SERVER_PACKAGE_MEMBERS = {
    path for path in BASELINE_MO2_MEMBERS if path.startswith("CHIM/server-plugins/") and path.endswith(".dwpkg")
}
SERVER_SOURCE_PATHS = tuple(LEGACY.QD_SERVER_SOURCES)
STAGE_ROOT = "server/setup/pcv-overlay/"

if len(BASELINE_MO2_MEMBERS) != 22 or len(SERVER_PACKAGE_MEMBERS) != 3:
    raise RuntimeError("prealpha.14 packaging contract does not contain the expected 22/3 members")

REQUIRED_SOURCE_PATHS = (
    set(LEGACY.REQUIRED_SOURCE_PATHS)
    | set(PREVIOUS.REQUIRED_SOURCE_PATHS)
    | {
        "docs/DEV24-COMPATIBILITY.md",
        "docs/PAIRING-CHECK.md",
        "docs/VERIFICATION.md",
        "docs/superpowers/plans/2026-10-25-sealed-fates-dev24.md",
        "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
        "packaging/build-installable-prealpha15.py",
        "packaging/public-source-allowlist.json",
        "packaging/tests/test_installable_prealpha15.py",
        "server/tests/correspondence/dev23-source-contract.php",
        DEPARTMENT_ADAPTER,
        DEPARTMENT_PROTOCOL,
        "server/manifest.json",
        *SERVER_SOURCE_PATHS,
    }
)


def _json_object(data: bytes, label: str) -> dict:
    return LEGACY._loads_object(data, label)


def _regular_file(path: Path, label: str) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise PackageError(f"{label} must be a regular file")
    data = path.read_bytes()
    if len(data) > 16 * 1024 * 1024:
        raise PackageError(f"{label} exceeds the 16 MiB input limit")
    return data


def _receipt(path: Path) -> bytes:
    if path.name != INPUT_COMPATIBILITY:
        raise PackageError(f"receipt input must be named {INPUT_COMPATIBILITY}")
    return _regular_file(path, INPUT_COMPATIBILITY)


def _hex_sha(value: object, label: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-fA-F]{64}", value) is None:
        raise PackageError(f"{label} must be a SHA-256 hex digest")
    return value.lower()


def _literal_owner_closure_pins(adapter_bytes: bytes) -> dict[str, str]:
    try:
        adapter = adapter_bytes.decode("utf-8")
    except UnicodeDecodeError as error:
        raise PackageError("committed correspondence adapter is not UTF-8") from error
    matches = list(re.finditer(
        r"(?m)^    '0\.1\.0-dev\.24' => \[\r?\n(?P<body>.*?)^    \],(?=\r?\n)",
        adapter,
        re.DOTALL,
    ))
    if len(matches) != 1:
        raise PackageError("committed correspondence adapter must contain one literal dev.24 pin map")

    pins = {}
    for line in matches[0].group("body").splitlines():
        entry = re.fullmatch(r"        '([^']+)' => '([0-9a-f]{64})',", line)
        if entry is None:
            raise PackageError("committed dev.24 pin map contains a non-literal entry")
        path, digest = entry.groups()
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError("committed dev.24 pin map contains an invalid path") from error
        if path in pins:
            raise PackageError("committed dev.24 pin map contains a duplicate path")
        pins[path] = digest
    if len(pins) != 19 or "manifest.json" not in pins:
        raise PackageError("committed dev.24 pin map does not contain the exact 19-file owner closure")
    return pins


def _validate_compatibility_receipt(git: str, commit: str, data: bytes) -> dict:
    receipt = _json_object(data, INPUT_COMPATIBILITY)
    expected_fields = {
        "schemaVersion",
        "sourceCommit",
        "ownerVersion",
        "ownerCommit",
        "ownerClosurePins",
        "adapterSha256",
        "protocolSha256",
        "checks",
        "limits",
    }
    if set(receipt) != expected_fields:
        raise PackageError("compatibility receipt fields differ from the fixed schema")
    if type(receipt["schemaVersion"]) is not int or receipt["schemaVersion"] != 1:
        raise PackageError("compatibility receipt must use schema version 1")
    if receipt["sourceCommit"] != commit:
        raise PackageError("compatibility receipt does not name the selected Department source commit")
    if receipt["ownerVersion"] != OWNER_VERSION or receipt["ownerCommit"] != OWNER_COMMIT:
        raise PackageError("compatibility receipt does not identify the pinned Sealed Fates dev.24 source")

    committed_adapter = BASE.git_blob(git, commit, DEPARTMENT_ADAPTER)
    expected_pins = _literal_owner_closure_pins(committed_adapter)
    pins = receipt["ownerClosurePins"]
    if not isinstance(pins, dict) or len(pins) != len(expected_pins):
        raise PackageError("compatibility receipt must bind the exact 19-file owner closure")
    receipt_pins = {}
    for path, digest in pins.items():
        if not isinstance(path, str):
            raise PackageError("owner closure pin path is malformed")
        BASE.validate_member_name(path)
        receipt_pins[path] = _hex_sha(digest, f"owner closure pin {path}")
    if receipt_pins != expected_pins:
        raise PackageError("owner closure pins differ from the selected dev.24 adapter map")

    for key, path in (
        ("adapterSha256", DEPARTMENT_ADAPTER),
        ("protocolSha256", DEPARTMENT_PROTOCOL),
    ):
        source_bytes = committed_adapter if path == DEPARTMENT_ADAPTER else BASE.git_blob(git, commit, path)
        expected = BASE.sha256(source_bytes)
        if _hex_sha(receipt[key], key) != expected:
            raise PackageError(f"compatibility receipt {key} differs from the selected source commit")

    checks = receipt["checks"]
    if not isinstance(checks, list):
        raise PackageError("compatibility receipt checks must be a list")
    actual_checks = {}
    for row in checks:
        if not isinstance(row, dict) or set(row) != {"name", "status", "receiptSha256"}:
            raise PackageError("compatibility receipt check rows are malformed")
        name = row["name"]
        if not isinstance(name, str) or name in actual_checks or name not in CHECK_NAMES:
            raise PackageError("compatibility receipt check names are invalid")
        if row["status"] != "passed":
            raise PackageError(f"compatibility check did not pass: {name}")
        _hex_sha(row["receiptSha256"], f"{name} receiptSha256")
        actual_checks[name] = row["status"]
    if set(actual_checks) != CHECK_NAMES:
        raise PackageError("compatibility receipt must contain all four focused checks")
    if receipt["limits"] != PROOF_LIMITS:
        raise PackageError("compatibility receipt claims evidence beyond source and pairing readiness")
    return receipt


def _read_baseline(path: Path) -> tuple[bytes, dict[str, bytes], dict[str, bytes]]:
    data = _regular_file(path, "prealpha.14 baseline ZIP")
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.14 archive")
    files = BASE.audit_mo2_bytes(data)
    if set(files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.14 baseline member list differs")
    department = LEGACY._verify_dpkg(files[BASELINE_DPKG_MEMBER], "0.1.0-prealpha.13")
    return data, files, department


def _compare_dpkg_update(previous: dict[str, bytes], current: dict[str, bytes]) -> None:
    if set(previous) != set(current):
        raise PackageError("prealpha.15 Department DWPkg payload paths differ from prealpha.14")
    old_outer = _json_object(previous["manifest.json"], "prealpha.14 DWPkg manifest")
    new_outer = _json_object(current["manifest.json"], "prealpha.15 DWPkg manifest")
    old_outer["version"] = VERSION
    if new_outer != old_outer:
        raise PackageError("prealpha.15 DWPkg outer manifest changed beyond its version")
    old_inner = _json_object(previous["server/manifest.json"], "prealpha.14 server manifest")
    new_inner = _json_object(current["server/manifest.json"], "prealpha.15 server manifest")
    old_inner["version"] = VERSION
    if new_inner != old_inner:
        raise PackageError("prealpha.15 server manifest changed beyond its version")
    changed = {path for path in previous if previous[path] != current[path]}
    if changed != UPDATED_DPKG_MEMBERS:
        raise PackageError("prealpha.15 DWPkg must change only its two manifests, correspondence adapter, and checksums")


def _build_department_package(
    old_package: dict[str, bytes],
    read_source,
) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.14 DWPkg manifest")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    for source_path in SERVER_SOURCE_PATHS:
        member = "server/" + source_path.removeprefix("server/")
        current[member] = read_source(source_path)
    stage_manifest, stage_files = BASE.validate_overlay_stage(read_source)
    current[STAGE_ROOT + "source-manifest.json"] = stage_manifest
    for name, data in stage_files.items():
        current[STAGE_ROOT + name] = data

    inner = _json_object(current["server/manifest.json"], "prealpha.15 server manifest")
    if inner.get("version") != VERSION:
        raise PackageError("committed Department server manifest does not name prealpha.15")
    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.14 server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("committed Department server manifest changed beyond its version")

    payload = {path: data for path, data in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = LEGACY._verify_dpkg(package, VERSION)
    _compare_dpkg_update(old_package, verified)
    return package, verified


def _assemble_mod(base_files: dict[str, bytes], department_package: bytes) -> dict[str, bytes]:
    if set(base_files) != BASELINE_MO2_MEMBERS or BASELINE_DPKG_MEMBER not in base_files:
        raise PackageError("prealpha.14 input member set differs")
    files = {path: data for path, data in base_files.items() if path != BASELINE_DPKG_MEMBER}
    files[DPKG_MEMBER] = department_package
    expected = (BASELINE_MO2_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected:
        raise PackageError("assembled prealpha.15 MO2 member set differs")
    for path in set(base_files) & set(files):
        if base_files[path] != files[path]:
            raise PackageError(f"prealpha.15 changed an unrelated MO2 member: {path}")
    if department_package == base_files[BASELINE_DPKG_MEMBER]:
        raise PackageError("prealpha.15 Department DWPkg did not change")
    return files


def _provenance_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    compatibility_bytes: bytes,
    receipt: dict,
) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": "0.1.0-prealpha.14", "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "compatibilityChecks": {
            "name": INPUT_COMPATIBILITY,
            "sha256": BASE.sha256(compatibility_bytes),
        },
        "owner": {
            "version": receipt["ownerVersion"],
            "commit": receipt["ownerCommit"],
            "closurePinCount": len(receipt["ownerClosurePins"]),
        },
        "scope": "source-pairing-readiness",
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        INPUT_COMPATIBILITY: compatibility_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


def _inputs(args, git: str):
    commit = BASE.canonical_source_commit(git, args.source_commit)
    _, baseline_files, old_department = _read_baseline(args.prealpha14_zip)
    compatibility_bytes = _receipt(args.compatibility_checks)
    receipt = _validate_compatibility_receipt(git, commit, compatibility_bytes)
    allowlist = BASE.source_allowlist(git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(git, commit, allowed, relative)

    return commit, baseline_files, old_department, compatibility_bytes, receipt, allowlist, read_source


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.15 MO2 archive")
    expected = (BASELINE_MO2_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected:
        raise PackageError("prealpha.15 MO2 member list differs from prealpha.14")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if any("private_conversation" in name.casefold() and name.endswith(".dwpkg") for name in files):
        raise PackageError("MO2 archive must not include a private_conversation DWPkg")
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != BASE.NOTICE_FILES:
        raise PackageError("MO2 archive does not include exactly the seven required notices")
    BASE.audit_ini(files[BASE.INI_MEMBER])
    return files


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
    commit, baseline_files, old_department, compatibility_bytes, receipt, allowlist, read_source = _inputs(args, args.git)
    outputs = _expected_output_files(args.output_dir.resolve())
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    for path, old in baseline_files.items():
        if path != BASELINE_DPKG_MEMBER and mod_files[path] != old:
            raise PackageError(f"prealpha.15 changed an unrelated MO2 member: {path}")
    department_package = mod_files[DPKG_MEMBER]
    canonical_department, _ = _build_department_package(old_department, read_source)
    BASE.require_canonical_bytes("prealpha.15 Department DWPkg", department_package, canonical_department)
    verified_department = LEGACY._verify_dpkg(department_package, VERSION)
    _compare_dpkg_update(old_department, verified_department)

    expected_source = BASE.build_source_zip(args.git, commit, allowlist)
    BASE.require_canonical_bytes("prealpha.15 source ZIP", outputs[OUTPUT_SOURCE_ZIP], expected_source)
    expected_proof = _provenance_zip(
        commit, outputs[OUTPUT_MOD_ZIP], expected_source, department_package, compatibility_bytes, receipt,
    )
    BASE.require_canonical_bytes("prealpha.15 compatibility proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    commit, baseline_files, old_department, compatibility_bytes, receipt, allowlist, read_source = _inputs(args, args.git)
    output = PREVIOUS._safe_output_dir(args.output_dir)
    department_package, _ = _build_department_package(old_department, read_source)
    mod_files = _assemble_mod(baseline_files, department_package)
    mod_zip = BASE.stable_zip_bytes(mod_files)
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _provenance_zip(commit, mod_zip, source_zip, department_package, compatibility_bytes, receipt)
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
            source_commit=commit,
            prealpha14_zip=args.prealpha14_zip,
            compatibility_checks=args.compatibility_checks,
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
        sub.add_argument("--prealpha14-zip", required=True, type=Path)
        sub.add_argument("--compatibility-checks", required=True, type=Path)
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
