#!/usr/bin/env python3
"""Assemble a local PCV 0.1.25 / Department prealpha.27 candidate from published prealpha.26."""

from __future__ import annotations

import importlib.util
import json
import re
import sys
from pathlib import Path


_P26_PATH = Path(__file__).with_name("build-installable-prealpha26.py")
_P26_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha26_for_pcv25", _P26_PATH)
if _P26_SPEC is None or _P26_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.26 package primitives")
P26 = importlib.util.module_from_spec(_P26_SPEC)
_P26_SPEC.loader.exec_module(P26)

P25 = P26.P25
P24 = P25.P24
P20 = P25.P20
BASE = P26.BASE
PackageError = P26.PackageError
ROOT = P26.ROOT

BASELINE_VERSION = "0.1.0-prealpha.26"
BASELINE_SHA256 = "f402cfb4e8db58c956dfae1e771c1f6773960a3cdbe5909a31942890cf3729ff"
BASELINE_SOURCE_COMMIT = "562f77f94f2aeb29d038d3824d087c92fe405abf"
WORKSPACE_BASE_COMMIT = "99948ff7ae6661f12bf848280fd484c140147c75"
VERSION = "0.1.0-prealpha.27"
PCV_VERSION = "0.1.0-prealpha.13"
BASELINE_PCV_VERSION = "0.1.0-prealpha.12"
OWNER_VERSION = "0.1.25"
OWNER_TAG = "private_conversation-v0.1.25"
OWNER_COMMIT = "886a54967db7c6c08725c8bec8854ea3919ea532"

OWNER_FILE_NAMES = (
    "installation_guard.php",
    "log.php",
    "manifest.json",
    "preprocessing.php",
    "reflection.php",
    "scope.php",
    "state.php",
)
OWNER_STAGE_DIRECTORY = "pcv-companion/private-conversation-v0.1.25/server"
OWNER_SOURCE_MANIFEST = "packaging/companion-only/pcv-0.1.25-source-manifest.json"
OWNER_BASE_PINS = {
    "installation_guard.php": "082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d",
    "log.php": "5ce07e9b834dba51ea5552d923c3a83f97dd3fa5dce7131db773d16813612a8c",
    "manifest.json": "198a242e4e85cd418aca588fd7583e1643437ffe069c3eaa6070b53d2eff4a3c",
    "preprocessing.php": "5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c",
    "reflection.php": "d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540",
    "scope.php": "75a2d61d9f223bae2d5bef32d1d2bbd88cf15dc4764f8759f8d763c236c1af2f",
    "state.php": "49c2b53466572015e77117653739966377267b5b50ec4514155e53256bca7361",
}
OWNER_POSTIMAGE_PINS = {
    "installation_guard.php": "082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d",
    "log.php": "5ce07e9b834dba51ea5552d923c3a83f97dd3fa5dce7131db773d16813612a8c",
    "manifest.json": "198a242e4e85cd418aca588fd7583e1643437ffe069c3eaa6070b53d2eff4a3c",
    "preprocessing.php": "01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950",
    "reflection.php": "d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540",
    "scope.php": "6eaa8d09d423a7364dfd26934b56794fa2cb51cacd26950e753f499989181e33",
    "state.php": "b64277236a520b5464a26becc9c7d15dce233fdf2e3f4c99201c568da22e16d6",
}
OWNER_STAGE_NAMES = {
    "installation_guard.php": "installation_guard.php",
    "log.php": "log.php",
    "manifest.json": "manifest.json",
    "preprocessing.php": "preprocessing.php.template",
    "reflection.php": "reflection.php",
    "scope.php": "scope.php",
    "state.php": "state.php",
}
PACKAGED_STAGE_ROOT = P24.STAGE_ROOT
PACKAGED_STAGE_MEMBERS = set(P24.OVERLAY_EXPECTED_STAGE_MEMBERS)
PACKAGED_SOURCE_MANIFEST = PACKAGED_STAGE_ROOT + "source-manifest.json"
ACTIVE_OVERLAY_PREPROCESSING_MEMBER = PACKAGED_STAGE_ROOT + "preprocessing.php"

BASELINE_DPKG_MEMBER = P26.DPKG_MEMBER
BASELINE_PCV_MEMBER = P26.PCV_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = f"CHIM/server-plugins/pcv_companion/{PCV_VERSION}.dwpkg"
BASELINE_EXPECTED_MEMBERS = set(P26.EXPECTED_INSTALLABLE_MEMBERS)
CANDIDATE_EXPECTED_MEMBERS = (
    BASELINE_EXPECTED_MEMBERS - {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
    | {DPKG_MEMBER, PCV_MEMBER}
)
DEPARTMENT_EXPECTED_MEMBERS = set(P26.DEPARTMENT_EXPECTED_MEMBERS)
PCV_EXPECTED_MEMBERS = set(P20.PCV_EXPECTED_MEMBERS)
DEPARTMENT_CHANGED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/adapters/pcv.php",
    "server/lib/overlay_setup.php",
    PACKAGED_SOURCE_MANIFEST,
    PACKAGED_STAGE_ROOT + "log.php",
    PACKAGED_STAGE_ROOT + "manifest.json",
    PACKAGED_STAGE_ROOT + "scope.php",
    "checksums.sha256",
}
PCV_CHANGED_MEMBERS = {
    "manifest.json",
    "server/manifest.json",
    "server/companion.php",
    "checksums.sha256",
}

ALLOWLIST_PATH = "packaging/public-source-allowlist.json"
SERVER_TEST_PATHS = (
    "server/tests/pcv25/source_contract.php",
    "server/tests/pcv25/overlay_upgrade.php",
    "server/tests/pcv25/owner_loaders.php",
    "server/tests/pcv25/recursive_loader_orders.php",
)
CANDIDATE_SOURCE_PATHS = frozenset({
    "docs/PCV25-OFFLINE-CANDIDATE.md",
    "docs/superpowers/plans/2026-10-29-pcv25-offline.md",
    "packaging/build-pcv25-offline-candidate.py",
    "packaging/companion-only/PCV25-PROVENANCE.md",
    OWNER_SOURCE_MANIFEST,
    "packaging/public-source-allowlist.json",
    "packaging/tests/test_pcv25_offline_candidate.py",
    "pcv-companion/companion.php",
    "pcv-companion/globals.php",
    "pcv-companion/manifest.json",
    "pcv-companion/preprocessing.php",
    *(f"{OWNER_STAGE_DIRECTORY}/{name}" for name in OWNER_FILE_NAMES),
    "server/adapters/pcv.php",
    "server/lib/overlay_setup.php",
    "server/manifest.json",
    *SERVER_TEST_PATHS,
})
REQUIRED_SOURCE_PATHS = CANDIDATE_SOURCE_PATHS

BASELINE_PATH = (
    ROOT.parent.parent
    / "artifacts"
    / "department-sf30-20261028"
    / "public-download"
    / "WorldOfDramaLlama-0.1.0-prealpha.26.zip"
)
ARTIFACT_DIR = ROOT.parent.parent / "artifacts" / "department-pcv25-offline-20261029"
OUTPUT_ZIP = f"WorldOfDramaLlama-{VERSION}-pcv25-offline-candidate.zip"
OUTPUT_AUDIT = "pcv25-offline-candidate-audit.json"
ZIP_TIMESTAMP = (2026, 10, 29, 0, 0, 2)



def _json_object(data: bytes, label: str) -> dict:
    return P20._json_object(data, label)


def _required_source(read_source, path: str) -> bytes:
    try:
        data = read_source(path)
    except (KeyError, FileNotFoundError, OSError) as error:
        raise PackageError(f"required PCV25 candidate source is missing: {path}") from error
    if not isinstance(data, bytes):
        raise PackageError(f"required PCV25 candidate source is not bytes: {path}")
    return data


def _validate_source_allowlist(paths: list[str]) -> set[str]:
    allowed = set(paths)
    if len(allowed) != len(paths):
        raise PackageError("public source allowlist contains duplicate paths")
    missing = sorted(REQUIRED_SOURCE_PATHS - allowed)
    if missing:
        raise PackageError(f"public source allowlist is missing PCV25 candidate paths: {', '.join(missing)}")
    return allowed


def _php_text(data: bytes, label: str) -> str:
    try:
        return data.decode("utf-8")
    except UnicodeDecodeError as error:
        raise PackageError(f"{label} is not UTF-8") from error


def _php_constant(source: str, name: str) -> str:
    matches = re.findall(rf"(?m)^const {re.escape(name)} = '([^']+)';", source)
    if len(matches) != 1:
        raise PackageError(f"PCV25 source does not contain one literal {name} identity")
    return matches[0]


def _php_hash_array(source: str, name: str) -> dict[str, str]:
    matches = list(re.finditer(
        rf"(?ms)^const {re.escape(name)} = \[\r?\n(?P<body>.*?)^\];",
        source,
    ))
    if len(matches) != 1:
        raise PackageError(f"PCV25 source does not contain one literal {name} pin map")
    pins: dict[str, str] = {}
    for line in matches[0].group("body").splitlines():
        if not line.strip():
            continue
        entry = re.fullmatch(r"\s*'([^']+)' => '([0-9a-f]{64})',", line)
        if entry is None:
            raise PackageError(f"PCV25 {name} pin map contains a non-literal entry")
        path, digest = entry.groups()
        if path in pins:
            raise PackageError(f"PCV25 {name} pin map contains a duplicate path")
        pins[path] = digest
    if set(pins) != set(OWNER_FILE_NAMES):
        raise PackageError(f"PCV25 {name} pin paths differ from the seven-file closure")
    return pins


def _php_function_body(source: str, name: str) -> str:
    matches = list(re.finditer(
        rf"(?ms)^function {re.escape(name)}\(\): array\r?\n\{{(?P<body>.*?)^\}}",
        source,
    ))
    if len(matches) != 1:
        raise PackageError(f"PCV25 overlay source does not contain one {name} function")
    return matches[0].group("body")


def _php_overlay_pins(source: str) -> dict[str, dict[str, str]]:
    body = _php_function_body(source, "qd_pcv_overlay_pins")
    returned = re.search(r"(?ms)return\s*\[\s*(?P<body>.*?)\s*\];", body)
    if returned is None:
        raise PackageError("PCV25 overlay pin function is not a literal map")
    entries = re.compile(
        r"(?ms)^\s*'([^']+)' => \[\r?\n"
        r"\s*'base' => '([0-9a-f]{64})',\r?\n"
        r"\s*'post' => '([0-9a-f]{64})',\r?\n"
        r"\s*\],"
    )
    pins: dict[str, dict[str, str]] = {}
    for match in entries.finditer(returned.group("body")):
        name, base, post = match.groups()
        if name in pins:
            raise PackageError("PCV25 overlay pin map contains a duplicate owner file")
        pins[name] = {"base": base, "post": post}
    if entries.sub("", returned.group("body")).strip() or tuple(pins) != OWNER_FILE_NAMES:
        raise PackageError("PCV25 overlay pin map is incomplete, non-literal or out of order")
    return pins


def _php_stage_names(source: str) -> dict[str, str]:
    body = _php_function_body(source, "qd_pcv_overlay_stage_names")
    returned = re.search(r"(?ms)return\s*\[\s*(?P<body>.*?)\s*\];", body)
    if returned is None:
        raise PackageError("PCV25 overlay stage names are not a literal map")
    entries = re.compile(r"(?m)^\s*'([^']+)' => '([^']+)',?\s*$")
    names: dict[str, str] = {}
    for match in entries.finditer(returned.group("body")):
        name, staged = match.groups()
        if name in names:
            raise PackageError("PCV25 overlay stage map contains a duplicate owner file")
        names[name] = staged
    if entries.sub("", returned.group("body")).strip() or tuple(names) != OWNER_FILE_NAMES:
        raise PackageError("PCV25 overlay stage mapping is incomplete, non-literal or out of order")
    return names


def _validate_pin_coherence(read_source) -> None:
    adapter = _php_text(_required_source(read_source, "server/adapters/pcv.php"), "PCV25 adapter")
    companion = _php_text(_required_source(read_source, "pcv-companion/companion.php"), "PCV25 companion")
    overlay = _php_text(_required_source(read_source, "server/lib/overlay_setup.php"), "PCV25 overlay installer")

    if _php_constant(adapter, "QD_PCV_EXPECTED_VERSION") != OWNER_VERSION:
        raise PackageError("PCV25 adapter is pinned to a different owner version")
    if _php_hash_array(adapter, "QD_PCV_EXPECTED_OWNER_SOURCE_HASHES") != OWNER_POSTIMAGE_PINS:
        raise PackageError("PCV25 adapter does not pin the exact seven owner postimages")

    if (_php_constant(companion, "PCV_COMPANION_OWNER_VERSION") != OWNER_VERSION
            or _php_constant(companion, "PCV_COMPANION_OWNER_UPSTREAM_COMMIT") != OWNER_COMMIT):
        raise PackageError("PCV25 companion load-owner identity differs from the frozen owner")
    if _php_hash_array(companion, "PCV_COMPANION_OWNER_BASE_SOURCE_HASHES") != OWNER_BASE_PINS:
        raise PackageError("PCV25 companion base pins differ from the frozen owner source")
    if _php_hash_array(companion, "PCV_COMPANION_OWNER_SOURCE_HASHES") != OWNER_POSTIMAGE_PINS:
        raise PackageError("PCV25 companion load-owner postimage pins differ from the candidate")

    if (_php_constant(overlay, "QD_PCV_OVERLAY_VERSION") != OWNER_VERSION
            or _php_constant(overlay, "QD_PCV_OVERLAY_TAG") != OWNER_TAG
            or _php_constant(overlay, "QD_PCV_OVERLAY_COMMIT") != OWNER_COMMIT
            or _php_constant(overlay, "QD_PCV_OVERLAY_LEGACY_VERSION") != "0.1.23"
            or _php_constant(overlay, "QD_PCV_OVERLAY_LEGACY_COMMIT") != "77265202d7f4a47b88e1eb88131b21f4b0752020"
            or _php_constant(overlay, "QD_PCV_OVERLAY_LEGACY_ARCHIVE") != "pcv-overlay-backup-v0.1.23.php"):
        raise PackageError("PCV25 installer or retained PCV23 archive identity differs")
    if f"'{OWNER_STAGE_DIRECTORY}'" not in overlay:
        raise PackageError("PCV25 installer does not point at the new source stage")
    expected_overlay_pins = {
        name: {"base": OWNER_BASE_PINS[name], "post": OWNER_POSTIMAGE_PINS[name]}
        for name in OWNER_FILE_NAMES
    }
    if _php_overlay_pins(overlay) != expected_overlay_pins:
        raise PackageError("PCV25 installer pins differ from the frozen seven-file source closure")
    if _php_stage_names(overlay) != OWNER_STAGE_NAMES:
        raise PackageError("PCV25 installer exposes an active preprocessing hook")


def _validate_owner_manifest(manifest_bytes: bytes, read_stage) -> dict:
    manifest = _json_object(manifest_bytes, "PCV25 owner source manifest")
    if list(manifest) != [
        "schemaVersion", "upstream", "stagedSourceDirectory", "installDirectory", "files"
    ]:
        raise PackageError("PCV25 owner manifest fields or order differ from the fixed schema")
    if (type(manifest.get("schemaVersion")) is not int or manifest["schemaVersion"] != 1
            or manifest.get("upstream") != {
                "version": OWNER_VERSION,
                "tag": OWNER_TAG,
                "commit": OWNER_COMMIT,
            }
            or manifest.get("stagedSourceDirectory") != OWNER_STAGE_DIRECTORY
            or manifest.get("installDirectory") != "ext/private_conversation"):
        raise PackageError("PCV25 owner manifest does not identify the frozen source and stage")
    entries = manifest.get("files")
    if (not isinstance(entries, list) or len(entries) != len(OWNER_FILE_NAMES)
            or [row.get("path") for row in entries if isinstance(row, dict)] != list(OWNER_FILE_NAMES)):
        raise PackageError("PCV25 owner manifest is not the exact ordered seven-file closure")
    stage_files = {}
    for row in entries:
        if not isinstance(row, dict) or list(row) != [
            "path", "stagedPath", "baseSha256", "postimageSha256", "modified"
        ]:
            raise PackageError("PCV25 owner manifest contains a malformed file entry")
        name = row["path"]
        if (row["stagedPath"] != OWNER_STAGE_NAMES[name]
                or row["baseSha256"] != OWNER_BASE_PINS[name]
                or row["postimageSha256"] != OWNER_POSTIMAGE_PINS[name]
                or type(row["modified"]) is not bool
                or row["modified"] is not (OWNER_BASE_PINS[name] != OWNER_POSTIMAGE_PINS[name])):
            raise PackageError(f"PCV25 owner manifest pin differs from its independent source constants: {name}")
        try:
            data = read_stage(name)
        except (KeyError, FileNotFoundError, OSError) as error:
            raise PackageError(f"PCV25 staged owner file is missing: {name}") from error
        if not isinstance(data, bytes) or BASE.sha256(data) != OWNER_POSTIMAGE_PINS[name]:
            raise PackageError(f"PCV25 staged owner postimage differs from its independent pin: {name}")
        stage_files[name] = data
    return stage_files


def _verify_source_stage_directory() -> None:
    stage_root = ROOT / OWNER_STAGE_DIRECTORY
    if stage_root.is_symlink() or not stage_root.is_dir():
        raise PackageError("PCV25 raw owner source stage is missing or unsafe")
    try:
        stage_root.resolve(strict=True).relative_to(ROOT.resolve(strict=True))
    except (OSError, ValueError) as error:
        raise PackageError("PCV25 owner source stage escapes the repository root") from error
    actual: list[str] = []
    for path in stage_root.rglob("*"):
        if path.is_symlink() or path.is_dir():
            raise PackageError("PCV25 raw owner source stage contains a directory or symlink")
        if not path.is_file():
            raise PackageError("PCV25 raw owner source stage contains a non-file")
        actual.append(path.relative_to(stage_root).as_posix())
    if sorted(actual) != sorted(OWNER_FILE_NAMES):
        raise PackageError("PCV25 raw owner source stage differs from the exact seven-file closure")


def _check_checksums(files: dict[str, bytes], label: str) -> None:
    checksums = BASE.parse_checksums(files["checksums.sha256"], label)
    payload = {path: content for path, content in files.items() if path != "checksums.sha256"}
    if set(checksums) != set(payload) or any(
        checksums[path] != BASE.sha256(content) for path, content in payload.items()
    ):
        raise PackageError(f"{label} checksum closure is incomplete or mismatched")


def _audit_department(data: bytes, expected_version: str, *, pcv25: bool) -> dict[str, bytes]:
    files = BASE.read_zip_bytes(data, "Department DWPkg")
    if set(files) != DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("Department DWPkg member set differs from the fixed 30-member contract")
    _check_checksums(files, "Department DWPkg")
    P20.P18._component_manifest(files, "questionable_decisions", expected_version)
    stage = {path for path in files if path.startswith(PACKAGED_STAGE_ROOT)}
    if stage != PACKAGED_STAGE_MEMBERS or ACTIVE_OVERLAY_PREPROCESSING_MEMBER in files:
        raise PackageError("Department DWPkg does not contain the exact inert eight-member PCV stage")
    if pcv25:
        _validate_owner_manifest(
            files[PACKAGED_SOURCE_MANIFEST],
            lambda name: files[PACKAGED_STAGE_ROOT + OWNER_STAGE_NAMES[name]],
        )
    else:
        staged = {
            path.removeprefix(PACKAGED_STAGE_ROOT): content
            for path, content in files.items()
            if path.startswith(PACKAGED_STAGE_ROOT) and path != PACKAGED_SOURCE_MANIFEST
        }
        P24._validate_overlay_stage(files[PACKAGED_SOURCE_MANIFEST], staged)
    return files


def audit_department_candidate(data: bytes) -> dict[str, bytes]:
    return _audit_department(data, VERSION, pcv25=True)


def _audit_baseline_bytes(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact published prealpha.26 archive")
    files = BASE.read_zip_bytes(data, "published prealpha.26 ZIP")
    if set(files) != BASELINE_EXPECTED_MEMBERS or len(files) != 22:
        raise PackageError("published prealpha.26 ZIP member set differs")
    department = _audit_department(
        files[BASELINE_DPKG_MEMBER], BASELINE_VERSION, pcv25=False
    )
    pcv = P20._audit_component(
        files[BASELINE_PCV_MEMBER], "pcv_companion", BASELINE_PCV_VERSION
    )
    return files, department, pcv


def _build_department_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    if set(old_package) != DEPARTMENT_EXPECTED_MEMBERS:
        raise PackageError("prealpha.26 Department member set differs")
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.26 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION:
        raise PackageError("prealpha.26 Department outer manifest has the wrong baseline version")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    source_inner_bytes = _required_source(read_source, "server/manifest.json")
    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.26 Department server manifest")
    source_inner = _json_object(source_inner_bytes, "current Department server manifest")
    if source_inner != old_inner or source_inner.get("version") != BASELINE_VERSION:
        raise PackageError("repository server manifest must remain the published prealpha.26 identity")
    inner = dict(old_inner)
    inner["version"] = VERSION
    current["server/manifest.json"] = BASE.json_bytes(inner)

    current["server/adapters/pcv.php"] = _required_source(read_source, "server/adapters/pcv.php")
    current["server/lib/overlay_setup.php"] = _required_source(read_source, "server/lib/overlay_setup.php")
    manifest_bytes = _required_source(read_source, OWNER_SOURCE_MANIFEST)
    stage_files = _validate_owner_manifest(
        manifest_bytes,
        lambda name: _required_source(read_source, f"{OWNER_STAGE_DIRECTORY}/{name}"),
    )
    current[PACKAGED_SOURCE_MANIFEST] = manifest_bytes
    for name, data in stage_files.items():
        current[PACKAGED_STAGE_ROOT + OWNER_STAGE_NAMES[name]] = data

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = _stable_zip_bytes(current)
    verified = audit_department_candidate(package)
    changed = {
        path for path in old_package.keys() | verified.keys()
        if old_package.get(path) != verified.get(path)
    }
    if changed != DEPARTMENT_CHANGED_MEMBERS:
        unexpected = sorted(changed - DEPARTMENT_CHANGED_MEMBERS)
        missing = sorted(DEPARTMENT_CHANGED_MEMBERS - changed)
        raise PackageError(
            f"Department candidate changed outside the fixed PCV25 delta; unexpected={unexpected}, missing={missing}"
        )
    return package, verified


def _build_pcv_package(old_package: dict[str, bytes], read_source) -> tuple[bytes, dict[str, bytes]]:
    if set(old_package) != PCV_EXPECTED_MEMBERS:
        raise PackageError("prealpha.26 PCV companion member set differs")
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.12 PCV companion outer manifest")
    if outer.get("version") != BASELINE_PCV_VERSION:
        raise PackageError("baseline PCV support package is not prealpha.12")
    outer["version"] = PCV_VERSION
    current["manifest.json"] = BASE.json_bytes(outer)

    for source_path in P20.PCV_COMPANION_SOURCES:
        source = _required_source(read_source, source_path)
        current["server/" + source_path.rsplit("/", 1)[-1]] = source
    old_inner = _json_object(old_package["server/manifest.json"], "prealpha.12 PCV server manifest")
    inner = _json_object(current["server/manifest.json"], "prealpha.13 PCV server manifest")
    expected_inner = dict(old_inner)
    expected_inner["version"] = PCV_VERSION
    if inner != expected_inner or inner.get("version") != PCV_VERSION:
        raise PackageError("PCV companion server manifest changed beyond the support version")

    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)
    package = _stable_zip_bytes(current)
    verified = P20._audit_component(package, "pcv_companion", PCV_VERSION)
    changed = {path for path in old_package if old_package[path] != verified[path]}
    if changed != PCV_CHANGED_MEMBERS:
        raise PackageError("PCV companion package changed outside its fixed prealpha.13 delta")
    return package, verified


def _stable_zip_bytes(files: dict[str, bytes]) -> bytes:
    original = BASE.ZIP_TIMESTAMP
    BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP
    try:
        return BASE.stable_zip_bytes(files)
    finally:
        BASE.ZIP_TIMESTAMP = original


def build_candidate_bytes(baseline_zip: bytes, read_source) -> tuple[bytes, dict]:
    baseline_files, old_department, old_pcv = _audit_baseline_bytes(baseline_zip)
    sources = {
        path: _required_source(read_source, path)
        for path in sorted(CANDIDATE_SOURCE_PATHS)
    }
    source_read = sources.__getitem__

    allowlist_document = _json_object(sources[ALLOWLIST_PATH], "public source allowlist")
    if (type(allowlist_document.get("schemaVersion")) is not int
            or allowlist_document.get("schemaVersion") != 1
            or allowlist_document.get("kind") != "explicit-public-source-allowlist"
            or not isinstance(allowlist_document.get("files"), list)):
        raise PackageError("public source allowlist has the wrong schema")
    paths = []
    for entry in allowlist_document["files"]:
        if not isinstance(entry, dict) or set(entry) != {"path"} or not isinstance(entry["path"], str):
            raise PackageError("public source allowlist contains a malformed path entry")
        paths.append(entry["path"])
    _validate_source_allowlist(paths)
    _validate_owner_manifest(
        source_read(OWNER_SOURCE_MANIFEST),
        lambda name: source_read(f"{OWNER_STAGE_DIRECTORY}/{name}"),
    )
    _validate_pin_coherence(source_read)

    department_package, department = _build_department_package(old_department, source_read)
    pcv_package, pcv = _build_pcv_package(old_pcv, source_read)
    candidate_files = {
        path: content for path, content in baseline_files.items()
        if path not in {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}
    }
    candidate_files[DPKG_MEMBER] = department_package
    candidate_files[PCV_MEMBER] = pcv_package
    if set(candidate_files) != CANDIDATE_EXPECTED_MEMBERS or len(candidate_files) != 22:
        raise PackageError("assembled PCV25 candidate does not have the fixed 22-member MO2 layout")
    common = set(baseline_files) & set(candidate_files)
    if common != BASELINE_EXPECTED_MEMBERS - {BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER}:
        raise PackageError("PCV25 candidate does not replace exactly the two versioned server packages")
    if any(baseline_files[path] != candidate_files[path] for path in common):
        raise PackageError("PCV25 candidate changed an unrelated published prealpha.26 payload")

    candidate_zip = _stable_zip_bytes(candidate_files)
    audit = {
        "schemaVersion": 1,
        "kind": "department-pcv25-offline-assembled-candidate",
        "baseline": {
            "version": BASELINE_VERSION,
            "sha256": BASELINE_SHA256,
            "sourceCommit": BASELINE_SOURCE_COMMIT,
        },
        "candidate": {
            "departmentVersion": VERSION,
            "pcvCompanionVersion": PCV_VERSION,
            "workspaceBaseCommit": WORKSPACE_BASE_COMMIT,
            "zipSha256": BASE.sha256(candidate_zip),
            "departmentDwpkgSha256": BASE.sha256(department_package),
            "pcvCompanionDwpkgSha256": BASE.sha256(pcv_package),
            "memberCount": len(candidate_files),
            "unchangedMemberCount": len(common),
            "unchangedMembers": {
                path: BASE.sha256(baseline_files[path]) for path in sorted(common)
            },
        },
        "owner": {
            "version": OWNER_VERSION,
            "tag": OWNER_TAG,
            "commit": OWNER_COMMIT,
            "manifestSha256": BASE.sha256(sources[OWNER_SOURCE_MANIFEST]),
            "sourceStage": OWNER_STAGE_DIRECTORY,
            "installedStageMembers": sorted(PACKAGED_STAGE_MEMBERS),
            "basePins": OWNER_BASE_PINS,
            "postimagePins": OWNER_POSTIMAGE_PINS,
        },
        "changedPaths": {
            "departmentDwpkg": sorted(DEPARTMENT_CHANGED_MEMBERS),
            "pcvCompanionDwpkg": sorted(PCV_CHANGED_MEMBERS),
            "modZip": [BASELINE_DPKG_MEMBER, BASELINE_PCV_MEMBER, DPKG_MEMBER, PCV_MEMBER],
        },
        "sourceHashes": {
            path: BASE.sha256(sources[path]) for path in sorted(sources)
        },
        "limits": {
            "offline": True,
            "server": False,
            "database": False,
            "http": False,
            "provider": False,
            "nativeBuild": False,
            "skyrim": False,
            "installedGame": False,
            "live": False,
            "published": False,
        },
    }
    return candidate_zip, audit


def _read_workspace_source(path: str) -> bytes:
    try:
        BASE.validate_member_name(path)
    except PackageError as error:
        raise PackageError(f"unsafe candidate source path: {path}") from error
    source = ROOT / Path(path)
    if source.is_symlink() or not source.is_file():
        raise PackageError(f"candidate source is missing or is a symlink: {path}")
    try:
        source.resolve(strict=True).relative_to(ROOT.resolve(strict=True))
    except (OSError, ValueError) as error:
        raise PackageError(f"candidate source escapes the repository root: {path}") from error
    return source.read_bytes()


def _write_candidate() -> tuple[Path, Path, dict]:
    _verify_source_stage_directory()
    baseline = P26.P15._regular_file(BASELINE_PATH, "published prealpha.26 ZIP")
    candidate, audit = build_candidate_bytes(baseline, _read_workspace_source)
    ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)
    output_path = ARTIFACT_DIR / OUTPUT_ZIP
    audit_path = ARTIFACT_DIR / OUTPUT_AUDIT
    if output_path.exists() or output_path.is_symlink() or audit_path.exists() or audit_path.is_symlink():
        raise PackageError("candidate output already exists; refusing to replace artifacts")
    output_path.write_bytes(candidate)
    audit_path.write_bytes(BASE.json_bytes(audit))
    return output_path, audit_path, audit


def main() -> int:
    try:
        output_path, audit_path, audit = _write_candidate()
    except (PackageError, OSError) as error:
        print(f"PCV25 offline candidate build refused: {error}", file=sys.stderr)
        return 1
    print(f"PCV25 offline candidate: {output_path}")
    print(f"Candidate SHA-256: {audit['candidate']['zipSha256']}")
    print(f"Package audit: {audit_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
