#!/usr/bin/env python3
"""Build and audit prealpha.21 from the exact published prealpha.20 installable."""

from __future__ import annotations

import argparse
import importlib.util
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path


_P20_PATH = Path(__file__).with_name("build-installable-prealpha20.py")
_P20_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha20_for21", _P20_PATH)
if _P20_SPEC is None or _P20_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.20 packaging helper")
P20 = importlib.util.module_from_spec(_P20_SPEC)
_P20_SPEC.loader.exec_module(P20)

P18 = P20.P18
P15 = P20.P15
BASE = P20.BASE
PackageError = P20.PackageError
ROOT = P20.ROOT

VERSION = "0.1.0-prealpha.21"
BASELINE_VERSION = "0.1.0-prealpha.20"
BASELINE_PCV_VERSION = "0.1.0-prealpha.11"
BASELINE_SHA256 = "2a60c5ac9d012c7ee30d6286b876ec3d117566ea1374ff2e9801bed34416b720"
BASELINE_ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 6)
ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 8)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = P20.DPKG_MEMBER
BASELINE_PCV_MEMBER = P20.PCV_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = BASELINE_PCV_MEMBER
DLL_MEMBER = P20.DLL_MEMBER
DLL_SHA256 = P20.DLL_SHA256
EXPECTED_BASELINE_MEMBERS = (
    set(P20.EXPECTED_BASELINE_MEMBERS)
    - {P20.BASELINE_DPKG_MEMBER, P20.BASELINE_PCV_MEMBER}
    | {P20.DPKG_MEMBER, P20.PCV_MEMBER}
)
BASELINE_DEPARTMENT_MEMBERS = set(P20.DEPARTMENT_EXPECTED_MEMBERS)
DEPARTMENT_EXPECTED_MEMBERS = BASELINE_DEPARTMENT_MEMBERS | {"server/globals.php"}
PCV_EXPECTED_MEMBERS = set(P20.PCV_EXPECTED_MEMBERS)
SERVER_SOURCE_PATHS = tuple(P20.SERVER_SOURCE_PATHS)
DEPARTMENT_REQUIRED_CHANGES = {
    "manifest.json",
    "server/manifest.json",
    "server/globals.php",
    "checksums.sha256",
}

INPUT_CHECKS = "sharmat-checks.json"
EVIDENCE_FILES = {
    "ingress": "ingress-verification.json",
    "compatibility": "compatibility.json",
    "focused": "focused-checks.json",
}
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-sharmat-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}

INGRESS_EVIDENCE_PATHS = {
    "server/globals.php",
    "server/lib/connect.php",
    "server/preprocessing.php",
}
COMPATIBILITY_EVIDENCE_PATHS = {
    "server/manifest.json",
    "server/adapters/brainwash.php",
    "server/adapters/correspondence.php",
    "server/adapters/mp.php",
    "server/adapters/pcv.php",
    "server/adapters/sas.php",
    "server/adapters/runtime_support.php",
    "server/lib/auth.php",
    "server/lib/protocol.php",
    "server/runtime.php",
}
FOCUSED_EVIDENCE_PATHS = {
    "server/globals.php",
    "server/tests/onboarding/stock-ingress.php",
}
EVIDENCE_REQUIRED_PATHS = {
    "ingress": INGRESS_EVIDENCE_PATHS,
    "compatibility": COMPATIBILITY_EVIDENCE_PATHS,
    "focused": FOCUSED_EVIDENCE_PATHS,
}
PROOF_LIMITS = {
    "isolatedTestOnly": True,
    "http": True,
    "nativeValidator": True,
    "skyrim": False,
    "provider": False,
    "nativeBuild": False,
    "live": False,
}
AGGREGATE_FIELDS = {
    "schemaVersion",
    "status",
    "sourceHashes",
    "checks",
    "limits",
    "fullHostMain",
}
CHILD_COMMON_FIELDS = {
    "schemaVersion",
    "status",
    "sourceHashes",
    "checks",
    "limits",
    "rawEvidence",
}
CHILD_EVIDENCE_FIELDS = {
    "ingress": CHILD_COMMON_FIELDS | {
        "nativeDllSha256",
        "http",
        "nativeValidator",
        "fullHostMain",
        "hostCommit",
        "sharmatCommit",
    },
    "compatibility": CHILD_COMMON_FIELDS | {"owners", "protectedSourceCount"},
    "focused": CHILD_COMMON_FIELDS,
}
COMPATIBLE_OWNERS = {
    "pcv": "0.1.24",
    "mp": "0.1.30",
    "sas": "0.1.12",
    "correspondence": "0.1.0-dev.26",
    "brainwash": "0.1.0-dev.3",
}
PROTECTED_SOURCE_COUNT = 75
HOST_COMMIT = "0ec590b42c8087860138bb4e9aab470049ff9a2b"
SHARMAT_COMMIT = "d6c6da595eb961f338245d6e4d044fe6e86279f0"
SAFE_CHECK_NAME = re.compile(r"[A-Za-z][A-Za-z0-9_]{0,63}\Z")
SAFE_EVIDENCE_NAME = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}\Z")

SOURCE_HASH_PATHS = tuple(sorted({
    "README.md",
    "docs/BUILD.md",
    "docs/VERIFICATION.md",
    "docs/superpowers/plans/2026-10-26-sharmat-connect21.md",
    "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
    "packaging/build-installable-prealpha21.py",
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha21.py",
    "server/globals.php",
    "server/tests/onboarding/stock-ingress.php",
    *SERVER_SOURCE_PATHS,
}))
REQUIRED_SOURCE_PATHS = set(SOURCE_HASH_PATHS)


def _json_object(data: bytes, label: str) -> dict:
    return P20._json_object(data, label)


def _require_sha256(value: object, label: str) -> str:
    return P20._require_sha256(value, label)


def _audit_department_component(data: bytes, expected_version: str) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "questionable_decisions DWPkg")
    if set(files) != DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("Department DWPkg member set differs from the prealpha.21 contract")
    checksums = BASE.parse_checksums(files["checksums.sha256"], "Department DWPkg")
    payload = {path: content for path, content in files.items() if path != "checksums.sha256"}
    if set(checksums) != set(payload) or any(
        checksums[path] != BASE.sha256(content) for path, content in payload.items()
    ):
        raise PackageError("Department DWPkg checksum closure is incomplete or mismatched")
    P18._component_manifest(files, "questionable_decisions", expected_version)
    return files


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.20 archive")
    files = BASE.read_zip_bytes(data, "prealpha.20 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.20 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.20 baseline DLL differs from the accepted native payload")
    department = P20._audit_component(
        files[BASELINE_DPKG_MEMBER], "questionable_decisions", BASELINE_VERSION
    )
    pcv = P20._audit_component(
        files[BASELINE_PCV_MEMBER], "pcv_companion", BASELINE_PCV_VERSION
    )
    if len(department) != 29 or len(pcv) != 6:
        raise PackageError("prealpha.20 baseline component member counts differ")
    return files, department, pcv


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.20 baseline ZIP")
    return _read_baseline_bytes(data)


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError) as error:
        raise PackageError(f"required prealpha.21 source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required prealpha.21 source is not bytes: {path}")
    return data


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.20 Department outer manifest")
    old_outer = dict(outer)
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    for source_path in SERVER_SOURCE_PATHS:
        current["server/" + source_path.removeprefix("server/")] = _required_source(read_source, source_path)
    current["server/globals.php"] = _required_source(read_source, "server/globals.php")

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.20 Department server manifest")
    inner_bytes = current["server/manifest.json"]
    inner = _json_object(inner_bytes, "prealpha.21 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")
    if not isinstance(inner_bytes, bytes):
        raise PackageError("Department server manifest source is not bytes")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = _audit_department_component(package, VERSION)
    new_outer, _new_inner = P18._component_manifest(verified, "questionable_decisions", VERSION)
    old_outer["version"] = VERSION
    if old_outer != new_outer:
        raise PackageError("Department outer manifest changed beyond its version")

    added = set(verified) - set(old_package)
    removed = set(old_package) - set(verified)
    modified = {path for path in set(old_package) & set(verified) if old_package[path] != verified[path]}
    if added | removed | modified != DEPARTMENT_REQUIRED_CHANGES:
        raise PackageError("Department DWPkg changed outside the fixed prealpha.21 globals delta")
    return package, verified


def _assemble_mod(
    base_files: dict[str, bytes],
    department_package: bytes,
) -> dict[str, bytes]:
    if set(base_files) != EXPECTED_BASELINE_MEMBERS or len(base_files) != 22:
        raise PackageError("prealpha.20 baseline member set differs")
    if department_package == base_files[BASELINE_DPKG_MEMBER]:
        raise PackageError("prealpha.21 must replace the Department DWPkg")
    files = {path: data for path, data in base_files.items() if path != BASELINE_DPKG_MEMBER}
    files[DPKG_MEMBER] = department_package
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("assembled prealpha.21 MO2 member set differs from prealpha.20")
    if any(base_files[path] != files[path] for path in set(base_files) & set(files)):
        raise PackageError("prealpha.21 changed an unrelated MO2 payload")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.21 changed the accepted native DLL bytes")
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.21 MO2 archive")
    expected = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("prealpha.21 MO2 member list differs from prealpha.20")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.21 DLL does not match the accepted prealpha.20 bytes")
    _audit_department_component(files[DPKG_MEMBER], VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", BASELINE_PCV_VERSION)
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.21 MO2 archive is not the canonical timestamped ZIP")
    return files


def _validate_sharmat_checks(
    data: bytes,
    evidence_files: dict[str, bytes],
    read_source,
) -> dict:
    receipt = _json_object(data, "SHARMAT checks receipt")
    if data != BASE.json_bytes(receipt):
        raise PackageError("SHARMAT checks receipt must use canonical UTF-8 JSON")
    if set(receipt) != AGGREGATE_FIELDS:
        raise PackageError("SHARMAT aggregate receipt fields differ from the fixed schema")
    if (
        type(receipt.get("schemaVersion")) is not int
        or receipt.get("schemaVersion") != 1
        or receipt.get("status") != "passed"
        or receipt.get("fullHostMain") is not True
    ):
        raise PackageError("SHARMAT checks receipt must be schema 1, passed, and fullHostMain true")
    source_hashes = receipt.get("sourceHashes")
    if not isinstance(source_hashes, dict) or set(source_hashes) != set(SOURCE_HASH_PATHS):
        raise PackageError("SHARMAT source hash paths differ from the fixed source closure")
    for path in SOURCE_HASH_PATHS:
        digest = _require_sha256(source_hashes[path], f"source hash {path}")
        if BASE.sha256(_required_source(read_source, path)) != digest:
            raise PackageError(f"SHARMAT source hash differs from the selected commit: {path}")

    if set(evidence_files) != set(EVIDENCE_FILES.values()):
        raise PackageError("SHARMAT evidence file set differs from the fixed three-file contract")
    checks = receipt.get("checks")
    if not isinstance(checks, dict) or set(checks) != set(EVIDENCE_FILES):
        raise PackageError("SHARMAT checks must contain exactly ingress, compatibility, and focused")

    for name, filename in EVIDENCE_FILES.items():
        row = checks[name]
        if not isinstance(row, dict) or set(row) != {"status", "evidenceFile", "evidenceSha256"}:
            raise PackageError(f"SHARMAT {name} check row is malformed")
        evidence = evidence_files[filename]
        if row.get("status") != "passed" or row.get("evidenceFile") != filename:
            raise PackageError(f"SHARMAT {name} evidence is not passed or uses the wrong filename")
        if _require_sha256(row.get("evidenceSha256"), f"{name} evidence hash") != BASE.sha256(evidence):
            raise PackageError(f"SHARMAT {name} evidence hash does not match its file")
        proof = _json_object(evidence, f"{name} evidence")
        if evidence != BASE.json_bytes(proof):
            raise PackageError(f"SHARMAT {name} evidence must use canonical UTF-8 JSON")
        if set(proof) != CHILD_EVIDENCE_FIELDS[name]:
            raise PackageError(f"SHARMAT {name} evidence fields differ from the fixed schema")
        if (
            type(proof.get("schemaVersion")) is not int
            or proof.get("schemaVersion") != 1
            or proof.get("status") != "passed"
        ):
            raise PackageError(f"SHARMAT {name} evidence is not schema 1 and passed")
        _validate_child_metadata(name, proof)
        if name == "ingress":
            if proof.get("http") is not True or proof.get("nativeValidator") is not True:
                raise PackageError("SHARMAT ingress evidence must prove isolated HTTP and native-validator checks")
            if proof.get("fullHostMain") is not True:
                raise PackageError("SHARMAT ingress evidence must prove fullHostMain true")
            if _require_sha256(proof.get("nativeDllSha256"), "ingress native DLL hash") != DLL_SHA256:
                raise PackageError("SHARMAT ingress evidence does not bind the accepted prealpha.20 DLL")
            if proof.get("hostCommit") != HOST_COMMIT or proof.get("sharmatCommit") != SHARMAT_COMMIT:
                raise PackageError("SHARMAT ingress evidence does not bind the fixed host and SHARMAT commits")
        if name == "compatibility":
            _validate_compatibility_owners(proof)
        proof_hashes = proof.get("sourceHashes")
        required = EVIDENCE_REQUIRED_PATHS[name]
        if not isinstance(proof_hashes, dict) or not required.issubset(proof_hashes):
            missing = sorted(required - set(proof_hashes or {})) if isinstance(proof_hashes, dict) else sorted(required)
            raise PackageError(f"SHARMAT {name} evidence is missing required source hashes: {', '.join(missing)}")
        for path, value in proof_hashes.items():
            if path not in SOURCE_HASH_PATHS:
                raise PackageError(f"SHARMAT {name} evidence names a source outside the fixed closure: {path}")
            digest = _require_sha256(value, f"{name} source hash {path}")
            if BASE.sha256(_required_source(read_source, path)) != digest:
                raise PackageError(f"SHARMAT {name} source hash differs from the selected commit: {path}")

    limits = receipt.get("limits")
    if (
        not isinstance(limits, dict)
        or set(limits) != set(PROOF_LIMITS)
        or any(type(limits[key]) is not bool or limits[key] is not value
               for key, value in PROOF_LIMITS.items())
    ):
        raise PackageError("SHARMAT checks claim behavior outside the fixed proof limits")
    return receipt


def _validate_child_metadata(name: str, proof: dict) -> None:
    checks = proof.get("checks")
    if (
        not isinstance(checks, dict)
        or not checks
        or any(
            not isinstance(check_name, str)
            or SAFE_CHECK_NAME.fullmatch(check_name) is None
            or type(passed) is not bool
            or passed is not True
            for check_name, passed in checks.items()
        )
    ):
        raise PackageError(f"SHARMAT {name} evidence checks must be safe named true booleans")

    limits = proof.get("limits")
    if (
        not isinstance(limits, dict)
        or not limits
        or any(
            not isinstance(limit_name, str)
            or SAFE_CHECK_NAME.fullmatch(limit_name) is None
            or type(enabled) is not bool
            for limit_name, enabled in limits.items()
        )
    ):
        raise PackageError(f"SHARMAT {name} evidence limits must be named booleans")

    raw_evidence = proof.get("rawEvidence")
    if not isinstance(raw_evidence, dict) or not raw_evidence:
        raise PackageError(f"SHARMAT {name} evidence rawEvidence must be a nonempty filename/hash map")
    for filename, value in raw_evidence.items():
        if (
            not isinstance(filename, str)
            or SAFE_EVIDENCE_NAME.fullmatch(filename) is None
            or ".." in filename
        ):
            raise PackageError(f"SHARMAT {name} rawEvidence names must be safe basenames")
        _require_sha256(value, f"{name} raw evidence hash {filename}")


def _validate_compatibility_owners(proof: dict) -> None:
    owners = proof.get("owners")
    if not isinstance(owners, dict) or set(owners) != set(COMPATIBLE_OWNERS):
        raise PackageError("SHARMAT compatibility owner set differs from the five supported plugins")
    for name, version in COMPATIBLE_OWNERS.items():
        owner = owners[name]
        if not isinstance(owner, dict) or set(owner) != {"version", "ready"}:
            raise PackageError(f"SHARMAT compatibility owner {name} record is malformed")
        if owner.get("version") != version:
            raise PackageError(f"SHARMAT compatibility owner {name} version differs")
        if owner.get("ready") is not True:
            raise PackageError(f"SHARMAT compatibility owner {name} is not ready")
    if type(proof.get("protectedSourceCount")) is not int or proof["protectedSourceCount"] != PROTECTED_SOURCE_COUNT:
        raise PackageError("SHARMAT compatibility protected source count differs from the fixed baseline")


def _proof_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    receipt_bytes: bytes,
    receipt: dict,
    evidence_files: dict[str, bytes],
) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "unchangedMo2Members": 21,
        "departmentPackageMembers": len(DEPARTMENT_EXPECTED_MEMBERS),
        "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
        "sharmatChecks": {"name": INPUT_CHECKS, "sha256": BASE.sha256(receipt_bytes)},
        "evidence": {
            name: {"name": filename, "sha256": BASE.sha256(evidence_files[filename])}
            for name, filename in EVIDENCE_FILES.items()
        },
        "scope": "reserved-physics-raw-dql-connect-v1-ingress-guard",
        "limits": PROOF_LIMITS,
        "fullHostMain": receipt.get("fullHostMain", False),
    }
    proof_files = {
        INPUT_CHECKS: receipt_bytes,
        **evidence_files,
        "package-provenance.json": BASE.json_bytes(provenance),
    }
    return BASE.stable_zip_bytes(proof_files)


def _read_check_inputs(directory: Path) -> tuple[bytes, dict[str, bytes]]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("SHARMAT checks directory is missing or unsafe")
    receipt = P15._regular_file(directory / INPUT_CHECKS, INPUT_CHECKS)
    evidence = {
        filename: P15._regular_file(directory / filename, filename)
        for filename in EVIDENCE_FILES.values()
    }
    return receipt, evidence


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    baseline_files, old_department, old_pcv = _read_baseline(args.prealpha20_zip)
    receipt_bytes, evidence_files = _read_check_inputs(args.checks_dir)
    allowlist = BASE.source_allowlist(args.git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required candidate paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    receipt = _validate_sharmat_checks(receipt_bytes, evidence_files, read_source)
    return (
        commit,
        baseline_files,
        old_department,
        old_pcv,
        receipt_bytes,
        receipt,
        evidence_files,
        allowlist,
        read_source,
    )


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.21 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.21 output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(directory.iterdir())
    if ({entry.name for entry in entries} != expected
            or any(entry.is_symlink() or not entry.is_file() for entry in entries)):
        raise PackageError("prealpha.21 output must contain only three ZIPs and SHA256SUMS.txt")
    return {entry.name: entry.read_bytes() for entry in entries}


def audit_output(args) -> None:
    (
        commit,
        baseline_files,
        old_department,
        _old_pcv,
        receipt_bytes,
        receipt,
        evidence_files,
        allowlist,
        read_source,
    ) = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    department_package, verified_department = _build_department_package(old_department, read_source)
    if verified_department != _audit_department_component(mod_files[DPKG_MEMBER], VERSION):
        raise PackageError("prealpha.21 Department package differs from the canonical source assembly")
    if mod_files != _assemble_mod(baseline_files, department_package):
        raise PackageError("prealpha.21 MO2 archive differs from the canonical single-package assembly")
    BASE.require_canonical_bytes(
        "prealpha.21 source ZIP",
        outputs[OUTPUT_SOURCE_ZIP],
        BASE.build_source_zip(args.git, commit, allowlist),
    )
    expected_proof = _proof_zip(
        commit,
        outputs[OUTPUT_MOD_ZIP],
        outputs[OUTPUT_SOURCE_ZIP],
        department_package,
        receipt_bytes,
        receipt,
        evidence_files,
    )
    BASE.require_canonical_bytes("prealpha.21 SHARMAT proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    (
        commit,
        baseline_files,
        old_department,
        _old_pcv,
        receipt_bytes,
        receipt,
        evidence_files,
        allowlist,
        read_source,
    ) = _inputs(args)
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
    proof_zip = _proof_zip(
        commit,
        mod_zip,
        source_zip,
        department_package,
        receipt_bytes,
        receipt,
        evidence_files,
    )
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
            prealpha20_zip=args.prealpha20_zip,
            checks_dir=args.checks_dir,
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
        sub.add_argument("--prealpha20-zip", required=True, type=Path)
        sub.add_argument("--checks-dir", required=True, type=Path)
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
