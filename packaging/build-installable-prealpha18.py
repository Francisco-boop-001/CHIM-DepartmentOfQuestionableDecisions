#!/usr/bin/env python3
"""Build and audit prealpha.18 from the exact published prealpha.17 installable."""

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


_P17_PATH = Path(__file__).with_name("build-installable-prealpha17.py")
_P17_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha17", _P17_PATH)
if _P17_SPEC is None or _P17_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.17 packaging helper")
P17 = importlib.util.module_from_spec(_P17_SPEC)
_P17_SPEC.loader.exec_module(P17)

P15 = P17.P15
BASE = P17.BASE
PackageError = P17.PackageError
ROOT = P17.ROOT

VERSION = "0.1.0-prealpha.18"
PCV_VERSION = "0.1.0-prealpha.10"
BASELINE_VERSION = "0.1.0-prealpha.17"
BASELINE_PCV_VERSION = "0.1.0-prealpha.9"
BASELINE_SHA256 = "0ba15af00904aa776c8186064d15d72608a8aeccd34a0aa70023be3857354197"
DLL_SHA256 = "bb901e6e423c19f63eb804f8303012dbfd8f759274ad719d31969076218cb6ef"
ZIP_TIMESTAMP = (2026, 10, 26, 0, 0, 2)

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-maintenance-proof.zip"
CHECKSUMS_NAME = P15.CHECKSUMS_NAME
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}

BASELINE_DEPARTMENT_MEMBER = (
    "CHIM/server-plugins/questionable_decisions/0.1.0-prealpha.17.dwpkg"
)
BASELINE_PCV_MEMBER = "CHIM/server-plugins/pcv_companion/0.1.0-prealpha.9.dwpkg"
DEPARTMENT_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = f"CHIM/server-plugins/pcv_companion/{PCV_VERSION}.dwpkg"
UI_MEMBER = "PrismaUI/views/WorldOfDramaLlama/app.js"
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"

EXPECTED_BASELINE_MEMBERS = {
    "CHIM/server-plugins/brainwash/0.1.0-dev.3.dwpkg",
    BASELINE_PCV_MEMBER,
    BASELINE_DEPARTMENT_MEMBER,
    "MALPRACTICE-MAKES-PERFECT-TEST.txt",
    "NOTICE/CHIM-Plugin-MIT-LICENSE.txt",
    "NOTICE/CommonLibSSE-NG-3.7.0-LICENSE.txt",
    "NOTICE/Department-nlohmann-json-3.11.3-MIT.txt",
    "NOTICE/PrismaUI-LICENSE.md",
    "NOTICE/fmt-10.2.1-LICENSE.txt",
    "NOTICE/rapidcsv-8.84-LICENSE.txt",
    "NOTICE/spdlog-1.13.0-LICENSE.txt",
    "PrismaUI/views/WorldOfDramaLlama/app.css",
    UI_MEMBER,
    "PrismaUI/views/WorldOfDramaLlama/brainwash-editor.css",
    "PrismaUI/views/WorldOfDramaLlama/brainwash-editor.js",
    "PrismaUI/views/WorldOfDramaLlama/index.html",
    "SEQ/WorldOfDramaLlama.seq",
    DLL_MEMBER,
    "SKSE/Plugins/WorldOfDramaLlama.ini",
    "Scripts/WorldOfDramaLlamaMCM.pex",
    "Scripts/WorldOfDramaLlamaNative.pex",
    "WorldOfDramaLlama.esp",
}

SERVER_SOURCE_PATHS = tuple(P17.SERVER_SOURCE_PATHS)
PCV_COMPANION_SOURCES = tuple(BASE.PCV_COMPANION_SOURCES)
STAGE_ROOT = P17.STAGE_ROOT
PCV_OVERLAY_MEMBERS = {
    STAGE_ROOT + "source-manifest.json",
    *(STAGE_ROOT + name for name in BASE.PCV_FILE_POSTIMAGES),
}
DEPARTMENT_EXPECTED_MEMBERS = {
    "manifest.json",
    "checksums.sha256",
    *(
        "server/" + path.removeprefix("server/")
        for path in SERVER_SOURCE_PATHS
    ),
    *PCV_OVERLAY_MEMBERS,
}
PCV_EXPECTED_MEMBERS = {
    "manifest.json",
    "checksums.sha256",
    *(
        "server/" + path.rsplit("/", 1)[-1]
        for path in PCV_COMPANION_SOURCES
    ),
}

DEPARTMENT_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/adapters/pcv.php",
    "server/adapters/runtime_support.php",
    "server/lib/overlay_setup.php",
    STAGE_ROOT + "source-manifest.json",
    STAGE_ROOT + "state.php",
    "checksums.sha256",
}
PCV_UPDATED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/companion.php",
    "checksums.sha256",
}

SOURCE_HASH_PATHS = tuple(sorted({
    "packaging/build-installable-prealpha8.py",
    "packaging/build-installable-prealpha18.py",
    "packaging/companion-only/pcv-0.1.23-source-manifest.json",
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_installable_prealpha18.py",
    "pcv-companion/companion.php",
    "pcv-companion/manifest.json",
    "pcv-companion/private-conversation-v0.1.23/server/state.php",
    "server/adapters/pcv.php",
    "server/adapters/runtime_support.php",
    "server/lib/overlay_setup.php",
    "server/manifest.json",
    "server/tests/onboarding/overlay.php",
    "server/tests/pcv/runtime_support_limits.php",
    "ui/app.js",
    "ui/tests/startup-handshake.cjs",
    "ui/tests/ui-functional.cjs",
}))

EXPECTED_CHECKS = (
    ("uiStartup", (28, 28), ".superpowers/sdd/2026-10-26-maintainer-fixes/task-1-green-startup.txt"),
    ("uiFunctional", (51, 51), ".superpowers/sdd/2026-10-26-maintainer-fixes/task-1-green-ui-functional.json"),
    ("profileReadBoundsPostgres", (11, 11), "root-pg-green1.json"),
    ("pcvAdapterProfileCatalogPostgres", (5, 5), "root-pg-green-adapter1.json"),
    ("pcvOverlayUpgrade", (7, 7), ".superpowers/sdd/2026-10-26-maintainer-fixes/task-2-green-overlay.txt"),
)
EXPECTED_REGRESSION_CONTROLS = (
    ("profileReadBoundsBeforeFix", (4, 8), "root-pg-red.json"),
    ("pcvAdapterBeforeFix", (3, 5), "root-pg-red-adapter1.json"),
)
PROOF_LIMITS = {
    "liveServer": False,
    "productionDatabase": False,
    "productionPostgresVersionKnown": False,
    "http": False,
    "provider": False,
    "skyrim": False,
    "sksePluginLoad": False,
    "nativeDllBuild": False,
}

REQUIRED_SOURCE_PATHS = (
    set(P17.REQUIRED_SOURCE_PATHS)
    | {
        "docs/audits/2026-10-26-maintainer-world-prompt.md",
        "docs/audits/2026-10-26-maintainer-world-findings.md",
        "docs/audits/2026-10-26-maintainer-world-coverage.md",
        "docs/audits/2026-10-26-maintainer-world-contracts.md",
        "docs/superpowers/plans/2026-10-26-maintainer-fixes.md",
        "packaging/build-installable-prealpha18.py",
        "packaging/tests/test_installable_prealpha18.py",
        "server/tests/pcv/runtime_support_limits.php",
        "server/tests/onboarding/overlay.php",
    }
)

# Reuse the existing Department builder with this release's exact version and delta.
P15.VERSION = VERSION
P15.BASELINE_SHA256 = BASELINE_SHA256
P15.BASELINE_DPKG_MEMBER = BASELINE_DEPARTMENT_MEMBER
P15.DPKG_MEMBER = DEPARTMENT_MEMBER
P15.UPDATED_DPKG_MEMBERS = DEPARTMENT_UPDATED_MEMBERS
P15.SERVER_SOURCE_PATHS = SERVER_SOURCE_PATHS
P15.REQUIRED_SOURCE_PATHS = REQUIRED_SOURCE_PATHS
P15.ZIP_TIMESTAMP = ZIP_TIMESTAMP
BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP

_HEX_SHA256 = re.compile(r"\A[a-f0-9]{64}\Z")


def _json_object(data: bytes, label: str) -> dict:
    try:
        value = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError(f"{label} is not valid UTF-8 JSON") from error
    if not isinstance(value, dict):
        raise PackageError(f"{label} must be a JSON object")
    return value


def _check_record(record: object, expected: tuple[str, tuple[int, int], str], status: str) -> None:
    name, counts, evidence = expected
    if not isinstance(record, dict) or set(record) != {
        "name", "status", "passed", "total", "evidenceName", "evidenceSha256"
    }:
        raise PackageError(f"maintenance receipt record {name} has the wrong fields")
    if (record.get("name") != name or record.get("status") != status
            or type(record.get("passed")) is not int or record.get("passed") != counts[0]
            or type(record.get("total")) is not int or record.get("total") != counts[1]
            or record.get("evidenceName") != evidence
            or not isinstance(record.get("evidenceSha256"), str)
            or _HEX_SHA256.fullmatch(record["evidenceSha256"]) is None):
        raise PackageError(f"maintenance receipt record {name} does not match the fixed check contract")


def _validate_maintenance_receipt(git: str, commit: str, data: bytes) -> dict:
    receipt = _json_object(data, "maintenance receipt")
    if data != BASE.json_bytes(receipt):
        raise PackageError("maintenance receipt must use canonical UTF-8 JSON with LF and no BOM")
    if set(receipt) != {
        "schemaVersion", "kind", "sourceCommit", "baseline", "sourceHashes",
        "checks", "regressionControls", "limits",
    }:
        raise PackageError("maintenance receipt has unexpected or missing top-level fields")
    if (type(receipt.get("schemaVersion")) is not int or receipt.get("schemaVersion") != 1
            or receipt.get("kind") != "department-maintainer-prealpha18"
            or receipt.get("sourceCommit") != commit):
        raise PackageError("maintenance receipt does not identify the selected prealpha.18 source commit")
    if receipt.get("baseline") != {
        "version": BASELINE_VERSION,
        "sha256": BASELINE_SHA256,
    }:
        raise PackageError("maintenance receipt does not identify the exact prealpha.17 baseline")

    source_hashes = receipt.get("sourceHashes")
    if not isinstance(source_hashes, dict) or set(source_hashes) != set(SOURCE_HASH_PATHS):
        raise PackageError("maintenance receipt source hash paths differ from the reviewed source set")
    for path in SOURCE_HASH_PATHS:
        digest = source_hashes.get(path)
        if not isinstance(digest, str) or _HEX_SHA256.fullmatch(digest) is None:
            raise PackageError(f"maintenance receipt source hash is malformed: {path}")
        if BASE.sha256(BASE.git_blob(git, commit, path)) != digest:
            raise PackageError(f"maintenance receipt source hash differs from the selected commit: {path}")

    for key, expected, status in (
        ("checks", EXPECTED_CHECKS, "passed"),
        ("regressionControls", EXPECTED_REGRESSION_CONTROLS, "expected-red"),
    ):
        records = receipt.get(key)
        if not isinstance(records, list) or len(records) != len(expected):
            raise PackageError(f"maintenance receipt {key} does not contain the fixed record set")
        for record, contract in zip(records, expected):
            _check_record(record, contract, status)
    limits = receipt.get("limits")
    if (not isinstance(limits, dict) or set(limits) != set(PROOF_LIMITS)
            or any(type(value) is not bool for value in limits.values())
            or limits != PROOF_LIMITS):
        raise PackageError("maintenance receipt claims behavior outside the recorded proof limits")
    return receipt


def _read_maintenance_receipt(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise PackageError("maintenance receipt must be a regular file")
    return path.read_bytes()


def _component_manifest(files: dict[str, bytes], name: str, version: str) -> tuple[dict, dict]:
    if "manifest.json" not in files or "server/manifest.json" not in files:
        raise PackageError(f"{name} DWPkg is missing an outer or server manifest")
    outer = _json_object(files["manifest.json"], f"{name} DWPkg manifest")
    inner = _json_object(files["server/manifest.json"], f"{name} server manifest")
    server = outer.get("server")
    if (outer.get("schema_version") != 4 or outer.get("name") != name
            or outer.get("version") != version or not isinstance(server, dict)
            or server.get("mutable_paths") != []):
        raise PackageError(f"{name} DWPkg outer identity/version mismatch")
    if (inner.get("schema_version") != 2 or inner.get("name") != name
            or inner.get("version") != version):
        raise PackageError(f"{name} server manifest identity/version mismatch")
    expected_url = BASE.PACKAGE_CONFIG_URL[name]
    if inner.get("config_url") != expected_url:
        raise PackageError(f"{name} server manifest config URL differs from the Department contract")
    return outer, inner


def _audit_component(data: bytes, expected_name: str, expected_version: str) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, f"{expected_name} DWPkg")
    expected_members = {
        "questionable_decisions": DEPARTMENT_EXPECTED_MEMBERS,
        "pcv_companion": PCV_EXPECTED_MEMBERS,
    }.get(expected_name)
    if expected_members is None or set(files) != expected_members:
        raise PackageError(f"{expected_name} DWPkg member set differs from its schema-4 contract")
    checksums = BASE.parse_checksums(files["checksums.sha256"], f"{expected_name} DWPkg")
    payload = {path: content for path, content in files.items() if path != "checksums.sha256"}
    if set(checksums) != set(payload) or any(
        checksums[path] != BASE.sha256(content) for path, content in payload.items()
    ):
        raise PackageError(f"{expected_name} DWPkg checksum closure is incomplete or mismatched")
    _component_manifest(files, expected_name, expected_version)
    if expected_name == "questionable_decisions":
        stage = {path for path in files if path.startswith(STAGE_ROOT)}
        if stage != PCV_OVERLAY_MEMBERS:
            raise PackageError("Department DWPkg does not contain the exact seven-file PCV overlay")
    elif any("pcv-overlay" in path for path in files):
        raise PackageError("PCV companion DWPkg must not contain Department overlay files")
    return files


def _compare_component_update(
    previous: dict[str, bytes],
    current: dict[str, bytes],
    name: str,
    version: str,
    allowed_changes: set[str],
) -> None:
    if set(previous) != set(current):
        raise PackageError(f"{name} DWPkg must contain only the reviewed payload members")
    old_outer = _json_object(previous["manifest.json"], f"previous {name} outer manifest")
    new_outer = _json_object(current["manifest.json"], f"current {name} outer manifest")
    old_inner = _json_object(previous["server/manifest.json"], f"previous {name} server manifest")
    new_inner = _json_object(current["server/manifest.json"], f"current {name} server manifest")
    if new_outer.get("version") not in {old_outer.get("version"), version}:
        raise PackageError(f"{name} outer manifest has an unapproved version change")
    if new_inner.get("version") not in {old_inner.get("version"), version}:
        raise PackageError(f"{name} server manifest has an unapproved version change")
    old_outer["version"] = new_outer.get("version")
    old_inner["version"] = new_inner.get("version")
    if old_outer != new_outer or old_inner != new_inner:
        raise PackageError(f"{name} manifests changed beyond their version")
    changed = {path for path in previous if previous[path] != current[path]}
    if not changed.issubset(allowed_changes):
        raise PackageError(f"{name} DWPkg must change only the reviewed members")


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    package, verified = P15._build_department_package(old_package, read_source)
    _audit_component(package, "questionable_decisions", VERSION)
    _compare_component_update(
        old_package,
        verified,
        "questionable_decisions",
        VERSION,
        DEPARTMENT_UPDATED_MEMBERS,
    )
    return package, verified


def _build_pcv_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.9 PCV companion manifest")
    outer["version"] = PCV_VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    for source_path in PCV_COMPANION_SOURCES:
        member = "server/" + source_path.rsplit("/", 1)[-1]
        current[member] = read_source(source_path)

    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.9 PCV server manifest")
    inner = _json_object(current["server/manifest.json"], "prealpha.10 PCV server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = PCV_VERSION
    if inner != expected_inner:
        raise PackageError("committed PCV server manifest changed beyond its version")

    payload = {path: data for path, data in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = BASE.stable_zip_bytes(current)
    verified = _audit_component(package, "pcv_companion", PCV_VERSION)
    _compare_component_update(old_package, verified, "pcv_companion", PCV_VERSION, PCV_UPDATED_MEMBERS)
    changed = {path for path in old_package if old_package[path] != verified[path]}
    if changed != PCV_UPDATED_MEMBERS:
        raise PackageError("prealpha.10 PCV DWPkg must change exactly its reviewed members")
    return package, verified


def _read_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact verified prealpha.17 archive")
    files = BASE.read_zip_bytes(data, "prealpha.17 baseline ZIP")
    if set(files) != EXPECTED_BASELINE_MEMBERS or len(files) != 22:
        raise PackageError("prealpha.17 baseline member list differs")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.17 baseline DLL differs from the accepted native payload")
    department = _audit_component(files[BASELINE_DEPARTMENT_MEMBER], "questionable_decisions", BASELINE_VERSION)
    pcv = _audit_component(files[BASELINE_PCV_MEMBER], "pcv_companion", BASELINE_PCV_VERSION)
    if len(department) != 29 or len(pcv) != 6:
        raise PackageError("prealpha.17 baseline component member counts differ")
    return files, department, pcv


def _read_baseline(path: Path) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    data = P15._regular_file(path, "prealpha.17 baseline ZIP")
    return _read_baseline_bytes(data)


def _assemble_mod(
    base_files: dict[str, bytes],
    department_package: bytes,
    pcv_package: bytes,
    app_js: bytes,
) -> dict[str, bytes]:
    if set(base_files) != EXPECTED_BASELINE_MEMBERS:
        raise PackageError("prealpha.17 baseline member list differs")
    if base_files[BASELINE_DEPARTMENT_MEMBER] == department_package:
        raise PackageError("prealpha.18 Department DWPkg did not change")
    if base_files[BASELINE_PCV_MEMBER] == pcv_package:
        raise PackageError("prealpha.10 PCV companion DWPkg did not change")
    files = {
        path: content
        for path, content in base_files.items()
        if path not in {BASELINE_DEPARTMENT_MEMBER, BASELINE_PCV_MEMBER}
    }
    files[DEPARTMENT_MEMBER] = department_package
    files[PCV_MEMBER] = pcv_package
    files[UI_MEMBER] = app_js
    if set(files) != EXPECTED_BASELINE_MEMBERS - {
        BASELINE_DEPARTMENT_MEMBER, BASELINE_PCV_MEMBER
    } | {DEPARTMENT_MEMBER, PCV_MEMBER}:
        raise PackageError("assembled prealpha.18 MO2 member set differs")
    changed = {
        path for path in set(base_files) & set(files) if base_files[path] != files[path]
    }
    if changed != {UI_MEMBER}:
        raise PackageError("prealpha.18 changed an unrelated shared MO2 member")
    if files[DLL_MEMBER] != base_files[DLL_MEMBER]:
        raise PackageError("prealpha.18 changed the accepted native DLL bytes")
    return files


def _read_installable(data: bytes) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "prealpha.18 MO2 archive")
    expected = EXPECTED_BASELINE_MEMBERS - {
        BASELINE_DEPARTMENT_MEMBER, BASELINE_PCV_MEMBER
    } | {DEPARTMENT_MEMBER, PCV_MEMBER}
    if set(files) != expected or len(files) != 22:
        raise PackageError("prealpha.18 MO2 member list differs from prealpha.17")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if BASE.sha256(files[DLL_MEMBER]) != DLL_SHA256:
        raise PackageError("prealpha.18 DLL does not match the accepted prealpha.17 bytes")
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != BASE.NOTICE_FILES:
        raise PackageError("MO2 archive does not include exactly the seven required notices")
    BASE.audit_ini(files["SKSE/Plugins/WorldOfDramaLlama.ini"])
    return files


def _proof_zip(
    commit: str,
    mod_zip: bytes,
    source_zip: bytes,
    department_package: bytes,
    pcv_package: bytes,
    receipt_bytes: bytes,
) -> bytes:
    proof = {
        "schemaVersion": 1,
        "version": VERSION,
        "sourceCommit": commit,
        "baseline": {"version": BASELINE_VERSION, "sha256": BASELINE_SHA256},
        "installable": {"name": OUTPUT_MOD_ZIP, "sha256": BASE.sha256(mod_zip)},
        "sourceArchive": {"name": OUTPUT_SOURCE_ZIP, "sha256": BASE.sha256(source_zip)},
        "departmentPackage": {"member": DEPARTMENT_MEMBER, "sha256": BASE.sha256(department_package)},
        "pcvPackage": {"member": PCV_MEMBER, "sha256": BASE.sha256(pcv_package)},
        "unchangedMo2Members": 19,
        "nativeDll": {"member": DLL_MEMBER, "sha256": DLL_SHA256},
        "maintenanceChecks": {"name": "maintenance-checks.json", "sha256": BASE.sha256(receipt_bytes)},
        "scope": "bounded-maintainer-maintenance",
        "limits": PROOF_LIMITS,
    }
    return BASE.stable_zip_bytes({
        "maintenance-checks.json": receipt_bytes,
        "package-provenance.json": BASE.json_bytes(proof),
    })


def _inputs(args) -> tuple[str, dict[str, bytes], dict[str, bytes], dict[str, bytes], bytes, list[str], object]:
    commit = BASE.canonical_source_commit(args.git, args.source_commit)
    baseline_files, old_department, old_pcv = _read_baseline(args.prealpha17_zip)
    receipt_bytes = _read_maintenance_receipt(args.maintenance_checks)
    _validate_maintenance_receipt(args.git, commit, receipt_bytes)
    allowlist = BASE.source_allowlist(args.git, commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"public source allowlist is missing required paths: {', '.join(missing)}")
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return BASE.committed_worktree_file(args.git, commit, allowed, relative)

    return commit, baseline_files, old_department, old_pcv, receipt_bytes, allowlist, read_source


def _build_packages(old_department: dict[str, bytes], old_pcv: dict[str, bytes], read_source):
    department_package, _ = _build_department_package(old_department, read_source)
    pcv_package, _ = _build_pcv_package(old_pcv, read_source)
    app_js = read_source("ui/app.js")
    return department_package, pcv_package, app_js


def _write_outputs(directory: Path, outputs: dict[str, bytes]) -> None:
    if set(outputs) != OUTPUT_NAMES:
        raise PackageError("prealpha.18 output set is incomplete")
    for name, data in outputs.items():
        (directory / name).write_bytes(data)
    (directory / CHECKSUMS_NAME).write_bytes(BASE.make_checksums(outputs))


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("prealpha.18 output directory is missing or unsafe")
    expected = OUTPUT_NAMES | {CHECKSUMS_NAME}
    entries = list(directory.iterdir())
    if ({item.name for item in entries} != expected
            or any(item.is_symlink() or not item.is_file() for item in entries)):
        raise PackageError("prealpha.18 output must contain only three ZIPs and SHA256SUMS.txt")
    return {item.name: item.read_bytes() for item in entries}


def audit_output(args) -> None:
    (commit, baseline_files, old_department, old_pcv, receipt_bytes,
     allowlist, read_source) = _inputs(args)
    outputs = _expected_output_files(args.output_dir)
    mod_files = _read_installable(outputs[OUTPUT_MOD_ZIP])
    department_package, pcv_package, app_js = _build_packages(
        old_department, old_pcv, read_source
    )
    expected_mod = _assemble_mod(baseline_files, department_package, pcv_package, app_js)
    if mod_files != expected_mod:
        raise PackageError("prealpha.18 MO2 archive differs from the canonical source assembly")
    BASE.require_canonical_bytes("prealpha.18 source ZIP", outputs[OUTPUT_SOURCE_ZIP],
                                 BASE.build_source_zip(args.git, commit, allowlist))
    expected_proof = _proof_zip(
        commit,
        outputs[OUTPUT_MOD_ZIP],
        outputs[OUTPUT_SOURCE_ZIP],
        department_package,
        pcv_package,
        receipt_bytes,
    )
    BASE.require_canonical_bytes("prealpha.18 maintenance proof ZIP", outputs[OUTPUT_PROOF_ZIP], expected_proof)
    BASE.audit_checksums_file(
        outputs[CHECKSUMS_NAME], {name: outputs[name] for name in OUTPUT_NAMES}
    )


def build(args) -> None:
    (commit, baseline_files, old_department, old_pcv, receipt_bytes,
     allowlist, read_source) = _inputs(args)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    department_package, pcv_package, app_js = _build_packages(
        old_department, old_pcv, read_source
    )
    mod_zip = BASE.stable_zip_bytes(
        _assemble_mod(baseline_files, department_package, pcv_package, app_js)
    )
    source_zip = BASE.build_source_zip(args.git, commit, allowlist)
    proof_zip = _proof_zip(
        commit,
        mod_zip,
        source_zip,
        department_package,
        pcv_package,
        receipt_bytes,
    )
    files = {
        OUTPUT_MOD_ZIP: mod_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PROOF_ZIP: proof_zip,
    }
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, files)
        audit_args = argparse.Namespace(
            git=args.git,
            source_commit=commit,
            prealpha17_zip=args.prealpha17_zip,
            maintenance_checks=args.maintenance_checks,
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
        sub.add_argument("--prealpha17-zip", required=True, type=Path)
        sub.add_argument("--maintenance-checks", required=True, type=Path)
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
