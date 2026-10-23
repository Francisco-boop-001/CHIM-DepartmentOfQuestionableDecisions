#!/usr/bin/env python3
"""Build and audit the installable prealpha.8 MO2 archive from pinned inputs."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
from io import BytesIO
from pathlib import Path, PurePosixPath
from typing import Mapping


VERSION = "0.1.0-prealpha.8"
ROOT = Path(__file__).resolve().parents[1]
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
COMPONENT_DIR = "component-packages"
CHECKSUMS_NAME = "SHA256SUMS.txt"
PUBLIC_CHECKSUM_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP}
OLD_GAME_ZIP_SHA256 = "a90532c7fa9e6fd7f7b6d95d135bc925c460b8baa2d2f00333f62043de9757f3"
OLD_DLL_SHA256 = "1c6d0a0370a2f9e8133deef574be2f2ecc0ee7ac1065e9eedddd7cc8554aea13"

OLD_GAME_MEMBERS = {
    "Data/PrismaUI/views/WorldOfDramaLlama/app.css",
    "Data/PrismaUI/views/WorldOfDramaLlama/app.js",
    "Data/PrismaUI/views/WorldOfDramaLlama/index.html",
    "Data/Scripts/WorldOfDramaLlamaMCM.pex",
    "Data/Scripts/WorldOfDramaLlamaNative.pex",
    "Data/SEQ/WorldOfDramaLlama.seq",
    "Data/SKSE/Plugins/WorldOfDramaLlama.dll",
    "Data/SKSE/Plugins/WorldOfDramaLlama.ini",
    "Data/WorldOfDramaLlama.esp",
}
REUSED_GAME_ASSETS = {
    "Data/WorldOfDramaLlama.esp": (
        "WorldOfDramaLlama.esp",
        "b4097ba98c3cd5b5227e89812b46ca2f6e2583a1953b6c25c6a4ceab8dcf0dbe",
    ),
    "Data/SEQ/WorldOfDramaLlama.seq": (
        "SEQ/WorldOfDramaLlama.seq",
        "cbfd218787df784c39fbc4f4fad92a8f28e2a880430c210b6d0d8dc133c07e66",
    ),
    "Data/Scripts/WorldOfDramaLlamaMCM.pex": (
        "Scripts/WorldOfDramaLlamaMCM.pex",
        "831af1fbdeaeae71f10f5828f706454a8e648951dfcd4992f646c091f44a43cd",
    ),
    "Data/Scripts/WorldOfDramaLlamaNative.pex": (
        "Scripts/WorldOfDramaLlamaNative.pex",
        "50bf6d1390d7908eedf3f685c6a9f7f65f304e686e72811e51d46710c81fa84d",
    ),
}
UI_ASSETS = {
    "ui/app.css": "PrismaUI/views/WorldOfDramaLlama/app.css",
    "ui/app.js": "PrismaUI/views/WorldOfDramaLlama/app.js",
    "ui/index.html": "PrismaUI/views/WorldOfDramaLlama/index.html",
}
INI_SOURCE = "packaging/companion-only/WorldOfDramaLlama.ini"
DLL_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.dll"
INI_MEMBER = "SKSE/Plugins/WorldOfDramaLlama.ini"
NOTICE_DIR = "packaging/companion-only/notices"
NOTICE_FILES = {
    "CHIM-Plugin-MIT-LICENSE.txt",
    "CommonLibSSE-NG-3.7.0-LICENSE.txt",
    "Department-nlohmann-json-3.11.3-MIT.txt",
    "PrismaUI-LICENSE.md",
    "fmt-10.2.1-LICENSE.txt",
    "rapidcsv-8.84-LICENSE.txt",
    "spdlog-1.13.0-LICENSE.txt",
}

PCV_BASE_SOURCE = "pcv-companion/private-conversation-v0.1.23/server"
PCV_SOURCE_MANIFEST = "packaging/companion-only/pcv-0.1.23-source-manifest.json"
PCV_UPSTREAM = {
    "version": "0.1.23",
    "tag": "private_conversation-v0.1.23",
    "commit": "77265202d7f4a47b88e1eb88131b21f4b0752020",
}
PCV_FILE_POSTIMAGES = {
    "installation_guard.php": "082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d",
    "log.php": "0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56",
    "manifest.json": "f045b9b6bd3e13a84cdabbf400a73ef4581acd8fa440e1e84f51b1dd0cb5f84e",
    "preprocessing.php": "01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950",
    "reflection.php": "d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540",
    "scope.php": "aa800deb4a34e0a77663e938b80a11d748978fc1b420a708c1c9125fce9bf398",
    "state.php": "1c77909a23f52cca914f15e78eb74b3c71995080e870986d8ee3e21bbfb3374b",
}
PCV_FILE_BASES = {
    "installation_guard.php": "082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d",
    "log.php": "0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56",
    "manifest.json": "f045b9b6bd3e13a84cdabbf400a73ef4581acd8fa440e1e84f51b1dd0cb5f84e",
    "preprocessing.php": "5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c",
    "reflection.php": "d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540",
    "scope.php": "763ff62b129086f7c3d91fbccbb80e8db0ec71b04d11fc150c4881420bbe6992",
    "state.php": "142f5a026740dfb629af925005c7e931396aa3dcde0d534002fb42b545c5664d",
}
PACKAGE_CONFIG_URL = {
    "questionable_decisions": "../ext/questionable_decisions/index.php",
    "pcv_companion": "../ext/questionable_decisions/index.php",
}

QD_SERVER_SOURCES = (
    "server/api.php",
    "server/connect.php",
    "server/index.php",
    "server/manifest.json",
    "server/runtime.php",
    "server/adapters/correspondence.php",
    "server/adapters/mp.php",
    "server/adapters/pcv.php",
    "server/adapters/runtime_support.php",
    "server/adapters/sas.php",
    "server/lib/auth.php",
    "server/lib/connect.php",
    "server/lib/dispatcher.php",
    "server/lib/journal.php",
    "server/lib/overlay_setup.php",
    "server/lib/protocol.php",
    "server/tools/provision.php",
)
PCV_COMPANION_SOURCES = (
    "pcv-companion/manifest.json",
    "pcv-companion/companion.php",
    "pcv-companion/globals.php",
    "pcv-companion/preprocessing.php",
)
PACKAGE_MANIFESTS = {
    "questionable_decisions": {
        "description": "Department server facade and supported plugin adapters.",
        "inner_manifest": "server/manifest.json",
        "sources": QD_SERVER_SOURCES,
    },
    "pcv_companion": {
        "description": "Department companion hooks for Private Conversation 0.1.23.",
        "inner_manifest": "pcv-companion/manifest.json",
        "sources": PCV_COMPANION_SOURCES,
    },
}

MO2_COMPONENT_PATHS = {
    name: f"CHIM/server-plugins/{name}/{VERSION}.dwpkg"
    for name in PACKAGE_MANIFESTS
}
MO2_FIXED_MEMBERS = {
    *UI_ASSETS.values(),
    DLL_MEMBER,
    INI_MEMBER,
    *(target for target, _ in REUSED_GAME_ASSETS.values()),
    *(f"NOTICE/{name}" for name in NOTICE_FILES),
    *MO2_COMPONENT_PATHS.values(),
}
COMPONENT_FILENAMES = {
    name: f"{name}-{VERSION}.dwpkg" for name in PACKAGE_MANIFESTS
}

SOURCE_ALLOWLIST_PATH = "packaging/public-source-allowlist.json"
REQUIRED_SOURCE_PATHS = {
    "packaging/build-installable-prealpha8.py",
    "packaging/tests/test_installable_prealpha8.py",
    "pcv-companion/manifest.json",
    *QD_SERVER_SOURCES,
    *PCV_COMPANION_SOURCES,
    PCV_SOURCE_MANIFEST,
    INI_SOURCE,
    *(f"{NOTICE_DIR}/{name}" for name in NOTICE_FILES),
    *UI_ASSETS,
}

ZIP_TIMESTAMP = (2026, 10, 23, 0, 0, 0)
SHA256_LINE = re.compile(r"\A([a-f0-9]{64})  ([^\r\n]+)\Z")


class PackageError(RuntimeError):
    """Raised when a source input or package archive violates the frozen contract."""


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def require_canonical_bytes(label: str, actual: bytes, expected: bytes) -> None:
    if actual != expected:
        raise PackageError(f"{label} differs from its committed source or pinned input")


def json_bytes(value: object) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def validate_member_name(name: str) -> None:
    if not name or "\\" in name or "\x00" in name:
        raise PackageError(f"unsafe archive member name: {name!r}")
    path = PurePosixPath(name)
    if path.is_absolute() or any(part in ("", ".", "..") for part in path.parts):
        raise PackageError(f"unsafe archive member name: {name!r}")
    if path.as_posix() != name:
        raise PackageError(f"non-canonical archive member name: {name!r}")


def stable_zip_bytes(files: Mapping[str, bytes]) -> bytes:
    buffer = BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name in sorted(files):
            validate_member_name(name)
            info = zipfile.ZipInfo(name, ZIP_TIMESTAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info, files[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return buffer.getvalue()


def read_zip_bytes(data: bytes, label: str) -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    try:
        with zipfile.ZipFile(BytesIO(data), "r") as archive:
            for info in archive.infolist():
                if info.is_dir():
                    continue
                mode = (info.external_attr >> 16) & 0xFFFF
                if stat.S_ISLNK(mode):
                    raise PackageError(f"{label} contains a symbolic link: {info.filename}")
                validate_member_name(info.filename)
                if info.filename in result:
                    raise PackageError(f"{label} has duplicate member {info.filename}")
                result[info.filename] = archive.read(info)
    except (OSError, zipfile.BadZipFile, RuntimeError) as error:
        raise PackageError(f"cannot read {label}: {error}") from error
    return result


def make_checksums(files: Mapping[str, bytes]) -> bytes:
    lines = [f"{sha256(files[name])}  {name}" for name in sorted(files)]
    return ("\n".join(lines) + "\n").encode("ascii")


def parse_checksums(data: bytes, label: str) -> dict[str, str]:
    try:
        text = data.decode("ascii")
    except UnicodeDecodeError as error:
        raise PackageError(f"{label} checksum file is not ASCII") from error
    records: dict[str, str] = {}
    for line in text.splitlines():
        match = SHA256_LINE.fullmatch(line)
        if match is None:
            raise PackageError(f"{label} has a malformed checksum line")
        digest, name = match.groups()
        validate_member_name(name)
        if name in records:
            raise PackageError(f"{label} lists a duplicate checksum path {name}")
        records[name] = digest
    return records


def validate_inner_manifest(name: str, data: bytes) -> dict[str, object]:
    try:
        manifest = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError(f"{name} inner manifest is invalid JSON") from error
    if not isinstance(manifest, dict):
        raise PackageError(f"{name} inner manifest must be an object")
    if manifest.get("name") != name or manifest.get("version") != VERSION:
        raise PackageError(f"{name} inner manifest identity/version mismatch")
    if manifest.get("schema_version") != 2:
        raise PackageError(f"{name} inner manifest must use schema 2")
    if manifest.get("config_url") != PACKAGE_CONFIG_URL[name]:
        raise PackageError(f"{name} inner manifest config_url does not target the Department setup page")
    return manifest


def build_component_bytes(name: str, server_files: Mapping[str, bytes]) -> bytes:
    if name not in PACKAGE_MANIFESTS:
        raise PackageError(f"unsupported component name {name!r}")
    normalized: dict[str, bytes] = {}
    for relative, data in server_files.items():
        validate_member_name(relative)
        if relative in normalized:
            raise PackageError(f"duplicate server component path {relative}")
        normalized[f"server/{relative}"] = data
    inner = normalized.get("server/manifest.json")
    if inner is None:
        raise PackageError(f"{name} package has no server/manifest.json")
    validate_inner_manifest(name, inner)
    outer_manifest = {
        "schema_version": 4,
        "name": name,
        "version": VERSION,
        "description": PACKAGE_MANIFESTS[name]["description"],
        "server": {"mutable_paths": []},
    }
    files: dict[str, bytes] = {"manifest.json": json_bytes(outer_manifest), **normalized}
    files["checksums.sha256"] = make_checksums(files)
    package = stable_zip_bytes(files)
    audit_component_bytes(package, name)
    return package


def audit_component_bytes(data: bytes, expected_name: str) -> dict[str, bytes]:
    files = read_zip_bytes(data, expected_name)
    if "manifest.json" not in files or "checksums.sha256" not in files:
        raise PackageError(f"{expected_name} package is missing its outer manifest/checksums")
    try:
        manifest = json.loads(files["manifest.json"].decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError(f"{expected_name} outer manifest is invalid JSON") from error
    if not isinstance(manifest, dict) or manifest.get("schema_version") != 4:
        raise PackageError(f"{expected_name} outer manifest must use schema 4")
    if manifest.get("name") != expected_name or manifest.get("version") != VERSION:
        raise PackageError(f"{expected_name} outer manifest identity/version mismatch")
    server = manifest.get("server")
    if not isinstance(server, dict) or server.get("mutable_paths") != []:
        raise PackageError(f"{expected_name} must declare server.mutable_paths as an empty list")
    expected_members = {"manifest.json", "checksums.sha256"}
    expected_members.update(name for name in files if name.startswith("server/"))
    if set(files) != expected_members or "server/manifest.json" not in files:
        raise PackageError(f"{expected_name} package has members outside the schema-4 server layout")
    inner = validate_inner_manifest(expected_name, files["server/manifest.json"])
    checksums = parse_checksums(files["checksums.sha256"], expected_name)
    payload = {name: content for name, content in files.items() if name != "checksums.sha256"}
    if set(checksums) != set(payload):
        raise PackageError(f"{expected_name} checksum paths do not cover the exact archive contents")
    if any(checksums[name] != sha256(content) for name, content in payload.items()):
        raise PackageError(f"{expected_name} contains a checksum mismatch")
    if expected_name == "pcv_companion" and "server/setup/pcv-overlay" in files:
        raise PackageError("PCV overlay must be staged under the Department plugin only")
    if expected_name == "questionable_decisions":
        stage = "server/setup/pcv-overlay/"
        staged = {name[len(stage):] for name in files if name.startswith(stage)}
        if staged != {"source-manifest.json", *PCV_FILE_POSTIMAGES}:
            raise PackageError("Department package does not contain the exact seven-file PCV setup stage")
    return files


def audit_ini(data: bytes) -> None:
    try:
        text = data.decode("utf-8-sig")
    except UnicodeDecodeError as error:
        raise PackageError("shipped INI is not UTF-8") from error
    section = ""
    values: dict[tuple[str, str], str] = {}
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line or line.startswith((";", "#")):
            continue
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip().casefold()
            continue
        if "=" in line:
            key, value = line.split("=", 1)
            values[(section, key.strip().casefold())] = value.strip()
    if values.get(("server", "baseurl"), None) != "" or values.get(("server", "pairtoken"), None) != "":
        raise PackageError("shipped Server.BaseUrl and Server.PairToken must be blank")
    if values.get(("input", "openkeycode"), None) != "-1":
        raise PackageError("shipped INI does not match the approved blank configuration template")


def audit_mo2_bytes(data: bytes) -> dict[str, bytes]:
    files = read_zip_bytes(data, "MO2 archive")
    if set(files) != MO2_FIXED_MEMBERS:
        missing = sorted(MO2_FIXED_MEMBERS - set(files))
        extra = sorted(set(files) - MO2_FIXED_MEMBERS)
        raise PackageError(f"MO2 member set mismatch; missing={missing}, extra={extra}")
    if any(Path(name).name.casefold() == "aiagent.dll" for name in files):
        raise PackageError("MO2 archive must not contain AIAgent.dll")
    if any("private_conversation" in name.casefold() and name.endswith(".dwpkg") for name in files):
        raise PackageError("MO2 archive must not include a private_conversation DWPkg")
    if {name.removeprefix("NOTICE/") for name in files if name.startswith("NOTICE/")} != NOTICE_FILES:
        raise PackageError("MO2 archive does not include exactly the seven required notices")
    audit_ini(files[INI_MEMBER])
    for component, member in MO2_COMPONENT_PATHS.items():
        audit_component_bytes(files[member], component)
    return files


def git_output(git_executable: str, args: list[str]) -> bytes:
    command = [git_executable, "-c", f"safe.directory={ROOT}", *args]
    result = subprocess.run(command, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
    if result.returncode != 0:
        detail = result.stderr.decode("utf-8", "replace").strip()
        raise PackageError(f"git command failed ({' '.join(args)}): {detail}")
    return result.stdout


def canonical_source_commit(git_executable: str, commit: str) -> str:
    if re.fullmatch(r"[0-9a-fA-F]{40}", commit) is None:
        raise PackageError("--source-commit must be a full 40-character commit ID")
    canonical = git_output(git_executable, ["rev-parse", "--verify", f"{commit}^{{commit}}"]).decode("ascii").strip().lower()
    head = git_output(git_executable, ["rev-parse", "--verify", "HEAD"]).decode("ascii").strip().lower()
    if canonical != commit.lower() or head != canonical:
        raise PackageError("source commit must be the exact current HEAD")
    return canonical


def git_blob(git_executable: str, commit: str, relative: str) -> bytes:
    validate_member_name(relative)
    return git_output(git_executable, ["cat-file", "blob", f"{commit}:{relative}"])


def source_allowlist(git_executable: str, commit: str) -> list[str]:
    raw = git_blob(git_executable, commit, SOURCE_ALLOWLIST_PATH)
    try:
        document = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError("committed source allowlist is invalid JSON") from error
    if not isinstance(document, dict) or document.get("schemaVersion") != 1 or document.get("kind") != "explicit-public-source-allowlist":
        raise PackageError("committed source allowlist has an unsupported format")
    entries = document.get("files")
    if not isinstance(entries, list):
        raise PackageError("committed source allowlist has no files list")
    paths: list[str] = []
    for entry in entries:
        if not isinstance(entry, dict) or not isinstance(entry.get("path"), str):
            raise PackageError("committed source allowlist has a malformed entry")
        path = entry["path"]
        validate_member_name(path)
        paths.append(path)
    if len(paths) != len(set(paths)):
        raise PackageError("committed source allowlist contains duplicate paths")
    missing = sorted(REQUIRED_SOURCE_PATHS - set(paths))
    if missing:
        raise PackageError(f"source allowlist is missing required release files: {missing}")
    return paths


def committed_worktree_file(git_executable: str, commit: str, allowed: set[str], relative: str) -> bytes:
    if relative not in allowed:
        raise PackageError(f"package source {relative} is not in the committed public-source allowlist")
    path = ROOT.joinpath(*PurePosixPath(relative).parts)
    if path.is_symlink() or not path.is_file():
        raise PackageError(f"package source is missing or symlinked: {relative}")
    committed = git_blob(git_executable, commit, relative)
    working = path.read_bytes()
    if working != committed:
        raise PackageError(f"working-tree source differs from the selected commit: {relative}")
    return committed


def validate_overlay_stage(read_source) -> tuple[bytes, dict[str, bytes]]:
    manifest_bytes = read_source(PCV_SOURCE_MANIFEST)
    try:
        manifest = json.loads(manifest_bytes.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError("PCV source manifest is invalid JSON") from error
    if not isinstance(manifest, dict) or manifest.get("schemaVersion") != 1:
        raise PackageError("PCV source manifest has an unsupported schema")
    if manifest.get("upstream") != PCV_UPSTREAM or manifest.get("stagedSourceDirectory") != PCV_BASE_SOURCE or manifest.get("installDirectory") != "ext/private_conversation":
        raise PackageError("PCV source manifest does not match the approved 0.1.23 owner pin")
    entries = manifest.get("files")
    if not isinstance(entries, list) or [item.get("path") for item in entries if isinstance(item, dict)] != list(PCV_FILE_POSTIMAGES):
        raise PackageError("PCV source manifest file list does not match the approved seven files")
    sources: dict[str, bytes] = {}
    for entry in entries:
        if not isinstance(entry, dict):
            raise PackageError("PCV source manifest has a malformed file entry")
        relative = entry.get("path")
        if relative not in PCV_FILE_POSTIMAGES:
            raise PackageError("PCV source manifest contains an unapproved PCV file")
        data = read_source(f"{PCV_BASE_SOURCE}/{relative}")
        if (sha256(data) != PCV_FILE_POSTIMAGES[relative]
                or entry.get("baseSha256") != PCV_FILE_BASES[relative]
                or entry.get("postimageSha256") != PCV_FILE_POSTIMAGES[relative]):
            raise PackageError(f"PCV overlay postimage mismatch: {relative}")
        if not isinstance(entry.get("baseSha256"), str) or re.fullmatch(r"[a-f0-9]{64}", entry["baseSha256"]) is None:
            raise PackageError(f"PCV overlay base pin is malformed: {relative}")
        if entry.get("modified") is not (entry["baseSha256"] != entry["postimageSha256"]):
            raise PackageError(f"PCV overlay modified flag mismatch: {relative}")
        sources[relative] = data
    if len(sources) != 7:
        raise PackageError("PCV overlay stage must have exactly seven source files")
    return manifest_bytes, sources


def build_source_zip(git_executable: str, commit: str, allowlist: list[str]) -> bytes:
    files = {path: git_blob(git_executable, commit, path) for path in allowlist}
    return stable_zip_bytes(files)


def audit_source_zip(data: bytes, git_executable: str, commit: str, allowlist: list[str]) -> None:
    files = read_zip_bytes(data, "source ZIP")
    if set(files) != set(allowlist):
        raise PackageError("source ZIP does not match the exact committed allowlist")
    for path in allowlist:
        if files[path] != git_blob(git_executable, commit, path):
            raise PackageError(f"source ZIP bytes differ from the selected commit: {path}")


def build_server_component(name: str, read_source) -> bytes:
    spec = PACKAGE_MANIFESTS[name]
    files: dict[str, bytes] = {}
    for source in spec["sources"]:
        relative = source.split("/", 1)[1]
        files[relative] = read_source(source)
    if name == "questionable_decisions":
        manifest_bytes, overlay_files = validate_overlay_stage(read_source)
        files["setup/pcv-overlay/source-manifest.json"] = manifest_bytes
        for relative, data in overlay_files.items():
            files[f"setup/pcv-overlay/{relative}"] = data
    return build_component_bytes(name, files)


def validate_dll(data: bytes) -> None:
    if len(data) < 0x40 or data[:2] != b"MZ":
        raise PackageError("DLL input is not a valid PE image")
    offset = int.from_bytes(data[0x3C:0x40], "little")
    if offset < 0x40 or offset + 6 > len(data) or data[offset:offset + 4] != b"PE\0\0":
        raise PackageError("DLL input has no PE signature")
    if int.from_bytes(data[offset + 4:offset + 6], "little") != 0x8664:
        raise PackageError("DLL input is not an x64 binary")
    if sha256(data) == OLD_DLL_SHA256:
        raise PackageError("refusing the reused prealpha.7 DLL; pass the new prealpha.8 build")


def build_mod_zip(files: Mapping[str, bytes]) -> bytes:
    if set(files) != MO2_FIXED_MEMBERS:
        raise PackageError("internal MO2 file map does not match the exact root layout")
    return stable_zip_bytes(files)


def audit_checksums_file(data: bytes, expected: Mapping[str, bytes]) -> None:
    checksums = parse_checksums(data, "release")
    if set(checksums) != set(expected):
        raise PackageError("release checksum manifest does not cover the exact release outputs")
    if any(checksums[name] != sha256(content) for name, content in expected.items()):
        raise PackageError("release checksum manifest contains a digest mismatch")


def expected_output_files(output: Path) -> dict[str, bytes]:
    if not output.is_dir():
        raise PackageError("output directory does not exist")
    expected_names = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, CHECKSUMS_NAME, COMPONENT_DIR}
    actual_names = {item.name for item in output.iterdir()}
    if actual_names != expected_names:
        raise PackageError(f"unexpected build output entries: missing={sorted(expected_names - actual_names)}, extra={sorted(actual_names - expected_names)}")
    component_root = output / COMPONENT_DIR
    if component_root.is_symlink() or not component_root.is_dir():
        raise PackageError("component output directory is missing or unsafe")
    expected_components = set(COMPONENT_FILENAMES.values())
    if {item.name for item in component_root.iterdir()} != expected_components:
        raise PackageError("component output directory contains unexpected files")
    for item in output.iterdir():
        if item.is_symlink():
            raise PackageError(f"symlink in output directory: {item.name}")
    for item in component_root.iterdir():
        if item.is_symlink() or not item.is_file():
            raise PackageError(f"unsafe component output: {item.name}")
    return {
        OUTPUT_MOD_ZIP: (output / OUTPUT_MOD_ZIP).read_bytes(),
        OUTPUT_SOURCE_ZIP: (output / OUTPUT_SOURCE_ZIP).read_bytes(),
        **{f"{COMPONENT_DIR}/{filename}": (component_root / filename).read_bytes() for filename in COMPONENT_FILENAMES.values()},
    }


def audit_output(output: Path, git_executable: str, source_commit: str) -> None:
    commit = canonical_source_commit(git_executable, source_commit)
    allowlist = source_allowlist(git_executable, commit)
    outputs = expected_output_files(output)
    mod = audit_mo2_bytes(outputs[OUTPUT_MOD_ZIP])
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return committed_worktree_file(git_executable, commit, allowed, relative)

    for source, member in UI_ASSETS.items():
        require_canonical_bytes(member, mod[member], read_source(source))
    require_canonical_bytes(INI_MEMBER, mod[INI_MEMBER], read_source(INI_SOURCE))
    for notice in NOTICE_FILES:
        member = f"NOTICE/{notice}"
        require_canonical_bytes(member, mod[member], read_source(f"{NOTICE_DIR}/{notice}"))
    for source, (member, digest) in REUSED_GAME_ASSETS.items():
        if sha256(mod[member]) != digest:
            raise PackageError(f"reused prealpha.7 asset does not match its pinned digest: {source}")
    validate_dll(mod[DLL_MEMBER])

    for name, member in MO2_COMPONENT_PATHS.items():
        standalone_name = f"{COMPONENT_DIR}/{COMPONENT_FILENAMES[name]}"
        if mod[member] != outputs[standalone_name]:
            raise PackageError(f"embedded {name} DWPkg differs from its standalone audit copy")
        audit_component_bytes(outputs[standalone_name], name)
        canonical_component = build_server_component(name, read_source)
        require_canonical_bytes(f"{name} DWPkg", outputs[standalone_name], canonical_component)
    audit_source_zip(outputs[OUTPUT_SOURCE_ZIP], git_executable, commit, allowlist)
    checksum_inputs = {name: outputs[name] for name in PUBLIC_CHECKSUM_NAMES}
    audit_checksums_file((output / CHECKSUMS_NAME).read_bytes(), checksum_inputs)


def collect_package_files(git_executable: str, commit: str, allowlist: list[str], dll_path: Path, old_game_zip: Path) -> tuple[dict[str, bytes], dict[str, bytes]]:
    allowed = set(allowlist)

    def read_source(relative: str) -> bytes:
        return committed_worktree_file(git_executable, commit, allowed, relative)

    old_data = old_game_zip.read_bytes()
    if sha256(old_data) != OLD_GAME_ZIP_SHA256:
        raise PackageError("prealpha.7 game-data ZIP does not match the verified base archive")
    old_files = read_zip_bytes(old_data, "prealpha.7 game-data ZIP")
    if set(old_files) != OLD_GAME_MEMBERS:
        raise PackageError("prealpha.7 game-data ZIP has unexpected members")

    mo2_files: dict[str, bytes] = {}
    for old_name, (target_name, expected_hash) in REUSED_GAME_ASSETS.items():
        content = old_files[old_name]
        if sha256(content) != expected_hash:
            raise PackageError(f"prealpha.7 reused game asset changed: {old_name}")
        mo2_files[target_name] = content

    for source, target in UI_ASSETS.items():
        mo2_files[target] = read_source(source)
    ini = read_source(INI_SOURCE)
    audit_ini(ini)
    mo2_files[INI_MEMBER] = ini
    dll_data = dll_path.read_bytes()
    validate_dll(dll_data)
    mo2_files[DLL_MEMBER] = dll_data

    for name in NOTICE_FILES:
        mo2_files[f"NOTICE/{name}"] = read_source(f"{NOTICE_DIR}/{name}")

    components: dict[str, bytes] = {}
    for name in PACKAGE_MANIFESTS:
        package = build_server_component(name, read_source)
        components[COMPONENT_FILENAMES[name]] = package
        mo2_files[MO2_COMPONENT_PATHS[name]] = package

    if set(mo2_files) != MO2_FIXED_MEMBERS:
        raise PackageError("assembled MO2 payload is incomplete")
    audit_mo2_bytes(build_mod_zip(mo2_files))
    return mo2_files, components


def build(git_executable: str, source_commit: str, dll_path: Path, old_game_zip: Path, output: Path) -> None:
    commit = canonical_source_commit(git_executable, source_commit)
    allowlist = source_allowlist(git_executable, commit)
    if output.exists():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    if resolved_output == ROOT or ROOT in resolved_output.parents:
        raise PackageError("release outputs must be outside the source repository")
    if dll_path.is_symlink() or not dll_path.is_file() or old_game_zip.is_symlink() or not old_game_zip.is_file():
        raise PackageError("DLL and verified base game ZIP inputs must be regular files")

    output.parent.mkdir(parents=True, exist_ok=True)
    stage_path = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        mo2_files, components = collect_package_files(git_executable, commit, allowlist, dll_path, old_game_zip)
        mod_zip = build_mod_zip(mo2_files)
        source_zip = build_source_zip(git_executable, commit, allowlist)
        public_artifacts = {
            OUTPUT_MOD_ZIP: mod_zip,
            OUTPUT_SOURCE_ZIP: source_zip,
        }
        artifacts = {
            **public_artifacts,
            **{f"{COMPONENT_DIR}/{name}": data for name, data in components.items()},
        }
        for name, data in artifacts.items():
            target = stage_path.joinpath(*PurePosixPath(name).parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        (stage_path / CHECKSUMS_NAME).write_bytes(make_checksums(public_artifacts))
        audit_output(stage_path, git_executable, commit)
        os.rename(stage_path, output)
    except Exception:
        if stage_path.exists():
            shutil.rmtree(stage_path)
        raise


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    build_parser = subparsers.add_parser("build", help="build the MO2 archive and matching DWPkgs")
    build_parser.add_argument("--dll", required=True, type=Path, help="new x64 Department DLL from the reviewed native build")
    build_parser.add_argument("--prealpha7-game-zip", required=True, type=Path, help="verified prealpha.7 game-data ZIP; only ESP/SEQ/PEX are reused")
    build_parser.add_argument("--source-commit", required=True, help="full commit ID at current HEAD")
    build_parser.add_argument("--output-dir", required=True, type=Path, help="new output directory outside the repository")
    build_parser.add_argument("--git", default="git", help="git executable")
    audit_parser = subparsers.add_parser("audit", help="reopen and audit a completed output directory")
    audit_parser.add_argument("--source-commit", required=True, help="full commit ID at current HEAD")
    audit_parser.add_argument("--output-dir", required=True, type=Path)
    audit_parser.add_argument("--git", default="git", help="git executable")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    try:
        if args.command == "build":
            build(args.git, args.source_commit, args.dll, args.prealpha7_game_zip, args.output_dir.resolve())
            print(f"Built and audited: {args.output_dir.resolve()}")
        else:
            audit_output(args.output_dir.resolve(), args.git, args.source_commit)
            print(f"Audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
