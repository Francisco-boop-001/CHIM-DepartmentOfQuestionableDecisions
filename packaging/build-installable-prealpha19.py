#!/usr/bin/env python3
"""Build and audit prealpha.19 from the exact published prealpha.18 installable."""

from __future__ import annotations

import argparse
import importlib.util
import json
import re
import subprocess
import sys
import zipfile
from pathlib import Path


_P18_PATH = Path(__file__).with_name("build-installable-prealpha18.py")
_P18_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha18", _P18_PATH)
if _P18_SPEC is None or _P18_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.18 packaging helper")
P18 = importlib.util.module_from_spec(_P18_SPEC)
_P18_SPEC.loader.exec_module(P18)

P15 = P18.P15
BASE = P18.BASE
PackageError = P18.PackageError
ROOT = P18.ROOT

VERSION = "0.1.0-prealpha.19"
OWNER_VERSION = "0.1.0-dev.26"
OWNER_COMMIT = "7fd78802348d5ee7dabee9506ff6ede1c25c0657"
BASELINE_VERSION = "0.1.0-prealpha.18"
BASELINE_SHA256 = "24c558a938db919fc39ac5b16ca0a15e46738b0d9d82e4332c6711f3c6c0bd02"
BASELINE_ZIP_TIMESTAMP = P18.ZIP_TIMESTAMP
DLL_SHA256 = P18.DLL_SHA256
ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 4)

BASELINE_DPKG_MEMBER = P18.DEPARTMENT_MEMBER
BASELINE_PCV_MEMBER = P18.PCV_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
DLL_MEMBER = P18.DLL_MEMBER
DEPARTMENT_ADAPTER = P15.DEPARTMENT_ADAPTER
DEPARTMENT_PROTOCOL = P15.DEPARTMENT_PROTOCOL
INPUT_COMPATIBILITY = "compatibility-checks.json"
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-compatibility-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
CHECK_NAMES = {
    "dev26SourceContract",
    "dev25Regression",
    "pairingReadiness",
    "protectedPayload",
}
PROOF_LIMITS = dict(P15.PROOF_LIMITS)
DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/adapters/correspondence.php",
    "checksums.sha256",
}
DEPARTMENT_EXPECTED_MEMBERS = set(P18.DEPARTMENT_EXPECTED_MEMBERS)
EXPECTED_BASELINE_MEMBERS = (
    set(P18.EXPECTED_BASELINE_MEMBERS)
    - {P18.BASELINE_DEPARTMENT_MEMBER, P18.BASELINE_PCV_MEMBER}
    | {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
)
BASELINE_MO2_MEMBERS = set(EXPECTED_BASELINE_MEMBERS)
SERVER_SOURCE_PATHS = tuple(P15.SERVER_SOURCE_PATHS)
SERVER_PACKAGE_MEMBERS = {
    path for path in EXPECTED_BASELINE_MEMBERS
    if path.startswith("CHIM/server-plugins/") and path.endswith(".dwpkg")
}
STAGE_ROOT = P15.STAGE_ROOT

if (len(EXPECTED_BASELINE_MEMBERS) != 22
        or len(SERVER_PACKAGE_MEMBERS) != 3
        or len(SERVER_SOURCE_PATHS) != 19
        or BASELINE_DPKG_MEMBER not in EXPECTED_BASELINE_MEMBERS):
    raise RuntimeError("prealpha.18 packaging contract differs from the expected 22/3/19 member set")

REQUIRED_SOURCE_PATHS = (
    set(P18.REQUIRED_SOURCE_PATHS)
    | {
        "docs/DEV26-COMPATIBILITY.md",
        "docs/superpowers/plans/2026-10-26-dev26-pairing.md",
        "packaging/build-installable-prealpha19.py",
        "packaging/public-source-allowlist.json",
        "packaging/tests/test_installable_prealpha19.py",
        "server/tests/correspondence/dev26-source-contract.php",
    }
)

_HEX_SHA256 = re.compile(r"\A[a-f0-9]{64}\Z")


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


def _literal_owner_closure_pins(adapter_bytes: bytes) -> dict[str, str]:
    try:
        adapter = adapter_bytes.decode("utf-8")
    except UnicodeDecodeError as error:
        raise PackageError("committed correspondence adapter is not UTF-8") from error
    matches = list(re.finditer(
        rf"(?m)^    '{re.escape(OWNER_VERSION)}' => \[\r?\n(?P<body>.*?)^    \],(?=\r?\n)",
        adapter,
        re.DOTALL,
    ))
    if len(matches) != 1:
        raise PackageError("committed correspondence adapter must contain one literal dev.26 pin map")

    pins: dict[str, str] = {}
    for line in matches[0].group("body").splitlines():
        entry = re.fullmatch(r"        '([^']+)' => '([0-9a-f]{64})',", line)
        if entry is None:
            raise PackageError("committed dev.26 pin map contains a non-literal entry")
        path, digest = entry.groups()
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError("committed dev.26 pin map contains an invalid path") from error
        if path in pins:
            raise PackageError("committed dev.26 pin map contains a duplicate path")
        pins[path] = digest
    if len(pins) != 19 or "manifest.json" not in pins:
        raise PackageError("committed dev.26 pin map must contain its manifest and 18 execution files")
    return pins


def _validate_compatibility_receipt(git: str, commit: str, data: bytes) -> dict:
    receipt = _json_object(data, "compatibility receipt")
    if data != BASE.json_bytes(receipt):
        raise PackageError("compatibility receipt must use canonical UTF-8 JSON with LF and no BOM")
    expected_fields = {
        "schemaVersion", "sourceCommit", "ownerVersion", "ownerCommit",
        "ownerClosurePins", "adapterSha256", "protocolSha256", "checks", "limits",
    }
    if set(receipt) != expected_fields:
        raise PackageError("compatibility receipt fields differ from the fixed schema")
    if type(receipt["schemaVersion"]) is not int or receipt["schemaVersion"] != 1:
        raise PackageError("compatibility receipt must use schema version 1")
    if receipt["sourceCommit"] != commit:
        raise PackageError("compatibility receipt does not name the selected Department source commit")
    if receipt["ownerVersion"] != OWNER_VERSION or receipt["ownerCommit"] != OWNER_COMMIT:
        raise PackageError("compatibility receipt does not identify the pinned Sealed Fates dev.26 source")

    committed_adapter = BASE.git_blob(git, commit, DEPARTMENT_ADAPTER)
    expected_pins = _literal_owner_closure_pins(committed_adapter)
    pins = receipt["ownerClosurePins"]
    if not isinstance(pins, dict) or set(pins) != set(expected_pins):
        raise PackageError("compatibility receipt must bind the exact 19-file dev.26 owner closure")
    for path, digest in pins.items():
        if not isinstance(path, str):
            raise PackageError("owner closure pin path is malformed")
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError("owner closure pin path is invalid") from error
        if not isinstance(digest, str) or _HEX_SHA256.fullmatch(digest) is None:
            raise PackageError(f"owner closure pin is malformed: {path}")
    if pins != expected_pins:
        raise PackageError("owner closure pins differ from the selected dev.26 adapter map")

    protocol = BASE.git_blob(git, commit, DEPARTMENT_PROTOCOL)
    for key, source in (("adapterSha256", committed_adapter), ("protocolSha256", protocol)):
        digest = receipt[key]
        if not isinstance(digest, str) or _HEX_SHA256.fullmatch(digest) is None:
            raise PackageError(f"compatibility receipt {key} is malformed")
        if digest != BASE.sha256(source):
            raise PackageError(f"compatibility receipt {key} differs from the selected source commit")

    checks = receipt["checks"]
    if not isinstance(checks, list):
        raise PackageError("compatibility receipt checks must be a list")
    actual_checks = set()
    for row in checks:
        if not isinstance(row, dict) or set(row) != {"name", "status", "receiptSha256"}:
            raise PackageError("compatibility receipt check rows are malformed")
        name = row["name"]
        if not isinstance(name, str) or name in actual_checks or name not in CHECK_NAMES:
            raise PackageError("compatibility receipt check names are invalid")
        if row["status"] != "passed":
            raise PackageError(f"compatibility check did not pass: {name}")
        digest = row["receiptSha256"]
        if not isinstance(digest, str) or _HEX_SHA256.fullmatch(digest) is None:
            raise PackageError(f"{name} receiptSha256 is malformed")
        actual_checks.add(name)
    if actual_checks != CHECK_NAMES:
        raise PackageError("compatibility receipt must contain all four focused checks")
    limits = receipt["limits"]
    if (not isinstance(limits, dict)
            or set(limits) != set(PROOF_LIMITS)
            or any(type(value) is not bool for value in limits.values())
            or limits != PROOF_LIMITS):
        raise PackageError("compatibility receipt claims evidence beyond source and pairing readiness")
    return receipt


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.18 archive")
    files = BASE.read_zip_bytes(data, "prealpha.18 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.18 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.18 baseline DLL differs from the accepted native payload")
    department = P18._audit_component(
        files[BASELINE_DPKG_MEMBER], "questionable_decisions", BASELINE_VERSION
    )
    if len(department) != 29:
        raise PackageError("prealpha.18 baseline Department DWPkg member count differs")
    return files, department


def _read_baseline(path: Path) -> tuple[bytes, dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.18 baseline ZIP")
    files, department = _read_baseline_bytes(data)
    return data, files, department


def _assemble_mod(base_files: dict[str, bytes], department_package: bytes) -> dict[str, bytes]:
    if set(base_files) != EXPECTED_BASELINE_MEMBERS:
        raise PackageError("prealpha.18 baseline member list differs")
    if department_package == base_files[BASELINE_DPKG_MEMBER]:
        raise PackageError("prealpha.19 Department DWPkg did not change")
    files = {
        path: data for path, data in base_files.items()
        if path != BASELINE_DPKG_MEMBER
    }
    files[DPKG_MEMBER] = department_package
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected:
        raise PackageError("assembled prealpha.19 MO2 member set differs from prealpha.18")
    if any(base_files[path] != files[path] for path in EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}):
        raise PackageError("prealpha.19 changed a protected prealpha.18 MO2 payload")
    return files


def _compare_dpkg_update(previous: dict[str, bytes], current: dict[str, bytes]) -> None:
    P15._compare_dpkg_update(previous, current)


_BUILD_DEPARTMENT_PACKAGE = P15._build_department_package


def _build_department_package(old_package: dict[str, bytes], read_source):
    package, _ = _BUILD_DEPARTMENT_PACKAGE(old_package, read_source)
    verified = P18._audit_component(package, "questionable_decisions", VERSION)
    _compare_dpkg_update(old_package, verified)
    return package, verified


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.19 MO2 archive")
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("prealpha.19 MO2 member list differs from prealpha.18")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.19 DLL does not match the accepted prealpha.18 bytes")
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != BASE.NOTICE_FILES:
        raise PackageError("MO2 archive does not include exactly the seven required notices")
    BASE.audit_ini(files["SKSE/Plugins/WorldOfDramaLlama.ini"])
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.19 MO2 archive is not the canonical timestamped ZIP")
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
        "baseline": {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256},
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
        "unchangedMo2Members": 21,
        "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
        "scope": "source-pairing-readiness",
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        INPUT_COMPATIBILITY: compatibility_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


# Reuse the existing canonical source builder and audited Department package assembly.
P15.VERSION = VERSION
P15.OWNER_VERSION = OWNER_VERSION
P15.OWNER_COMMIT = OWNER_COMMIT
P15.BASELINE_SHA256 = BASELINE_SHA256
P15.BASELINE_DPKG_MEMBER = BASELINE_DPKG_MEMBER
P15.DPKG_MEMBER = DPKG_MEMBER
P15.INPUT_COMPATIBILITY = INPUT_COMPATIBILITY
P15.ZIP_TIMESTAMP = ZIP_TIMESTAMP
P15.CHECK_NAMES = CHECK_NAMES
P15.PROOF_LIMITS = PROOF_LIMITS
P15.UPDATED_DPKG_MEMBERS = DEPARTMENT_UPDATED_MEMBERS
P15.BASELINE_MO2_MEMBERS = BASELINE_MO2_MEMBERS
P15.SERVER_PACKAGE_MEMBERS = SERVER_PACKAGE_MEMBERS
P15.REQUIRED_SOURCE_PATHS = REQUIRED_SOURCE_PATHS
P15.OUTPUT_MOD_ZIP = OUTPUT_MOD_ZIP
P15.OUTPUT_SOURCE_ZIP = OUTPUT_SOURCE_ZIP
P15.OUTPUT_PROOF_ZIP = OUTPUT_PROOF_ZIP
P15.OUTPUT_NAMES = OUTPUT_NAMES
P15._build_department_package = _build_department_package
P15._read_baseline = _read_baseline
P15._read_installable = _read_installable
P15._validate_compatibility_receipt = _validate_compatibility_receipt
P15._literal_owner_closure_pins = _literal_owner_closure_pins
P15._provenance_zip = _provenance_zip
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP


def _p15_args(args: argparse.Namespace) -> argparse.Namespace:
    return argparse.Namespace(
        git=args.git,
        source_commit=args.source_commit,
        prealpha14_zip=args.prealpha18_zip,
        compatibility_checks=args.compatibility_checks,
        output_dir=args.output_dir,
    )


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    for command in ("build", "audit"):
        sub = subparsers.add_parser(command)
        sub.add_argument("--prealpha18-zip", required=True, type=Path)
        sub.add_argument("--compatibility-checks", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
        sub.add_argument("--git", default="git")
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        if args.command == "build":
            P15.build(_p15_args(args))
            print(f"Built and audited: {args.output_dir.resolve()}")
        else:
            P15.audit_output(_p15_args(args))
            print(f"Audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, zipfile.BadZipFile, ValueError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
