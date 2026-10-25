#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

readonly EXPECTED_DISTRO='DwemerAI4Skyrim3-test2'
readonly MOUNT_ROOT='/mnt/k/ActorwrightExchange'
readonly ARTIFACT_ROOT='/mnt/k/ActorwrightExchange/artifacts/department-dev23-compatibility-20261024'
readonly RUNTIME_ROOT='/mnt/k/ActorwrightExchange/artifacts/department-dev23-compatibility-20261024/runtime'
readonly DEPARTMENT_ROOT='/mnt/k/ActorwrightExchange/projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server'
readonly TEST_ROOT="$DEPARTMENT_ROOT/tests/dev23-pg"
readonly RUNNER_SOURCE_ROOT="$TEST_ROOT"
readonly CORE_SOURCE_ROOT='/mnt/k/ActorwrightExchange/artifacts/chim-correspondence-offscreen-20261019/upstream/HerikaServer'
readonly CORE_PIN_MANIFEST='/mnt/k/ActorwrightExchange/artifacts/department-dev18-candidate-20261021/coordinator/approved-core-source-pins.json'
readonly OWNER_PIN_MANIFEST="$TEST_ROOT/owner-runtime-pins.json"
readonly CORE_PIN_SHA256='d81281bc30a240f2ef819044dad6ee54786edca44d25ccfae230aa2bc98517a0'
readonly OWNER_PIN_SHA256='45e43d191e747d5593b5cc912bbcd9ec1e6597cd8e5ce9e6e91130d5a07e04ef'
readonly CORE_COMMIT='0ec590b42c8087860138bb4e9aab470049ff9a2b'
readonly CORE_TREE='ab97c6dd3947501b8a76bf9b9c6ad74b30be3975'
readonly EXPECTED_OWNER_ROOT='/mnt/k/ActorwrightExchange/artifacts/chim-sealed-fates-release-dev14-20261020/source'
readonly EXPECTED_SCRATCH_ROOT='/var/tmp'

RUN_ID=""
RUN_ROOT=""
SOURCE_BINDINGS=""
OWNER_SOURCE=""
SCRATCH=""
PGDATA=""
SOCKET_DIR=""
STAGE_ROOT=""
CORE_ROOT=""
SERVER_ROOT=""
DRIVER_ROOT=""
HOME_DIR=""
PHP_BIN=""
PSQL_BIN=""
PG_CTL=""
INITDB=""
PG_CONTROLDATA=""
PG_STARTED=0
POSTMASTER_PID=""
SYSTEM_IDENTIFIER=""
STOP_ATTEMPTED=0
STOP_EXIT_CODE=1
POSTMASTER_STOPPED=0
CLEANUP_OK=0
RUN_OK=0
CHECKS_FAILED=0
FAILURE=""
SOURCE_POSTCHECK=0
SCENARIO_CHECK=0
SELF_NET=""
INIT_NET=""
INTERFACES=""

abort() {
    FAILURE="$*"
    printf 'FAIL: %s\n' "$FAILURE" >&2
    exit 1
}

assert_owned_postgres_identity() {
    [[ -n "$SELF_NET" && -n "$INIT_NET" && "$SELF_NET" != "$INIT_NET" ]] || return 1
    [[ "$(ip -o link show | awk -F': ' '{ sub(/@.*/, "", $2); print $2 }' | sort)" == 'lo' ]] || return 1
    [[ -z "$(ip -4 route show table main)" && -z "$(ip -6 route show table main)" &&
        -z "$(ip -4 route show default)" && -z "$(ip -6 route show default)" ]] || return 1
    [[ "$PGDATA" == "$SCRATCH/pgdata" && -d "$PGDATA" && ! -L "$PGDATA" ]] || return 1
    [[ "$(realpath -e -- "$PGDATA" 2>/dev/null)" == "$PGDATA" ]] || return 1
    [[ -f "$PGDATA/postmaster.pid" && ! -L "$PGDATA/postmaster.pid" ]] || return 1
    [[ -n "$PG_CONTROLDATA" && -x "$PG_CONTROLDATA" && -n "$PG_CTL" && -x "$PG_CTL" ]] || return 1
    local -a fields=()
    mapfile -t fields <"$PGDATA/postmaster.pid"
    [[ "${fields[0]:-}" == "$POSTMASTER_PID" && "${fields[1]:-}" == "$PGDATA" &&
        "${fields[3]:-}" == '5432' && "${fields[4]:-}" == "$SOCKET_DIR" &&
        "${fields[5]:-}" == '127.0.0.1' ]] || return 1
    [[ "$POSTMASTER_PID" =~ ^[1-9][0-9]*$ && -n "$SYSTEM_IDENTIFIER" ]] || return 1
    local actual_system_identifier
    actual_system_identifier="$(runuser -u postgres -- "$PG_CONTROLDATA" "$PGDATA" 2>/dev/null | awk -F: '/Database system identifier/ { gsub(/[[:space:]]/, "", $2); print $2 }')"
    [[ "$actual_system_identifier" == "$SYSTEM_IDENTIFIER" ]] || return 1
    runuser -u postgres -- "$PG_CTL" -D "$PGDATA" status >/dev/null 2>&1 || return 1
    [[ "$(ss -H -ltn | awk '{print $4}' | sort)" == '127.0.0.1:5432' ]] || return 1
    return 0
}

on_exit() {
    local original_rc="$?"
    local final_rc="$original_rc"
    local stop_output="$RUN_ROOT/postgres-stop.stdout.txt"
    local stop_error="$RUN_ROOT/postgres-stop.stderr.txt"
    local pid_alive=0
    local contained=0
    local artifacts_preserved=0
    local identity_verified=0

    trap - EXIT
    set +e

    if (( PG_STARTED == 1 )); then
        if assert_owned_postgres_identity; then
            identity_verified=1
            STOP_ATTEMPTED=1
            runuser -u postgres -- "$PG_CTL" -D "$PGDATA" -m fast -w -t 30 stop >"$stop_output" 2>"$stop_error"
            STOP_EXIT_CODE="$?"
        else
            FAILURE='private_postgres_identity_changed_before_stop'
            final_rc=1
        fi
        if [[ -n "$POSTMASTER_PID" ]] && kill -0 "$POSTMASTER_PID" 2>/dev/null; then
            pid_alive=1
        fi
        if (( STOP_EXIT_CODE == 0 )) && [[ ! -e "$PGDATA/postmaster.pid" ]] && (( pid_alive == 0 )); then
            POSTMASTER_STOPPED=1
        fi
        if [[ -f "$PGDATA/postgres.log" && ! -L "$PGDATA/postgres.log" ]]; then
            cp -- "$PGDATA/postgres.log" "$RUN_ROOT/postgres.log"
        fi
        if (( POSTMASTER_STOPPED == 1 )); then
            CLEANUP_OK=1
        fi
    fi

    if [[ "$SCRATCH" == "$EXPECTED_SCRATCH_ROOT/department-dev23-$RUN_ID" && ! -L "$SCRATCH" ]]; then
        local resolved_scratch
        resolved_scratch="$(realpath -m -- "$SCRATCH" 2>/dev/null)"
        if [[ "$resolved_scratch" == "$SCRATCH" ]]; then contained=1; fi
    fi

    if [[ -d "$RUN_ROOT" && -f "$RUN_ROOT/postgres.log" ]]; then artifacts_preserved=1; fi

    if (( RUN_OK != 1 || original_rc != 0 )); then final_rc=1; fi
    if (( PG_STARTED != 1 || CLEANUP_OK != 1 || STOP_ATTEMPTED != 1 || STOP_EXIT_CODE != 0 || POSTMASTER_STOPPED != 1 || contained != 1 || artifacts_preserved != 1 )); then
        final_rc=1
    fi

    if [[ -n "$RUN_ROOT" && -d "$RUN_ROOT" ]]; then
        if (( PG_STARTED == 1 )); then
            python3 - "$RUN_ROOT/linux-cleanup.json" "$RUN_ID" "$PGDATA" "$SOCKET_DIR" "$POSTMASTER_PID" "$STOP_ATTEMPTED" "$STOP_EXIT_CODE" "$POSTMASTER_STOPPED" "$contained" "$artifacts_preserved" "$CLEANUP_OK" "$SYSTEM_IDENTIFIER" "$identity_verified" <<'PY'
import json, sys
path, run_id, data, socket, pid, attempted, stop_code, stopped, contained, preserved, passed, system_id, identity_verified = sys.argv[1:]
value = {
    "schema": "department.dev23.private-postgres-cleanup.v1",
    "runId": run_id,
    "clusterStartedByRun": True,
    "dataDirectory": data,
    "socketDirectory": socket,
    "postmasterPid": pid,
    "port": 5432,
    "systemIdentifier": system_id,
    "ownedIdentityVerifiedBeforeStop": identity_verified == "1",
    "stopAttempted": attempted == "1",
    "stopExitCode": int(stop_code),
    "postmasterStopped": stopped == "1",
    "ownedPathsContained": contained == "1",
    "failedRunArtifactsPreserved": preserved == "1",
    "passed": passed == "1",
}
with open(path, "w", encoding="utf-8") as stream:
    json.dump(value, stream, sort_keys=True, separators=(",", ":"))
    stream.write("\n")
PY
        fi
        python3 - "$RUN_ROOT/linux.status.json" "$RUN_ID" "$final_rc" "$RUN_OK" "$CHECKS_FAILED" "$SOURCE_POSTCHECK" "$SCENARIO_CHECK" "$FAILURE" "$POSTMASTER_PID" "$SYSTEM_IDENTIFIER" <<'PY'
import json, sys
path, run_id, exit_code, run_ok, checks_failed, source_postcheck, scenario_check, failure, pid, system_id = sys.argv[1:]
value = {
    "schema": "department.dev20.linux-run-status.v1",
    "runId": run_id,
    "passed": run_ok == "1" and exit_code == "0",
    "runnerExitCode": int(exit_code),
    "checksFailed": int(checks_failed),
    "sourcePostcheckPassed": source_postcheck == "1",
    "scenarioSummaryPassed": scenario_check == "1",
    "postgresPostmasterPid": pid or None,
    "postgresSystemIdentifier": system_id or None,
    "failure": failure or None,
}
with open(path, "w", encoding="utf-8") as stream:
    json.dump(value, stream, sort_keys=True, separators=(",", ":"))
    stream.write("\n")
PY
    fi

    if (( final_rc == 0 )); then
        printf '{"runId":"%s","passed":true,"cleanupPassed":true}\n' "$RUN_ID"
    else
        printf '{"runId":"%s","passed":false,"cleanupPassed":%s}\n' "$RUN_ID" "$([[ "$CLEANUP_OK" == 1 ]] && printf true || printf false)"
    fi
    exit "$final_rc"
}
[[ "$#" -eq 4 ]] || abort 'Expected run id, artifact directory, source binding manifest, and owner root.'
RUN_ID="$1"
RUN_ROOT="$2"
SOURCE_BINDINGS="$3"
OWNER_SOURCE="$4"
SCRATCH="$EXPECTED_SCRATCH_ROOT/department-dev23-$RUN_ID"
PGDATA="$SCRATCH/pgdata"
SOCKET_DIR="$SCRATCH/socket"
STAGE_ROOT="$SCRATCH/stage"
CORE_ROOT="$STAGE_ROOT/core"
SERVER_ROOT="$CORE_ROOT/ext/questionable_decisions"
DRIVER_ROOT="$SERVER_ROOT/tests/dev23-pg"
HOME_DIR="$SCRATCH/home"
[[ "$RUN_ID" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}$ ]] || abort 'Run id has an invalid format.'
[[ "$(printenv WSL_DISTRO_NAME || true)" == "$EXPECTED_DISTRO" ]] || abort 'Runner is restricted to the exact TEST distribution.'
[[ "$EUID" -eq 0 ]] || abort 'Runner must execute as root inside the isolated test distribution.'
[[ "$RUN_ROOT" == "$RUNTIME_ROOT/runs/$RUN_ID" ]] || abort 'Artifact directory is outside the exact per-run root.'
[[ "$SOURCE_BINDINGS" == "$RUNTIME_ROOT/source-bindings.json" ]] || abort 'Source binding path is not the assigned manifest.'
[[ "$OWNER_SOURCE" == "$EXPECTED_OWNER_ROOT" ]] || abort 'Owner source root is not the pinned immutable closure root.'
[[ -f "$SOURCE_BINDINGS" && ! -L "$SOURCE_BINDINGS" ]] || abort 'Source binding manifest is absent or redirected.'
[[ -d "$RUN_ROOT" && ! -L "$RUN_ROOT" ]] || abort 'Run artifact directory is absent or redirected.'
[[ "$(realpath -e -- "$RUN_ROOT" 2>/dev/null)" == "$RUN_ROOT" ]] || abort 'Run artifact directory is not canonical.'
command -v runuser >/dev/null 2>&1 || abort 'runuser is required.'
command -v python3 >/dev/null 2>&1 || abort 'Python 3 is required.'
command -v ip >/dev/null 2>&1 || abort 'iproute2 is required.'
command -v ss >/dev/null 2>&1 || abort 'ss is required to inspect namespace listeners.'
[[ -z "${WSLENV:-}" ]] || abort 'A non-empty WSLENV was inherited.'
unset WSLENV
trap on_exit EXIT
for variable_name in $(env | cut -d= -f1); do
    if [[ "$variable_name" =~ (^PG[A-Z0-9_]*$|^POSTGRES[A-Z0-9_]*$|^DATABASE_URL$|PASS|TOKEN|SECRET|API[-_]?KEY|CREDENTIAL|AUTH|COOKIE|BEARER|PRIVATE[-_]?KEY|ACCESS[-_]?KEY|CLIENT[-_]?KEY) ]]; then
        abort "Sensitive or database environment name was inherited: $variable_name"
    fi
done

SELF_NET="$(readlink /proc/self/ns/net)"
INIT_NET="$(readlink /proc/1/ns/net)"
[[ -n "$SELF_NET" && -n "$INIT_NET" && "$SELF_NET" != "$INIT_NET" ]] || abort 'Runner is not in a distinct network namespace.'
ip link set lo up
INTERFACES="$(ip -o link show | awk -F': ' '{ sub(/@.*/, "", $2); print $2 }' | sort)"
[[ "$INTERFACES" == 'lo' ]] || abort 'The isolated namespace contains an unexpected network interface.'
[[ -z "$(ip -4 route show table main)" && -z "$(ip -6 route show table main)" ]] || abort 'The isolated namespace contains a non-loopback route.'
[[ -z "$(ip -4 route show default)" && -z "$(ip -6 route show default)" ]] || abort 'The isolated namespace contains a default route.'
[[ -z "$(ss -ltnH)" ]] || abort 'An unexpected TCP listener exists before the private database starts.'
python3 - "$RUN_ROOT/network-isolation.json" "$SELF_NET" "$INIT_NET" "$INTERFACES" <<'PY'
import json, sys
path, self_net, init_net, interfaces = sys.argv[1:]
value = {
    "schema": "department.dev23.network-isolation.v1",
    "networkNamespace": self_net,
    "initialNetworkNamespace": init_net,
    "distinctFromDistributionInit": self_net != init_net,
    "interfaces": interfaces.splitlines(),
    "ipv4MainRoutes": [],
    "ipv6MainRoutes": [],
    "ipv4DefaultRoutes": [],
    "ipv6DefaultRoutes": [],
    "preexistingTcpListeners": [],
    "postgresListenAddress": "127.0.0.1:5432",
    "providerCalls": 0,
    "campaignBootstrap": False,
}
with open(path, "x", encoding="utf-8") as stream:
    json.dump(value, stream, sort_keys=True, separators=(",", ":"))
    stream.write("\n")
PY
[[ ! -e "$SCRATCH" && ! -L "$SCRATCH" ]] || abort 'Per-run Linux scratch already exists.'

SCRIPT_DIR="$(cd -- "$(dirname -- "$0")" && pwd -P)"
[[ "$SCRIPT_DIR" == "$RUNNER_SOURCE_ROOT" ]] || abort 'Runner script is not executing from the exact dev23 source root.'

verify_and_stage_sources() {
    local phase="$1"
    python3 - "$phase" "$MOUNT_ROOT" "$SOURCE_BINDINGS" "$OWNER_SOURCE" "$SCRATCH" "$CORE_PIN_MANIFEST" "$OWNER_PIN_MANIFEST" "$RUN_ID" <<'PY'
import hashlib
import json
import os
import shutil
import stat
import sys
from pathlib import Path, PurePosixPath

phase, mount_text, binding_text, owner_text, scratch_text, core_pin_text, owner_pin_text, run_id = sys.argv[1:]
mount = Path(mount_text)
bindings_path = Path(binding_text)
owner_root = Path(owner_text)
scratch = Path(scratch_text)
core_pin_path = Path(core_pin_text)
owner_pin_path = Path(owner_pin_text)
runtime_root = mount / "artifacts/department-dev23-compatibility-20261024/runtime"
dept_root = mount / "projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server"
test_root = dept_root / "tests/dev23-pg"
runner_source_root = test_root
core_source = mount / "artifacts/chim-correspondence-offscreen-20261019/upstream/HerikaServer"
expected_owner = Path("/mnt/k/ActorwrightExchange/artifacts/chim-sealed-fates-release-dev14-20261020/source")
expected_core_pin_sha = "d81281bc30a240f2ef819044dad6ee54786edca44d25ccfae230aa2bc98517a0"
expected_owner_pin_sha = "45e43d191e747d5593b5cc912bbcd9ec1e6597cd8e5ce9e6e91130d5a07e04ef"
expected_commit = "0ec590b42c8087860138bb4e9aab470049ff9a2b"
expected_tree = "ab97c6dd3947501b8a76bf9b9c6ad74b30be3975"
expected_runtime = {
    "api_driver.php",
    "base.sql",
    "build-source-bindings.ps1",
    "cases.json",
    "companion_cases.php",
    "controller_switch.php",
    "coverage.json",
    "fixture_guard.php",
    "launch-dev23-test.ps1",
    "owner-runtime-pins.json",
    "owner_fixture.php",
    "pg_scenarios.php",
    "run-dev23-owner-facade.sh",
    "seed_unknown_receipt.php",
    "test_runner_contract.py",
    "verify-dev23-harness.ps1",
    "verify-owner-pins.ps1",
}

def fail(message):
    raise SystemExit(message)

def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()

def ensure_chain(path, root=None):
    path = Path(os.path.abspath(path))
    if root is not None:
        root = Path(os.path.abspath(root))
        try:
            path.relative_to(root)
        except ValueError:
            fail(f"path escapes source root: {path}")
        cursor = root
        if cursor.is_symlink() or not cursor.is_dir():
            fail(f"source root is redirected or not a directory: {cursor}")
        parts = path.relative_to(root).parts
    else:
        parts = path.parts[1:]
        cursor = Path(path.anchor)
    for part in parts:
        cursor = cursor / part
        try:
            mode = cursor.lstat().st_mode
        except FileNotFoundError:
            fail(f"source path is missing: {cursor}")
        if stat.S_ISLNK(mode):
            fail(f"source path contains a symlink: {cursor}")
    return path

def load_json(path, limit):
    ensure_chain(path)
    if path.stat().st_size > limit:
        fail(f"JSON input exceeds size limit: {path}")
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        fail(f"invalid JSON input {path}: {type(exc).__name__}")

def canonical_relative(text):
    if not isinstance(text, str) or not text or "\\" in text or text.startswith("/"):
        fail(f"non-canonical relative path: {text!r}")
    parts = text.split("/")
    if any(part in ("", ".", "..") for part in parts):
        fail(f"unsafe path: {text!r}")
    return text

def entry_map(bindings):
    result = {}
    for entry in bindings.get("files", []):
        if not isinstance(entry, dict):
            fail("source binding entry is not an object")
        root_key = entry.get("rootKey")
        relative = canonical_relative(entry.get("path"))
        identity = (root_key, relative)
        if root_key not in ("departmentServer", "test", "core", "owner", "ownerPayload", "runtime"):
            fail(f"unknown root key: {root_key!r}")
        if identity in result:
            fail(f"duplicate source binding: {root_key}/{relative}")
        digest = entry.get("sha256")
        count = entry.get("bytes")
        if not isinstance(digest, str) or len(digest) != 64 or any(ch not in "0123456789abcdef" for ch in digest):
            fail(f"invalid source hash: {root_key}/{relative}")
        if not isinstance(count, int) or isinstance(count, bool) or count < 0:
            fail(f"invalid source byte count: {root_key}/{relative}")
        result[identity] = (count, digest)
    return result

def regular_files(root):
    ensure_chain(root)
    found = set()
    for base, dirs, files in os.walk(root, topdown=True, followlinks=False):
        base_path = Path(base)
        for name in list(dirs):
            path = base_path / name
            mode = path.lstat().st_mode
            if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
                fail(f"non-directory or redirected tree entry: {path}")
        dirs.sort()
        for name in files:
            path = base_path / name
            mode = path.lstat().st_mode
            if stat.S_ISLNK(mode) or not stat.S_ISREG(mode):
                fail(f"non-regular tree entry: {path}")
            found.add(path.relative_to(root).as_posix())
    return found

def validate_root(entries, key, root, expected=None, exact_tree=False):
    selected = {path: value for (root_key, path), value in entries.items() if root_key == key}
    if not selected:
        fail(f"source bindings omit root: {key}")
    if expected is not None and set(selected) != expected:
        fail(f"source binding closure differs for root: {key}")
    if exact_tree and set(selected) != regular_files(root):
        fail(f"source tree enumeration differs for root: {key}")
    for relative, (count, digest) in selected.items():
        path = ensure_chain(root / Path(relative), root)
        mode = path.stat().st_mode
        if not stat.S_ISREG(mode):
            fail(f"source is not a regular file: {key}/{relative}")
        if path.stat().st_size != count or sha256(path) != digest:
            fail(f"source hash or size mismatch: {key}/{relative}")
    return selected

def validate_staged_files(selected, root, strip_owner_prefix=False):
    ensure_chain(root)
    for relative, (count, digest) in selected.items():
        staged_relative = relative[len("server/"):] if strip_owner_prefix else relative
        path = ensure_chain(root / Path(staged_relative), root)
        mode = path.stat().st_mode
        if not stat.S_ISREG(mode) or path.stat().st_size != count or sha256(path) != digest:
            fail(f"staged source changed or is invalid: {staged_relative}")

try:
    if bindings_path != runtime_root / "source-bindings.json":
        fail("source binding path is not the assigned manifest")
    if owner_root != expected_owner:
        fail("owner root differs from exact pinned path")
    if sha256(core_pin_path) != expected_core_pin_sha or sha256(owner_pin_path) != expected_owner_pin_sha:
        fail("source pin manifest hash changed")
    core_pin = load_json(core_pin_path, 1024 * 1024)
    owner_pin = load_json(owner_pin_path, 65536)
    bindings = load_json(bindings_path, 1024 * 1024)
    if bindings.get("schema") != "department.dev23.runtime-source-bindings.v1":
        fail("unexpected source-binding schema")
    expected_fields = {
        "mountRoot": "/mnt/k/ActorwrightExchange",
        "departmentServerRoot": "projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server",
        "testRoot": "projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server/tests/dev23-pg",
        "coreSourceRoot": "artifacts/chim-correspondence-offscreen-20261019/upstream/HerikaServer",
        "ownerPinManifest": "projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server/tests/dev23-pg/owner-runtime-pins.json",
        "corePinManifestSha256": expected_core_pin_sha,
        "coreCommit": expected_commit,
        "coreTree": expected_tree,
        "ownerPinManifestSha256": expected_owner_pin_sha,
        "ownerClosureCount": 19,
        "ownerPayloadCount": 11,
        "ownerFullPayloadCount": 30,
    }
    for key, value in expected_fields.items():
        if bindings.get(key) != value:
            fail(f"source-binding metadata mismatch: {key}")
    if core_pin.get("schema") != "department.dev18.approved-core-source.v1" or core_pin.get("commit") != expected_commit or core_pin.get("tree") != expected_tree or core_pin.get("sourceRoot") != "K:\\ActorwrightExchange\\artifacts\\chim-correspondence-offscreen-20261019\\upstream\\HerikaServer" or len(core_pin.get("files", [])) != 3081:
        fail("approved core source manifest identity changed")
    if owner_pin.get("schema") != "department.dev23.owner-runtime-pins.v1" or owner_pin.get("ownerRoot") != "K:\\ActorwrightExchange\\artifacts\\chim-sealed-fates-release-dev14-20261020\\source" or owner_pin.get("ownerVersion") != "0.1.0-dev.23" or owner_pin.get("ownerCommit") != "01d62982d9b10ca4fce040af9d97fe7111231ba3" or owner_pin.get("ownerClosureCount") != 19 or len(owner_pin.get("files", [])) != 19 or owner_pin.get("fullPayloadCount") != 30 or len(owner_pin.get("payloadFiles", [])) != 11:
        fail("owner source manifest identity changed")

    entries = entry_map(bindings)
    core_expected = {}
    for file in core_pin["files"]:
        relative = canonical_relative(file["path"])
        if relative in core_expected:
            fail(f"duplicate pinned core path: {relative}")
        core_expected[relative] = (file["bytes"], file["sha256"].lower())
    owner_expected = {}
    for file in owner_pin["files"]:
        relative = canonical_relative(file["path"])
        if not relative.startswith("server/"):
            fail(f"owner pin escapes server tree: {relative}")
        owner_expected[relative] = (file["bytes"], file["sha256"].lower())
    owner_payload_expected = {}
    for file in owner_pin["payloadFiles"]:
        relative = canonical_relative(file["path"])
        if not relative.startswith("server/"):
            fail(f"owner payload pin escapes server tree: {relative}")
        owner_payload_expected[relative] = (file["bytes"], file["sha256"].lower())
    if len(core_expected) != 3081 or len(owner_expected) != 19 or len(owner_payload_expected) != 11:
        fail("pinned closure count changed")
    if {path for key, path in entries if key == "core"} != set(core_expected):
        fail("core bindings do not equal the approved tracked-file list")
    if {path for key, path in entries if key == "owner"} != set(owner_expected):
        fail("owner bindings do not equal the pinned 19-file source closure")
    if {path for key, path in entries if key == "ownerPayload"} != set(owner_payload_expected):
        fail("owner payload bindings do not equal the remaining 11 pinned payload files")
    if {path for key, path in entries if key == "runtime"} != expected_runtime:
        fail("runtime script binding set differs from its allowlist")
    if {key for key, _ in entries} != {"departmentServer", "test", "core", "owner", "ownerPayload", "runtime"}:
        fail("one or more source roots are absent")
    if not test_root.is_dir() or not dept_root.is_dir() or not core_source.is_dir() or not owner_root.is_dir() or not runtime_root.is_dir():
        fail("one or more bound source roots are unavailable")

    dept = validate_root(entries, "departmentServer", dept_root, exact_tree=True)
    test = validate_root(entries, "test", test_root, exact_tree=True)
    for relative, identity in test.items():
        department_relative = "tests/dev23-pg/" + relative
        if dept.get(department_relative) != identity:
            fail(f"dev23 test input is missing or differs in Department binding: {relative}")
    core = validate_root(entries, "core", core_source, expected=set(core_expected))
    owner = validate_root(entries, "owner", owner_root, expected=set(owner_expected))
    owner_payload = validate_root(entries, "ownerPayload", owner_root, expected=set(owner_payload_expected))
    validate_root(entries, "runtime", runner_source_root, expected=expected_runtime)
    if core != core_expected or owner != owner_expected or owner_payload != owner_payload_expected:
        fail("bound core or owner bytes differ from the immutable pin manifests")
    assets = {
        "data/fonts/GloriaHallelujah-Regular.ttf": (54232, "4206cbda828ccde275f6d8b406ad80a29d2f5d9bc1a39a86e56a1c9c7691ed8c"),
        "data/textures/chim.png": (10626, "820c235caa44a8f7a798e91d8091a633954963761de6e1377c70d8c8247d0342"),
    }
    for path, value in assets.items():
        if core.get(path) != value:
            fail(f"pinned render asset mismatch: {path}")
    extension_paths = ("ext/questionable_decisions/", "ext/correspondence/")
    if any(path.startswith(extension_paths) for path in core_expected):
        fail("stock core collides with a staged extension destination")

    if phase == "stage":
        if scratch.exists() or scratch.is_symlink():
            fail("per-run scratch already exists")
        os.umask(0o022)
        scratch.mkdir(mode=0o755)
        stage_root = scratch / "stage"
        core_target = stage_root / "core"
        server_target = core_target / "ext/questionable_decisions"
        owner_target = core_target / "ext/correspondence"
        if stage_root.exists():
            fail("stage target already exists")
        stage_root.mkdir(mode=0o755)
        core_target.mkdir(mode=0o755)
        def copy_entries(selected, source_root, destination_root, map_owner=False):
            for relative in sorted(selected):
                source = ensure_chain(source_root / Path(relative), source_root)
                target_relative = relative[len("server/"):] if map_owner else relative
                target = destination_root / Path(target_relative)
                target.parent.mkdir(parents=True, exist_ok=True)
                if target.exists() or target.is_symlink():
                    fail(f"stage destination unexpectedly exists: {target}")
                expected_size, expected_hash = selected[relative]
                shutil.copyfile(source, target, follow_symlinks=False)
                if target.is_symlink() or not target.is_file():
                    fail(f"staged source is not a regular file: {target}")
                if target.stat().st_size != expected_size or sha256(target) != expected_hash:
                    fail(f"staged source differs from its pinned bytes: {relative}")
                os.chmod(target, 0o644)
        copy_entries(core, core_source, core_target)
        copy_entries(dept, dept_root, server_target)
        copy_entries(owner | owner_payload, owner_root, owner_target, map_owner=True)
        runtime_dir = core_target / "log/playthrough_runtime"
        runtime_dir.mkdir(mode=0o770, parents=True, exist_ok=True)
        print(json.dumps({"stagedCoreFiles": len(core), "stagedDepartmentFiles": len(dept), "stagedOwnerClosureFiles": len(owner), "stagedOwnerPayloadFiles": len(owner_payload), "runId": run_id}, sort_keys=True))
    elif phase == "verify":
        core_target = scratch / "stage/core"
        validate_staged_files(core, core_target)
        validate_staged_files(dept, core_target / "ext/questionable_decisions")
        validate_staged_files(owner | owner_payload, core_target / "ext/correspondence", strip_owner_prefix=True)
        print(json.dumps({"verifiedRoots": 6, "verifiedFiles": len(entries), "runId": run_id}, sort_keys=True))
    else:
        fail("unknown source verification phase")
except SystemExit:
    raise
except Exception as exc:
    fail(f"source verification failed: {type(exc).__name__}")
PY
}

verify_and_stage_sources stage

python3 - "$SCRATCH/fixture-marker.json" "$RUN_ID" "$CORE_ROOT" "$SELF_NET" <<'PY'
import json, os, sys
path, run_id, core_root, netns = sys.argv[1:]
value = {
    "schema": "department.dev23.fixture.v1",
    "runId": run_id,
    "coreRoot": core_root,
    "networkNamespace": netns,
}
fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
with os.fdopen(fd, "w", encoding="utf-8") as stream:
    json.dump(value, stream, sort_keys=True, separators=(",", ":"))
    stream.write("\n")
os.chmod(path, 0o644)
PY
mkdir -p -- "$HOME_DIR"
chmod 0700 "$HOME_DIR"
id -u www-data >/dev/null 2>&1 || abort 'www-data user is required for the actual facade driver.'
chown www-data:www-data "$CORE_ROOT/log/playthrough_runtime"
chmod 0770 "$CORE_ROOT/log/playthrough_runtime"
[[ -d "$CORE_ROOT/data" && ! -L "$CORE_ROOT/data" ]] || abort 'Pinned core data directory is absent or redirected.'
mkdir -m 0770 -p -- "$CORE_ROOT/data/books"
[[ -d "$CORE_ROOT/data/books" && ! -L "$CORE_ROOT/data/books" ]] || abort 'Private renderer output directory is absent or redirected.'
chown www-data:www-data "$CORE_ROOT/data/books"
chmod 0770 "$CORE_ROOT/data/books"
mkdir -p -- "$SOCKET_DIR" "$PGDATA"
chown postgres:postgres "$SOCKET_DIR" "$PGDATA"
chmod 0700 "$SOCKET_DIR" "$PGDATA"

PG_BINDIR='/usr/lib/postgresql/15/bin'
INITDB="$PG_BINDIR/initdb"
PG_CTL="$PG_BINDIR/pg_ctl"
PG_CONTROLDATA="$PG_BINDIR/pg_controldata"
PHP_BIN="$(command -v php || true)"
PSQL_BIN="$(command -v psql || true)"
[[ "$PHP_BIN" == /* && -x "$PHP_BIN" ]] || abort 'PHP CLI is unavailable.'
[[ "$PSQL_BIN" == /* && -x "$PSQL_BIN" ]] || abort 'psql is unavailable.'
[[ -x "$INITDB" && -x "$PG_CTL" && -x "$PG_CONTROLDATA" ]] || abort 'PostgreSQL 15 server utilities are unavailable.'
[[ "$(runuser -u postgres -- "$INITDB" --version)" == *'15.'* ]] || abort 'Private fixture requires PostgreSQL 15.'
command -v pg_isready >/dev/null 2>&1 || abort 'pg_isready is required.'

if ! env -i PATH=/usr/bin:/bin HOME="$HOME_DIR" LANG=C.UTF-8 LC_ALL=C.UTF-8 "$PHP_BIN" -m | tr '[:upper:]' '[:lower:]' | grep -qx 'pgsql'; then
    abort 'PHP CLI must load the pgsql extension.'
fi
if ! env -i PATH=/usr/bin:/bin HOME="$HOME_DIR" LANG=C.UTF-8 LC_ALL=C.UTF-8 "$PHP_BIN" -m | tr '[:upper:]' '[:lower:]' | grep -qx 'mbstring'; then
    abort 'PHP CLI must load mbstring for the foundation suite.'
fi
if ! env -i PATH=/usr/bin:/bin HOME="$HOME_DIR" LANG=C.UTF-8 LC_ALL=C.UTF-8 "$PHP_BIN" -m | tr '[:upper:]' '[:lower:]' | grep -qx 'ctype'; then
    abort 'PHP CLI must load ctype for the foundation suite.'
fi

if ! env -i PATH=/usr/bin:/bin HOME="$HOME_DIR" LANG=C.UTF-8 LC_ALL=C.UTF-8 "$PHP_BIN" -r '
$required = ["imagettfbbox", "imagecreatefrompng", "imagettftext", "imagepng"];
foreach ($required as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "Missing required PHP renderer function: {$function}" . PHP_EOL);
        exit(1);
    }
}
'; then
    abort 'PHP CLI must provide GD renderer functions: imagettfbbox, imagecreatefrompng, imagettftext, imagepng.'
fi

"$INITDB" --version >"$RUN_ROOT/postgres-version.txt"
runuser -u postgres -- "$INITDB" -D "$PGDATA" --auth-local=trust --auth-host=trust --username=postgres --no-instructions >"$RUN_ROOT/initdb.stdout.txt" 2>"$RUN_ROOT/initdb.stderr.txt"
cat >>"$PGDATA/postgresql.conf" <<EOF
listen_addresses = '127.0.0.1'
port = 5432
unix_socket_directories = '$SOCKET_DIR'
unix_socket_permissions = 0700
log_destination = 'stderr'
logging_collector = off
log_line_prefix = '%m [%p] user=%u,db=%d,app=%a,client=%h '
log_statement = 'all'
log_min_messages = info
EOF
if runuser -u postgres -- "$PG_CTL" -D "$PGDATA" -l "$PGDATA/postgres.log" -w -t 30 start >"$RUN_ROOT/postgres-start.stdout.txt" 2>"$RUN_ROOT/postgres-start.stderr.txt"; then
    PG_STARTED=1
elif runuser -u postgres -- "$PG_CTL" -D "$PGDATA" status >/dev/null 2>&1; then
    PG_STARTED=1
    abort 'PostgreSQL startup reported failure after creating a running process.'
else
    abort 'Private PostgreSQL did not start.'
fi
POSTMASTER_PID="$(head -n 1 "$PGDATA/postmaster.pid" | tr -d '[:space:]')"
[[ "$POSTMASTER_PID" =~ ^[1-9][0-9]*$ ]] || abort 'PostgreSQL did not create a valid owned postmaster PID.'
SYSTEM_IDENTIFIER="$(runuser -u postgres -- "$PG_CONTROLDATA" "$PGDATA" | awk -F: '/Database system identifier/ { gsub(/[[:space:]]/, "", $2); print $2 }')"
[[ "$SYSTEM_IDENTIFIER" =~ ^[1-9][0-9]*$ ]] || abort 'PostgreSQL system identifier could not be verified.'
pg_isready -h 127.0.0.1 -p 5432 -d postgres -U postgres >/dev/null || abort 'Private PostgreSQL did not become ready.'
[[ -z "$(ss -ltnH | awk '$4 !~ /^127\.0\.0\.1:5432$/ { print }')" ]] || abort 'Private PostgreSQL exposed an unexpected TCP listener.'
[[ "$(ss -ltnH | awk '$4 ~ /^127\.0\.0\.1:5432$/ { n++ } END { print n+0 }')" -eq 1 ]] || abort 'Private PostgreSQL did not bind exactly one loopback listener.'
assert_owned_postgres_identity || abort 'Private PostgreSQL identity or loopback boundary changed after startup.'

runuser -u postgres -- "$PSQL_BIN" -X -q -h "$SOCKET_DIR" -p 5432 -U postgres -d postgres -v ON_ERROR_STOP=1 -c "CREATE ROLE dwemer LOGIN PASSWORD 'dwemer' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;" >"$RUN_ROOT/create-role.stdout.txt" 2>"$RUN_ROOT/create-role.stderr.txt"
runuser -u postgres -- "$PSQL_BIN" -X -q -h "$SOCKET_DIR" -p 5432 -U postgres -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE dwemer OWNER dwemer;' >"$RUN_ROOT/create-database.stdout.txt" 2>"$RUN_ROOT/create-database.stderr.txt"
runuser -u postgres -- "$PSQL_BIN" -X -q -h "$SOCKET_DIR" -p 5432 -U postgres -d dwemer -v ON_ERROR_STOP=1 -f "$DRIVER_ROOT/base.sql" >"$RUN_ROOT/base-sql.stdout.txt" 2>"$RUN_ROOT/base-sql.stderr.txt"
OWNER_SCHEMA_EXISTS="$(runuser -u postgres -- "$PSQL_BIN" -X -Atq -h "$SOCKET_DIR" -p 5432 -U postgres -d dwemer -c "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'plugins');")"
[[ "$OWNER_SCHEMA_EXISTS" == 'f' ]] || abort 'Fresh owner schema exists before the no-schema refusal case.'

CHECKS_FILE="$RUN_ROOT/host-checks.tsv"
: >"$CHECKS_FILE"
run_check() {
    local check_id="$1"
    shift
    local out="$RUN_ROOT/$check_id.stdout.txt"
    local err="$RUN_ROOT/$check_id.stderr.txt"
    local status=0
    if env -i PATH=/usr/bin:/bin HOME="$HOME_DIR" LANG=C.UTF-8 LC_ALL=C.UTF-8 "$@" >"$out" 2>"$err"; then
        status=0
    else
        status="$?"
        CHECKS_FAILED=1
        if [[ -z "$FAILURE" ]]; then FAILURE="${check_id}_failed"; fi
    fi
    printf '%s\t%s\n' "$check_id" "$status" >>"$CHECKS_FILE"
}
run_check process-group-self-test "$PHP_BIN" "$SCRIPT_DIR/pg_scenarios.php" --process-group-self-test
run_check journal-directory-fsync "$PHP_BIN" "$SERVER_ROOT/tests/foundation/journal.php" --directory-fsync-guard
run_check dev14-interface "$PHP_BIN" -n -d extension=mbstring -d extension=ctype "$SERVER_ROOT/tests/correspondence/dev14-interface-compatibility.php"
run_check shared-generation-reader "$PHP_BIN" "$SERVER_ROOT/tests/correspondence/dev18-generation-reader.php"
run_check shared-ingress-authority "$PHP_BIN" "$SERVER_ROOT/tests/correspondence/dev18-ingress-authority.php"
run_check shared-lease-identity "$PHP_BIN" "$SERVER_ROOT/tests/correspondence/dev18-lease-identity.php"
run_check shared-owned-close "$PHP_BIN" "$SERVER_ROOT/tests/correspondence/dev18-owned-close.php"
run_check dev23-source-contract "$PHP_BIN" -n -d extension=mbstring -d extension=ctype "$SERVER_ROOT/tests/correspondence/dev23-source-contract.php" --owner-root "$OWNER_SOURCE/server"

if (( CHECKS_FAILED == 0 )); then
    local_driver="$SCRIPT_DIR/pg_scenarios.php"
    [[ -f "$local_driver" && ! -L "$local_driver" ]] || abort 'Authoritative PostgreSQL scenario driver is absent or redirected.'
    if env -i PATH=/usr/bin:/bin HOME="$HOME_DIR" LANG=C.UTF-8 LC_ALL=C.UTF-8 "$PHP_BIN" "$local_driver" \
        --run-id "$RUN_ID" \
        --artifact-dir "$RUN_ROOT" \
        --core-root "$CORE_ROOT" \
        --server-root "$SERVER_ROOT" \
        --owner-root "$OWNER_SOURCE" \
        --test-root "$DRIVER_ROOT" \
        --runtime-root "$SCRIPT_DIR" \
        --php "$PHP_BIN" \
        --pg-socket "$SOCKET_DIR" \
        --pg-host 127.0.0.1 \
        --pg-port 5432 \
        --pg-database dwemer \
        --pg-admin-role postgres \
        --pg-app-role dwemer \
        --scratch-root "$SCRATCH" \
        --fixture-marker "$SCRATCH/fixture-marker.json" \
        --pg-log-path "$PGDATA/postgres.log" >"$RUN_ROOT/linux-scenarios.stdout.txt" 2>"$RUN_ROOT/linux-scenarios.stderr.txt"; then
        SCENARIO_CHECK=1
    else
        scenario_exit="$?"
        printf 'Scenario driver exited %s\n' "$scenario_exit" >>"$RUN_ROOT/linux-scenarios.stderr.txt"
        CHECKS_FAILED=1
        if [[ -z "$FAILURE" ]]; then FAILURE="scenario_driver_exit_${scenario_exit}"; fi
    fi
else
    printf 'Skipped PG scenario driver because a required host check failed.\n' >"$RUN_ROOT/linux-scenarios.skipped.txt"
    CHECKS_FAILED=1
fi

if (( SCENARIO_CHECK == 1 )); then
    if python3 - "$RUN_ROOT/linux.scenarios.summary.json" "$RUN_ROOT/linux.scenarios.jsonl" "$RUN_ID" "$RUN_ROOT/network-isolation.json" <<'PY'
import json, sys
summary_path, jsonl_path, run_id, network_path = sys.argv[1:]
expected_ids = [
    "bootstrap_exact_pins",
    "read_does_not_initialize",
    "source_drift_refuses_facade",
    "restore_init_marker_repair_and_overlap",
    "read_waits_for_init_fence",
    "read_all_facade_projections",
    "read_unlock_failure_closes_connection",
    "valid_compose",
    "valid_reconcile",
    "signed_unknown_negative_delta",
    "signed_unknown_overdelivery",
    "signed_unknown_partial_receipt",
    "negative_and_overshoot",
    "request_id_unknown_no_resend",
    "preserve_dev14_to_dev18",
    "sentinel_db_epoch_refusal",
    "null_request_token_refusal",
    "preprocessed_request_globals_refusal",
    "captured_current_token_success",
    "captured_current_native_floor_cases",
    "initial_pointer_generation_absence_allowed",
    "pointer_generation_refusal",
    "borrowed_work_lock_path_replacement_refusal",
    "globals_and_borrowed_lease_restoration",
    "controller_pause_with_held_lease",
    "epoch_change_inflight_observed_queue",
    "unrelated_event_inflight",
    "pause_between_lease_and_dispatch",
    "begin_commit_before_owner_factory",
    "operator_close_late_receipt",
    "operator_close_identity_contradiction",
    "full_forwarded_letter_detail",
    "actual_sql_and_row_deltas",
]
with open(summary_path, encoding="utf-8") as stream:
    value = json.load(stream)
cases = value.get("cases")
companion = value.get("companionCases")
with open(jsonl_path, encoding="utf-8") as stream:
    rows = [json.loads(line) for line in stream if line.strip()]
summary_cases = cases if isinstance(cases, list) else []
ids = [row.get("id") for row in rows if isinstance(row, dict)]
companion_cases = summary_cases[33:]
companion_ids = [case.get("id") for case in companion_cases if isinstance(case, dict)]
companion_expected = companion.get("expectedCases") if isinstance(companion, dict) else None
expected_total = 33 + companion_expected if isinstance(companion_expected, int) and not isinstance(companion_expected, bool) else -1
with open(network_path, encoding="utf-8") as stream:
    network = json.load(stream)
if (value.get("schema") != "department.dev20.pg-scenarios.v1"
        or value.get("runId") != run_id or value.get("passed") is not True
        or value.get("facadeExpectedCases") != 33
        or value.get("expectedCases") != expected_total or value.get("executedCases") != expected_total
        or value.get("passedCases") != expected_total or value.get("failedCases") != []
        or type(value.get("providerCalls")) is not int or value.get("providerCalls") != 0
        or value.get("ownerClosureFileCount") != 19
        or not isinstance(cases, list) or len(cases) != expected_total
        or any(not isinstance(case, dict) or not isinstance(case.get("id"), str) or case.get("status") != "passed" for case in cases)
        or len(rows) != expected_total or len(ids) != expected_total or len(set(ids)) != expected_total
        or any(not isinstance(row, dict) or row.get("schema") != "department.dev20.pg-case-result.v1" or row.get("status") != "passed" for row in rows)
        or [case["id"] for case in summary_cases[:33]] != expected_ids
        or ids[:33] != expected_ids
        or not isinstance(companion, dict) or companion.get("schema") != "department.dev23.companion-cases.v1"
        or companion.get("passed") is not True or companion_expected < 1
        or companion.get("executedCases") != companion_expected or companion.get("passedCases") != companion_expected
        or companion.get("failedCases") != [] or companion.get("caseIds") != companion_ids
        or any(not isinstance(case_id, str) or not case_id.startswith("companion_") for case_id in companion_ids)
        or companion.get("providerCalls") != 0 or len(companion_ids) != companion_expected
        or ids[33:] != companion_ids
        or network.get("schema") != "department.dev23.network-isolation.v1"
        or network.get("distinctFromDistributionInit") is not True or network.get("interfaces") != ["lo"]
        or network.get("ipv4MainRoutes") != [] or network.get("ipv6MainRoutes") != []
        or network.get("ipv4DefaultRoutes") != [] or network.get("ipv6DefaultRoutes") != []
        or network.get("preexistingTcpListeners") != []
        or network.get("postgresListenAddress") != "127.0.0.1:5432"
        or network.get("providerCalls") != 0 or network.get("campaignBootstrap") is not False):
    raise SystemExit("scenario summary does not satisfy the retained facade and bounded companion contract")
PY
    then
        :
    else
        SCENARIO_CHECK=0
        CHECKS_FAILED=1
        if [[ -z "$FAILURE" ]]; then FAILURE='scenario_summary_contract_failed'; fi
    fi
fi

if verify_and_stage_sources verify; then
    SOURCE_POSTCHECK=1
else
    CHECKS_FAILED=1
    if [[ -z "$FAILURE" ]]; then FAILURE='source_postcheck_failed'; fi
fi
if (( CHECKS_FAILED == 0 && SCENARIO_CHECK == 1 )); then RUN_OK=1; else RUN_OK=0; fi
