#!/usr/bin/env python3
"""Build a deterministic source-only dev.21 compatibility release from pinned inputs."""

import argparse
import hashlib
import io
import json
import os
import re
import shutil
import stat
import subprocess
import zipfile
from pathlib import Path


KIND = "department-source-compatibility-repack"
BASE_TAG = "v0.1.0-prealpha.4"
BASE_SOURCE_COMMIT = "08680e31635a4ed91bd9efcb46d7d9b8f30d95e1"
CANDIDATE_TAG = "v0.1.0-prealpha.5"
GUIDE = "PRE-ALPHA-RELEASE.md"
FREEZE = "input-freeze.json"
MANIFEST = "package-manifest.json"
RECEIPTS = "verification-receipts.zip"
SUMS = "SHA256SUMS.txt"
GAME = "WorldOfDramaLlama-department-pre-alpha-game-data.zip"
SERVER = "WorldOfDramaLlama-department-pre-alpha-server.zip"
SOURCE = "WorldOfDramaLlama-department-pre-alpha-source.zip"
NOTICES = "WorldOfDramaLlama-department-pre-alpha-notices.zip"
CANDIDATE = "WorldOfDramaLlama-department-pre-alpha-candidate.zip"
PAIRING_SIDECAR = "Department-SealedFates-dev20-pairing.zip"
COMPONENT_NAMES = (GAME, SERVER, SOURCE, NOTICES)
SUM_ORDER = (GUIDE, CANDIDATE, GAME, NOTICES, SERVER, SOURCE, FREEZE, MANIFEST, RECEIPTS)
OUTPUT_NAMES = (*SUM_ORDER, SUMS)
SERVER_ADAPTER = "ext/questionable_decisions/adapters/correspondence.php"
SOURCE_ADAPTER = "server/adapters/correspondence.php"
PROTOCOL_SOURCE = "server/lib/protocol.php"
OLD_GATE_MEMBERS = {
    "critique": "critique.json",
    "native": "native.json",
    "privateRoute": "private-route.json",
    "runtimeRelease": "runtime-release.json",
    "ui": "ui.json",
}
READINESS_CHECKS = (
    "adapterLoaded",
    "manifestReady",
    "sourceIdentityReady",
    "runtimeReady",
    "stagedClosureMatches",
)


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def json_bytes(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def safe_member(name):
    require(
        isinstance(name, str)
        and name
        and not name.startswith("/")
        and "\\" not in name
        and ":" not in name
        and not name.endswith("/")
        and all(part not in ("", ".", "..") for part in name.split("/")),
        f"Unsafe archive path: {name!r}",
    )


def zip_members(data, label):
    with zipfile.ZipFile(io.BytesIO(data), "r") as archive:
        infos = archive.infolist()
        names = [info.filename for info in infos]
        require(len(names) == len(set(names)), f"{label} contains duplicate paths")
        require(len(names) == len({name.casefold() for name in names}), f"{label} has Windows path collisions")
        members = []
        for info in infos:
            safe_member(info.filename)
            mode = info.external_attr >> 16
            require(not stat.S_ISLNK(mode), f"{label} contains a symbolic link: {info.filename}")
            require(not info.is_dir(), f"{label} contains a directory entry: {info.filename}")
            members.append((info.filename, archive.read(info), mode & 0o777 or 0o644))
        return members


def make_zip(members):
    result = io.BytesIO()
    with zipfile.ZipFile(result, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        archive.comment = b""
        for name, data, mode in members:
            safe_member(name)
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | (mode & 0o777)) << 16
            info.extra = b""
            info.comment = b""
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return result.getvalue()


def regular_file(path, label):
    require(not path.is_symlink() and path.is_file(), f"{label} must be a regular file: {path}")
    return path.read_bytes()


def parse_base_checksums(data):
    require(b"\r" not in data and data.endswith(b"\n"), "Base SHA256SUMS.txt must use LF and end with one newline")
    rows = {}
    for line in data.decode("ascii").splitlines():
        match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9][A-Za-z0-9._-]*)", line)
        require(match is not None, f"Invalid base checksum line: {line!r}")
        digest, name = match.groups()
        require(name not in rows, f"Duplicate base checksum entry: {name}")
        rows[name] = digest
    require(set(rows) == set(SUM_ORDER), "Unexpected base checksum asset set")
    return rows


def component_record(name, data, members):
    return {
        "name": name,
        "bytes": len(data),
        "sha256": sha256(data),
        "memberCount": len(members),
        "members": [
            {"path": member, "bytes": len(payload), "sha256": sha256(payload)}
            for member, payload, _mode in members
        ],
    }


def git_run(repo, args, input_bytes=None):
    git_exe = shutil.which("git") or (r"C:\Program Files\Git\cmd\git.exe" if os.name == "nt" else "git")
    safe_repo = str(repo).replace("\\", "/")
    result = subprocess.run(
        [git_exe, "-c", f"safe.directory={safe_repo}", "-C", str(repo), *args],
        input=input_bytes,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if result.returncode:
        raise ValueError(f"Git command failed ({' '.join(args)}): {result.stderr.decode('utf-8', 'replace').strip()}")
    return result.stdout


def committed_tree(repo, commit):
    raw = git_run(repo, ["ls-tree", "-r", "-z", "--full-tree", commit])
    records = []
    for item in raw.split(b"\0"):
        if not item:
            continue
        header, path_bytes = item.split(b"\t", 1)
        mode, kind, oid = header.split()
        path = path_bytes.decode("utf-8", "strict")
        safe_member(path)
        require(kind == b"blob" and mode in (b"100644", b"100755"), f"Unsupported tracked entry: {path}")
        records.append((path, mode, oid))
    require(len(records) == len({path for path, _mode, _oid in records}), "Git tree contains duplicate paths")
    require(len(records) == len({path.casefold() for path, _mode, _oid in records}), "Git tree has Windows path collisions")
    return sorted(records, key=lambda row: row[0])


def committed_blobs(repo, records):
    request = b"".join(oid + b"\n" for _path, _mode, oid in records)
    raw = git_run(repo, ["cat-file", "--batch"], request)
    cursor = 0
    blobs = {}
    for path, _mode, oid in records:
        end = raw.find(b"\n", cursor)
        require(end >= 0, f"Missing Git blob header for {path}")
        header = raw[cursor:end].split()
        require(len(header) == 3 and header[0] == oid and header[1] == b"blob", f"Unexpected Git object for {path}")
        size = int(header[2])
        cursor = end + 1
        payload = raw[cursor : cursor + size]
        require(len(payload) == size and raw[cursor + size : cursor + size + 1] == b"\n", f"Truncated Git blob: {path}")
        blobs[path] = payload
        cursor += size + 1
    require(cursor == len(raw), "Unexpected trailing data from git cat-file")
    return blobs


def run(args):
    repo = Path(__file__).resolve().parents[1]
    require((repo / "packaging" / "public-source-allowlist.json").is_file(), "Cannot locate the repository allowlist")
    require(re.fullmatch(r"[0-9a-f]{40}", args.source_commit) is not None, "--source-commit must be a full lowercase Git SHA-1")
    commit = git_run(repo, ["rev-parse", "--verify", f"{args.source_commit}^{{commit}}"]).decode("ascii").strip()
    require(commit == args.source_commit, "Resolved source commit differs from --source-commit")

    base_arg = Path(args.base_assets).absolute()
    readiness_arg = Path(args.readiness).absolute()
    output_arg = Path(args.output).absolute()
    require(not base_arg.is_symlink(), "--base-assets cannot be a symlink")
    require(not readiness_arg.is_symlink(), "--readiness cannot be a symlink")
    require(not output_arg.is_symlink(), "--output cannot be a symlink")
    base = base_arg.resolve()
    readiness_path = readiness_arg.resolve()
    output = output_arg.resolve()
    require(base.is_dir(), "--base-assets must be a normal directory")
    require(readiness_path.is_file(), "--readiness must be a regular JSON file")
    output_parent = output.parent
    require(output_parent.is_dir(), "--output parent must already exist")
    if output.exists():
        require(output.is_dir() and not any(output.iterdir()), "--output must be absent or empty")
    try:
        output.resolve().relative_to(repo)
    except ValueError:
        pass
    else:
        raise ValueError("--output must be outside the source worktree")
    resolved_output = output.resolve()
    require(
        not (resolved_output == base or resolved_output in base.parents or base in resolved_output.parents),
        "--output must be separate from the verified base assets directory",
    )
    require(resolved_output != readiness_path and resolved_output not in readiness_path.parents, "--output cannot contain or replace the readiness receipt")

    expected_base_files = set(OUTPUT_NAMES) | {PAIRING_SIDECAR}
    base_entries = list(base.iterdir())
    require(all(not p.is_symlink() and p.is_file() for p in base_entries), "Base assets directory may contain only regular files")
    base_names = {p.name for p in base_entries}
    require(base_names in (set(OUTPUT_NAMES), expected_base_files), "Unexpected prealpha.4 asset set")
    base_files = {p.name: p.read_bytes() for p in base_entries if p.name != PAIRING_SIDECAR}
    require(set(base_files) == set(OUTPUT_NAMES), "Missing a canonical prealpha.4 asset")

    base_sums = parse_base_checksums(base_files[SUMS])
    for name, digest in base_sums.items():
        require(sha256(base_files[name]) == digest, f"Base asset hash mismatch: {name}")

    base_manifest = json.loads(base_files[MANIFEST].decode("utf-8"))
    base_freeze = json.loads(base_files[FREEZE].decode("utf-8"))
    require(base_manifest.get("schemaVersion") == 1, "Unsupported base manifest schema")
    require(base_freeze.get("candidateTag") == BASE_TAG and base_freeze.get("testOnly") is True, "Base freeze is not prealpha.4")
    require(base_manifest.get("freeze", {}).get("sha256") == sha256(base_files[FREEZE]), "Base manifest freeze hash mismatch")
    old_components = {item.get("name"): item for item in base_manifest.get("components", [])}
    require(set(old_components) == set(COMPONENT_NAMES), "Unexpected prealpha.4 component manifest")
    base_members = {}
    for name in COMPONENT_NAMES:
        record = old_components[name]
        data = base_files[name]
        require(record.get("bytes") == len(data) and record.get("sha256") == sha256(data), f"Base component hash mismatch: {name}")
        members = zip_members(data, name)
        require(record.get("memberCount") == len(members), f"Base component member-count mismatch: {name}")
        expected_members = {m.get("path"): m for m in record.get("members", [])}
        require(len(expected_members) == len(record.get("members", [])), f"Duplicate manifest member: {name}")
        require(set(expected_members) == {member for member, _payload, _mode in members}, f"Base component member set mismatch: {name}")
        for member, payload, _mode in members:
            expected = expected_members[member]
            require(expected.get("bytes") == len(payload) and expected.get("sha256") == sha256(payload), f"Base member hash mismatch: {name}:{member}")
        base_members[name] = members

    old_receipts = zip_members(base_files[RECEIPTS], RECEIPTS)
    gate_hashes = base_manifest.get("gates", {})
    require(set(gate_hashes) == set(OLD_GATE_MEMBERS), "Unexpected historical prealpha.4 gate set")
    old_receipt_map = {name: payload for name, payload, _mode in old_receipts}
    require(len(old_receipt_map) == len(OLD_GATE_MEMBERS), "Unexpected historical receipt member count")
    for gate, filename in OLD_GATE_MEMBERS.items():
        path = f"evidence/companion-only/accepted-prealpha4-20261021/{filename}"
        require(path in old_receipt_map and gate_hashes[gate].get("sha256") == sha256(old_receipt_map[path]), f"Historical receipt hash mismatch: {gate}")

    source_records = committed_tree(repo, commit)
    source_blobs = committed_blobs(repo, source_records)
    allowlist = json.loads(source_blobs["packaging/public-source-allowlist.json"].decode("utf-8"))
    allowlisted = [entry.get("path") for entry in allowlist.get("files", [])]
    require(allowlist.get("kind") == "explicit-public-source-allowlist", "Unexpected public source allowlist")
    require(len(allowlisted) == len(set(allowlisted)), "Public source allowlist contains duplicates")
    require(set(allowlisted) == {path for path, _mode, _oid in source_records}, "Allowlist does not exactly cover the committed tracked tree")
    require(SOURCE_ADAPTER in source_blobs and PROTOCOL_SOURCE in source_blobs, "Required compatibility sources are missing")

    readiness_bytes = regular_file(readiness_path, "Readiness receipt")
    readiness = json.loads(readiness_bytes.decode("utf-8"))
    owner = readiness.get("owner", {})
    checks = readiness.get("checks", {})
    limits = readiness.get("limits", {})
    pins = owner.get("selectedClosurePins", [])
    require(readiness.get("ready") is True and readiness.get("errorReason") is None, "Source pairing readiness is not ready")
    require(owner.get("version") == "0.1.0-dev.21", "Readiness receipt is not for dev.21")
    require(len(pins) == 13 and all(pin.get("matches") is True and pin.get("expectedSha256") == pin.get("actualSha256") for pin in pins), "Readiness receipt does not contain 13 matched closure pins")
    require(set(checks) == set(READINESS_CHECKS) and all(checks[name] is True for name in READINESS_CHECKS), "Readiness receipt does not pass all five source checks")
    require(
        limits.get("sourceOnly") is True
        and all(limits.get(name) is False for name in ("database", "http", "skyrim", "ownerRuntimeLoaded")),
        "Readiness receipt exceeds its source-only boundary",
    )
    protocol = readiness.get("department", {}).get("protocol", {})
    adapter = readiness.get("department", {}).get("adapter", {})
    require(protocol.get("file") == "lib/protocol.php" and protocol.get("sha256") == sha256(source_blobs[PROTOCOL_SOURCE]), "Readiness protocol hash does not match committed source")
    require(adapter.get("file") == "adapters/correspondence.php" and adapter.get("sha256") == sha256(source_blobs[SOURCE_ADAPTER]), "Readiness adapter hash does not match committed source")

    source_members = [
        (path, source_blobs[path], 0o755 if mode == b"100755" else 0o644)
        for path, mode, _oid in source_records
    ]
    source_archive = make_zip(source_members)
    server_members = [
        (name, source_blobs[SOURCE_ADAPTER] if name == SERVER_ADAPTER else payload, mode)
        for name, payload, mode in base_members[SERVER]
    ]
    require({name for name, _payload, _mode in server_members} == {name for name, _payload, _mode in base_members[SERVER]}, "Server member set changed")
    server_archive = make_zip(server_members)
    new_component_bytes = {
        GAME: base_files[GAME],
        SERVER: server_archive,
        SOURCE: source_archive,
        NOTICES: base_files[NOTICES],
    }
    new_component_members = {
        GAME: base_members[GAME],
        SERVER: server_members,
        SOURCE: source_members,
        NOTICES: base_members[NOTICES],
    }
    require(len(new_component_members[GAME]) == 9, "Game archive must contain exactly nine members")
    game_dll = next((payload for name, payload, _mode in new_component_members[GAME] if name == "Data/SKSE/Plugins/WorldOfDramaLlama.dll"), None)
    require(game_dll is not None and sha256(game_dll) == "1c6d0a0370a2f9e8133deef574be2f2ecc0ee7ac1065e9eedddd7cc8554aea13", "Game DLL identity differs from the verified prealpha.4 input")
    server_delta = [
        name
        for name, old_payload, _mode in base_members[SERVER]
        if next(payload for new_name, payload, _new_mode in server_members if new_name == name) != old_payload
    ]
    require(server_delta == [SERVER_ADAPTER], f"Server payload delta must be only {SERVER_ADAPTER}")
    require(
        all(new_component_bytes[name] == base_files[name] for name in (GAME, NOTICES)),
        "Unchanged component archive hash assertion failed",
    )

    guide = source_blobs["packaging/COMPANION-ONLY-TEST-PACKAGE.md"]
    historical_members = [("historical-prealpha4/input-freeze.json", base_files[FREEZE], 0o644)]
    historical_members.extend((f"historical-prealpha4/{name}", payload, mode) for name, payload, mode in old_receipts)
    historical_members.append(("source-readiness.json", readiness_bytes, 0o644))
    receipts_archive = make_zip(sorted(historical_members, key=lambda item: item[0]))

    base_asset_hashes = {name: sha256(base_files[name]) for name in SUM_ORDER}
    source_file_hashes = {
        path: {"bytes": len(source_blobs[path]), "sha256": sha256(source_blobs[path])}
        for path, _mode, _oid in source_records
    }
    readiness_hash = sha256(readiness_bytes)
    source_commit_time = git_run(repo, ["show", "-s", "--format=%cI", commit]).decode("ascii").strip()
    source_readiness = {
        "path": "source-readiness.json",
        "sha256": readiness_hash,
        "ownerVersion": owner["version"],
        "closurePins": {"matched": len(pins), "total": len(pins)},
        "checks": checks,
        "limits": limits,
    }
    limitations = [
        "This package carries source-only dev.21 owner pairing evidence; no database, HTTP, Skyrim, owner-runtime, or gameplay execution is claimed.",
        "The historical prealpha.4 receipts and freeze are retained under historical-prealpha4/ for provenance and do not certify dev.21.",
        "The game-data archive, including the DLL, is reused byte-for-byte from the verified prealpha.4 download.",
    ]
    source_summary = {
        "commit": commit,
        "commitTime": source_commit_time,
        "allowlist": "packaging/public-source-allowlist.json",
        "fileCount": len(source_records),
        "files": source_file_hashes,
    }
    freeze_value = {
        "schemaVersion": 1,
        "status": "source-package-inputs-verified",
        "kind": KIND,
        "candidateTag": CANDIDATE_TAG,
        "source": source_summary,
        "baseRelease": {"tag": BASE_TAG, "sourceCommit": BASE_SOURCE_COMMIT, "assetSha256": base_asset_hashes},
        "sourceReadiness": source_readiness,
        "components": [
            {
                "name": name,
                "bytes": len(new_component_bytes[name]),
                "sha256": sha256(new_component_bytes[name]),
                "memberCount": len(new_component_members[name]),
            }
            for name in COMPONENT_NAMES
        ],
        "limitations": limitations,
    }
    freeze_bytes = json_bytes(freeze_value)
    components = [
        component_record(name, new_component_bytes[name], new_component_members[name])
        for name in COMPONENT_NAMES
    ]
    manifest_value = {
        "schemaVersion": 1,
        "status": "source-compatibility-repack",
        "kind": KIND,
        "candidateTag": CANDIDATE_TAG,
        "source": source_summary,
        "baseRelease": {"tag": BASE_TAG, "sourceCommit": BASE_SOURCE_COMMIT, "assetSha256": base_asset_hashes},
        "sourceReadiness": source_readiness,
        "freeze": {"name": FREEZE, "sha256": sha256(freeze_bytes)},
        "historicalEvidence": {
            "tag": BASE_TAG,
            "pathPrefix": "historical-prealpha4/",
            "gateReceiptCount": len(old_receipts),
            "certifiesCandidate": False,
        },
        "inputs": {
            "guide": {"sourcePath": "packaging/COMPANION-ONLY-TEST-PACKAGE.md", "sha256": sha256(guide)},
            "serverDelta": [SERVER_ADAPTER],
            "sourceTreeFileCount": len(source_records),
        },
        "components": components,
        "limitations": limitations,
    }
    manifest_bytes = json_bytes(manifest_value)
    candidate_members = [
        (GAME, new_component_bytes[GAME], 0o644),
        (NOTICES, new_component_bytes[NOTICES], 0o644),
        (SERVER, new_component_bytes[SERVER], 0o644),
        (SOURCE, new_component_bytes[SOURCE], 0o644),
        (GUIDE, guide, 0o644),
        (MANIFEST, manifest_bytes, 0o644),
    ]
    candidate_archive = make_zip(candidate_members)
    assets = {
        CANDIDATE: candidate_archive,
        GAME: new_component_bytes[GAME],
        SERVER: new_component_bytes[SERVER],
        SOURCE: new_component_bytes[SOURCE],
        NOTICES: new_component_bytes[NOTICES],
        GUIDE: guide,
        MANIFEST: manifest_bytes,
        FREEZE: freeze_bytes,
        RECEIPTS: receipts_archive,
    }
    require(set(assets) == set(SUM_ORDER), "Unexpected output asset set")
    sums_bytes = "".join(f"{sha256(assets[name])}  {name}\n" for name in SUM_ORDER).encode("ascii")
    assets[SUMS] = sums_bytes

    # Re-open the generated archives before writing any release files.
    require([name for name, _payload, _mode in zip_members(source_archive, SOURCE)] == [path for path, _mode, _oid in source_records], "Source archive order or tree differs from the committed allowlist")
    generated_server = zip_members(server_archive, SERVER)
    require([name for name, _payload, _mode in generated_server] == [name for name, _payload, _mode in base_members[SERVER]], "Server archive member order changed")
    require(
        [(name, sha256(payload)) for name, payload, _mode in generated_server]
        == [(name, sha256(payload)) for name, payload, _mode in server_members],
        "Generated server archive member bytes differ from the planned one-file delta",
    )
    generated_candidate = {name: payload for name, payload, _mode in zip_members(candidate_archive, CANDIDATE)}
    require(set(generated_candidate) == {name for name, _payload, _mode in candidate_members}, "Candidate archive member set mismatch")
    require(all(generated_candidate[name] == data for name, data, _mode in candidate_members), "Candidate archive contains mismatched assets")
    generated_receipts = {name: payload for name, payload, _mode in zip_members(receipts_archive, RECEIPTS)}
    require(generated_receipts["source-readiness.json"] == readiness_bytes, "Source readiness evidence changed in the receipt archive")
    require("historical-prealpha4/Department-SealedFates-dev20-pairing.zip" not in generated_receipts, "Dev.20 pairing sidecar must not be included as dev.21 evidence")

    output.mkdir(exist_ok=True)
    for name in OUTPUT_NAMES:
        with (output / name).open("xb") as file:
            file.write(assets[name])
    require({path.name for path in output.iterdir()} == set(OUTPUT_NAMES), "Written release asset set differs from the checksum manifest")
    for name in OUTPUT_NAMES:
        require(sha256((output / name).read_bytes()) == sha256(assets[name]), f"Written asset self-check failed: {name}")
    print(f"Prepared {len(OUTPUT_NAMES)} deterministic prealpha.5 assets from source {commit}.")
    print(f"Source-only readiness: dev.21, {len(pins)}/{len(pins)} closure pins, five checks passed.")
    print(f"Server payload delta: {SERVER_ADAPTER}; game and notices archives reused byte-for-byte.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-assets", required=True, help="verified prealpha.4 public downloads directory")
    parser.add_argument("--source-commit", required=True, help="full committed Git SHA-1 for the exact source tree")
    parser.add_argument("--readiness", required=True, help="source-only dev.21 pairing receipt JSON")
    parser.add_argument("--output", required=True, help="new absent or empty release asset directory")
    args = parser.parse_args()
    try:
        run(args)
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError, zipfile.BadZipFile, subprocess.SubprocessError) as error:
        parser.exit(2, f"error: {error}\n")


if __name__ == "__main__":
    main()
