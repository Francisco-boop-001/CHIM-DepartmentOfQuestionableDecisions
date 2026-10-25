[CmdletBinding()]
param(
    [string]$OwnerRoot = 'K:\ActorwrightExchange\artifacts\chim-sealed-fates-release-dev14-20261020\source',
    [string]$TestRoot = 'K:\ActorwrightExchange\projects\CHIM-DepartmentOfQuestionableDecisions-dev18\server\tests\dev23-pg',
    [string]$RuntimeRoot = 'K:\ActorwrightExchange\artifacts\department-dev23-compatibility-20261024\runtime'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-Json([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw 'Required harness JSON is missing.' }
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Harness JSON is redirected.' }
    Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json -Depth 16
}

function Read-Text([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw 'Required harness source is missing.' }
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Harness source is redirected.' }
    Get-Content -LiteralPath $Path -Raw
}

function Assert-Contains([string]$Text, [string[]]$Needles, [string]$Failure) {
    foreach ($needle in $Needles) {
        if (-not $Text.Contains($needle)) { throw $Failure }
    }
}

$expectedTestRoot = 'K:\ActorwrightExchange\projects\CHIM-DepartmentOfQuestionableDecisions-dev18\server\tests\dev23-pg'
$expectedRuntimeRoot = 'K:\ActorwrightExchange\artifacts\department-dev23-compatibility-20261024\runtime'
if ([IO.Path]::GetFullPath($TestRoot).TrimEnd('\') -cne $expectedTestRoot -or
    [IO.Path]::GetFullPath($RuntimeRoot).TrimEnd('\') -cne $expectedRuntimeRoot) {
    throw 'Harness roots must match the exact dev23 source and artifact roots.'
}
foreach ($path in @($TestRoot, $OwnerRoot)) {
    $item = Get-Item -LiteralPath $path -Force
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'A harness root is missing or redirected.' }
}
if (Test-Path -LiteralPath $RuntimeRoot) {
    $runtimeItem = Get-Item -LiteralPath $RuntimeRoot -Force
    if (-not $runtimeItem.PSIsContainer -or ($runtimeItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'The runtime artifact root is not a normal directory.' }
}

$cases = Read-Json (Join-Path $TestRoot 'cases.json')
if ($cases.schema -ne 'department.dev20.public-facade-cases.v3' -or
    $cases.entryPoint -ne 'qd_api_handle(POST, application/json, ..., qd_api_runtime_dependencies())') {
    throw 'The retained 33-case facade manifest has an unexpected contract.'
}
$requiredIds = @(
    'bootstrap_exact_pins', 'read_does_not_initialize', 'source_drift_refuses_facade',
    'restore_init_marker_repair_and_overlap', 'read_waits_for_init_fence', 'read_all_facade_projections',
    'read_unlock_failure_closes_connection', 'valid_compose', 'valid_reconcile',
    'signed_unknown_negative_delta', 'signed_unknown_overdelivery', 'signed_unknown_partial_receipt',
    'negative_and_overshoot', 'request_id_unknown_no_resend', 'preserve_dev14_to_dev18',
    'sentinel_db_epoch_refusal', 'null_request_token_refusal', 'preprocessed_request_globals_refusal',
    'captured_current_token_success', 'captured_current_native_floor_cases',
    'initial_pointer_generation_absence_allowed', 'pointer_generation_refusal',
    'borrowed_work_lock_path_replacement_refusal', 'globals_and_borrowed_lease_restoration',
    'controller_pause_with_held_lease', 'epoch_change_inflight_observed_queue', 'unrelated_event_inflight',
    'pause_between_lease_and_dispatch', 'begin_commit_before_owner_factory',
    'operator_close_late_receipt', 'operator_close_identity_contradiction',
    'full_forwarded_letter_detail', 'actual_sql_and_row_deltas'
)
$actualIds = @($cases.cases | ForEach-Object { [string]$_.id })
$actualSignature = $actualIds -join '|'
$requiredSignature = $requiredIds -join '|'
if ($actualIds.Count -ne 33 -or (@($actualIds | Sort-Object -Unique).Count -ne 33) -or
    $actualSignature -cne $requiredSignature) {
    throw 'The retained 33-case facade list is incomplete, duplicated, or reordered.'
}

$driver = Read-Text (Join-Path $TestRoot 'api_driver.php')
Assert-Contains $driver @('qd_api_runtime_dependencies()', 'qd_api_handle(', 'qd_api_handle(') 'Driver does not use the real public API boundary.'
if ($driver.Contains('qd_server_dispatch(') -or $driver.Contains('new CorrespondenceService')) {
    throw 'Driver bypasses the public API dependency chain.'
}
$fixtureGuard = Read-Text (Join-Path $TestRoot 'fixture_guard.php')
Assert-Contains $fixtureGuard @(
    'QD_DEV20_RUN_ID',
    '/var/tmp/department-dev23-',
    'department.dev23.fixture.v1',
    'readlink(''/proc/self/ns/net'')',
    'readlink(''/proc/1/ns/net'')',
    '$selfNamespace === $initNamespace'
) 'Private fixture guard is not bound to the dev23 scratch root and isolated namespace.'
$baseSql = Read-Text (Join-Path $TestRoot 'base.sql')
Assert-Contains $baseSql @(
    'personality text NOT NULL DEFAULT ''''',
    'extended_data jsonb NOT NULL DEFAULT ''{}''',
    'metadata jsonb NOT NULL DEFAULT ''{}''',
    '(900003,''Lydia'''
) 'Dev23 synthetic CHIM schema lacks the pinned companion actor fields.'
$coverage = Read-Json (Join-Path $TestRoot 'coverage.json')
if ($coverage.schema -ne 'department.dev23.runtime-coverage.v1' -or
    @($coverage.behaviorCoverage).Count -ne 15 -or
    @($coverage.caseFamilies | Where-Object { $_.name -eq 'departmentFacade' -and $_.expectedCases -eq 33 }).Count -ne 1 -or
    @($coverage.caseFamilies | Where-Object { $_.name -eq 'companionFacade' -and $_.source -eq 'companion_cases.php' }).Count -ne 1) {
    throw 'Dev23 coverage inventory does not retain the facade and companion families.'
}
$companion = Read-Text (Join-Path $TestRoot 'companion_cases.php')
Assert-Contains $companion @(
    'function dev23_companion_cases(array &$ctx): array',
    'CorrespondenceCompanionPg',
    'CorrespondenceService',
    'dev23_companion_with_owner_runtime',
    'dev20_api_call',
    'qd_api_runtime_dependencies',
    '$companion->tick($model)',
    'correspondence.mailbox.read',
    'correspondence.detail.read',
    'correspondence.reconcile',
    'companion_scheduler_owned',
    'dispatch_unknown',
    'operator_closed',
    "providerCalls' => 0"
) 'Companion case family is not bound to the pinned API and scheduler.'
if ($companion -match '(?i)curl_exec\s*\(|file_get_contents\s*\(\s*[''"]https?://|OpenAI|Anthropic') {
    throw 'Companion fixture references an external provider or HTTP service.'
}
$sourceContract = Read-Text 'K:\ActorwrightExchange\projects\CHIM-DepartmentOfQuestionableDecisions-dev18\server\tests\correspondence\dev23-source-contract.php'
Assert-Contains $sourceContract @('0.1.0-dev.23', 'function dev23Expect') 'The dev23 source-only contract is unavailable.'

$pinOutput = & (Join-Path $PSScriptRoot 'verify-owner-pins.ps1') -OwnerRoot $OwnerRoot
$pinResult = ($pinOutput -join [Environment]::NewLine) | ConvertFrom-Json -Depth 8
if ($pinResult.schema -ne 'department.dev23.owner-pin-preflight.v1' -or $pinResult.passed -ne $true -or
    @($pinResult.verifiedClosureFiles).Count -ne 19 -or @($pinResult.verifiedPayloadFiles).Count -ne 11 -or
    $pinResult.fullPayloadCount -ne 30 -or $pinResult.wslInvoked -ne $false -or
    $pinResult.postgresStarted -ne $false -or $pinResult.productRuntimeStarted -ne $false -or
    $pinResult.providerOrGameCalls -ne $false) {
    throw 'The immutable dev23 owner source preflight failed.'
}

[pscustomobject]@{
    schema = 'department.dev23.harness-host-selftest.v1'
    passed = $true
    publicFacadeDriver = $true
    caseCount = $actualIds.Count
    companionSource = $true
    ownerClosureCount = 19
    ownerFullPayloadCount = 30
    fixtureGuards = $true
    sourceContractPresent = $true
    scope = 'host-only source/preflight checks; no WSL or PostgreSQL runtime execution'
    wslInvoked = $false
    postgresStarted = $false
    productRuntimeStarted = $false
    providerOrGameCalls = $false
} | ConvertTo-Json -Depth 4 -Compress
