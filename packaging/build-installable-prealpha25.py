#!/usr/bin/env python3
"""Build and audit the offline prealpha.25 Department-only candidate."""

from __future__ import annotations

import argparse
import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path


_P24_PATH = Path(__file__).with_name("build-installable-prealpha24.py")
_P24_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha24_for25", _P24_PATH)
if _P24_SPEC is None or _P24_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.24 packaging helper")
P24 = importlib.util.module_from_spec(_P24_SPEC)
_P24_SPEC.loader.exec_module(P24)

P23 = P24.P23
P22 = P23.P22
P20 = P24.P20
P15 = P24.P15
BASE = P24.BASE
PackageError = P24.PackageError
ROOT = P24.ROOT

VERSION = "0.1.0-prealpha.25"
BASELINE_VERSION = P24.VERSION
BASELINE_SHA256 = "3d4a8cfbf8c27b7b655865c5191c8a5d45e6d3fb4f9c999184098786b9c5e237"
BASELINE_SOURCE_COMMIT = "5b0fa12db93000a866493548b913cf6d6e16a7fd"
BASELINE_ZIP_TIMESTAMP = P24.ZIP_TIMESTAMP
ZIP_TIMESTAMP = (2026, 10, 28, 0, 0, 0)
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

BASELINE_DPKG_MEMBER = P24.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = P24.PCV_MEMBER
MMP_MEMBER = P23.MMP_MEMBER
DLL_MEMBER = P23.DLL_MEMBER
DLL_SHA256 = "5b55122b8916629c1130260a24781b709e0192a34049211a67f91e337177df6a"
MMP_SHA256 = P23.MMP_PACKAGE_SHA256
EXPECTED_BASELINE_MEMBERS = set(P24.EXPECTED_INSTALLABLE_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
DEPARTMENT_EXPECTED_MEMBERS = set(P24.DEPARTMENT_EXPECTED_MEMBERS)
DEPARTMENT_ADAPTER = "server/adapters/correspondence.php"
DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    "checksums.sha256",
}

SF_OWNER_VERSION = "0.1.0-dev.29"
SF_OWNER_COMMIT = "dd128bb7568e0095423c49e02c0e70a395208a18"
BASELINE_OWNER_VERSION = "0.1.0-dev.28"
OWNER_MANIFEST_SHA256 = "40a837fad35a830b2f39cb210ddc04900b10f2c7d02b3134dcb806cba7cfcb26"
NEW_OWNER_MIGRATION = "migrations/006_history_indexes.sql"
OWNER_CHANGED_CLOSURE_PINS = {
    "manifest.json": OWNER_MANIFEST_SHA256,
    "lib/runtime.php": "346418d189df83b2c26f504873a0fc6a842fcea7f67b044ca942e429d4a22d93",
    "lib/store.php": "5f846b73c330183980c9e8c1655cf32b57dbe0f2added7d69fc35cd8dac08f9c",
    "lib/autonomy_host.php": "b0ce613ebc7d02d6b3dc539b003022981a07e808208070554a68a105f42fcf12",
    NEW_OWNER_MIGRATION: "9691b8ae97f60981e4baca883aecd8d2d615ddbb77c0b90ea1f6efd6c1f58a66",
}
DEV28_OWNER_PATHS = frozenset({
    "manifest.json",
    "lib/runtime.php",
    "lib/store.php",
    "lib/boundaries.php",
    "lib/bootstrap.php",
    "lib/autonomy_policy.php",
    "lib/autonomy_store.php",
    "lib/autonomy_host.php",
    "lib/offscreen_policy.php",
    "lib/offscreen.php",
    "lib/offscreen_host.php",
    "lib/companion.php",
    "lib/companion_policy.php",
    "lib/companion_host.php",
    "migrations/001_initial.sql",
    "migrations/002_autonomy.sql",
    "migrations/003_offscreen.sql",
    "migrations/004_restore_state.sql",
    "migrations/005_companion.sql",
})
CHANGED_OWNER_PATHS = frozenset({
    "manifest.json",
    "lib/runtime.php",
    "lib/store.php",
    "lib/autonomy_host.php",
    NEW_OWNER_MIGRATION,
})
SERVER_SOURCE_PATHS = tuple(P24.SERVER_SOURCE_PATHS)
EXPECTED_SERVER_SOURCE_CHANGES = frozenset({
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    "server/tests/correspondence/dev23-source-contract.php",
    "server/tests/correspondence/dev29-source-contract.php",
    "server/tests/correspondence/dev29-transaction-memo.php",
})

INPUT_COMPATIBILITY = "offline-compatibility.json"
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-offline-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
OFFLINE_CHECK_NAMES = {
    "sourceContract29",
    "newInterfaceService",
    "memoLifetime",
    "retained28",
    "retained27",
    "protectedComponents",
}
PROOF_LIMITS = {
    "server": False,
    "database": False,
    "http": False,
    "provider": False,
    "skyrim": False,
    "installedGame": False,
    "live": False,
}
SOURCE_HASH_PATHS = {
    "server/manifest.json",
    DEPARTMENT_ADAPTER,
    "server/tests/correspondence/dev23-source-contract.php",
    "server/tests/correspondence/dev29-source-contract.php",
    "server/tests/correspondence/dev29-transaction-memo.php",
}
REQUIRED_SOURCE_PATHS = {
    "README.md",
    "docs/BUILD.md",
    "docs/DEV29-COMPATIBILITY.md",
    "packaging/public-source-allowlist.json",
    "packaging/build-installable-prealpha25.py",
    "packaging/tests/test_installable_prealpha25.py",
    "server/manifest.json",
    *SOURCE_HASH_PATHS,
}
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME}


def _json_object(data: bytes, label: str) -> dict:
    return P24._json_object(data, label)


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError) as error:
        raise PackageError(f"required prealpha.25 source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required prealpha.25 source is not bytes: {path}")
    return data


def _sha256(value: object, label: str) -> str:
    if not isinstance(value, str) or len(value) != 64 or any(char not in "0123456789abcdef" for char in value):
        raise PackageError(f"{label} must be a lowercase SHA-256 digest")
    return value


def _validate_source_allowlist(allowlist: list[str]) -> set[str]:
    allowed = set(allowlist)
    if len(allowed) != len(allowlist):
        raise PackageError("public source allowlist contains duplicate paths")
    missing = sorted(REQUIRED_SOURCE_PATHS - allowed)
    if missing:
        raise PackageError(f"public source allowlist is missing required candidate paths: {', '.join(missing)}")
    missing_hashes = sorted(SOURCE_HASH_PATHS - allowed)
    if missing_hashes:
        raise PackageError(f"public source allowlist omits offline-bound source files: {', '.join(missing_hashes)}")
    return allowed


def _literal_owner_closure_pins(adapter_bytes: bytes, version: str) -> dict[str, str]:
    import re

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
    if len(pins) != 20 or "manifest.json" not in pins:
        raise PackageError(f"committed {version} pin map must contain the exact 20-file owner closure")
    return pins


def _validate_dev29_adapter(old_adapter: bytes, new_adapter: bytes) -> dict[str, str]:
    old_pins = P22._literal_owner_closure_pins(old_adapter, BASELINE_OWNER_VERSION)
    new_pins = _literal_owner_closure_pins(new_adapter, SF_OWNER_VERSION)
    if set(old_pins) != DEV28_OWNER_PATHS:
        raise PackageError("prealpha.24 adapter does not contain the exact retained dev.28 closure")
    if set(new_pins) != set(old_pins) | {NEW_OWNER_MIGRATION}:
        raise PackageError("dev.29 adapter pin paths differ from the retained dev.28 closure plus migration 006")
    changed = {path for path in set(old_pins) | set(new_pins) if old_pins.get(path) != new_pins.get(path)}
    if changed != CHANGED_OWNER_PATHS:
        raise PackageError("dev.29 adapter pin delta differs from the five fixed owner changes")
    if any(new_pins[path] != digest for path, digest in OWNER_CHANGED_CLOSURE_PINS.items()):
        raise PackageError("dev.29 adapter does not pin the exact frozen changed owner hashes")
    return new_pins


def _validate_selected_source_delta(git: str, commit: str) -> None:
    raw = BASE.git_output(git, [
        "diff", "--no-renames", "--name-only", "-z", BASELINE_SOURCE_COMMIT, commit,
    ])
    changed = {path.decode("utf-8") for path in raw.split(b"\0") if path}
    if any(path.startswith(("native/", "ui/")) for path in changed):
        raise PackageError("native/UI source changed from immutable prealpha.24 source")
    server_changes = {path for path in changed if path.startswith("server/")}
    if server_changes != EXPECTED_SERVER_SOURCE_CHANGES:
        missing = sorted(EXPECTED_SERVER_SOURCE_CHANGES - server_changes)
        unexpected = sorted(server_changes - EXPECTED_SERVER_SOURCE_CHANGES)
        raise PackageError(
            f"Department server source delta differs from the fixed adapter-and-test set; missing={missing}, unexpected={unexpected}"
        )


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.24 archive")
    files = BASE.read_zip_bytes(data, "prealpha.24 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.24 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.24 baseline DLL differs from the pinned published bytes")
    if BASE.sha256(files[MMP_MEMBER]) != MMP_SHA256:
        raise PackageError("prealpha.24 baseline MMP dev.4 package differs from the pinned published bytes")
    department = P24._audit_department_component(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", P24.PCV_VERSION)
    P23._read_brainwash_component(files[MMP_MEMBER], P23.MMP_VERSION)
    return files, department


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes]]:
    return _read_baseline_bytes(P15._regular_file(path, "prealpha.24 baseline ZIP"))


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    if set(old_package) != DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("prealpha.24 Department member set differs from its fixed contract")
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.24 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION:
        raise PackageError("prealpha.24 Department outer manifest has the wrong baseline version")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    current[DEPARTMENT_ADAPTER] = _required_source(read_source, DEPARTMENT_ADAPTER)
    _validate_dev29_adapter(old_package[DEPARTMENT_ADAPTER], current[DEPARTMENT_ADAPTER])
    current["server/manifest.json"] = _required_source(read_source, "server/manifest.json")
    for source_path in SERVER_SOURCE_PATHS:
        if source_path in {DEPARTMENT_ADAPTER, "server/manifest.json"}:
            continue
        member = "server/" + source_path.removeprefix("server/")
        source = _required_source(read_source, source_path)
        if old_package.get(member) != source:
            raise PackageError(f"Department source must remain unchanged from prealpha.24: {source_path}")

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.24 Department server manifest")
    inner = _json_object(current["server/manifest.json"], "prealpha.25 Department server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = VERSION
    if inner != expected_inner:
        raise PackageError("Department server manifest changed beyond its version")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = P24._audit_department_component(package, VERSION)
    changed = {path for path in old_package.keys() | verified.keys() if old_package.get(path) != verified.get(path)}
    if changed != DEPARTMENT_UPDATED_MEMBERS:
        unexpected = ", ".join(sorted(changed - DEPARTMENT_UPDATED_MEMBERS))
        missing = ", ".join(sorted(DEPARTMENT_UPDATED_MEMBERS - changed))
        raise PackageError(f"Department DWPkg changed outside the fixed prealpha.25 delta; unexpected=[{unexpected}] missing=[{missing}]")
    return package, verified


def _assert_mod_delta(baseline_files: dict[str, bytes], files: dict[str, bytes]) -> None:
    if set(baseline_files) != EXPECTED_BASELINE_MEMBERS or len(baseline_files) != 22:
        raise PackageError("prealpha.24 baseline member set differs")
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.25 MO2 member set differs from the fixed 22-member layout")
    common = set(baseline_files) & set(files)
    if common != EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER} or len(common) != 21:
        raise PackageError("prealpha.25 must replace only the versioned Department DWPkg")
    if any(baseline_files[path] != files[path] for path in common):
        raise PackageError("prealpha.25 changed an unrelated MO2 payload")


def _assemble_mod(baseline_files: dict[str, bytes], department_package: bytes) -> dict[str, bytes]:
    if department_package == baseline_files.get(BASELINE_DPKG_MEMBER):
        raise PackageError("prealpha.25 must replace the prealpha.24 Department package")
    files = {path: data for path, data in baseline_files.items() if path != BASELINE_DPKG_MEMBER}
    files[DPKG_MEMBER] = department_package
    _assert_mod_delta(baseline_files, files)
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.25 MO2 archive")
    if set(files) != EXPECTED_INSTALLABLE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.25 MO2 member list differs from the fixed 22-member layout")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.25 changed the accepted native DLL bytes")
    if BASE.sha256(files[MMP_MEMBER]) != MMP_SHA256:
        raise PackageError("prealpha.25 changed the accepted MMP dev.4 package")
    P24._audit_department_component(files[DPKG_MEMBER], VERSION)
    P20._audit_component(files[PCV_MEMBER], "pcv_companion", P24.PCV_VERSION)
    P23._read_brainwash_component(files[MMP_MEMBER], P23.MMP_VERSION)
    if BASE.stable_zip_bytes(files) != data:
        raise PackageError("prealpha.25 MO2 archive is not the canonical timestamped ZIP")
    return files


def _read_json_file(path: Path, label: str) -> tuple[bytes, dict]:
    data = P15._regular_file(path, label)
    if len(data) > 1024 * 1024:
        raise PackageError(f"{label} exceeds 1 MiB")
    value = _json_object(data, label)
    if data != BASE.json_bytes(value):
        raise PackageError(f"{label} must use canonical UTF-8 JSON with LF and no BOM")
    return data, value


def _validate_offline_compatibility(
    receipt: dict,
    commit: str,
    git: str,
    allowlist: set[str],
    old_department: dict[str, bytes],
) -> dict:
    fields = {
        "schemaVersion", "kind", "sourceCommit", "ownerVersion", "ownerCommit", "ownerClosurePins",
        "sourceHashes", "checks", "receiptHashes", "limits",
    }
    if set(receipt) != fields:
        raise PackageError("offline compatibility input fields differ from the fixed schema")
    if type(receipt["schemaVersion"]) is not int or receipt["schemaVersion"] != 1:
        raise PackageError("offline compatibility input must use schema version 1")
    if receipt["kind"] != "department-offline-compatibility" or receipt["sourceCommit"] != commit:
        raise PackageError("offline compatibility input does not identify the selected source commit")
    if receipt["ownerVersion"] != SF_OWNER_VERSION or receipt["ownerCommit"] != SF_OWNER_COMMIT:
        raise PackageError("offline compatibility owner identity differs from the frozen dev.29 pin")

    source_hashes = receipt["sourceHashes"]
    if not isinstance(source_hashes, dict) or set(source_hashes) != SOURCE_HASH_PATHS:
        raise PackageError("offline source hashes differ from the fixed Department source set")
    committed: dict[str, bytes] = {}
    for path, digest in source_hashes.items():
        _sha256(digest, f"offline source hash {path}")
        if path not in allowlist:
            raise PackageError(f"offline-bound source is not in the public source allowlist: {path}")
        try:
            data = BASE.git_blob(git, commit, path)
        except (KeyError, OSError, subprocess.SubprocessError) as error:
            raise PackageError(f"offline-bound source is unavailable in the selected commit: {path}") from error
        if not isinstance(data, bytes) or BASE.sha256(data) != digest:
            raise PackageError(f"offline source hash differs from the selected commit: {path}")
        committed[path] = data

    pins = receipt["ownerClosurePins"]
    if not isinstance(pins, dict) or set(pins) != DEV28_OWNER_PATHS | {NEW_OWNER_MIGRATION}:
        raise PackageError("offline owner closure differs from the exact 20-file dev.29 map")
    for path, digest in pins.items():
        _sha256(digest, f"offline owner pin {path}")
    adapter_pins = _validate_dev29_adapter(old_department[DEPARTMENT_ADAPTER], committed[DEPARTMENT_ADAPTER])
    if pins != adapter_pins:
        raise PackageError("offline owner pin map differs from the committed dev.29 adapter")

    checks = receipt["checks"]
    if (
        not isinstance(checks, dict)
        or set(checks) != OFFLINE_CHECK_NAMES
        or any(type(value) is not bool or value is not True for value in checks.values())
    ):
        raise PackageError("offline compatibility input must pass every fixed source-only check")
    receipt_hashes = receipt["receiptHashes"]
    if not isinstance(receipt_hashes, dict) or set(receipt_hashes) != OFFLINE_CHECK_NAMES:
        raise PackageError("offline receipt hashes differ from the fixed six-check set")
    for name, digest in receipt_hashes.items():
        _sha256(digest, f"offline receipt hash {name}")

    limits = receipt["limits"]
    if (
        not isinstance(limits, dict)
        or set(limits) != set(PROOF_LIMITS)
        or any(type(value) is not bool for value in limits.values())
        or limits != PROOF_LIMITS
    ):
        raise PackageError("offline compatibility input claims behavior beyond the fixed offline limits")
    return receipt


def _inputs(args):
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    _validate_selected_source_delta(args.git, commit)
    baseline_files, old_department = _read_baseline(args.prealpha24_zip)
    offline_bytes, receipt = _read_json_file(args.offline_compatibility, INPUT_COMPATIBILITY)
    allowlist = BASE.source_allowlist(args.git, commit)
    allowed = _validate_source_allowlist(allowlist)
    _validate_offline_compatibility(receipt, commit, args.git, allowed, old_department)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    return commit, baseline_files, old_department, offline_bytes, receipt, allowlist, read_source


def _proof_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    offline_bytes: bytes,
    receipt: dict,
) -> bytes:
    provenance = {
        "schemaVersion": 1,
        "kind": "department-offline-package-provenance",
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sourceCommit": BASELINE_SOURCE_COMMIT, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DPKG_MEMBER, "sha256": BASE.sha256(department_package)},
        "retainedPayloadPins": {
            "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
            "pcvCompanion": {"member": PCV_MEMBER, "version": P24.PCV_VERSION},
            "mmp": {"member": MMP_MEMBER, "version": P23.MMP_VERSION, "sha256": MMP_SHA256},
        },
        "departmentDpkgDelta": sorted(DEPARTMENT_UPDATED_MEMBERS),
        "changedMo2Members": sorted({BASELINE_DPKG_MEMBER, DPKG_MEMBER}),
        "unchangedMo2Members": 21,
        "owner": {
            "version": receipt["ownerVersion"],
            "commit": receipt["ownerCommit"],
            "closurePins": receipt["ownerClosurePins"],
        },
        "offlineCompatibility": {
            "name": INPUT_COMPATIBILITY,
            "sha256": BASE.sha256(offline_bytes),
            "checks": receipt["checks"],
            "sourceHashes": receipt["sourceHashes"],
            "receiptHashes": receipt["receiptHashes"],
            "limits": receipt["limits"],
        },
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        INPUT_COMPATIBILITY: offline_bytes,
        "package-provenance.json": BASE.json_bytes(provenance),
    })


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.25 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.25 output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if {entry.name for entry in entries} != OUTPUT_FILE_NAMES or any(entry.is_symlink() or not entry.is_file() for entry in entries):
        raise PackageError("prealpha.25 output must contain only three ZIPs and SHA256SUMS.txt")
    return {entry.name: entry.read_bytes() for entry in entries}


def audit_output(args) -> None:
    commit, baseline_files, old_department, offline_bytes, receipt, allowlist, read_source = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    department_package, verified_department = _build_department_package(old_department, read_source)
    if verified_department != P24._audit_department_component(mod_files[DPKG_MEMBER], VERSION):
        raise PackageError("prealpha.25 Department package differs from the committed source assembly")
    expected_mod = _assemble_mod(baseline_files, department_package)
    if mod_files != expected_mod:
        raise PackageError("prealpha.25 MO2 archive differs from the canonical Department-only assembly")

    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    BASE.require_canonical_bytes("prealpha.25 source ZIP", outputs[OUTPUT_SOURCE_ZIP], source_zip)
    expected_proof = _proof_zip(commit, outputs[OUTPUT_MOD_ZIP], outputs[OUTPUT_SOURCE_ZIP], department_package, offline_bytes, receipt)
    BASE.require_canonical_bytes("prealpha.25 offline proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES})


def build(args) -> None:
    commit, baseline_files, old_department, offline_bytes, receipt, allowlist, read_source = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, _department = _build_department_package(old_department, read_source)
    mod_zip = BASE.stable_zip_bytes(_assemble_mod(baseline_files, department_package))
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _proof_zip(commit, mod_zip, source_zip, department_package, offline_bytes, receipt)
    outputs = {OUTPUT_MOD_ZIP: mod_zip, OUTPUT_SOURCE_ZIP: source_zip, OUTPUT_PROOF_ZIP: proof_zip}
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, outputs)
        audit_args = argparse.Namespace(
            git=args.git,
            source_commit=commit,
            prealpha24_zip=args.prealpha24_zip,
            offline_compatibility=args.offline_compatibility,
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
        sub.add_argument("--prealpha24-zip", required=True, type=Path)
        sub.add_argument("--offline-compatibility", required=True, type=Path)
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
