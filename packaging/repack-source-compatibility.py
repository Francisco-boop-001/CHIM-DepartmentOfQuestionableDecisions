#!/usr/bin/env python3
"""Build a deterministic source-only prealpha.7 owner compatibility release."""

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
BASE_TAG = "v0.1.0-prealpha.6"
BASE_SOURCE_COMMIT = "1c50d3eb9fd07ad8ebae95629964488bb315a29d"
CANDIDATE_TAG = "v0.1.0-prealpha.7"
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
COMPONENT_NAMES = (GAME, SERVER, SOURCE, NOTICES)
SUM_ORDER = (GUIDE, CANDIDATE, GAME, NOTICES, SERVER, SOURCE, FREEZE, MANIFEST, RECEIPTS)
OUTPUT_NAMES = (*SUM_ORDER, SUMS)
BASE_RECEIPT_PATHS = {
    "source-readiness.json",
    "historical-prealpha5/input-freeze.json",
    "historical-prealpha5/source-readiness.json",
    "historical-prealpha5/historical-prealpha4/input-freeze.json",
    *(
        f"historical-prealpha5/historical-prealpha4/evidence/companion-only/accepted-prealpha4-20261021/{name}"
        for name in ("critique.json", "native.json", "private-route.json", "runtime-release.json", "ui.json")
    ),
}
PCV_OVERLAY_FILES = (
    "installation_guard.php",
    "log.php",
    "manifest.json",
    "preprocessing.php",
    "reflection.php",
    "scope.php",
    "state.php",
)
PCV_MANIFEST = "packaging/companion-only/pcv-0.1.23-source-manifest.json"
PCV_MANIFEST_MEMBER = "docs/pcv-0.1.23-source-manifest.json"
OLD_PCV_MANIFEST = "docs/pcv-0.1.22-source-manifest.json"
PCV_PROVENANCE = "packaging/companion-only/PCV-PROVENANCE.md"
PCV_PROVENANCE_MEMBER = "docs/PCV-PROVENANCE.md"
PCV_OWNER_TAG = "private_conversation-v0.1.23"
PCV_OWNER_COMMIT = "77265202d7f4a47b88e1eb88131b21f4b0752020"
OWNER_VERSIONS = {
    "pcv": "0.1.23",
    "sas": "0.1.12",
    "correspondence": "0.1.0-dev.22",
}
OWNER_PIN_COUNTS = {"pcv": 7, "sas": 6, "correspondence": 13}
OWNER_CHECKS = {
    "pcv": (
        "ownerPositiveLoad",
        "tamperedSourceRefused",
        "actualReaderShape",
        "wrapUpSceneDirection",
        "includedPlayerFallback",
        "lockedOwnerCallbacks",
        "privateTextboxTokenRoute",
        "ownerRevisionChangesPreserved",
    ),
    "sas": ("adapterLoaded", "manifestReady", "sourceIdentityReady", "readerLoaded", "perPinDriftRefused", "unsupportedVersionRefused"),
    "correspondence": ("adapterLoaded", "manifestReady", "sourceIdentityReady", "runtimeReady", "stagedClosureMatches"),
}
ADAPTER_SOURCE_BY_OWNER = {
    "pcv": "server/adapters/pcv.php",
    "sas": "server/adapters/sas.php",
    "correspondence": "server/adapters/correspondence.php",
}
SERVER_SOURCE_MAP = {
    "docs/PCV-PROVENANCE.md": PCV_PROVENANCE,
    PCV_MANIFEST_MEMBER: PCV_MANIFEST,
    "ext/pcv_companion/companion.php": "pcv-companion/companion.php",
    "ext/pcv_companion/globals.php": "pcv-companion/globals.php",
    "ext/pcv_companion/preprocessing.php": "pcv-companion/preprocessing.php",
    **{
        f"ext/private_conversation/{name}": f"pcv-companion/private-conversation-v0.1.23/server/{name}"
        for name in PCV_OVERLAY_FILES
    },
    "ext/questionable_decisions/adapters/correspondence.php": "server/adapters/correspondence.php",
    "ext/questionable_decisions/adapters/mp.php": "server/adapters/mp.php",
    "ext/questionable_decisions/adapters/pcv.php": "server/adapters/pcv.php",
    "ext/questionable_decisions/adapters/runtime_support.php": "server/adapters/runtime_support.php",
    "ext/questionable_decisions/adapters/sas.php": "server/adapters/sas.php",
    "ext/questionable_decisions/api.php": "server/api.php",
    "ext/questionable_decisions/lib/auth.php": "server/lib/auth.php",
    "ext/questionable_decisions/lib/dispatcher.php": "server/lib/dispatcher.php",
    "ext/questionable_decisions/lib/journal.php": "server/lib/journal.php",
    "ext/questionable_decisions/lib/protocol.php": "server/lib/protocol.php",
    "ext/questionable_decisions/runtime.php": "server/runtime.php",
    "ext/questionable_decisions/tools/provision.php": "server/tools/provision.php",
}
SERVER_SOURCE_TARGETS = {source: target for target, source in SERVER_SOURCE_MAP.items()}


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


def validate_limits(limits, label, runtime_key="ownerRuntimeLoaded"):
    require(
        isinstance(limits, dict)
        and limits.get("sourceOnly") is True
        and all(limits.get(name) is False for name in ("database", "http", "skyrim", runtime_key)),
        f"{label} exceeds the source-only boundary",
    )


def validate_owner_readiness(name, receipt, source_blobs):
    require(
        isinstance(receipt, dict)
        and receipt.get("ready") is True
        and receipt.get("errorReason") is None,
        f"{name} source readiness is not ready",
    )
    owner = receipt.get("owner")
    require(isinstance(owner, dict) and owner.get("version") == OWNER_VERSIONS[name], f"Readiness receipt is not for {name} {OWNER_VERSIONS[name]}")
    pins = owner.get("selectedClosurePins", [])
    require(isinstance(pins, list) and len(pins) == OWNER_PIN_COUNTS[name], f"{name} readiness has an unexpected closure pin count")
    pin_files = []
    for pin in pins:
        require(
            isinstance(pin, dict)
            and isinstance(pin.get("file"), str)
            and isinstance(pin.get("expectedSha256"), str)
            and re.fullmatch(r"[0-9a-f]{64}", pin["expectedSha256"]) is not None
            and pin.get("expectedSha256") == pin.get("actualSha256")
            and pin.get("matches") is True,
            f"{name} readiness contains an unmatched or malformed source pin",
        )
        safe_member(pin["file"])
        pin_files.append(pin["file"])
    require(len(pin_files) == len(set(pin_files)), f"{name} readiness contains duplicate source pins")

    checks = receipt.get("checks")
    expected_checks = OWNER_CHECKS[name]
    require(
        isinstance(checks, dict)
        and set(checks) == set(expected_checks)
        and all(value is True for value in checks.values()),
        f"{name} readiness does not pass its focused source checks",
    )
    validate_limits(
        receipt.get("limits", {}),
        f"{name} readiness",
        "runtime" if name == "pcv" else "ownerRuntimeLoaded",
    )

    adapter = receipt.get("department", {}).get("adapter", {})
    source = ADAPTER_SOURCE_BY_OWNER[name]
    require(
        isinstance(adapter, dict)
        and adapter.get("file") == f"adapters/{name}.php"
        and source in source_blobs
        and adapter.get("sha256") == sha256(source_blobs[source]),
        f"{name} adapter hash does not match the committed source",
    )
    if name == "correspondence":
        protocol = receipt.get("department", {}).get("protocol", {})
        protocol_source = "server/lib/protocol.php"
        require(
            isinstance(protocol, dict)
            and protocol.get("file") == "lib/protocol.php"
            and protocol.get("sha256") == sha256(source_blobs[protocol_source]),
            "Correspondence readiness protocol hash does not match committed source",
        )
    return owner, checks


def validate_current_readiness(readiness, source_blobs):
    require(
        isinstance(readiness, dict)
        and readiness.get("ready") is True
        and ("errorReason" not in readiness or readiness.get("errorReason") is None)
        and {"ready", "owners", "department", "limits"}.issubset(readiness),
        "Combined owner source readiness is not ready or has an unexpected shape",
    )
    require(
        set(readiness) in (
            {"ready", "owners", "department", "limits"},
            {"ready", "errorReason", "owners", "department", "limits"},
        ),
        "Combined owner source readiness has unexpected top-level fields",
    )
    owners = readiness.get("owners", {})
    require(isinstance(owners, dict) and set(owners) == set(OWNER_VERSIONS), "Combined readiness has an unexpected owner set")
    owner_details = {
        name: validate_owner_readiness(name, owners[name], source_blobs)
        for name in OWNER_VERSIONS
    }

    department = readiness.get("department", {})
    require(isinstance(department, dict), "Combined readiness department record is malformed")
    adapters = department.get("adapters", {})
    expected_adapter_paths = {f"adapters/{name}.php" for name in OWNER_VERSIONS}
    require(isinstance(adapters, dict) and set(adapters) == expected_adapter_paths, "Combined readiness has an unexpected adapter hash set")
    for name, source in ADAPTER_SOURCE_BY_OWNER.items():
        path = f"adapters/{name}.php"
        require(adapters[path] == sha256(source_blobs[source]), f"Combined adapter hash does not match {source}")

    runtime_files = department.get("runtimeFiles", {})
    require(isinstance(runtime_files, dict) and runtime_files, "Combined readiness lacks changed runtime source hashes")
    for path, digest in runtime_files.items():
        require(path in SERVER_SOURCE_TARGETS, f"Source path is not in the explicit server map: {path}")
        require(
            isinstance(digest, str)
            and re.fullmatch(r"[0-9a-f]{64}", digest) is not None
            and path in source_blobs
            and digest == sha256(source_blobs[path]),
            f"Runtime source hash does not match the committed source: {path}",
        )
    limits = readiness.get("limits", {})
    validate_limits(limits, "Combined readiness")
    require(
        set(limits) == {"sourceOnly", "database", "http", "skyrim", "ownerRuntimeLoaded"},
        "Combined readiness has an unexpected limit set",
    )
    return owners, owner_details, adapters, runtime_files, limits


def validate_pcv_manifest(source_blobs, base_server_members, pcv_receipt):
    require(PCV_MANIFEST in source_blobs, "The PCV 0.1.23 source manifest is missing")
    manifest = json.loads(source_blobs[PCV_MANIFEST].decode("utf-8"))
    upstream = manifest.get("upstream", {})
    require(
        manifest.get("schemaVersion") == 1
        and upstream.get("version") == OWNER_VERSIONS["pcv"]
        and upstream.get("tag") == PCV_OWNER_TAG
        and upstream.get("commit") == PCV_OWNER_COMMIT
        and manifest.get("stagedSourceDirectory") == "pcv-companion/private-conversation-v0.1.23/server"
        and manifest.get("installDirectory") == "ext/private_conversation",
        "PCV source manifest does not identify the pinned 0.1.23 overlay",
    )
    entries = manifest.get("files")
    require(isinstance(entries, list) and [entry.get("path") for entry in entries] == list(PCV_OVERLAY_FILES), "PCV source manifest has an unexpected overlay file set or order")
    pins = {
        pin["file"]: pin
        for pin in pcv_receipt["owner"]["selectedClosurePins"]
    }
    require(set(pins) == set(PCV_OVERLAY_FILES), "PCV readiness pins do not match the seven-file overlay")
    for entry in entries:
        name = entry["path"]
        base_hash = entry.get("baseSha256")
        postimage_hash = entry.get("postimageSha256")
        modified = entry.get("modified")
        require(
            isinstance(base_hash, str)
            and re.fullmatch(r"[0-9a-f]{64}", base_hash) is not None
            and isinstance(postimage_hash, str)
            and re.fullmatch(r"[0-9a-f]{64}", postimage_hash) is not None
            and isinstance(modified, bool)
            and modified is (base_hash != postimage_hash),
            f"PCV manifest hashes or modified flag are malformed for {name}",
        )
        staged_path = f"pcv-companion/private-conversation-v0.1.23/server/{name}"
        target_path = f"ext/private_conversation/{name}"
        require(staged_path in source_blobs, f"PCV staged overlay source is missing: {staged_path}")
        require(sha256(source_blobs[staged_path]) == postimage_hash, f"PCV overlay postimage does not match committed source: {name}")
        require(
            pins[name].get("expectedSha256") == postimage_hash
            and pins[name].get("actualSha256") == postimage_hash,
            f"PCV readiness pin does not match the manifest postimage: {name}",
        )
        require(target_path in base_server_members, f"Base server archive lacks the PCV overlay target: {target_path}")
    return manifest


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
    require(output.parent.is_dir(), "--output parent must already exist")
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

    expected_base_files = set(OUTPUT_NAMES)
    base_entries = list(base.iterdir())
    require(all(not path.is_symlink() and path.is_file() for path in base_entries), "Base assets directory may contain only regular files")
    require({path.name for path in base_entries} == expected_base_files, "Unexpected prealpha.6 asset set")
    base_files = {path.name: path.read_bytes() for path in base_entries}

    base_sums = parse_base_checksums(base_files[SUMS])
    for name, digest in base_sums.items():
        require(sha256(base_files[name]) == digest, f"Base asset hash mismatch: {name}")

    base_manifest = json.loads(base_files[MANIFEST].decode("utf-8"))
    base_freeze = json.loads(base_files[FREEZE].decode("utf-8"))
    require(base_manifest.get("schemaVersion") == 1, "Unsupported base manifest schema")
    require(
        base_manifest.get("kind") == KIND
        and base_manifest.get("candidateTag") == BASE_TAG
        and base_manifest.get("source", {}).get("commit") == BASE_SOURCE_COMMIT,
        "Base manifest is not the pinned prealpha.6 source release",
    )
    require(
        base_freeze.get("candidateTag") == BASE_TAG
        and base_freeze.get("source", {}).get("commit") == BASE_SOURCE_COMMIT,
        "Base freeze is not the pinned prealpha.6 source release",
    )
    require(base_manifest.get("freeze", {}).get("sha256") == sha256(base_files[FREEZE]), "Base manifest freeze hash mismatch")
    old_components = {item.get("name"): item for item in base_manifest.get("components", [])}
    require(set(old_components) == set(COMPONENT_NAMES), "Unexpected prealpha.6 component manifest")
    base_members = {}
    for name in COMPONENT_NAMES:
        record = old_components[name]
        data = base_files[name]
        require(record.get("bytes") == len(data) and record.get("sha256") == sha256(data), f"Base component hash mismatch: {name}")
        members = zip_members(data, name)
        require(record.get("memberCount") == len(members), f"Base component member-count mismatch: {name}")
        expected_members = {member.get("path"): member for member in record.get("members", [])}
        require(len(expected_members) == len(record.get("members", [])), f"Duplicate manifest member: {name}")
        require(set(expected_members) == {member for member, _payload, _mode in members}, f"Base component member set mismatch: {name}")
        for member, payload, _mode in members:
            expected = expected_members[member]
            require(expected.get("bytes") == len(payload) and expected.get("sha256") == sha256(payload), f"Base member hash mismatch: {name}:{member}")
        base_members[name] = members

    old_receipts = zip_members(base_files[RECEIPTS], RECEIPTS)
    old_receipt_map = {name: payload for name, payload, _mode in old_receipts}
    require(set(old_receipt_map) == BASE_RECEIPT_PATHS, "Unexpected prealpha.6 receipt member set")
    base_readiness = base_manifest.get("sourceReadiness", {})
    require(
        base_readiness.get("path") == "source-readiness.json"
        and base_readiness.get("sha256") == sha256(old_receipt_map["source-readiness.json"])
        and base_readiness.get("ownerVersion") == "0.1.30",
        "Prealpha.6 source-readiness provenance mismatch",
    )
    base_history = base_manifest.get("historicalEvidence", {})
    require(
        base_history.get("tag") == "v0.1.0-prealpha.5"
        and base_history.get("pathPrefix") == "historical-prealpha5/"
        and base_history.get("receiptMemberCount") == 7
        and base_history.get("certifiesCandidate") is False,
        "Prealpha.6 historical evidence provenance mismatch",
    )

    base_server_map = {name: payload for name, payload, _mode in base_members[SERVER]}
    expected_base_server_names = set(SERVER_SOURCE_MAP)
    expected_base_server_names.remove(PCV_MANIFEST_MEMBER)
    expected_base_server_names.add(OLD_PCV_MANIFEST)
    require(set(base_server_map) == expected_base_server_names, "Unexpected prealpha.6 server archive member set")

    source_records = committed_tree(repo, commit)
    source_blobs = committed_blobs(repo, source_records)
    allowlist = json.loads(source_blobs["packaging/public-source-allowlist.json"].decode("utf-8"))
    allowlisted = [entry.get("path") for entry in allowlist.get("files", [])]
    require(allowlist.get("kind") == "explicit-public-source-allowlist", "Unexpected public source allowlist")
    require(len(allowlisted) == len(set(allowlisted)), "Public source allowlist contains duplicates")
    require(set(allowlisted) == {path for path, _mode, _oid in source_records}, "Allowlist does not exactly cover the committed tracked tree")
    require(
        len(SERVER_SOURCE_MAP) == len(set(SERVER_SOURCE_MAP.values()))
        and set(SERVER_SOURCE_MAP.values()).issubset(source_blobs),
        "Explicit server source map is incomplete or maps a source more than once",
    )

    readiness_bytes = regular_file(readiness_path, "Combined readiness receipt")
    readiness = json.loads(readiness_bytes.decode("utf-8"))
    owners, owner_details, adapter_hashes, runtime_hashes, limits = validate_current_readiness(readiness, source_blobs)
    validate_pcv_manifest(source_blobs, base_server_map, owners["pcv"])

    server_members = []
    for base_name, _old_payload, mode in base_members[SERVER]:
        target = PCV_MANIFEST_MEMBER if base_name == OLD_PCV_MANIFEST else base_name
        require(target in SERVER_SOURCE_MAP, f"Base server member is absent from the explicit current source map: {target}")
        source_path = SERVER_SOURCE_MAP[target]
        server_members.append((target, source_blobs[source_path], mode))
    require({name for name, _payload, _mode in server_members} == set(SERVER_SOURCE_MAP), "Current server mapping changed the exact member set")
    require(
        source_blobs["server/adapters/mp.php"] == base_server_map["ext/questionable_decisions/adapters/mp.php"],
        "The MP 0.1.30 adapter must remain byte-identical to the prealpha.6 server component",
    )

    old_server_map = {name: payload for name, payload, _mode in base_members[SERVER]}
    new_server_map = {name: payload for name, payload, _mode in server_members}
    added = sorted(set(new_server_map) - set(old_server_map))
    removed = sorted(set(old_server_map) - set(new_server_map))
    changed = sorted(
        name
        for name in set(old_server_map) & set(new_server_map)
        if old_server_map[name] != new_server_map[name]
    )
    expected_added = [PCV_MANIFEST_MEMBER]
    expected_removed = [OLD_PCV_MANIFEST]
    expected_changed = {
        PCV_PROVENANCE_MEMBER,
        "ext/pcv_companion/companion.php",
        "ext/private_conversation/manifest.json",
        "ext/private_conversation/preprocessing.php",
        "ext/private_conversation/scope.php",
        "ext/private_conversation/state.php",
        "ext/questionable_decisions/adapters/correspondence.php",
        "ext/questionable_decisions/adapters/pcv.php",
        "ext/questionable_decisions/adapters/sas.php",
    }
    require(added == expected_added and removed == expected_removed, "Server manifest rename differs from the exact PCV 0.1.23 source map")
    require(set(changed) == expected_changed, "Server payload delta contains an unexpected changed or missing member")
    changed_runtime_sources = {
        SERVER_SOURCE_MAP[path]
        for path in changed
        if path.startswith("ext/")
    }
    require(
        changed_runtime_sources.issubset(runtime_hashes),
        "Combined readiness does not bind every changed installed production source",
    )
    require(
        all(adapter_hashes[f"adapters/{name}.php"] == sha256(source_blobs[ADAPTER_SOURCE_BY_OWNER[name]]) for name in OWNER_VERSIONS),
        "A Department adapter is not bound to the combined readiness receipt",
    )

    source_members = [
        (path, source_blobs[path], 0o755 if mode == b"100755" else 0o644)
        for path, mode, _oid in source_records
    ]
    source_archive = make_zip(source_members)
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
    require(game_dll is not None and sha256(game_dll) == "1c6d0a0370a2f9e8133deef574be2f2ecc0ee7ac1065e9eedddd7cc8554aea13", "Game DLL identity differs from the verified prealpha.6 input")
    require(
        all(new_component_bytes[name] == base_files[name] for name in (GAME, NOTICES)),
        "Unchanged component archive hash assertion failed",
    )

    current_receipt_names = {
        "pcv": "pcv-source-readiness.json",
        "sas": "sas-source-readiness.json",
        "correspondence": "correspondence-source-readiness.json",
    }
    current_receipt_payloads = {
        "current-source-readiness.json": readiness_bytes,
        **{
            current_receipt_names[name]: json_bytes(owners[name])
            for name in OWNER_VERSIONS
        },
    }
    historical_members = [
        ("historical-prealpha6/input-freeze.json", base_files[FREEZE], 0o644),
        *(
            (f"historical-prealpha6/{name}", payload, mode)
            for name, payload, mode in old_receipts
        ),
    ]
    receipts_archive = make_zip(
        sorted(
            [
                *historical_members,
                *((name, payload, 0o644) for name, payload in current_receipt_payloads.items()),
            ],
            key=lambda item: item[0],
        )
    )

    base_asset_hashes = {name: sha256(base_files[name]) for name in SUM_ORDER}
    source_file_hashes = {
        path: {"bytes": len(source_blobs[path]), "sha256": sha256(source_blobs[path])}
        for path, _mode, _oid in source_records
    }
    readiness_hash = sha256(readiness_bytes)
    source_commit_time = git_run(repo, ["show", "-s", "--format=%cI", commit]).decode("ascii").strip()
    guide = source_blobs["packaging/COMPANION-ONLY-TEST-PACKAGE.md"]
    owner_readiness = {}
    for name in OWNER_VERSIONS:
        owner, checks = owner_details[name]
        receipt_path = current_receipt_names[name]
        owner_readiness[name] = {
            "path": receipt_path,
            "sha256": sha256(current_receipt_payloads[receipt_path]),
            "version": owner["version"],
            "closurePins": {"matched": len(owner["selectedClosurePins"]), "total": len(owner["selectedClosurePins"])},
            "checks": checks,
            "limits": owners[name]["limits"],
        }
    source_readiness = {
        "path": "current-source-readiness.json",
        "sha256": readiness_hash,
        "owners": owner_readiness,
        "department": {
            "adapters": adapter_hashes,
            "runtimeFiles": runtime_hashes,
        },
        "limits": limits,
    }
    limitations = [
        "Current owner readiness is source-only. The focused PCV, SAS, and Correspondence receipts bind exact source pins and Department adapter hashes; no database, HTTP/provider, Skyrim, native, or gameplay acceptance is claimed.",
        "The Mind Poisoning 0.1.30 adapter remains byte-identical to prealpha.6; its earlier source-readiness receipt is preserved only as historical evidence.",
        "The prealpha.6 freeze and its nine receipt members are retained byte-for-byte beneath historical-prealpha6/; their prior prealpha.5 history remains nested and is not recertified.",
        "The game-data and notices archives, including the DLL, are reused byte-for-byte from the verified prealpha.6 download.",
        "The PCV 0.1.23 seven-file overlay is not a complete owner release. Install the exact complete owner release first and verify every manifest base hash before applying the overlay.",
    ]
    source_summary = {
        "commit": commit,
        "commitTime": source_commit_time,
        "allowlist": "packaging/public-source-allowlist.json",
        "fileCount": len(source_records),
        "files": source_file_hashes,
    }
    server_delta = {
        "changed": [
            {"path": path, "sourcePath": SERVER_SOURCE_MAP[path]}
            for path in changed
        ],
        "added": [
            {"path": path, "sourcePath": SERVER_SOURCE_MAP[path]}
            for path in added
        ],
        "removed": [{"path": path} for path in removed],
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
            "pathPrefix": "historical-prealpha6/",
            "freezePath": "historical-prealpha6/input-freeze.json",
            "receiptMemberCount": len(old_receipts),
            "readinessPath": "historical-prealpha6/source-readiness.json",
            "certifiesCandidate": False,
        },
        "inputs": {
            "guide": {"sourcePath": "packaging/COMPANION-ONLY-TEST-PACKAGE.md", "sha256": sha256(guide)},
            "serverDelta": server_delta,
            "serverSourceMap": SERVER_SOURCE_MAP,
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

    require([name for name, _payload, _mode in zip_members(source_archive, SOURCE)] == [path for path, _mode, _oid in source_records], "Source archive order or tree differs from the committed allowlist")
    generated_server = zip_members(server_archive, SERVER)
    require([name for name, _payload, _mode in generated_server] == [name for name, _payload, _mode in server_members], "Server archive member order changed")
    require(
        [(name, sha256(payload)) for name, payload, _mode in generated_server]
        == [(name, sha256(payload)) for name, payload, _mode in server_members],
        "Generated server archive member bytes differ from the explicit source map",
    )
    generated_candidate = {name: payload for name, payload, _mode in zip_members(candidate_archive, CANDIDATE)}
    require(set(generated_candidate) == {name for name, _payload, _mode in candidate_members}, "Candidate archive member set mismatch")
    require(all(generated_candidate[name] == data for name, data, _mode in candidate_members), "Candidate archive contains mismatched assets")
    generated_receipts = {name: payload for name, payload, _mode in zip_members(receipts_archive, RECEIPTS)}
    historical_expected = {
        "historical-prealpha6/input-freeze.json",
        *(f"historical-prealpha6/{name}" for name in BASE_RECEIPT_PATHS),
    }
    require(
        set(generated_receipts) == historical_expected | set(current_receipt_payloads),
        "Historical/current receipt member set differs from the 14-member evidence contract",
    )
    require(
        all(generated_receipts[name] == payload for name, payload in current_receipt_payloads.items()),
        "Current source readiness evidence changed in the receipt archive",
    )
    require(generated_receipts["historical-prealpha6/input-freeze.json"] == base_files[FREEZE], "Prealpha.6 freeze evidence changed in the receipt archive")
    require(
        all(generated_receipts[f"historical-prealpha6/{name}"] == payload for name, payload, _mode in old_receipts),
        "Historical receipt payloads changed in the receipt archive",
    )

    output.mkdir(exist_ok=True)
    for name in OUTPUT_NAMES:
        with (output / name).open("xb") as file:
            file.write(assets[name])
    require({path.name for path in output.iterdir()} == set(OUTPUT_NAMES), "Written release asset set differs from the checksum manifest")
    for name in OUTPUT_NAMES:
        require(sha256((output / name).read_bytes()) == sha256(assets[name]), f"Written asset self-check failed: {name}")
    print(f"Prepared {len(OUTPUT_NAMES)} deterministic prealpha.7 assets from source {commit}.")
    print("Source-only readiness: PCV 0.1.23, SAS 0.1.12, and Sealed Fates dev.22 receipts validated.")
    print(f"Server payload delta: {len(changed)} changed, {len(added)} added, {len(removed)} removed; MP 0.1.30, game, and notices remain byte-identical.")

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-assets", required=True, help="verified prealpha.6 public downloads directory")
    parser.add_argument("--source-commit", required=True, help="full committed Git SHA-1 for the exact source tree")
    parser.add_argument("--readiness", required=True, help="combined source-only PCV/SAS/Correspondence readiness receipt JSON")
    parser.add_argument("--output", required=True, help="new absent or empty release asset directory")
    args = parser.parse_args()
    try:
        run(args)
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError, zipfile.BadZipFile, subprocess.SubprocessError) as error:
        parser.exit(2, f"error: {error}\n")


if __name__ == "__main__":
    main()
