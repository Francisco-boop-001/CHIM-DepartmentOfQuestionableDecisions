[CmdletBinding()]
param(
    [switch]$SelfTest,
    [string]$LeaseReceipt = 'K:\ActorwrightExchange\artifacts\chim-sealed-fates-department-dev23-pairing-20261024\test2-window.json'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$targetDistro = 'DwemerAI4Skyrim3-test2'
$expectedLeaseId = '85d28a7e-bf8b-44ab-9f2e-3da197bd8d04'
$expectedRegistrationKey = '{164d71de-0650-43d8-b1b0-8b94e0841cd3}'
$expectedRegistrationBasePath = 'E:\WSL\DwemerAI4Skyrim3-test2'
$approvedRegistrationReceiptPath = 'K:\ActorwrightExchange\artifacts\chim-sealed-fates-department-dev23-pairing-20261024\test2-registration-before.json'
$registryRoot = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Lxss'
$workspaceRoot = 'K:\ActorwrightExchange'
$artifactRoot = Join-Path $workspaceRoot 'artifacts\department-dev23-compatibility-20261024'
$runtimeRoot = Join-Path $artifactRoot 'runtime'
$protectedBaselinePath = Join-Path $artifactRoot 'protected-source-before.json'
$expectedOwnerRoot = Join-Path $workspaceRoot 'artifacts\chim-sealed-fates-release-dev14-20261020\source'
$script:runRoot = $null
$script:runId = $null
$script:failure = $null
$script:runnerExitCode = $null
$script:runnerInvoked = $false
$script:preflightPassed = $false
$script:initialState = 'Unknown'
$script:finalState = 'Unknown'
$script:initialRegistration = $null
$script:finalRegistration = $null
$script:runningOthersBefore = @()
$script:runningOthersAfter = @()
$script:protectedBefore = $null
$script:protectedAfter = $null
$script:linuxStdout = ''
$script:linuxStderr = ''

if ($PSVersionTable.PSVersion.Major -lt 7) { throw 'PowerShell 7 or newer is required.' }

function Read-BoundedJson([string]$Path, [long]$MaximumBytes) {
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) -or $item.Length -gt $MaximumBytes) {
        throw 'A JSON input is not a bounded regular file.'
    }
    return Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json -Depth 16
}

function Assert-LinuxRunReceipts([string]$Root, [string]$Id) {
    $linux = Read-BoundedJson -Path (Join-Path $Root 'linux.status.json') -MaximumBytes 65536
    if ($linux.schema -cne 'department.dev20.linux-run-status.v1' -or $linux.runId -cne $Id -or
        $linux.passed -ne $true -or $linux.runnerExitCode -ne 0 -or
        $linux.sourcePostcheckPassed -ne $true -or $linux.scenarioSummaryPassed -ne $true) {
        throw 'The Linux runner status receipt is incomplete or failed.'
    }
    $cleanup = Read-BoundedJson -Path (Join-Path $Root 'linux-cleanup.json') -MaximumBytes 65536
    $expectedScratch = "/var/tmp/department-dev23-$Id"
    if ($cleanup.schema -cne 'department.dev23.private-postgres-cleanup.v1' -or $cleanup.runId -cne $Id -or
        $cleanup.clusterStartedByRun -ne $true -or $cleanup.dataDirectory -cne "$expectedScratch/pgdata" -or
        $cleanup.socketDirectory -cne "$expectedScratch/socket" -or $cleanup.port -ne 5432 -or
        $cleanup.stopAttempted -ne $true -or $cleanup.stopExitCode -ne 0 -or $cleanup.postmasterStopped -ne $true -or
        $cleanup.ownedIdentityVerifiedBeforeStop -ne $true -or $cleanup.ownedPathsContained -ne $true -or
        $cleanup.failedRunArtifactsPreserved -ne $true -or $cleanup.passed -ne $true -or
        [string]$cleanup.postmasterPid -notmatch '\A[1-9][0-9]*\z' -or
        [string]$cleanup.systemIdentifier -notmatch '\A[1-9][0-9]*\z') {
        throw 'The private PostgreSQL cleanup receipt does not prove exact owned-cluster shutdown.'
    }
    $network = Read-BoundedJson -Path (Join-Path $Root 'network-isolation.json') -MaximumBytes 65536
    if ($network.schema -cne 'department.dev23.network-isolation.v1' -or
        $network.distinctFromDistributionInit -ne $true -or $network.interfaces.Count -ne 1 -or
        $network.interfaces[0] -cne 'lo' -or $network.ipv4MainRoutes.Count -ne 0 -or
        $network.ipv6MainRoutes.Count -ne 0 -or $network.ipv4DefaultRoutes.Count -ne 0 -or
        $network.ipv6DefaultRoutes.Count -ne 0 -or $network.preexistingTcpListeners.Count -ne 0 -or
        $network.postgresListenAddress -cne '127.0.0.1:5432' -or $network.providerCalls -ne 0 -or
        $network.campaignBootstrap -ne $false) {
        throw 'The private network namespace receipt does not satisfy the loopback-only contract.'
    }
}

function Assert-NoReparsePath([string]$Path) {
    $current = [IO.Path]::GetFullPath($Path)
    while ($true) {
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'A protected runner path is redirected.' }
        }
        $parent = [IO.Directory]::GetParent($current)
        if ($null -eq $parent) { break }
        $current = $parent.FullName
    }
}

function Get-TargetRegistration {
    if (-not (Test-Path -LiteralPath $registryRoot -PathType Container)) { throw 'The WSL registration root is unavailable.' }
    $matches = @(
        foreach ($key in Get-ChildItem -LiteralPath $registryRoot -ErrorAction Stop) {
            $value = Get-ItemProperty -LiteralPath $key.PSPath -ErrorAction Stop
            if ([string]$value.DistributionName -ceq $targetDistro) {
                [pscustomobject]@{
                    keyName = [string]$key.PSChildName
                    version = [int]$value.Version
                    name = [string]$value.DistributionName
                    basePath = [string]$value.BasePath
                }
            }
        }
    )
    if ($matches.Count -ne 1 -or $matches[0].version -ne 2) { throw 'The exact test2 WSL registration is not unique and version 2.' }
    return $matches[0]
}

function Assert-SameRegistration($Before, $After) {
    if ($null -eq $Before -or $null -eq $After -or
        $Before.keyName -cne $After.keyName -or $Before.version -ne $After.version -or
        $Before.name -cne $After.name -or $Before.basePath -cne $After.basePath) {
        throw 'The test2 WSL registration identity changed during the run.'
    }
}

function Normalize-RegistrationBasePath([string]$Path) {
    if ([string]::IsNullOrWhiteSpace($Path)) { throw 'The test2 registration base path is empty.' }
    $normalized = $Path.Trim()
    if ($normalized.StartsWith('\\?\', [StringComparison]::OrdinalIgnoreCase)) { $normalized = $normalized.Substring(4) }
    return [IO.Path]::GetFullPath($normalized).TrimEnd([char]'\')
}

function Assert-ApprovedRegistration($Registration) {
    if ($null -eq $Registration -or $Registration.name -cne $targetDistro -or $Registration.version -ne 2 -or
        $Registration.keyName -ine $expectedRegistrationKey -or
        (Normalize-RegistrationBasePath ([string]$Registration.basePath)) -ine $expectedRegistrationBasePath) {
        throw 'The exact approved test2 WSL registration identity is not present.'
    }
}

function New-TestDistroLifecycleReceipt([string]$InitialState, [string]$FinalState, $InitialRegistration, $FinalRegistration, $ProtectedBefore, $ProtectedAfter, [string[]]$RunningOthersBefore, [string[]]$RunningOthersAfter) {
    $sameRegistration = $null -ne $InitialRegistration -and $null -ne $FinalRegistration -and
        $InitialRegistration.keyName -ceq $FinalRegistration.keyName -and
        $InitialRegistration.basePath -ceq $FinalRegistration.basePath -and
        $InitialRegistration.name -ceq $FinalRegistration.name -and
        $InitialRegistration.version -eq $FinalRegistration.version
    $sameProtected = $null -ne $ProtectedBefore -and $null -ne $ProtectedAfter -and
        (($ProtectedBefore | ConvertTo-Json -Compress) -ceq ($ProtectedAfter | ConvertTo-Json -Compress))
    $sameRunningOthers = $null -ne $RunningOthersBefore -and $null -ne $RunningOthersAfter -and
        ((@($RunningOthersBefore | Sort-Object -CaseSensitive) -join [Environment]::NewLine) -ceq (@($RunningOthersAfter | Sort-Object -CaseSensitive) -join [Environment]::NewLine))
    return [ordered]@{
        schemaVersion = 1
        distro = $targetDistro
        initialState = $InitialState
        finalState = $FinalState
        startedByRun = $false
        stopRequested = $false
        preservationVerified = ($InitialState -ceq 'running' -and $FinalState -ceq 'running' -and
            $sameRegistration -and $sameProtected -and $sameRunningOthers)
    }
}

function Invoke-CleanProcess([string]$FilePath, [string[]]$Arguments) {
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $FilePath
    $start.WorkingDirectory = $workspaceRoot
    $start.UseShellExecute = $false
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.StandardOutputEncoding = [Text.Encoding]::UTF8
    $start.StandardErrorEncoding = [Text.Encoding]::UTF8
    $start.Environment.Clear()
    foreach ($name in @('SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'USERPROFILE')) {
        $value = [Environment]::GetEnvironmentVariable($name, 'Process')
        if (-not [string]::IsNullOrEmpty($value)) { $start.Environment[$name] = $value }
    }
    foreach ($argument in $Arguments) { $null = $start.ArgumentList.Add($argument) }
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'A required read-only host process did not start.' }
    $stdoutTask = $process.StandardOutput.ReadToEndAsync()
    $stderrTask = $process.StandardError.ReadToEndAsync()
    $process.WaitForExit()
    $stdout = $stdoutTask.GetAwaiter().GetResult()
    $stderr = $stderrTask.GetAwaiter().GetResult()
    $exitCode = $process.ExitCode
    $process.Dispose()
    return [pscustomobject]@{ exitCode = $exitCode; stdout = $stdout; stderr = $stderr }
}

function Get-WslSnapshot([string]$WslPath) {
    $result = Invoke-CleanProcess -FilePath $WslPath -Arguments @('--list', '--verbose', '--all')
    if ($result.exitCode -ne 0) { throw 'Could not read WSL distribution state.' }
    $rows = @()
    foreach ($line in ([string]$result.stdout).Replace([string][char]0, '').Split([char]10)) {
        if ($line -match '^\s*\*?\s*(?<name>\S+)\s+(?<state>Running|Stopped)\s+(?<version>[0-9]+)\s*$') {
            $rows += [pscustomobject]@{ name = $Matches.name; state = $Matches.state; version = [int]$Matches.version }
        }
    }
    $targetRows = @($rows | Where-Object { $_.name -ceq $targetDistro })
    $duplicateNames = @($rows | Group-Object { $_.name.ToLowerInvariant() } | Where-Object { $_.Count -ne 1 })
    if ($targetRows.Count -ne 1 -or $targetRows[0].version -ne 2 -or $duplicateNames.Count -gt 0) { throw 'WSL state output does not identify one test2 distribution.' }
    return [pscustomobject]@{
        rows = $rows
        targetState = ([string]$targetRows[0].state).ToLowerInvariant()
        runningOthers = @($rows | Where-Object { $_.state -ceq 'Running' -and $_.name -cne $targetDistro } | ForEach-Object { [string]$_.name } | Sort-Object -CaseSensitive)
    }
}

function Assert-SameRunningOthers([string[]]$Before, [string[]]$After) {
    $beforeJoined = (@($Before | Sort-Object -CaseSensitive) -join "`n")
    $afterJoined = (@($After | Sort-Object -CaseSensitive) -join "`n")
    if ($beforeJoined -cne $afterJoined) {
        throw 'A different WSL distribution changed running state during the test.'
    }
}

function Get-ProtectedSourceSnapshot {
    $entries = @(Read-BoundedJson -Path $protectedBaselinePath -MaximumBytes 65536)
    if ($entries.Count -ne 6) { throw 'The exact cached protected-source baseline is missing or changed.' }
    $verified = @()
    foreach ($entry in $entries) {
        $path = [string]$entry.path
        Assert-NoReparsePath $path
        $item = Get-Item -LiteralPath $path -Force
        if ($item.PSIsContainer -or $item.Length -ne [long]$entry.bytes) { throw 'A cached protected source file changed size.' }
        $hash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($hash -cne ([string]$entry.sha256).ToLowerInvariant()) { throw 'A cached protected source file changed hash.' }
        $verified += [pscustomobject]@{ path = $path; bytes = [long]$item.Length; sha256 = $hash }
    }
    return ,$verified
}

function Assert-LeaseReceipt($lease) {
    if ($lease.lease_id -cne $expectedLeaseId -or $lease.distro -cne $targetDistro -or $lease.state -cne 'granted-to-department' -or
        $lease.old_sealedfates_cluster_leased -isnot [bool] -or $lease.old_sealedfates_cluster_leased -ne $false -or
        $lease.distro_exclusivity -isnot [bool] -or $lease.distro_exclusivity -ne $false -or
        $lease.shared_or_campaign_mutation -isnot [bool] -or $lease.shared_or_campaign_mutation -ne $false -or
        $lease.live_or_game_access -isnot [bool] -or $lease.live_or_game_access -ne $false -or
        $lease.handoff_required_before_independent_rerun -isnot [bool] -or $lease.handoff_required_before_independent_rerun -ne $true -or
        [string]$lease.scope -notmatch 'network namespace') {
        throw 'The exact test2 fixture lease does not authorize this bounded run.'
    }
    return $lease
}

function Assert-Lease {
    $lease = Read-BoundedJson -Path $LeaseReceipt -MaximumBytes 32768
    return Assert-LeaseReceipt $lease
}

function Assert-LinuxReceiptSchemaAlignment {
    $hostSource = Get-Content -LiteralPath $PSCommandPath -Raw
    $linuxPath = Join-Path $PSScriptRoot 'run-dev23-owner-facade.sh'
    $linuxSource = Get-Content -LiteralPath $linuxPath -Raw
    $schemas = @(
        'department.dev20.linux-run-status.v1',
        'department.dev23.private-postgres-cleanup.v1',
        'department.dev23.network-isolation.v1'
    )
    foreach ($schema in $schemas) {
        if (-not $hostSource.Contains("-cne '$schema'") -or -not $linuxSource.Contains('"schema": "' + $schema + '"')) {
            throw "The Linux receipt producer and host consumer disagree on schema $schema."
        }
    }
}

function Convert-WindowsPathToWsl([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    if ($full -notmatch '^([A-Za-z]):\\(.+)$') { throw 'Runner inputs must be on a mounted drive.' }
    return '/mnt/' + $Matches[1].ToLowerInvariant() + '/' + ($Matches[2] -replace '\\', '/')
}

function Write-RunReceipt([bool]$Passed, [string]$Failure) {
    if ($null -eq $script:runRoot -or -not (Test-Path -LiteralPath $script:runRoot -PathType Container)) { return }
    $lifecycle = New-TestDistroLifecycleReceipt -InitialState $script:initialState -FinalState $script:finalState -InitialRegistration $script:initialRegistration -FinalRegistration $script:finalRegistration -ProtectedBefore $script:protectedBefore -ProtectedAfter $script:protectedAfter -RunningOthersBefore $script:runningOthersBefore -RunningOthersAfter $script:runningOthersAfter
    $effectivePassed = $Passed -and $lifecycle.preservationVerified
    $receipt = [ordered]@{
        schema = 'department.dev23.host-runtime.v1'
        runId = $script:runId
        leaseId = $expectedLeaseId
        registrationKey = if ($null -ne $script:initialRegistration) { $script:initialRegistration.keyName } else { $null }
        passed = $effectivePassed
        runnerInvoked = $script:runnerInvoked
        runnerExitCode = $script:runnerExitCode
        testDistroStopVerified = $false
        testDistroLifecycle = $lifecycle
        protectedCachedSourcesMatchedBeforeAndAfter = ($null -ne $script:protectedBefore -and $null -ne $script:protectedAfter -and
            ($script:protectedBefore | ConvertTo-Json -Compress) -ceq ($script:protectedAfter | ConvertTo-Json -Compress))
        runningOtherDistrosBefore = $script:runningOthersBefore
        runningOtherDistrosAfter = $script:runningOthersAfter
        failure = if ([string]::IsNullOrEmpty($Failure)) { $null } else { $Failure }
        providerCalls = 0
        campaignBootstrap = $false
        liveOrGameAccess = $false
        failedRunArtifactsPreserved = $true
    }
    [IO.File]::WriteAllText((Join-Path $script:runRoot 'host-runtime.json'), ($receipt | ConvertTo-Json -Depth 12) + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $script:runRoot 'wsl.stdout.txt'), $script:linuxStdout, [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $script:runRoot 'wsl.stderr.txt'), $script:linuxStderr, [Text.UTF8Encoding]::new($false))
}

if ($SelfTest) {
    $null = Assert-Lease
    Assert-LinuxReceiptSchemaAlignment
    $syntheticLease = [pscustomobject]@{
        lease_id = $expectedLeaseId; distro = $targetDistro; state = 'granted-to-department'
        old_sealedfates_cluster_leased = $false; distro_exclusivity = $false
        shared_or_campaign_mutation = $false; live_or_game_access = $false
        handoff_required_before_independent_rerun = $true; scope = 'private network namespace only'
    }
    $null = Assert-LeaseReceipt $syntheticLease
    $badLeaseTypeRefused = $false
    $badLease = $syntheticLease.PSObject.Copy()
    $badLease.old_sealedfates_cluster_leased = 'false'
    try { $null = Assert-LeaseReceipt $badLease } catch { $badLeaseTypeRefused = $true }
    if (-not $badLeaseTypeRefused) { throw 'Lease Boolean type self-test failed to refuse a string.' }
    $pinResult = & (Join-Path $PSScriptRoot 'verify-owner-pins.ps1') -OwnerRoot $expectedOwnerRoot | ConvertFrom-Json -Depth 8
    if ($pinResult.passed -ne $true -or $pinResult.selectedClosureCount -ne 19 -or $pinResult.fullPayloadCount -ne 30) { throw 'Owner source preflight self-test failed.' }
    Assert-SameRunningOthers -Before @('chim-alpha', 'chim-beta') -After @('chim-beta', 'chim-alpha')
    $refusedDifferentRunningSet = $false
    try { Assert-SameRunningOthers -Before @('chim-alpha') -After @('chim-beta') } catch { $refusedDifferentRunningSet = $true }
    if (-not $refusedDifferentRunningSet) { throw 'Running distribution drift self-test failed to refuse.' }
    $registration = [pscustomobject]@{ keyName = $expectedRegistrationKey; basePath = '\\?\E:\WSL\DwemerAI4Skyrim3-test2'; name = $targetDistro; version = 2 }
    Assert-ApprovedRegistration $registration
    $wrongGuidRefused = $false
    $wrongRegistration = $registration.PSObject.Copy()
    $wrongRegistration.keyName = '{ffffffff-ffff-ffff-ffff-ffffffffffff}'
    try { Assert-ApprovedRegistration $wrongRegistration } catch { $wrongGuidRefused = $true }
    if (-not $wrongGuidRefused) { throw 'Approved registration GUID self-test failed to refuse.' }
    $wrongBaseRefused = $false
    $wrongRegistration = $registration.PSObject.Copy()
    $wrongRegistration.basePath = '\\?\E:\WSL\other-test'
    try { Assert-ApprovedRegistration $wrongRegistration } catch { $wrongBaseRefused = $true }
    if (-not $wrongBaseRefused) { throw 'Approved registration base path self-test failed to refuse.' }
    $protectedSnapshot = @([pscustomobject]@{ path = 'pinned-source.php'; bytes = 8; sha256 = ('a' * 64) })
    $lifecycle = New-TestDistroLifecycleReceipt -InitialState 'running' -FinalState 'running' -InitialRegistration $registration -FinalRegistration ($registration.PSObject.Copy()) -ProtectedBefore $protectedSnapshot -ProtectedAfter @($protectedSnapshot) -RunningOthersBefore @('chim-alpha', 'chim-beta') -RunningOthersAfter @('chim-beta', 'chim-alpha')
    $expectedLifecycleFields = @('schemaVersion', 'distro', 'initialState', 'finalState', 'startedByRun', 'stopRequested', 'preservationVerified')
    if ((@($lifecycle.Keys) -join '|') -cne ($expectedLifecycleFields -join '|') -or
        $lifecycle.schemaVersion -ne 1 -or $lifecycle.distro -cne $targetDistro -or
        $lifecycle.initialState -cne 'running' -or $lifecycle.finalState -cne 'running' -or
        $lifecycle.startedByRun -ne $false -or $lifecycle.stopRequested -ne $false -or
        $lifecycle.preservationVerified -ne $true) { throw 'Matching running lifecycle receipt self-test failed.' }
    $changedLifecycle = New-TestDistroLifecycleReceipt -InitialState 'running' -FinalState 'running' -InitialRegistration $registration -FinalRegistration ($registration.PSObject.Copy()) -ProtectedBefore $protectedSnapshot -ProtectedAfter @($protectedSnapshot) -RunningOthersBefore @('chim-alpha') -RunningOthersAfter @('chim-beta')
    if ($changedLifecycle.preservationVerified -ne $false) { throw 'Changed running-set lifecycle was incorrectly preserved.' }
    [pscustomobject]@{ schema = 'department.dev23.host-runner-selftest.v1'; passed = $true; wslInvoked = $false; postgresStarted = $false; testDistroStopVerified = $false } | ConvertTo-Json -Compress
    exit 0
}

try {
    $null = Assert-NoReparsePath $PSScriptRoot
    $null = Assert-NoReparsePath $artifactRoot
    $null = Assert-NoReparsePath $approvedRegistrationReceiptPath
    Assert-LinuxReceiptSchemaAlignment
    $null = Assert-Lease
    $approvedRegistrationReceipt = Read-BoundedJson -Path $approvedRegistrationReceiptPath -MaximumBytes 32768
    Assert-ApprovedRegistration ([pscustomobject]@{
        keyName = [string]$approvedRegistrationReceipt.keyName
        basePath = [string]$approvedRegistrationReceipt.basePath
        name = [string]$approvedRegistrationReceipt.distro
        version = [int]$approvedRegistrationReceipt.version
    })
    $script:initialRegistration = Get-TargetRegistration
    Assert-ApprovedRegistration $script:initialRegistration
    $wslPath = Join-Path $env:WINDIR 'System32\wsl.exe'
    if (-not (Test-Path -LiteralPath $wslPath -PathType Leaf)) { throw 'The WSL host command is unavailable.' }
    $before = Get-WslSnapshot $wslPath
    $script:initialState = $before.targetState
    $script:runningOthersBefore = @($before.runningOthers)
    if ($script:initialState -cne 'running') { throw 'The exact test2 distribution must already be running.' }
    $script:protectedBefore = Get-ProtectedSourceSnapshot

    $pinOutput = & (Join-Path $PSScriptRoot 'verify-owner-pins.ps1') -OwnerRoot $expectedOwnerRoot
    $pinResult = ($pinOutput -join [Environment]::NewLine) | ConvertFrom-Json -Depth 8
    if ($pinResult.passed -ne $true -or $pinResult.ownerCommit -cne '01d62982d9b10ca4fce040af9d97fe7111231ba3' -or
        $pinResult.selectedClosureCount -ne 19 -or $pinResult.fullPayloadCount -ne 30) { throw 'Pinned owner source preflight failed.' }
    $harnessOutput = & (Join-Path $PSScriptRoot 'verify-dev23-harness.ps1') -OwnerRoot $expectedOwnerRoot
    $harness = ($harnessOutput -join [Environment]::NewLine) | ConvertFrom-Json -Depth 8
    if ($harness.passed -ne $true -or $harness.caseCount -ne 33) { throw 'The 33-case facade source preflight failed.' }

    if (-not (Test-Path -LiteralPath $runtimeRoot -PathType Container)) { $null = New-Item -ItemType Directory -Path $runtimeRoot }
    $null = Assert-NoReparsePath $runtimeRoot
    $build = & (Join-Path $PSScriptRoot 'build-source-bindings.ps1')
    $binding = ($build -join [Environment]::NewLine) | ConvertFrom-Json -Depth 8
    if ($binding.passed -ne $true -or -not (Test-Path -LiteralPath $binding.sourceBindings -PathType Leaf)) { throw 'Exact runtime source binding build failed.' }
    $bindingsPath = [string]$binding.sourceBindings

    $bytes = [byte[]]::new(4)
    [Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    $script:runId = [DateTime]::UtcNow.ToString("yyyyMMdd'T'HHmmss'Z'") + '-' + [Convert]::ToHexString($bytes).ToLowerInvariant()
    $runsRoot = Join-Path $runtimeRoot 'runs'
    if (-not (Test-Path -LiteralPath $runsRoot -PathType Container)) { $null = New-Item -ItemType Directory -Path $runsRoot }
    $script:runRoot = Join-Path $runsRoot $script:runId
    if (Test-Path -LiteralPath $script:runRoot) { throw 'Generated run directory already exists.' }
    $null = New-Item -ItemType Directory -Path $script:runRoot
    $script:preflightPassed = $true

    $immediateRegistration = Get-TargetRegistration
    Assert-SameRegistration -Before $script:initialRegistration -After $immediateRegistration
    $immediate = Get-WslSnapshot $wslPath
    if ($immediate.targetState -cne 'running') { throw 'Test2 stopped before the runner call; no restart will be attempted.' }
    Assert-SameRunningOthers -Before $script:runningOthersBefore -After $immediate.runningOthers
    $protectedImmediate = Get-ProtectedSourceSnapshot
    if (($script:protectedBefore | ConvertTo-Json -Compress) -cne ($protectedImmediate | ConvertTo-Json -Compress)) {
        throw 'A protected cached source changed before the test runner call.'
    }

    $linuxRunner = Convert-WindowsPathToWsl (Join-Path $PSScriptRoot 'run-dev23-owner-facade.sh')
    $linuxRunRoot = Convert-WindowsPathToWsl $script:runRoot
    $linuxBindings = Convert-WindowsPathToWsl $bindingsPath
    $linuxOwnerRoot = Convert-WindowsPathToWsl $expectedOwnerRoot
    $arguments = @('--distribution', $targetDistro, '--user', 'root', '--exec', '/usr/bin/env', '-i',
        'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'HOME=/nonexistent', 'LANG=C', 'LC_ALL=C', "WSL_DISTRO_NAME=$targetDistro",
        '/usr/bin/unshare', '--net', '--', '/bin/bash', '--noprofile', '--norc', $linuxRunner,
        $script:runId, $linuxRunRoot, $linuxBindings, $linuxOwnerRoot)
    $script:runnerInvoked = $true
    $result = Invoke-CleanProcess -FilePath $wslPath -Arguments $arguments
    $script:runnerExitCode = $result.exitCode
    $script:linuxStdout = $result.stdout
    $script:linuxStderr = $result.stderr
    if ($result.exitCode -ne 0) { throw "The private dev23 runner exited $($result.exitCode)." }
    Assert-LinuxRunReceipts -Root $script:runRoot -Id $script:runId
} catch {
    $script:failure = $_.Exception.Message
} finally {
    if ($null -ne $script:runRoot -and (Test-Path -LiteralPath $script:runRoot -PathType Container)) {
        try {
            $wslPath = Join-Path $env:WINDIR 'System32\wsl.exe'
            $script:finalRegistration = Get-TargetRegistration
            Assert-SameRegistration -Before $script:initialRegistration -After $script:finalRegistration
            $after = Get-WslSnapshot $wslPath
            $script:finalState = $after.targetState
            $script:runningOthersAfter = @($after.runningOthers)
            $script:protectedAfter = Get-ProtectedSourceSnapshot
            Assert-SameRunningOthers -Before $script:runningOthersBefore -After $script:runningOthersAfter
        } catch {
            if ([string]::IsNullOrEmpty($script:failure)) { $script:failure = $_.Exception.Message }
        }
        $passed = [string]::IsNullOrEmpty($script:failure) -and $script:runnerInvoked -and $script:runnerExitCode -eq 0 -and
            $script:initialState -ceq 'running' -and $script:finalState -ceq 'running' -and
            $null -ne $script:protectedBefore -and $null -ne $script:protectedAfter -and
            (($script:protectedBefore | ConvertTo-Json -Compress) -ceq ($script:protectedAfter | ConvertTo-Json -Compress))
        Write-RunReceipt -Passed $passed -Failure $script:failure
    }
}

if (-not [string]::IsNullOrEmpty($script:failure)) { throw $script:failure }
