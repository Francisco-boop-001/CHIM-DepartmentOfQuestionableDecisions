#!/usr/bin/env python3
"""Build and audit prealpha.17 from the exact published prealpha.16 installable."""

from __future__ import annotations

import argparse
import importlib.util
import json
import re
import subprocess
import sys
import zipfile
from pathlib import Path

_P15_PATH = Path(__file__).with_name("build-installable-prealpha15.py")
_P15_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha15", _P15_PATH)
if _P15_SPEC is None or _P15_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.15 packaging helper")
P15 = importlib.util.module_from_spec(_P15_SPEC)
_P15_SPEC.loader.exec_module(P15)

VERSION = "0.1.0-prealpha.17"
OWNER_VERSION = "0.1.0-dev.25"
OWNER_COMMIT = "a28cb8333582c59a92c25f67f72073899cd0e63c"
BASELINE_VERSION = "0.1.0-prealpha.16"
BASELINE_SHA256 = "5de96da71a044496d8d9e767165ef460c82725e708ba7920b40eebcededb30c1"
BASELINE_DPKG_VERSION = P15.VERSION
BASELINE_DPKG_MEMBER = P15.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 0)
CHECK_NAMES = {
    "dev25SourceContract",
    "dev24Regression",
    "pairingReadiness",
    "protectedPayload",
}
UPDATED_DPKG_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    P15.DEPARTMENT_ADAPTER,
    "checksums.sha256",
}

BASE = P15.BASE
PackageError = P15.PackageError
ROOT = P15.ROOT
INPUT_COMPATIBILITY = "compatibility-checks.json"
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-compatibility-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
PROOF_LIMITS = dict(P15.PROOF_LIMITS)
BASELINE_MO2_MEMBERS = (
    set(P15.BASELINE_MO2_MEMBERS) - {P15.BASELINE_DPKG_MEMBER}
) | {BASELINE_DPKG_MEMBER}
SERVER_PACKAGE_MEMBERS = set(P15.SERVER_PACKAGE_MEMBERS)
SERVER_PACKAGE_MEMBERS.discard(P15.BASELINE_DPKG_MEMBER)
SERVER_PACKAGE_MEMBERS.add(BASELINE_DPKG_MEMBER)
SERVER_SOURCE_PATHS = tuple(P15.SERVER_SOURCE_PATHS)
DEPARTMENT_ADAPTER = P15.DEPARTMENT_ADAPTER
DEPARTMENT_PROTOCOL = P15.DEPARTMENT_PROTOCOL
STAGE_ROOT = P15.STAGE_ROOT

if (len(BASELINE_MO2_MEMBERS) != 22 or len(SERVER_PACKAGE_MEMBERS) != 3
        or len(SERVER_SOURCE_PATHS) != 19
        or BASELINE_DPKG_MEMBER not in BASELINE_MO2_MEMBERS):
    raise RuntimeError("prealpha.16 packaging contract differs from the expected 22/3/19 member set")

REQUIRED_SOURCE_PATHS = (
    set(P15.REQUIRED_SOURCE_PATHS)
    | {
        "README.md",
        "docs/DEV25-COMPATIBILITY.md",
        "docs/VERIFICATION.md",
        "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
        "packaging/build-installable-prealpha17.py",
        "packaging/public-source-allowlist.json",
        "packaging/tests/test_installable_prealpha17.py",
        "server/manifest.json",
        *SERVER_SOURCE_PATHS,
    }
)

_DEV24_LITERAL_OWNER_CLOSURE_PINS = P15._literal_owner_closure_pins
_P15_VALIDATE_COMPATIBILITY_RECEIPT = P15._validate_compatibility_receipt

# Reuse the prealpha.15 implementation with the next release's immutable inputs.
P15.VERSION = VERSION
P15.OWNER_VERSION = OWNER_VERSION
P15.OWNER_COMMIT = OWNER_COMMIT
P15.BASELINE_SHA256 = BASELINE_SHA256
P15.BASELINE_DPKG_MEMBER = BASELINE_DPKG_MEMBER
P15.DPKG_MEMBER = DPKG_MEMBER
P15.INPUT_COMPATIBILITY = INPUT_COMPATIBILITY
P15.ZIP_TIMESTAMP = ZIP_TIMESTAMP
P15.CHECK_NAMES = CHECK_NAMES
P15.UPDATED_DPKG_MEMBERS = UPDATED_DPKG_MEMBERS
P15.BASELINE_MO2_MEMBERS = BASELINE_MO2_MEMBERS
P15.SERVER_PACKAGE_MEMBERS = SERVER_PACKAGE_MEMBERS
P15.REQUIRED_SOURCE_PATHS = REQUIRED_SOURCE_PATHS
P15.OUTPUT_MOD_ZIP = OUTPUT_MOD_ZIP
P15.OUTPUT_SOURCE_ZIP = OUTPUT_SOURCE_ZIP
P15.OUTPUT_PROOF_ZIP = OUTPUT_PROOF_ZIP
P15.OUTPUT_NAMES = OUTPUT_NAMES
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP


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
        raise PackageError("committed correspondence adapter must contain one literal dev.25 pin map")

    pins: dict[str, str] = {}
    for line in matches[0].group("body").splitlines():
        entry = re.fullmatch(r"        '([^']+)' => '([0-9a-f]{64})',", line)
        if entry is None:
            raise PackageError("committed dev.25 pin map contains a non-literal entry")
        path, digest = entry.groups()
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError("committed dev.25 pin map contains an invalid path") from error
        if path in pins:
            raise PackageError("committed dev.25 pin map contains a duplicate path")
        pins[path] = digest
    if len(pins) != 19 or "manifest.json" not in pins:
        raise PackageError("committed dev.25 pin map must contain its manifest and 18 execution files")

    dev24_pins = _DEV24_LITERAL_OWNER_CLOSURE_PINS(adapter_bytes)
    dev25_execution = {path: digest for path, digest in pins.items() if path != "manifest.json"}
    dev24_execution = {path: digest for path, digest in dev24_pins.items() if path != "manifest.json"}
    if dev25_execution != dev24_execution:
        raise PackageError("dev.25 execution-file pins differ from the exact dev.24 closure")
    if pins["manifest.json"] == dev24_pins["manifest.json"]:
        raise PackageError("dev.25 manifest pin did not advance from dev.24")
    return pins


def _validate_compatibility_receipt(git: str, commit: str, data: bytes) -> dict:
    try:
        return _P15_VALIDATE_COMPATIBILITY_RECEIPT(git, commit, data)
    except PackageError as error:
        message = str(error)
        message = message.replace(
            "compatibility receipt does not identify the pinned Sealed Fates dev.24 source",
            "compatibility receipt does not identify the pinned Sealed Fates dev.25 source",
        )
        message = message.replace(
            "owner closure pins differ from the selected dev.24 adapter map",
            "owner closure pins differ from the selected dev.25 adapter map",
        )
        raise PackageError(message) from error


def _read_baseline(path: Path) -> tuple[bytes, dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.16 baseline ZIP")
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.16 archive")
    files = BASE.read_zip_bytes(data, "prealpha.16 baseline ZIP")
    if set(files) != BASELINE_MO2_MEMBERS:
        raise PackageError("prealpha.16 baseline member list differs")
    department = P15.LEGACY._verify_dpkg(files[BASELINE_DPKG_MEMBER], BASELINE_DPKG_VERSION)
    return data, files, department


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.17 MO2 archive")
    expected = (BASELINE_MO2_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected:
        raise PackageError("prealpha.17 MO2 member list differs from prealpha.16")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if any("private_conversation" in name.casefold() and name.endswith(".dwpkg") for name in files):
        raise PackageError("MO2 archive must not include a private_conversation DWPkg")
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != BASE.NOTICE_FILES:
        raise PackageError("MO2 archive does not include exactly the seven required notices")
    BASE.audit_ini(files[BASE.INI_MEMBER])
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
        "scope": "source-pairing-readiness",
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        INPUT_COMPATIBILITY: compatibility_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


P15._literal_owner_closure_pins = _literal_owner_closure_pins
P15._validate_compatibility_receipt = _validate_compatibility_receipt
P15._read_baseline = _read_baseline
P15._read_installable = _read_installable
P15._provenance_zip = _provenance_zip


def _p15_args(args: argparse.Namespace) -> argparse.Namespace:
    return argparse.Namespace(
        git=args.git,
        source_commit=args.source_commit,
        prealpha14_zip=args.prealpha16_zip,
        compatibility_checks=args.compatibility_checks,
        output_dir=args.output_dir,
    )


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    for command in ("build", "audit"):
        sub = subparsers.add_parser(command)
        sub.add_argument("--prealpha16-zip", required=True, type=Path)
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
