#!/usr/bin/env python3
"""Build and audit the source-bound PCV25 prealpha.27 offline release."""

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


_PCV_PATH = Path(__file__).with_name("build-pcv25-offline-candidate.py")
_PCV_SPEC = importlib.util.spec_from_file_location("_department_pcv25_for_release27", _PCV_PATH)
if _PCV_SPEC is None or _PCV_SPEC.loader is None:
    raise RuntimeError("could not load the reviewed PCV25 candidate assembler")
PCV = importlib.util.module_from_spec(_PCV_SPEC)
_PCV_SPEC.loader.exec_module(PCV)

BASE = PCV.BASE
P26 = PCV.P26
PackageError = PCV.PackageError
ROOT = PCV.ROOT

VERSION = "0.1.0-prealpha.27"
PCV_VERSION = "0.1.0-prealpha.13"
BASELINE_VERSION = PCV.BASELINE_VERSION
BASELINE_SHA256 = PCV.BASELINE_SHA256
BASELINE_SOURCE_COMMIT = PCV.BASELINE_SOURCE_COMMIT
ROOT_CANDIDATE_AUDIT_SHA256 = "5bf0df9681aaf5970b65abe449103b8f7b1e2f287be51e73fe9965abe5f32df9"
REVIEWED_OFFLINE_CHECKS_SHA256 = "49612d44cbfd2549931e4c913274331702e06e49880c6c60b021e6c15ae4e4d9"
REVIEWED_RUNTIME_ZIP_SHA256 = "abb2fe3fb85859b26a26c251929a464e27c36d13179380e6ff491d15af2eb978"
REVIEWED_DPKG_SHA256 = "36009ede6fc39a0d409ff8929f3406ae6bfeb2729bcbd513e4689bd616a34d45"
REVIEWED_PCV_DPKG_SHA256 = "689474c970a60b991e40a5cf34e91ddc2c0699eb2842788e70232f35db8d68c4"

SERVER_MANIFEST = "server/manifest.json"
ALLOWLIST_PATH = "packaging/public-source-allowlist.json"
RELEASE_ALLOWLIST_ADDITIONS = (
    "docs/PCV25-RELEASE.md",
    "docs/superpowers/plans/2026-10-29-pcv25-release.md",
    "packaging/build-installable-prealpha27.py",
    "packaging/tests/test_installable_prealpha27.py",
)

REQUIRED_SOURCE_PATHS = frozenset({
    *PCV.CANDIDATE_SOURCE_PATHS,
    "README.md",
    "docs/BUILD.md",
    "docs/PCV25-RELEASE.md",
    "docs/superpowers/plans/2026-10-29-pcv25-release.md",
    "packaging/build-installable-prealpha27.py",
    "packaging/tests/test_installable_prealpha27.py",
    SERVER_MANIFEST,
    ALLOWLIST_PATH,
})

OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
OUTPUT_PROOF_ZIP = f"WorldOfDramaLlama-{VERSION}-offline-proof.zip"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, OUTPUT_PROOF_ZIP}
CHECKSUMS_NAME = "SHA256SUMS.txt"
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME}
ZIP_TIMESTAMP = (2026, 10, 29, 0, 0, 4)

_HEX_SHA256 = re.compile(r"\A[a-f0-9]{64}\Z")
_FULL_COMMIT = re.compile(r"\A[a-f0-9]{40}\Z")
_PRIVATE_PATH = re.compile(r"(?:[A-Za-z]:\\|/var/tmp/|/home/|\\\\Users\\)")


def _json_object(data: bytes, label: str) -> dict:
    try:
        value = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError(f"{label} is not valid UTF-8 JSON") from error
    if not isinstance(value, dict):
        raise PackageError(f"{label} must be a JSON object")
    return value


def _require_sha256(value: object, label: str) -> str:
    if not isinstance(value, str) or _HEX_SHA256.fullmatch(value) is None:
        raise PackageError(f"{label} must be a lowercase SHA-256 digest")
    return value


def _safe_json_input(data: bytes, label: str) -> dict:
    if _PRIVATE_PATH.search(data.decode("utf-8", "replace")):
        raise PackageError(f"{label} contains an absolute or machine-specific path")
    return _json_object(data, label)


def validate_review_inputs(candidate_audit_bytes: bytes, offline_checks_bytes: bytes) -> tuple[dict, dict]:
    if BASE.sha256(candidate_audit_bytes) != ROOT_CANDIDATE_AUDIT_SHA256:
        raise PackageError("reviewed candidate audit SHA-256 differs from the accepted receipt")
    if BASE.sha256(offline_checks_bytes) != REVIEWED_OFFLINE_CHECKS_SHA256:
        raise PackageError("offline checks receipt SHA-256 differs from the accepted receipt")

    candidate_audit = _safe_json_input(candidate_audit_bytes, "reviewed candidate audit")
    checks = _safe_json_input(offline_checks_bytes, "reviewed offline checks")
    if (candidate_audit.get("schemaVersion") != 1
            or candidate_audit.get("kind") != "department-pcv25-offline-assembled-candidate"):
        raise PackageError("reviewed candidate audit has an unsupported schema or kind")
    if candidate_audit.get("baseline") != {
        "version": BASELINE_VERSION,
        "sha256": BASELINE_SHA256,
        "sourceCommit": BASELINE_SOURCE_COMMIT,
    }:
        raise PackageError("reviewed candidate audit does not identify the exact published prealpha.26 baseline")
    candidate = candidate_audit.get("candidate")
    if (not isinstance(candidate, dict)
            or candidate.get("departmentVersion") != VERSION
            or candidate.get("pcvCompanionVersion") != PCV_VERSION
            or candidate.get("zipSha256") != REVIEWED_RUNTIME_ZIP_SHA256
            or candidate.get("departmentDwpkgSha256") != REVIEWED_DPKG_SHA256
            or candidate.get("pcvCompanionDwpkgSha256") != REVIEWED_PCV_DPKG_SHA256
            or candidate.get("memberCount") != 22
            or candidate.get("unchangedMemberCount") != 20):
        raise PackageError("reviewed candidate audit differs from the fixed PCV25 candidate receipt")
    owner = candidate_audit.get("owner")
    if (not isinstance(owner, dict)
            or owner.get("version") != PCV.OWNER_VERSION
            or owner.get("tag") != PCV.OWNER_TAG
            or owner.get("commit") != PCV.OWNER_COMMIT):
        raise PackageError("reviewed candidate audit does not bind the frozen PCV25 owner")
    source_hashes = candidate_audit.get("sourceHashes")
    if (not isinstance(source_hashes, dict)
            or set(source_hashes) != set(PCV.CANDIDATE_SOURCE_PATHS)):
        raise PackageError("reviewed candidate audit does not contain the exact 25-source closure")
    for path, digest in source_hashes.items():
        _require_sha256(digest, f"reviewed source hash for {path}")

    if (checks.get("schemaVersion") != 1
            or checks.get("kind") != "department-pcv25-reviewed-offline-checks"
            or checks.get("reviewedAuditSha256") != ROOT_CANDIDATE_AUDIT_SHA256
            or checks.get("reviewedRuntimeZipSha256") != REVIEWED_RUNTIME_ZIP_SHA256):
        raise PackageError("offline checks receipt is not bound to the accepted candidate audit and runtime ZIP")
    limits = checks.get("limits")
    if (not isinstance(limits, dict)
            or limits.get("authority") != "native-Windows-PHP-source-control-flow-and-package-only"
            or any(limits.get(name) is not False for name in (
                "database", "http", "provider", "nativeBuild", "skyrim", "installedGame", "server"
            ))):
        raise PackageError("offline checks receipt claims behavior outside its accepted scope")
    if not isinstance(checks.get("checks"), dict) or not isinstance(checks.get("originalProgramResults"), list):
        raise PackageError("offline checks receipt is missing its bounded check summary")
    return candidate_audit, checks


def validate_manifest_transition(old_bytes: bytes, release_bytes: bytes, expected_old_sha256: str) -> dict:
    expected_old_sha256 = _require_sha256(expected_old_sha256, "reviewed prealpha.26 server manifest hash")
    if BASE.sha256(old_bytes) != expected_old_sha256:
        raise PackageError("reviewed prealpha.26 server manifest hash differs from the accepted source audit")
    old = _json_object(old_bytes, "published prealpha.26 server manifest")
    release = _json_object(release_bytes, "release server manifest")
    expected = dict(old)
    if (old.get("version") != BASELINE_VERSION
            or release.get("version") != VERSION
            or list(release) != list(old)):
        raise PackageError("server manifest is not an exact version-only prealpha.26 to prealpha.27 change")
    expected["version"] = VERSION
    if release != expected or BASE.json_bytes(expected) != release_bytes:
        raise PackageError("server manifest is not an exact version-only prealpha.26 to prealpha.27 change")
    return {
        "baselineSha256": expected_old_sha256,
        "releaseSha256": BASE.sha256(release_bytes),
    }


def validate_allowlist_transition(
    release_bytes: bytes,
    expected_previous_sha256: str,
    baseline_bytes: bytes,
) -> dict:
    expected_previous_sha256 = _require_sha256(expected_previous_sha256, "reviewed prior allowlist hash")
    baseline = _json_object(baseline_bytes, "published prealpha.26 source allowlist")
    release = _json_object(release_bytes, "release source allowlist")
    if list(baseline) != ["schemaVersion", "kind", "files"] or list(release) != list(baseline):
        raise PackageError("source allowlist schema or top-level order changed")
    if any(release.get(key) != baseline.get(key) for key in ("schemaVersion", "kind")):
        raise PackageError("source allowlist schema differs from the published baseline")
    rows = release.get("files")
    if not isinstance(rows, list) or len(rows) < len(RELEASE_ALLOWLIST_ADDITIONS):
        raise PackageError("release source allowlist is incomplete")
    for row in rows:
        if (not isinstance(row, dict) or list(row) != ["path"]
                or not isinstance(row.get("path"), str)):
            raise PackageError("release source allowlist contains a malformed entry")
        BASE.validate_member_name(row["path"])
    paths = [row["path"] for row in rows]
    if len(paths) != len(set(paths)):
        raise PackageError("release source allowlist contains duplicate paths")
    suffix_rows = rows[-len(RELEASE_ALLOWLIST_ADDITIONS):]
    if suffix_rows != [{"path": path} for path in RELEASE_ALLOWLIST_ADDITIONS]:
        raise PackageError("source allowlist must preserve the old prefix and append the exact release-only suffix")
    lines = release_bytes.splitlines(keepends=True)
    if (len(lines) < len(RELEASE_ALLOWLIST_ADDITIONS) + 4
            or lines[-2].strip() != b"]" or lines[-1].strip() != b"}"):
        raise PackageError("release source allowlist does not retain its exact final JSON framing")
    suffix_start = len(lines) - 2 - len(RELEASE_ALLOWLIST_ADDITIONS)
    prefix_last_index = suffix_start - 1
    if prefix_last_index < 0:
        raise PackageError("release source allowlist has no preserved prior entries")
    entry_line = re.compile(rb'^    \{ "path": "([^"\\]+)" \}(,?)(\r\n|\n)\Z')
    previous_match = entry_line.fullmatch(lines[prefix_last_index])
    if (previous_match is None or previous_match.group(2) != b"," or not rows[:-len(RELEASE_ALLOWLIST_ADDITIONS)]
            or previous_match.group(1).decode("utf-8") != rows[-len(RELEASE_ALLOWLIST_ADDITIONS) - 1]["path"]):
        raise PackageError("release source allowlist cannot recover the exact previous prefix bytes")
    for index, expected_path in enumerate(RELEASE_ALLOWLIST_ADDITIONS):
        match = entry_line.fullmatch(lines[suffix_start + index])
        expected_comma = b"," if index < len(RELEASE_ALLOWLIST_ADDITIONS) - 1 else b""
        if (match is None or match.group(1).decode("utf-8") != expected_path
                or match.group(2) != expected_comma):
            raise PackageError("source allowlist must preserve the old prefix and append the exact release-only suffix")
    previous_last_line = (
        lines[prefix_last_index][:previous_match.start(2)]
        + previous_match.group(3)
    )
    prefix_bytes = b"".join(
        lines[:prefix_last_index] + [previous_last_line] + lines[-2:]
    )
    previous_doc = _json_object(prefix_bytes, "preserved prior source allowlist")
    if previous_doc.get("files") != rows[:-len(RELEASE_ALLOWLIST_ADDITIONS)]:
        raise PackageError("release source allowlist does not preserve the old prefix rows")
    prefix_sha256 = BASE.sha256(prefix_bytes)
    if prefix_sha256 != expected_previous_sha256:
        raise PackageError("source allowlist old prefix differs from the accepted candidate source audit")
    return {
        "previousPrefixSha256": prefix_sha256,
        "releaseSha256": BASE.sha256(release_bytes),
        "appendedPaths": list(RELEASE_ALLOWLIST_ADDITIONS),
    }


def validate_original_source_closure(
    candidate_audit: dict,
    read_current,
    old_manifest: bytes,
    baseline_allowlist: bytes,
) -> dict:
    source_hashes = candidate_audit.get("sourceHashes")
    if (not isinstance(source_hashes, dict)
            or set(source_hashes) != set(PCV.CANDIDATE_SOURCE_PATHS)):
        raise PackageError("accepted source audit does not contain the exact original 25-source closure")
    exceptions = {}
    exact_matches = 0
    for path in sorted(source_hashes):
        expected = _require_sha256(source_hashes[path], f"accepted source hash for {path}")
        current = read_current(path)
        if not isinstance(current, bytes):
            raise PackageError(f"selected source is not bytes: {path}")
        if path == SERVER_MANIFEST:
            exceptions[path] = validate_manifest_transition(old_manifest, current, expected)
        elif path == ALLOWLIST_PATH:
            exceptions[path] = validate_allowlist_transition(current, expected, baseline_allowlist)
        else:
            actual = BASE.sha256(current)
            if actual != expected:
                raise PackageError(f"reviewed source hash differs for {path}")
            exact_matches += 1
    if exact_matches != len(source_hashes) - 2 or set(exceptions) != {SERVER_MANIFEST, ALLOWLIST_PATH}:
        raise PackageError("reviewed source reconciliation did not have exactly two metadata exceptions")
    return {
        "reviewedSourceCount": len(source_hashes),
        "exactSourceMatches": exact_matches,
        "metadataExceptions": exceptions,
    }


def require_clean_source_tree(git: str) -> None:
    status = BASE.git_output(git, ["status", "--porcelain=v1", "--untracked-files=all"])
    if status:
        raise PackageError("release build requires a clean source tree")


def _safe_input(path: Path, label: str) -> bytes:
    return P26.P15._regular_file(path, label)


def _source_zip_files(source_zip: bytes, allowlist: list[str], source_files: dict[str, bytes]) -> None:
    files = BASE.read_zip_bytes(source_zip, "prealpha.27 source archive")
    if set(files) != set(allowlist) or set(source_files) != set(allowlist):
        raise PackageError("source archive does not match the exact committed public allowlist")
    for path in allowlist:
        if files[path] != source_files[path]:
            raise PackageError(f"source archive bytes differ from the selected source commit: {path}")


def _proof_zip(
    release_audit: dict,
    candidate_audit_bytes: bytes,
    offline_checks_bytes: bytes,
) -> bytes:
    files = {
        "offline-proof.json": BASE.json_bytes(release_audit),
        "reviewed-candidate-audit.json": candidate_audit_bytes,
        "reviewed-offline-checks.json": offline_checks_bytes,
        "package-provenance.json": BASE.json_bytes({
            "schemaVersion": 1,
            "kind": "department-prealpha27-release-provenance",
            "version": VERSION,
            "sourceCommit": release_audit["sourceCommit"],
            "runtimeZipSha256": release_audit["runtimeZipSha256"],
            "sourceArchiveSha256": release_audit["sourceArchiveSha256"],
            "departmentDwpkgSha256": release_audit["departmentDwpkgSha256"],
            "pcvCompanionDwpkgSha256": release_audit["pcvCompanionDwpkgSha256"],
            "reviewedCandidateAuditSha256": ROOT_CANDIDATE_AUDIT_SHA256,
            "reviewedOfflineChecksSha256": REVIEWED_OFFLINE_CHECKS_SHA256,
            "limits": release_audit["limits"],
        }),
    }
    original_timestamp = BASE.ZIP_TIMESTAMP
    BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP
    try:
        return BASE.stable_zip_bytes(files)
    finally:
        BASE.ZIP_TIMESTAMP = original_timestamp


def build_release_assets(
    baseline_zip: bytes,
    candidate_audit_bytes: bytes,
    offline_checks_bytes: bytes,
    source_commit: str,
    allowlist: list[str],
    source_files: dict[str, bytes],
    source_zip: bytes,
    old_manifest: bytes,
    baseline_allowlist: bytes,
) -> tuple[dict[str, bytes], dict]:
    candidate_audit, checks = validate_review_inputs(candidate_audit_bytes, offline_checks_bytes)
    if _FULL_COMMIT.fullmatch(source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    if BASE.sha256(baseline_zip) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact published prealpha.26 archive")
    if len(allowlist) != len(set(allowlist)) or set(REQUIRED_SOURCE_PATHS) - set(allowlist):
        raise PackageError("committed source allowlist is missing required release source paths")
    if set(source_files) != set(allowlist):
        raise PackageError("committed source bytes do not match the selected public allowlist")
    for path in allowlist:
        BASE.validate_member_name(path)
        if not isinstance(source_files[path], bytes):
            raise PackageError(f"committed source is not bytes: {path}")
    _source_zip_files(source_zip, allowlist, source_files)

    allowlist_bytes = source_files[ALLOWLIST_PATH]
    allowlist_doc = _json_object(allowlist_bytes, "release source allowlist")
    if [entry.get("path") for entry in allowlist_doc.get("files", []) if isinstance(entry, dict)] != allowlist:
        raise PackageError("committed source allowlist differs from its selected path list")
    if allowlist != [entry["path"] for entry in allowlist_doc["files"]]:
        raise PackageError("committed source allowlist has malformed entries")

    def read_current(path: str) -> bytes:
        try:
            return source_files[path]
        except KeyError as error:
            raise PackageError(f"selected committed source is missing: {path}") from error

    source_reconciliation = validate_original_source_closure(
        candidate_audit,
        read_current,
        old_manifest,
        baseline_allowlist,
    )

    def package_source(path: str) -> bytes:
        # The reviewed prealpha.26 assembler requires the original server manifest.
        # The release archive and audit continue to use the committed prealpha.27 bytes.
        if path == SERVER_MANIFEST:
            return old_manifest
        return read_current(path)

    runtime_zip, assembly_audit = PCV.build_candidate_bytes(baseline_zip, package_source)
    runtime_sha256 = BASE.sha256(runtime_zip)
    if (runtime_sha256 != REVIEWED_RUNTIME_ZIP_SHA256
            or runtime_sha256 != candidate_audit["candidate"]["zipSha256"]):
        raise PackageError("assembled runtime ZIP is not byte-identical to the reviewed PCV25 candidate")
    if (assembly_audit["candidate"]["departmentDwpkgSha256"] != REVIEWED_DPKG_SHA256
            or assembly_audit["candidate"]["pcvCompanionDwpkgSha256"] != REVIEWED_PCV_DPKG_SHA256
            or assembly_audit["candidate"]["unchangedMemberCount"] != 20):
        raise PackageError("assembled runtime component hashes differ from the reviewed candidate receipt")

    source_sha256 = BASE.sha256(source_zip)
    release_audit = {
        "schemaVersion": 1,
        "kind": "department-prealpha27-source-bound-offline-release",
        "version": VERSION,
        "sourceCommit": source_commit,
        "baseline": {
            "version": BASELINE_VERSION,
            "sha256": BASELINE_SHA256,
            "sourceCommit": BASELINE_SOURCE_COMMIT,
        },
        "runtimeZipSha256": runtime_sha256,
        "sourceArchiveSha256": source_sha256,
        "sourceAllowlistSha256": BASE.sha256(allowlist_bytes),
        "sourceFileCount": len(allowlist),
        "releaseSourceHashes": {
            path: BASE.sha256(source_files[path]) for path in sorted(REQUIRED_SOURCE_PATHS)
        },
        "reviewedCandidateAuditSha256": ROOT_CANDIDATE_AUDIT_SHA256,
        "reviewedOfflineChecksSha256": REVIEWED_OFFLINE_CHECKS_SHA256,
        "reviewedCandidateSourceReconciliation": source_reconciliation,
        "departmentDwpkgSha256": assembly_audit["candidate"]["departmentDwpkgSha256"],
        "pcvCompanionDwpkgSha256": assembly_audit["candidate"]["pcvCompanionDwpkgSha256"],
        "unchangedInstallableMembers": candidate_audit["candidate"]["unchangedMembers"],
        "offlineChecks": checks["checks"],
        "offlineAuthority": checks["limits"]["authority"],
        "limits": {
            "windowsSourceControlFlowAndPackageOnly": True,
            "inputFreezeFixed": False,
            "loggerPersistence": False,
            "posixFilesystemSafety": False,
            "taggedNativePacketRecursiveOrdering": False,
            "database": False,
            "http": False,
            "provider": False,
            "server": False,
            "nativeBuild": False,
            "skyrim": False,
            "installedGame": False,
            "live": False,
            "published": False,
        },
    }
    proof_zip = _proof_zip(release_audit, candidate_audit_bytes, offline_checks_bytes)
    assets = {
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        OUTPUT_PROOF_ZIP: proof_zip,
    }
    assets[CHECKSUMS_NAME] = BASE.make_checksums(assets)
    return assets, release_audit


def _inputs(args):
    source_commit = BASE.canonical_source_commit(args.git, args.source_commit)
    require_clean_source_tree(args.git)
    baseline_zip = _safe_input(args.prealpha26_zip, "published prealpha.26 ZIP")
    candidate_audit_bytes = _safe_input(args.candidate_audit, "accepted source audit")
    offline_checks_bytes = _safe_input(args.offline_checks, "reviewed offline checks")
    candidate_audit, _checks = validate_review_inputs(candidate_audit_bytes, offline_checks_bytes)
    allowlist = BASE.source_allowlist(args.git, source_commit)
    missing = sorted(REQUIRED_SOURCE_PATHS - set(allowlist))
    if missing:
        raise PackageError(f"source allowlist is missing prealpha.27 release paths: {', '.join(missing)}")
    source_files = {
        path: BASE.committed_worktree_file(args.git, source_commit, set(allowlist), path)
        for path in allowlist
    }
    old_manifest = BASE.git_blob(args.git, BASELINE_SOURCE_COMMIT, SERVER_MANIFEST)
    baseline_allowlist = BASE.git_blob(args.git, BASELINE_SOURCE_COMMIT, ALLOWLIST_PATH)
    source_zip = BASE.build_source_zip(args.git, source_commit, allowlist)
    return (
        source_commit,
        baseline_zip,
        candidate_audit_bytes,
        offline_checks_bytes,
        allowlist,
        source_files,
        source_zip,
        old_manifest,
        baseline_allowlist,
        candidate_audit,
    )


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("release output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if ({path.name for path in entries} != OUTPUT_FILE_NAMES
            or any(path.is_symlink() or not path.is_file() for path in entries)):
        raise PackageError("release output must contain only the installable, source, offline-proof ZIPs, and checksums")
    return {path.name: path.read_bytes() for path in entries}


def audit_output(args) -> None:
    inputs = _inputs(args)
    commit, baseline_zip, audit_bytes, checks_bytes, allowlist, sources, source_zip, old_manifest, baseline_allowlist, _ = inputs
    expected, _release_audit = build_release_assets(
        baseline_zip, audit_bytes, checks_bytes, commit, allowlist, sources,
        source_zip, old_manifest, baseline_allowlist,
    )
    if args.output_dir.is_symlink():
        raise PackageError("release output directory is missing or unsafe")
    actual = _expected_output_files(args.output_dir)
    for name in sorted(OUTPUT_FILE_NAMES):
        if actual[name] != expected[name]:
            raise PackageError(f"release output differs from the canonical source-bound build: {name}")
    BASE.audit_checksums_file(actual[CHECKSUMS_NAME], {name: actual[name] for name in OUTPUT_NAMES})


def _write_outputs(directory: Path, assets: dict[str, bytes]) -> None:
    if set(assets) != OUTPUT_FILE_NAMES:
        raise PackageError("prealpha.27 release output set is incomplete")
    for name, content in assets.items():
        BASE.validate_member_name(name)
        (directory / name).write_bytes(content)


def build(args) -> None:
    inputs = _inputs(args)
    commit, baseline_zip, audit_bytes, checks_bytes, allowlist, sources, source_zip, old_manifest, baseline_allowlist, _ = inputs
    assets, _release_audit = build_release_assets(
        baseline_zip, audit_bytes, checks_bytes, commit, allowlist, sources,
        source_zip, old_manifest, baseline_allowlist,
    )
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    resolved_output = output.resolve()
    resolved_root = ROOT.resolve()
    if resolved_output == resolved_root or resolved_root in resolved_output.parents:
        raise PackageError("release output directory must be outside the source repository")
    output.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, assets)
        audit_args = argparse.Namespace(
            git=args.git,
            source_commit=commit,
            prealpha26_zip=args.prealpha26_zip,
            candidate_audit=args.candidate_audit,
            offline_checks=args.offline_checks,
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
        sub.add_argument("--prealpha26-zip", required=True, type=Path)
        sub.add_argument("--candidate-audit", required=True, type=Path)
        sub.add_argument("--offline-checks", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
        sub.add_argument("--git", default="git")
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    try:
        if args.command == "build":
            build(args)
            print(f"Built and audited prealpha.27 release: {args.output_dir.resolve()}")
        else:
            audit_output(args)
            print(f"Source-bound prealpha.27 release audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, zipfile.BadZipFile, ValueError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
