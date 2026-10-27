#!/usr/bin/env python3
"""Build and audit prealpha.22 from the exact prealpha.21 installable."""

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


_P21_PATH = Path(__file__).with_name("build-installable-prealpha21.py")
_P21_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha21_for22", _P21_PATH)
if _P21_SPEC is None or _P21_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.21 packaging helper")
P21 = importlib.util.module_from_spec(_P21_SPEC)
_P21_SPEC.loader.exec_module(P21)

P20 = P21.P20
P15 = P21.P15
BASE = P21.BASE
PackageError = P21.PackageError
ROOT = P21.ROOT

VERSION = "0.1.0-prealpha.22"
OWNER_VERSION = "0.1.0-dev.27"
OWNER_COMMIT = "d1eaf714a7661a1f785a4d8c2131c3565a6eddee"
OWNER_MANIFEST_SHA256 = "157610b7d2d79cf68d6203c61fb7768399b3d5b6d4e0bdfc56651d2d0ab3b22d"
BASELINE_VERSION = "0.1.0-prealpha.21"
BASELINE_SHA256 = "53cc3fcf7d625a8e2694a5ab857c39b488854d6060bcf87bf346fdc67a2081d0"
BASELINE_ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 8)
ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 10)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = P21.DPKG_MEMBER
BASELINE_PCV_MEMBER = P21.PCV_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = BASELINE_PCV_MEMBER
DLL_MEMBER = P21.DLL_MEMBER
DLL_SHA256 = P21.DLL_SHA256
EXPECTED_BASELINE_MEMBERS = (
    set(P21.EXPECTED_BASELINE_MEMBERS)
    - {P21.BASELINE_DPKG_MEMBER}
    | {P21.DPKG_MEMBER}
)
DEPARTMENT_EXPECTED_MEMBERS = set(P21.DEPARTMENT_EXPECTED_MEMBERS)
PCV_EXPECTED_MEMBERS = set(P21.PCV_EXPECTED_MEMBERS)
SERVER_SOURCE_PATHS = tuple(P21.SERVER_SOURCE_PATHS)
DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/adapters/correspondence.php",
    "checksums.sha256",
}

DEPARTMENT_ADAPTER = "server/adapters/correspondence.php"
DEPARTMENT_PROTOCOL = "server/lib/protocol.php"
INPUT_COMPATIBILITY = "compatibility-checks.json"
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-compatibility-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
CHECK_NAMES = {
    "dev27SourceContract",
    "dev26Regression",
    "pairingReadiness",
    "protectedPayload",
}
PROOF_LIMITS = {"sourceOnly": True, "database": False, "http": False, "skyrim": False}
REQUIRED_SOURCE_PATHS = set(P21.REQUIRED_SOURCE_PATHS) | {
    "README.md",
    "docs/BUILD.md",
    "docs/DEV27-COMPATIBILITY.md",
    "docs/VERIFICATION.md",
    "docs/superpowers/plans/2026-10-26-dev27-pairing.md",
    "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
    "packaging/build-installable-prealpha22.py",
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha22.py",
    "server/tests/correspondence/dev23-source-contract.php",
    "server/tests/correspondence/dev27-source-contract.php",
}
_HEX_SHA256 = re.compile(r"\A[a-f0-9]{64}\Z")


def _json_object(data: bytes, label: str) -> dict:
    return P21._json_object(data, label)


def _require_sha256(value: object, label: str) -> str:
    digest = P21._require_sha256(value, label)
    if _HEX_SHA256.fullmatch(digest) is None:
        raise PackageError(f"{label} must be a lowercase SHA-256 digest")
    return digest


def _literal_owner_closure_pins(adapter_bytes: bytes, version: str) -> dict[str, str]:
    try:
        adapter = adapter_bytes.decode("utf-8")
    except UnicodeDecodeError as error:
        raise PackageError("committed correspondence adapter is not UTF-8") from error
    matches = list(re.finditer(
        rf"(?m)^    '{re.escape(version)}' => \[\r?\n(?P<body>.*?)^    \],(?=\r?\n)",
        adapter,
        re.DOTALL,
    ))
    if len(matches) != 1:
        raise PackageError(f"committed correspondence adapter must contain one literal {version} pin map")

    pins: dict[str, str] = {}
    for line in matches[0].group("body").splitlines():
        entry = re.fullmatch(r"        '([^']+)' => '([0-9a-f]{64})',", line)
        if entry is None:
            raise PackageError(f"committed {version} pin map contains a non-literal entry")
        path, digest = entry.groups()
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError(f"committed {version} pin map contains an invalid path") from error
        if path in pins:
            raise PackageError(f"committed {version} pin map contains a duplicate path")
        pins[path] = digest
    if len(pins) != 19 or "manifest.json" not in pins:
        raise PackageError(f"committed {version} pin map must contain the exact 19-file owner closure")
    return pins


def _validate_pin_transition(old_adapter: bytes, new_adapter: bytes) -> dict[str, str]:
    old_pins = _literal_owner_closure_pins(old_adapter, "0.1.0-dev.26")
    new_pins = _literal_owner_closure_pins(new_adapter, OWNER_VERSION)
    if set(old_pins) != set(new_pins):
        raise PackageError("dev.27 adapter pin paths differ from the retained dev.26 closure")
    changed = {path for path in new_pins if old_pins[path] != new_pins[path]}
    if changed != {"manifest.json"}:
        raise PackageError("dev.27 adapter pin delta must change only the owner manifest")
    if new_pins["manifest.json"] != OWNER_MANIFEST_SHA256:
        raise PackageError("dev.27 adapter does not pin the accepted owner manifest")
    return new_pins


def _validate_compatibility_receipt(git: str, commit: str, data: bytes) -> dict:
    receipt = _json_object(data, INPUT_COMPATIBILITY)
    if data != BASE.json_bytes(receipt):
        raise PackageError("compatibility receipt must use canonical UTF-8 JSON with LF and no BOM")
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
        raise PackageError("compatibility receipt does not identify the pinned Sealed Fates dev.27 source")

    committed_adapter = BASE.git_blob(git, commit, DEPARTMENT_ADAPTER)
    expected_pins = _literal_owner_closure_pins(committed_adapter, OWNER_VERSION)
    if expected_pins["manifest.json"] != OWNER_MANIFEST_SHA256:
        raise PackageError("selected source commit does not pin the accepted dev.27 owner manifest")
    pins = receipt["ownerClosurePins"]
    if not isinstance(pins, dict) or set(pins) != set(expected_pins):
        raise PackageError("compatibility receipt must bind the exact 19-file dev.27 owner closure")
    for path, digest in pins.items():
        if not isinstance(path, str):
            raise PackageError("owner closure pin path is malformed")
        try:
            BASE.validate_member_name(path)
        except PackageError as error:
            raise PackageError("owner closure pin path is invalid") from error
        _require_sha256(digest, f"owner closure pin {path}")
    if pins != expected_pins:
        raise PackageError("owner closure pins differ from the selected dev.27 adapter map")

    committed_protocol = BASE.git_blob(git, commit, DEPARTMENT_PROTOCOL)
    for key, source in (("adapterSha256", committed_adapter), ("protocolSha256", committed_protocol)):
        if _require_sha256(receipt[key], key) != BASE.sha256(source):
            raise PackageError(f"compatibility receipt {key} differs from the selected source commit")

    checks = receipt["checks"]
    if not isinstance(checks, list):
        raise PackageError("compatibility receipt checks must be a list")
    actual_checks: set[str] = set()
    for row in checks:
        if not isinstance(row, dict) or set(row) != {"name", "status", "receiptSha256"}:
            raise PackageError("compatibility receipt check rows are malformed")
        name = row["name"]
        if not isinstance(name, str) or name in actual_checks or name not in CHECK_NAMES:
            raise PackageError("compatibility receipt check names are invalid")
        if row["status"] != "passed":
            raise PackageError(f"compatibility check did not pass: {name}")
        _require_sha256(row["receiptSha256"], f"{name} receiptSha256")
        actual_checks.add(name)
    if actual_checks != CHECK_NAMES:
        raise PackageError("compatibility receipt must contain all four focused checks")

    limits = receipt["limits"]
    if (
        not isinstance(limits, dict)
        or set(limits) != set(PROOF_LIMITS)
        or any(type(limits[key]) is not bool for key in PROOF_LIMITS)
        or limits != PROOF_LIMITS
    ):
        raise PackageError("compatibility receipt claims evidence beyond source and pairing readiness")
    return receipt


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.21 archive")
    files = BASE.read_zip_bytes(data, "prealpha.21 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.21 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.21 baseline DLL differs from the accepted native payload")
    department = P21._audit_department_component(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION)
    pcv = P20._audit_component(files[BASELINE_PCV_MEMBER], "pcv_companion", P21.BASELINE_PCV_VERSION)
    if len(department) != 30 or len(pcv) != 6:
        raise PackageError("prealpha.21 baseline component member counts differ")
    return files, department, pcv


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.21 baseline ZIP")
    return _read_baseline_bytes(data)


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError) as error:
        raise PackageError(f"required prealpha.22 source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required prealpha.22 source is not bytes: {path}")
    return data


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.21 Department outer manifest")
    old_outer = dict(outer)
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    for source_path in SERVER_SOURCE_PATHS:
        member = "server/" + source_path.removeprefix("server/")
        current[member] = _required_source(read_source, source_path)
    _validate_pin_transition(
        old_package[DEPARTMENT_ADAPTER],
        current[DEPARTMENT_ADAPTER],
    )
    source_globals = _required_source(read_source, "server/globals.php")
    if source_globals != old_package["server/globals.php"]:
        raise PackageError("selected source changes the prealpha.21 Department globals bytes")
    if current["server/globals.php"] != old_package["server/globals.php"]:
        raise PackageError("prealpha.22 must preserve the prealpha.21 Department globals bytes")

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.21 Department server manifest")
    inner_bytes = current["server/manifest.json"]
    inner = _json_object(inner_bytes, "prealpha.22 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = P21._audit_department_component(package, VERSION)
    new_outer, _new_inner = P21.P18._component_manifest(verified, "questionable_decisions", VERSION)
    old_outer["version"] = VERSION
    if old_outer != new_outer:
        raise PackageError("Department outer manifest changed beyond its version")

    changed = {path for path in old_package if old_package[path] != verified[path]}
    if changed != DEPARTMENT_UPDATED_MEMBERS:
        raise PackageError("Department DWPkg changed outside the fixed prealpha.22 dev.27 pin delta")
    return package, verified


def _assemble_mod(base_files: dict[str, bytes], department_package: bytes) -> dict[str, bytes]:
    if set(base_files) != EXPECTED_BASELINE_MEMBERS or len(base_files) != 22:
        raise PackageError("prealpha.21 baseline member set differs")
    if department_package == base_files[BASELINE_DPKG_MEMBER]:
        raise PackageError("prealpha.22 must replace the Department DWPkg")
    files = {path: data for path, data in base_files.items() if path != BASELINE_DPKG_MEMBER}
    files[DPKG_MEMBER] = department_package
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("assembled prealpha.22 MO2 member set differs from prealpha.21")
    if any(base_files[path] != files[path] for path in set(base_files) & set(files)):
        raise PackageError("prealpha.22 changed an unrelated MO2 payload")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.22 changed the accepted native DLL bytes")
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.22 MO2 archive")
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("prealpha.22 MO2 member list differs from prealpha.21")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.22 DLL does not match the accepted prealpha.21 bytes")
    P21._audit_department_component(files[DPKG_MEMBER], VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", P21.BASELINE_PCV_VERSION)
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.22 MO2 archive is not the canonical timestamped ZIP")
    return files


def _proof_zip(
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
            "version": OWNER_VERSION,
            "commit": OWNER_COMMIT,
            "manifestSha256": OWNER_MANIFEST_SHA256,
            "closurePinCount": len(receipt["ownerClosurePins"]),
        },
        "compatibilityCheckReceipts": [
            {"name": row["name"], "sha256": row["receiptSha256"]}
            for row in receipt["checks"]
        ],
        "departmentDpkgDelta": sorted(DEPARTMENT_UPDATED_MEMBERS),
        "unchangedMo2Members": 21,
        "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
        "scope": "source-pairing-readiness",
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        INPUT_COMPATIBILITY: compatibility_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    baseline_files, old_department, old_pcv = _read_baseline(args.prealpha21_zip)
    compatibility_bytes = P15._regular_file(args.compatibility_checks, INPUT_COMPATIBILITY)
    if args.compatibility_checks.name != INPUT_COMPATIBILITY:
        raise PackageError(f"compatibility receipt input must be named {INPUT_COMPATIBILITY}")
    allowlist = BASE.source_allowlist(args.git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required candidate paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    receipt = _validate_compatibility_receipt(args.git, commit, compatibility_bytes)
    return commit, baseline_files, old_department, old_pcv, compatibility_bytes, receipt, allowlist, read_source


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.22 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.22 output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(directory.iterdir())
    if ({entry.name for entry in entries} != expected
            or any(entry.is_symlink() or not entry.is_file() for entry in entries)):
        raise PackageError("prealpha.22 output must contain only three ZIPs and SHA256SUMS.txt")
    return {entry.name: entry.read_bytes() for entry in entries}


def audit_output(args) -> None:
    commit, baseline_files, old_department, _old_pcv, compatibility_bytes, receipt, allowlist, read_source = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    department_package, verified_department = _build_department_package(old_department, read_source)
    if verified_department != P21._audit_department_component(mod_files[DPKG_MEMBER], VERSION):
        raise PackageError("prealpha.22 Department package differs from the canonical source assembly")
    if mod_files != _assemble_mod(baseline_files, department_package):
        raise PackageError("prealpha.22 MO2 archive differs from the canonical single-package assembly")
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    BASE.require_canonical_bytes("prealpha.22 source ZIP", outputs[OUTPUT_SOURCE_ZIP], source_zip)
    expected_proof = _proof_zip(
        commit,
        outputs[OUTPUT_MOD_ZIP],
        outputs[OUTPUT_SOURCE_ZIP],
        department_package,
        compatibility_bytes,
        receipt,
    )
    BASE.require_canonical_bytes("prealpha.22 compatibility proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    commit, baseline_files, old_department, _old_pcv, compatibility_bytes, receipt, allowlist, read_source = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, _verified_department = _build_department_package(old_department, read_source)
    mod_zip = BASE.stable_zip_bytes(_assemble_mod(baseline_files, department_package))
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _proof_zip(commit, mod_zip, source_zip, department_package, compatibility_bytes, receipt)
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
            prealpha21_zip=args.prealpha21_zip,
            compatibility_checks=args.compatibility_checks,
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
        sub.add_argument("--prealpha21-zip", required=True, type=Path)
        sub.add_argument("--compatibility-checks", required=True, type=Path)
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
