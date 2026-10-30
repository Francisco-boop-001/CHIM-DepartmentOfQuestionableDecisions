#!/usr/bin/env python3
"""Build and audit the source-bound Department prealpha.29 package."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


_PREVIOUS_PATH = Path(__file__).with_name("build-installable-prealpha28.py")
_PREVIOUS_SPEC = importlib.util.spec_from_file_location("_department_prealpha28_for_release29", _PREVIOUS_PATH)
if _PREVIOUS_SPEC is None or _PREVIOUS_SPEC.loader is None:
    raise RuntimeError("could not load the reviewed prealpha.28 package primitives")
PREVIOUS = importlib.util.module_from_spec(_PREVIOUS_SPEC)
_PREVIOUS_SPEC.loader.exec_module(PREVIOUS)

BASE = PREVIOUS.BASE
PCV = PREVIOUS.PCV
P20 = PCV.P20
PackageError = PREVIOUS.PackageError
ROOT = Path(__file__).resolve().parents[1]

VERSION = "0.1.0-prealpha.29"
BASELINE_VERSION = "0.1.0-prealpha.28"
BASELINE_SHA256 = "73665957569091e4cbd307bb85d65a3f236cafef5f55472fe82ccdb803c7c97b"
BASELINE_SOURCE_COMMIT = "b66568de0b9411bacd114a15e99e118776e81764"
PCV_VERSION = PREVIOUS.PCV_VERSION
SERVER_MANIFEST = "server/manifest.json"
ALLOWLIST_PATH = "packaging/public-source-allowlist.json"
UI_APP_SOURCE = "ui/app.js"
UI_TEST_SOURCE = "ui/tests/ui-functional.cjs"
UI_APP_MEMBER = PREVIOUS.UI_APP_MEMBER
BASELINE_DPKG_MEMBER = PREVIOUS.DPKG_MEMBER
DPKG_MEMBER = f"CHIM/server-plugins/questionable_decisions/{VERSION}.dwpkg"
PCV_MEMBER = PREVIOUS.PCV_MEMBER
EXPECTED_BASELINE_MEMBERS = frozenset(PREVIOUS.EXPECTED_INSTALLABLE_MEMBERS)
EXPECTED_INSTALLABLE_MEMBERS = (EXPECTED_BASELINE_MEMBERS - {BASELINE_DPKG_MEMBER}) | {DPKG_MEMBER}
RELEASE_ALLOWLIST_ADDITIONS = (
    "packaging/build-installable-prealpha29.py",
    "packaging/tests/test_installable_prealpha29.py",
)
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
CHECKSUMS_NAME = "SHA256SUMS.txt"
BUILD_AUDIT_NAME = "prealpha29-package-audit.json"
OUTPUT_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP, BUILD_AUDIT_NAME}
OUTPUT_FILE_NAMES = OUTPUT_NAMES | {CHECKSUMS_NAME, BUILD_AUDIT_NAME}
ZIP_TIMESTAMP = (2026, 10, 30, 0, 0, 0)

_FULL_COMMIT = re.compile(r"\A[a-f0-9]{40}\Z")


def _git(*args: str) -> bytes:
    try:
        result = subprocess.run(
            ["git", "-c", f"safe.directory={ROOT.as_posix()}", "-C", str(ROOT), *args],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
    except subprocess.CalledProcessError as error:
        detail = error.stderr.decode("utf-8", "replace").strip()
        raise PackageError(f"git {' '.join(args)} failed: {detail}") from error
    return result.stdout


def _git_blob(commit: str, path: str) -> bytes:
    try:
        BASE.validate_member_name(path)
    except PackageError as error:
        raise PackageError(f"invalid committed source path: {path}") from error
    return _git("show", f"{commit}:{path}")


def _json_object(data: bytes, label: str) -> dict:
    try:
        value = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise PackageError(f"{label} is not valid UTF-8 JSON") from error
    if not isinstance(value, dict):
        raise PackageError(f"{label} must be a JSON object")
    return value


def _allowlist_paths(data: bytes, label: str) -> list[str]:
    document = _json_object(data, label)
    if (document.get("schemaVersion") != 1
            or document.get("kind") != "explicit-public-source-allowlist"
            or not isinstance(document.get("files"), list)):
        raise PackageError(f"{label} has an unsupported format")
    paths = []
    for entry in document["files"]:
        if not isinstance(entry, dict) or set(entry) != {"path"} or not isinstance(entry["path"], str):
            raise PackageError(f"{label} has a malformed path entry")
        BASE.validate_member_name(entry["path"])
        paths.append(entry["path"])
    if len(paths) != len(set(paths)):
        raise PackageError(f"{label} contains duplicate paths")
    return paths


def validate_source_diff_paths(changed_paths: list[str]) -> None:
    """Keep runtime source and owner pins fixed for this UI-only release."""
    allowed = {
        "README.md",
        "server/manifest.json",
        ALLOWLIST_PATH,
        *RELEASE_ALLOWLIST_ADDITIONS,
        UI_APP_SOURCE,
        UI_TEST_SOURCE,
    }
    for path in changed_paths:
        if (path in allowed or path.startswith("docs/") or path.startswith("tasks/")):
            continue
        raise PackageError(f"source changed outside the prealpha.29 release scope: {path}")


def validate_manifest_transition(old_bytes: bytes, release_bytes: bytes) -> dict:
    old = _json_object(old_bytes, "published prealpha.28 server manifest")
    release = _json_object(release_bytes, "prealpha.29 server manifest")
    expected = dict(old)
    if (old.get("version") != BASELINE_VERSION
            or release.get("version") != VERSION
            or list(release) != list(old)):
        raise PackageError("server manifest is not a version-only prealpha.28 to prealpha.29 change")
    expected["version"] = VERSION
    if release != expected or BASE.json_bytes(expected) != release_bytes:
        raise PackageError("server manifest is not a version-only prealpha.28 to prealpha.29 change")
    return {"baselineSha256": BASE.sha256(old_bytes), "releaseSha256": BASE.sha256(release_bytes)}


def _stable_zip_bytes(files: dict[str, bytes]) -> bytes:
    original = BASE.ZIP_TIMESTAMP
    BASE.ZIP_TIMESTAMP = ZIP_TIMESTAMP
    try:
        return BASE.stable_zip_bytes(files)
    finally:
        BASE.ZIP_TIMESTAMP = original


def _read_baseline(data: bytes) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, bytes]]:
    if BASE.sha256(data) != BASELINE_SHA256:
        raise PackageError("baseline ZIP is not the exact published prealpha.28 archive")
    files = BASE.read_zip_bytes(data, "published prealpha.28 ZIP")
    if set(files) != set(EXPECTED_BASELINE_MEMBERS) or len(files) != 22:
        raise PackageError("published prealpha.28 ZIP member set differs")
    department = PCV._audit_department(files[BASELINE_DPKG_MEMBER], BASELINE_VERSION, pcv25=True)
    pcv = P20._audit_component(files[PCV_MEMBER], "pcv_companion", PCV_VERSION)
    return files, department, pcv


def _build_department_package(
    old_package: dict[str, bytes],
    old_server_manifest: bytes,
    release_server_manifest: bytes,
) -> tuple[bytes, dict[str, bytes], list[str]]:
    if old_package["server/manifest.json"] != old_server_manifest:
        raise PackageError("prealpha.28 DWPkg server manifest differs from its pinned source commit")
    manifest_transition = validate_manifest_transition(old_server_manifest, release_server_manifest)
    current = dict(old_package)
    outer = _json_object(current["manifest.json"], "prealpha.28 Department outer manifest")
    if outer.get("version") != BASELINE_VERSION:
        raise PackageError("prealpha.28 Department outer manifest has the wrong version")
    if BASE.json_bytes(outer) != current["manifest.json"]:
        raise PackageError("prealpha.28 Department outer manifest is not canonical JSON")
    outer["version"] = VERSION
    current["manifest.json"] = BASE.json_bytes(outer)
    current["server/manifest.json"] = release_server_manifest
    payload = {path: content for path, content in current.items() if path != "checksums.sha256"}
    current["checksums.sha256"] = BASE.make_checksums(payload)

    package = _stable_zip_bytes(current)
    verified = PCV._audit_department(package, VERSION, pcv25=True)
    changed = sorted(path for path in old_package if old_package[path] != verified.get(path))
    expected_changed = ["checksums.sha256", "manifest.json", SERVER_MANIFEST]
    if changed != expected_changed:
        raise PackageError(f"Department DWPkg changed outside its two manifests and checksums: {changed}")
    if manifest_transition["releaseSha256"] != BASE.sha256(release_server_manifest):
        raise PackageError("Department manifest transition receipt differs")
    return package, verified, changed


def assemble_installable(
    baseline_zip: bytes,
    source_files: dict[str, bytes],
    old_server_manifest: bytes,
) -> tuple[bytes, dict]:
    baseline, old_department, old_pcv = _read_baseline(baseline_zip)
    if not isinstance(source_files.get(UI_APP_SOURCE), bytes) or not source_files[UI_APP_SOURCE]:
        raise PackageError("committed UI app.js is missing")
    if not isinstance(source_files.get(SERVER_MANIFEST), bytes):
        raise PackageError("committed Department server manifest is missing")
    department_package, new_department, department_changes = _build_department_package(
        old_department,
        old_server_manifest,
        source_files[SERVER_MANIFEST],
    )
    release = {path: content for path, content in baseline.items() if path != BASELINE_DPKG_MEMBER}
    release[DPKG_MEMBER] = department_package
    release[UI_APP_MEMBER] = source_files[UI_APP_SOURCE]
    if set(release) != set(EXPECTED_INSTALLABLE_MEMBERS) or len(release) != 22:
        raise PackageError("prealpha.29 installable member set differs from its fixed contract")

    common = set(release) & set(baseline)
    changed_common = {path for path in common if release[path] != baseline[path]}
    if changed_common != {UI_APP_MEMBER}:
        raise PackageError("prealpha.29 must change only the UI app among prior installable payloads")
    unchanged = sorted(path for path in common if release[path] == baseline[path])
    if len(unchanged) != 20:
        raise PackageError("prealpha.29 must preserve twenty prior installable payloads byte-for-byte")
    if old_pcv != P20._audit_component(release[PCV_MEMBER], "pcv_companion", PCV_VERSION):
        raise PackageError("prealpha.29 changed the pinned PCV companion package")

    runtime_zip = _stable_zip_bytes(release)
    verified_outer = BASE.read_zip_bytes(runtime_zip, "prealpha.29 installable ZIP")
    if verified_outer != release:
        raise PackageError("prealpha.29 installable ZIP did not round-trip exactly")
    new_owner_manifest = new_department[PCV.PACKAGED_SOURCE_MANIFEST]
    audit = {
        "departmentDwpkgChangedMembers": department_changes,
        "changedInstallableMembers": sorted({BASELINE_DPKG_MEMBER, DPKG_MEMBER, UI_APP_MEMBER}),
        "unchangedInstallableMembers": unchanged,
        "pcvCompanionVersion": PCV_VERSION,
        "pcvCompanionSha256": BASE.sha256(release[PCV_MEMBER]),
        "pcvOwnerManifestSha256": BASE.sha256(new_owner_manifest),
    }
    return runtime_zip, audit


def _validate_source_inputs(
    source_commit: str,
    source_files: dict[str, bytes],
    baseline_allowlist: bytes,
) -> tuple[list[str], dict]:
    if _FULL_COMMIT.fullmatch(source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    allowlist_bytes = source_files.get(ALLOWLIST_PATH)
    if allowlist_bytes is None:
        raise PackageError("committed source allowlist is missing")
    paths = _allowlist_paths(allowlist_bytes, "committed source allowlist")
    old_paths = _allowlist_paths(baseline_allowlist, "published prealpha.28 source allowlist")
    if paths != old_paths + list(RELEASE_ALLOWLIST_ADDITIONS):
        raise PackageError("prealpha.29 source allowlist must append only the builder and targeted test")
    if set(paths) != set(source_files):
        raise PackageError("source bytes do not match the exact committed public allowlist")
    if set(RELEASE_ALLOWLIST_ADDITIONS) - set(paths):
        raise PackageError("source allowlist is missing the prealpha.29 builder or targeted test")
    return paths, validate_manifest_transition(
        _git_blob(BASELINE_SOURCE_COMMIT, SERVER_MANIFEST), source_files.get(SERVER_MANIFEST, b"")
    )


def _validate_source_diff(source_commit: str) -> None:
    changed = _git("diff", "--name-only", "--no-renames", BASELINE_SOURCE_COMMIT, source_commit, "--")
    paths = changed.decode("utf-8").splitlines()
    validate_source_diff_paths(paths)


def _build_assets(
    baseline_zip: bytes,
    source_commit: str,
    source_files: dict[str, bytes],
    baseline_allowlist: bytes,
    old_server_manifest: bytes,
) -> dict[str, bytes]:
    paths, manifest_audit = _validate_source_inputs(source_commit, source_files, baseline_allowlist)
    _validate_source_diff(source_commit)
    if source_files[SERVER_MANIFEST] != _git_blob(source_commit, SERVER_MANIFEST):
        raise PackageError("prealpha.29 server manifest differs from the selected source commit")
    source_zip = _stable_zip_bytes(source_files)
    runtime_zip, installable_audit = assemble_installable(baseline_zip, source_files, old_server_manifest)
    receipt = {
        "schemaVersion": 1,
        "kind": "department-prealpha29-source-bound-package",
        "version": VERSION,
        "sourceCommit": source_commit,
        "baseline": {
            "version": BASELINE_VERSION,
            "sha256": BASELINE_SHA256,
            "sourceCommit": BASELINE_SOURCE_COMMIT,
        },
        "installableSha256": BASE.sha256(runtime_zip),
        "sourceArchiveSha256": BASE.sha256(source_zip),
        "sourceAllowlistSha256": BASE.sha256(source_files[ALLOWLIST_PATH]),
        "sourceFileCount": len(paths),
        "serverManifestTransition": manifest_audit,
        **installable_audit,
        "limits": {
            "sourceAndPackageAssembly": True,
            "ownerPinsAndPackagesUnchanged": True,
            "serverRuntimeChanged": False,
            "database": False,
            "http": False,
            "provider": False,
            "installedGame": False,
            "skyrim": False,
            "physicalDelivery": False,
            "mouseFix": False,
            "published": False,
        },
    }
    receipt_bytes = BASE.json_bytes(receipt)
    checksums = BASE.make_checksums({
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        BUILD_AUDIT_NAME: receipt_bytes,
    })
    if not checksums.endswith(b"\n") or b"\r" in checksums:
        raise PackageError("release checksums must use canonical LF-only lines")
    return {
        OUTPUT_MOD_ZIP: runtime_zip,
        OUTPUT_SOURCE_ZIP: source_zip,
        CHECKSUMS_NAME: checksums,
        BUILD_AUDIT_NAME: receipt_bytes,
    }


def _inputs(args) -> tuple[str, bytes, dict[str, bytes], bytes, bytes]:
    if _FULL_COMMIT.fullmatch(args.source_commit) is None:
        raise PackageError("release source commit must be a full lowercase 40-character commit ID")
    head = _git("rev-parse", "HEAD").decode("ascii").strip()
    if args.source_commit != head:
        raise PackageError("release source commit must be the current frozen checkout HEAD")
    dirty = _git("status", "--porcelain", "--untracked-files=normal").decode("utf-8").strip()
    if dirty:
        raise PackageError("source checkout must be clean before packaging")
    if args.baseline_zip.is_symlink() or not args.baseline_zip.is_file():
        raise PackageError("published prealpha.28 ZIP is missing or unsafe")
    baseline_zip = args.baseline_zip.read_bytes()
    allowlist = _git_blob(args.source_commit, ALLOWLIST_PATH)
    paths = _allowlist_paths(allowlist, "committed source allowlist")
    source_files = {path: _git_blob(args.source_commit, path) for path in paths}
    baseline_allowlist = _git_blob(BASELINE_SOURCE_COMMIT, ALLOWLIST_PATH)
    old_server_manifest = _git_blob(BASELINE_SOURCE_COMMIT, SERVER_MANIFEST)
    return args.source_commit, baseline_zip, source_files, baseline_allowlist, old_server_manifest


def _expected_output_files(directory: Path) -> dict[str, bytes]:
    if directory.is_symlink() or not directory.is_dir():
        raise PackageError("release output directory is missing or unsafe")
    entries = list(directory.iterdir())
    if (set(path.name for path in entries) != OUTPUT_FILE_NAMES
            or any(path.is_symlink() or not path.is_file() for path in entries)):
        raise PackageError("release output must contain only the installable, source, checksums, and package audit")
    return {path.name: path.read_bytes() for path in entries}


def audit_output(args) -> None:
    commit, baseline_zip, source_files, baseline_allowlist, old_manifest = _inputs(args)
    expected = _build_assets(baseline_zip, commit, source_files, baseline_allowlist, old_manifest)
    actual = _expected_output_files(args.output_dir)
    for name in sorted(OUTPUT_FILE_NAMES):
        if actual[name] != expected[name]:
            raise PackageError(f"release output differs from the canonical source-bound build: {name}")
    BASE.audit_checksums_file(actual[CHECKSUMS_NAME], {name: actual[name] for name in OUTPUT_NAMES})


def _write_outputs(directory: Path, assets: dict[str, bytes]) -> None:
    if set(assets) != OUTPUT_FILE_NAMES or directory.is_symlink() or not directory.is_dir() or any(directory.iterdir()):
        raise PackageError("release staging directory is unsafe, not empty, or has an incomplete output set")
    for name, content in assets.items():
        BASE.validate_member_name(name)
        with (directory / name).open("xb") as stream:
            stream.write(content)


def build(args) -> None:
    commit, baseline_zip, source_files, baseline_allowlist, old_manifest = _inputs(args)
    assets = _build_assets(baseline_zip, commit, source_files, baseline_allowlist, old_manifest)
    output = args.output_dir
    if output.exists() or output.is_symlink():
        raise PackageError("output directory must be new; refusing to overwrite it")
    parent = output.parent.resolve()
    root = ROOT.resolve()
    if parent == root or root in parent.parents or output.resolve() == root or root in output.resolve().parents:
        raise PackageError("release output directory must be outside the source repository")
    if output.parent.is_symlink():
        raise PackageError("release output parent must not be a symlink")
    output.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output.name}-", dir=output.parent))
    try:
        _write_outputs(stage, assets)
        staged = _expected_output_files(stage)
        for name in sorted(OUTPUT_FILE_NAMES):
            if staged[name] != assets[name]:
                raise PackageError(f"staged output differs from the canonical build: {name}")
        BASE.audit_checksums_file(staged[CHECKSUMS_NAME], {name: staged[name] for name in OUTPUT_NAMES})
        os.rename(stage, output)
    except Exception:
        if stage.exists():
            shutil.rmtree(stage)
        raise


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for command in ("build", "audit"):
        sub = commands.add_parser(command)
        sub.add_argument("--baseline-zip", required=True, type=Path)
        sub.add_argument("--source-commit", required=True)
        sub.add_argument("--output-dir", required=True, type=Path)
    return parser.parse_args(sys.argv[1:] if argv is None else argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        if args.command == "build":
            build(args)
            print(f"Built and source-audited prealpha.29 package: {args.output_dir.resolve()}")
        else:
            audit_output(args)
            print(f"Source-bound prealpha.29 package audit passed: {args.output_dir.resolve()}")
    except (PackageError, OSError, subprocess.SubprocessError, ValueError, KeyError, TypeError) as error:
        print(f"package error: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
