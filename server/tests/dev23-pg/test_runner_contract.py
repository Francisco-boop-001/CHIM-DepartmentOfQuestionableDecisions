from __future__ import annotations

import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
RUNNER = Path(__file__).resolve().parent
REQUIRED_COVERAGE = {
    "bootstrap",
    "catalog",
    "mailbox",
    "detail",
    "compose",
    "reconcile",
    "restore_overlap",
    "stale_authority",
    "read_fence_lease_ownership",
    "operator_close_pruned",
    "late_receipt",
    "signed_unknown_partial",
    "no_resend",
    "legacy_pins",
    "full_forward_body",
}


class Dev23RunnerContractTests(unittest.TestCase):
    def test_source_bindings_schema_matches_package_freeze(self) -> None:
        producer = (RUNNER / "build-source-bindings.ps1").read_text(encoding="utf-8")
        consumer = (RUNNER / "run-dev23-owner-facade.sh").read_text(encoding="utf-8")
        package = (ROOT / "packaging" / "build-installable-prealpha13.py").read_text(encoding="utf-8")
        producer_match = re.search(r"schema\s*=\s*'(?P<schema>department\.dev[0-9]+\.runtime-source-bindings\.v1)'", producer)
        consumer_match = re.search(r'bindings\.get\("schema"\)\s*!=\s*"(?P<schema>department\.dev[0-9]+\.runtime-source-bindings\.v1)"', consumer)
        freeze_match = re.search(r'^FREEZE_SCHEMA\s*=\s*"(?P<schema>department\.dev[0-9]+\.runtime-source-bindings\.v1)"', package, re.MULTILINE)
        self.assertIsNotNone(producer_match)
        self.assertIsNotNone(consumer_match)
        self.assertIsNotNone(freeze_match)
        self.assertEqual(producer_match.group("schema"), freeze_match.group("schema"))
        self.assertEqual(consumer_match.group("schema"), freeze_match.group("schema"))

    def test_runner_sources_exist_before_host_execution(self) -> None:
        required = (
            "launch-dev23-test.ps1",
            "run-dev23-owner-facade.sh",
            "pg_scenarios.php",
            "companion_cases.php",
            "controller_switch.php",
            "build-source-bindings.ps1",
            "verify-dev23-harness.ps1",
            "verify-owner-pins.ps1",
            "owner-runtime-pins.json",
            "coverage.json",
            "base.sql",
            "api_driver.php",
            "cases.json",
            "fixture_guard.php",
            "owner_fixture.php",
            "seed_unknown_receipt.php",
        )
        for name in required:
            with self.subTest(name=name):
                self.assertTrue((RUNNER / name).is_file(), f"missing tracked dev23 runner input: {name}")

    def test_host_runner_requires_the_reserved_running_test2_without_lifecycle_commands(self) -> None:
        path = RUNNER / "launch-dev23-test.ps1"
        self.assertTrue(path.is_file(), "host runner is required")
        source = path.read_text(encoding="utf-8")
        self.assertIn("HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Lxss", source)
        self.assertIn("DwemerAI4Skyrim3-test2", source)
        self.assertIn("85d28a7e-bf8b-44ab-9f2e-3da197bd8d04", source)
        self.assertIn("{164d71de-0650-43d8-b1b0-8b94e0841cd3}", source)
        self.assertIn("E:\\WSL\\DwemerAI4Skyrim3-test2", source)
        self.assertIn("Assert-ApprovedRegistration", source)
        self.assertIn("test2-registration-before.json", source)
        self.assertRegex(source, r"-InitialState\s+\$script:initialState")
        self.assertRegex(source, r"-FinalState\s+\$script:finalState")
        self.assertIn("testDistroStopVerified", source)
        self.assertIn("preservationVerified = (", source)
        self.assertNotRegex(source, r"(?i)--terminate|--shutdown|Stop-Service|Stop-Process.*wsl")
        self.assertNotRegex(source, r"(?i)AIAgent\.dll|ext4\.vhdx|protectedVhd")
        self.assertIn("wrongGuidRefused", source)
        self.assertIn("wrongBaseRefused", source)

    def test_linux_runner_is_route_free_and_stops_only_its_owned_loopback_postgres(self) -> None:
        path = RUNNER / "run-dev23-owner-facade.sh"
        self.assertTrue(path.is_file(), "Linux runner is required")
        source = path.read_text(encoding="utf-8")
        self.assertIn("readonly EXPECTED_SCRATCH_ROOT='/var/tmp'", source)
        self.assertIn('SCRATCH="$EXPECTED_SCRATCH_ROOT/department-dev23-$RUN_ID"', source)
        self.assertIn('SELF_NET="$(readlink /proc/self/ns/net)"', source)
        self.assertIn('INIT_NET="$(readlink /proc/1/ns/net)"', source)
        self.assertIn("ip link set lo up", source)
        self.assertIn('INTERFACES="$(ip -o link show', source)
        self.assertIn('ip -4 route show table main', source)
        self.assertIn('ip -6 route show table main', source)
        self.assertIn("listen_addresses = '127.0.0.1'", source)
        self.assertIn('ss -ltnH', source)
        self.assertIn('runuser -u postgres -- "$PG_CTL" -D "$PGDATA" -m fast -w -t 30 stop', source)
        self.assertIn('assert_owned_postgres_identity', source)
        self.assertIn('system_identifier', source)
        self.assertNotIn(' -m immediate ', source)
        self.assertNotIn("--terminate", source)
        self.assertNotIn("wsl --shutdown", source.lower())
        self.assertNotIn("systemctl stop", source.lower())
        self.assertIn("failedRunArtifactsPreserved", source)
        self.assertNotIn('rm -rf -- "$SCRATCH"', source)

    def test_scenario_runtime_root_matches_the_artifact_directory_contract(self) -> None:
        linux = (RUNNER / "run-dev23-owner-facade.sh").read_text(encoding="utf-8")
        scenarios = (RUNNER / "pg_scenarios.php").read_text(encoding="utf-8")
        self.assertIn('readonly RUNTIME_ROOT=', linux)
        self.assertIn('--runtime-root "$RUNTIME_ROOT"', linux)
        self.assertNotIn('--runtime-root "$SCRIPT_DIR"', linux)
        self.assertIn("realpath($ctx['runtimeRoot'] . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $runId)", scenarios)

    def test_controller_helper_uses_test_root_and_persists_outcome_on_success_and_finally(self) -> None:
        scenarios = (RUNNER / "pg_scenarios.php").read_text(encoding="utf-8")
        case_start = scenarios.index("// Case 25: controller pauses while the request owns SH")
        case_end = scenarios.index("// Case 26:", case_start)
        case = scenarios[case_start:case_end]
        self.assertIn("$ctx['testRoot'] . DIRECTORY_SEPARATOR . 'controller_switch.php'", case)
        self.assertNotIn("$ctx['runtimeRoot'] . DIRECTORY_SEPARATOR . 'controller_switch.php'", scenarios)
        self.assertIn("function dev20_persist_controller_process_outcome(", scenarios)
        self.assertIn("dev20_persist_process_output_files($directory, $result, 'controller');", scenarios)
        self.assertIn("'controller.exit.json'", scenarios)
        self.assertIn("dev20_persist_controller_process_outcome($caseDirectory, $controllerResult);", case)
        self.assertIn("dev20_persist_controller_process_outcome($caseDirectory, $controllerResult, $controllerFinishFailure);", case)
        self.assertIn("'timedOut' => null", case)
        self.assertIn("dev20_finish_process($controller, 15)", case)

    def test_preflight_failure_detail_is_bounded_and_path_redacted(self) -> None:
        scenarios = (RUNNER / "pg_scenarios.php").read_text(encoding="utf-8")
        self.assertIn("function dev20_safe_failure_detail(Throwable $error): string", scenarios)
        self.assertIn("preg_match('/\\\\A[a-z][a-z0-9_]{0,63}\\\\z/iD', $message)", scenarios)
        self.assertIn("return 'detail_unavailable';", scenarios)
        self.assertIn("dev20_safe_failure_detail(new RuntimeException('artifact_directory_run_mismatch'))", scenarios)
        self.assertIn("dev20_safe_failure_detail(new RuntimeException('/mnt/private/source/path'))", scenarios)
        self.assertIn("dev20_fail($kind . '_' . dev20_safe_failure_detail($error));", scenarios)

    def test_coverage_and_owner_pins_match_the_frozen_dev23_sources(self) -> None:
        coverage_path = RUNNER / "coverage.json"
        self.assertTrue(coverage_path.is_file(), "coverage manifest is required")
        coverage = json.loads(coverage_path.read_text(encoding="utf-8"))
        self.assertEqual(coverage.get("schema"), "department.dev23.runtime-coverage.v1")
        self.assertEqual(set(coverage.get("behaviorCoverage", [])), REQUIRED_COVERAGE)
        pins_path = RUNNER / "owner-runtime-pins.json"
        self.assertTrue(pins_path.is_file(), "owner closure pins are required")
        pins = json.loads(pins_path.read_text(encoding="utf-8"))
        self.assertEqual(pins.get("schema"), "department.dev23.owner-runtime-pins.v1")
        self.assertEqual(pins.get("ownerVersion"), "0.1.0-dev.23")
        self.assertEqual(pins.get("ownerCommit"), "01d62982d9b10ca4fce040af9d97fe7111231ba3")
        self.assertEqual(pins.get("ownerClosureCount"), 19)
        self.assertEqual(len(pins.get("files", [])), 19)
        self.assertEqual(pins.get("fullPayloadCount"), 30)
        self.assertEqual(len(pins.get("payloadFiles", [])), 11)
        combined = [entry["path"] for entry in pins["files"] + pins["payloadFiles"]]
        self.assertEqual(len(combined), len(set(combined)))
        self.assertEqual(len(combined), 30)

    def test_host_receipt_records_preservation_without_distro_lifecycle_actions(self) -> None:
        source = (RUNNER / "launch-dev23-test.ps1").read_text(encoding="utf-8")
        linux = (RUNNER / "run-dev23-owner-facade.sh").read_text(encoding="utf-8")
        self.assertIn('testDistroStopVerified = $false', source)
        self.assertIn("targetState = ([string]$targetRows[0].state).ToLowerInvariant()", source)
        self.assertIn("$script:initialState -ceq 'running'", source)
        self.assertIn("$script:finalState -ceq 'running'", source)
        self.assertIn("function New-TestDistroLifecycleReceipt", source)
        self.assertIn("$InitialState -ceq 'running'", source)
        self.assertIn("$FinalState -ceq 'running'", source)
        self.assertIn("$lifecycle.preservationVerified -ne $true", source)
        for schema in (
            "department.dev20.linux-run-status.v1",
            "department.dev23.private-postgres-cleanup.v1",
            "department.dev23.network-isolation.v1",
        ):
            self.assertIn(f"-cne '{schema}'", source)
            self.assertIn(f'"schema": "{schema}"', linux)
        for marker in ("startedByRun = $false", "stopRequested = $false", "preservationVerified = ("):
            self.assertIn(marker, source)
        self.assertIn("handoff_required_before_independent_rerun", source)

    def test_companion_case_uses_a_synthetic_callback_and_never_calls_a_provider(self) -> None:
        path = RUNNER / "companion_cases.php"
        self.assertTrue(path.is_file(), "cross-boundary companion case is required")
        source = path.read_text(encoding="utf-8")
        for marker in (
            "CorrespondenceCompanionPg",
            "CorrespondenceService",
            "dev23_companion_with_owner_runtime",
            "dev20_api_call",
            "qd_api_runtime_dependencies",
            "$companion->tick($model)",
            "correspondence.mailbox.read",
            "correspondence.detail.read",
            "correspondence.reconcile",
            "companion_scheduler_owned",
            "dispatch_unknown",
            "operator_closed",
            "providerCalls' => 0",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, source)
        self.assertNotRegex(source, r"(?i)curl_exec\s*\(|file_get_contents\s*\(\s*['\"]https?://|OpenAI|Anthropic")

    def test_dev23_fixture_adds_only_the_owner_companion_actor_columns(self) -> None:
        source = (RUNNER / "base.sql").read_text(encoding="utf-8")
        self.assertIn("personality text NOT NULL DEFAULT ''", source)
        self.assertIn("extended_data jsonb NOT NULL DEFAULT '{}'", source)
        self.assertIn("metadata jsonb NOT NULL DEFAULT '{}'", source)
        self.assertIn("(900003,'Lydia'", source)

    def test_direct_fixture_guard_refuses_the_distribution_network_namespace(self) -> None:
        source = (RUNNER / "fixture_guard.php").read_text(encoding="utf-8")
        self.assertIn("readlink('/proc/self/ns/net')", source)
        self.assertIn("readlink('/proc/1/ns/net')", source)
        self.assertIn("$selfNamespace === $initNamespace", source)
        self.assertIn("fixture_network_namespace_not_isolated", source)

    def test_private_fixture_guard_uses_protected_initial_namespace_and_role_probes(self) -> None:
        guard = (RUNNER / "fixture_guard.php").read_text(encoding="utf-8")
        linux = (RUNNER / "run-dev23-owner-facade.sh").read_text(encoding="utf-8")
        api = (RUNNER / "api_driver.php").read_text(encoding="utf-8")
        self.assertIn("qd_dev23_is_network_namespace($selfNamespace)", guard)
        self.assertIn("$effectiveUid === 0", guard)
        self.assertIn("fixture_initial_network_namespace_unreadable", guard)
        self.assertIn("$selfNamespace === $initNamespace", guard)
        self.assertIn("'initialNetworkNamespace'", guard)
        self.assertIn("$initNamespace !== $marker['initialNetworkNamespace']", guard)
        self.assertIn("'initialNetworkNamespace' => $marker['initialNetworkNamespace']", guard)
        self.assertIn('"initialNetworkNamespace": init_netns', linux)
        self.assertIn('"$SELF_NET" "$INIT_NET"', linux)
        self.assertIn("runuser -u www-data -- /usr/bin/env -i", linux)
        self.assertIn('if "$NSENTER_BIN" --net=/proc/1/ns/net -- /usr/sbin/runuser -u www-data -- /usr/bin/env -i', linux)
        self.assertIn('in_array($error->getMessage(), ["fixture_network_namespace_not_isolated", "fixture_marker_mismatch"], true)', linux)
        self.assertIn("fixture_network_namespace_not_isolated", linux)
        self.assertLess(linux.index("fixture-guard-www-data"), linux.index('runuser -u postgres -- "$INITDB" -D "$PGDATA"'))
        self.assertLess(linux.index("fixture-guard-initial-namespace"), linux.index('runuser -u postgres -- "$INITDB" -D "$PGDATA"'))
        self.assertIn("private_fixture_guard_refused_", api)
        self.assertIn("$detail = 'detail_unavailable'", api)
        self.assertNotIn("private_fixture_guard_refused'", api)


if __name__ == "__main__":
    unittest.main()
