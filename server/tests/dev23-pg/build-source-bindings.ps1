[CmdletBinding()]
param([switch]$SelfTest)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspaceRoot = 'K:\ActorwrightExchange'
$runtimeRoot = Join-Path $workspaceRoot 'artifacts\department-dev23-compatibility-20261024\runtime'
$departmentServerRoot = Join-Path $workspaceRoot 'projects\CHIM-DepartmentOfQuestionableDecisions-dev18\server'
$testRoot = Join-Path $departmentServerRoot 'tests\dev23-pg'
$coreSourceRoot = Join-Path $workspaceRoot 'artifacts\chim-correspondence-offscreen-20261019\upstream\HerikaServer'
$ownerRoot = Join-Path $workspaceRoot 'artifacts\chim-sealed-fates-release-dev14-20261020\source'
$coreManifestPath = Join-Path $workspaceRoot 'artifacts\department-dev18-candidate-20261021\coordinator\approved-core-source-pins.json'
$ownerManifestPath = Join-Path $testRoot 'owner-runtime-pins.json'
$outputPath = Join-Path $runtimeRoot 'source-bindings.json'
$coreManifestSha256 = 'd81281bc30a240f2ef819044dad6ee54786edca44d25ccfae230aa2bc98517a0'
$coreCommit = '0ec590b42c8087860138bb4e9aab470049ff9a2b'
$coreTree = 'ab97c6dd3947501b8a76bf9b9c6ad74b30be3975'
$ownerManifestSha256 = '45e43d191e747d5593b5cc912bbcd9ec1e6597cd8e5ce9e6e91130d5a07e04ef'
$expectedOwnerRoot = 'K:\ActorwrightExchange\artifacts\chim-sealed-fates-release-dev14-20261020\source'
$runtimeFiles = @(
    'api_driver.php',
    'base.sql',
    'build-source-bindings.ps1',
    'cases.json',
    'companion_cases.php',
    'controller_switch.php',
    'coverage.json',
    'fixture_guard.php',
    'launch-dev23-test.ps1',
    'owner-runtime-pins.json',
    'owner_fixture.php',
    'pg_scenarios.php',
    'run-dev23-owner-facade.sh',
    'seed_unknown_receipt.php',
    'test_runner_contract.py',
    'verify-dev23-harness.ps1',
    'verify-owner-pins.ps1'
)

function Assert-NoReparsePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $current = $full
    while ($true) {
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw "Reparse point refused: $current"
            }
        }
        $parent = [IO.Directory]::GetParent($current)
        if ($null -eq $parent) { break }
        $current = $parent.FullName
    }
}

function Assert-Directory([string]$Path) {
    Assert-NoReparsePath $Path
    $item = Get-Item -LiteralPath $Path -Force
    if (-not $item.PSIsContainer) { throw "Expected a directory: $Path" }
    return [IO.Path]::GetFullPath($Path)
}

function Assert-Relative([string]$Relative) {
    if ([string]::IsNullOrWhiteSpace($Relative) -or $Relative.Contains('\') -or
        [IO.Path]::IsPathRooted($Relative)) {
        throw "Non-canonical relative source path: $Relative"
    }
    $parts = $Relative.Split('/')
    if (@($parts | Where-Object { $_ -in @('', '.', '..') }).Count -gt 0) {
        throw "Unsafe source path component: $Relative"
    }
}

function Get-ContainedFile([string]$Root, [string]$Relative) {
    Assert-Relative $Relative
    $candidate = [IO.Path]::GetFullPath((Join-Path $Root ($Relative -replace '/', '\')))
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\')
    if (-not $candidate.StartsWith($rootFull + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw "Source path escaped root: $Relative"
    }
    Assert-NoReparsePath $candidate
    $item = Get-Item -LiteralPath $candidate -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::Directory) -ne 0)) {
        throw "Expected a regular file: $Relative"
    }
    return $item
}

function Get-SourceEntry([string]$RootKey, [string]$Root, [string]$Relative) {
    $file = Get-ContainedFile -Root $Root -Relative $Relative
    return [pscustomobject]@{
        rootKey = $RootKey
        path = $Relative
        bytes = [long]$file.Length
        sha256 = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

function Get-TreeRelativeFiles([string]$Root) {
    $null = Assert-Directory $Root
    $files = [Collections.Generic.List[string]]::new()
    foreach ($item in Get-ChildItem -LiteralPath $Root -Force -Recurse) {
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Reparse point in staged source tree: $($item.FullName)"
        }
        if ($item.PSIsContainer) { continue }
        if (($item.Attributes -band [IO.FileAttributes]::Directory) -ne 0) {
            throw "Non-regular filesystem entry in staged source tree: $($item.FullName)"
        }
        $relative = $item.FullName.Substring(([IO.Path]::GetFullPath($Root).TrimEnd('\').Length) + 1).Replace('\', '/')
        Assert-Relative $relative
        $files.Add($relative)
    }
    $array = $files.ToArray()
    [Array]::Sort($array, [StringComparer]::Ordinal)
    return ,$array
}

function Read-JsonFile([string]$Path, [long]$MaximumBytes) {
    Assert-NoReparsePath $Path
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer -or $item.Length -gt $MaximumBytes) {
        throw "Invalid or oversized JSON input: $Path"
    }
    return Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json -Depth 24
}

function New-Bindings {
    $null = Assert-Directory $workspaceRoot
    $null = Assert-Directory $runtimeRoot
    $null = Assert-Directory $departmentServerRoot
    $null = Assert-Directory $testRoot
    $null = Assert-Directory $coreSourceRoot
    $null = Assert-Directory $ownerRoot

    $actualCoreManifestHash = (Get-FileHash -LiteralPath $coreManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualCoreManifestHash -cne $coreManifestSha256) { throw 'Approved core source manifest hash changed.' }
    $coreManifest = Read-JsonFile -Path $coreManifestPath -MaximumBytes 1048576
    if ($coreManifest.schema -cne 'department.dev18.approved-core-source.v1' -or
        $coreManifest.commit -cne $coreCommit -or $coreManifest.tree -cne $coreTree -or
        [IO.Path]::GetFullPath([string]$coreManifest.sourceRoot) -cne [IO.Path]::GetFullPath($coreSourceRoot) -or
        @($coreManifest.files).Count -ne 3081) {
        throw 'Approved core manifest identity or file count changed.'
    }

    $actualOwnerManifestHash = (Get-FileHash -LiteralPath $ownerManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualOwnerManifestHash -cne $ownerManifestSha256) { throw 'Owner source pin manifest hash changed.' }
    $ownerManifest = Read-JsonFile -Path $ownerManifestPath -MaximumBytes 65536
    if ($ownerManifest.schema -cne 'department.dev23.owner-runtime-pins.v1' -or
        $ownerManifest.ownerVersion -cne '0.1.0-dev.23' -or
        $ownerManifest.ownerCommit -cne '01d62982d9b10ca4fce040af9d97fe7111231ba3' -or
        [int]$ownerManifest.ownerClosureCount -ne 19 -or
        [int]$ownerManifest.fullPayloadCount -ne 30 -or
        [string]::Equals([string]$ownerManifest.ownerRoot, $expectedOwnerRoot, [StringComparison]::OrdinalIgnoreCase) -eq $false -or
        @($ownerManifest.files).Count -ne 19 -or @($ownerManifest.payloadFiles).Count -ne 11) {
        throw 'Owner pin manifest identity or file count changed.'
    }
    $ownerServerManifestPath = Join-Path $ownerRoot 'server\manifest.json'
    $ownerServerManifest = Read-JsonFile -Path $ownerServerManifestPath -MaximumBytes 65536
    if ($ownerServerManifest.name -cne 'correspondence' -or $ownerServerManifest.version -cne '0.1.0-dev.23') {
        throw 'Immutable owner server manifest identity changed.'
    }

    $entries = [Collections.Generic.List[object]]::new()

    $departmentPaths = Get-TreeRelativeFiles $departmentServerRoot
    foreach ($path in $departmentPaths) {
        $entries.Add((Get-SourceEntry -RootKey 'departmentServer' -Root $departmentServerRoot -Relative $path))
    }

    $testPaths = Get-TreeRelativeFiles $testRoot
    foreach ($path in $testPaths) {
        $entries.Add((Get-SourceEntry -RootKey 'test' -Root $testRoot -Relative $path))
    }

    $corePaths = [Collections.Generic.List[string]]::new()
    foreach ($file in $coreManifest.files) {
        $path = [string]$file.path
        Assert-Relative $path
        $entry = Get-SourceEntry -RootKey 'core' -Root $coreSourceRoot -Relative $path
        if ([long]$entry.bytes -ne [long]$file.bytes -or $entry.sha256 -cne ([string]$file.sha256).ToLowerInvariant()) {
            throw "Approved core file changed: $path"
        }
        $corePaths.Add($path)
        $entries.Add($entry)
    }

    foreach ($file in $ownerManifest.files) {
        $path = [string]$file.path
        if (-not $path.StartsWith('server/', [StringComparison]::Ordinal)) {
            throw "Owner pin escapes server tree: $path"
        }
        $entry = Get-SourceEntry -RootKey 'owner' -Root $ownerRoot -Relative $path
        if ([long]$entry.bytes -ne [long]$file.bytes -or $entry.sha256 -cne ([string]$file.sha256).ToLowerInvariant()) {
            throw "Pinned owner file changed: $path"
        }
        $entries.Add($entry)
    }

    foreach ($file in $ownerManifest.payloadFiles) {
        $path = [string]$file.path
        if (-not $path.StartsWith('server/', [StringComparison]::Ordinal)) {
            throw "Owner payload pin escapes server tree: $path"
        }
        $entry = Get-SourceEntry -RootKey 'ownerPayload' -Root $ownerRoot -Relative $path
        if ([long]$entry.bytes -ne [long]$file.bytes -or $entry.sha256 -cne ([string]$file.sha256).ToLowerInvariant()) {
            throw "Pinned owner payload file changed: $path"
        }
        $entries.Add($entry)
    }


    foreach ($path in $runtimeFiles) {
        $entries.Add((Get-SourceEntry -RootKey 'runtime' -Root $testRoot -Relative $path))
    }

    $result = [ordered]@{
        schema = 'department.dev23.runtime-source-bindings.v1'
        mountRoot = '/mnt/k/ActorwrightExchange'
        departmentServerRoot = 'projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server'
        testRoot = 'projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server/tests/dev23-pg'
        coreSourceRoot = 'artifacts/chim-correspondence-offscreen-20261019/upstream/HerikaServer'
        ownerPinManifest = 'projects/CHIM-DepartmentOfQuestionableDecisions-dev18/server/tests/dev23-pg/owner-runtime-pins.json'
        corePinManifestSha256 = $coreManifestSha256
        coreCommit = $coreCommit
        coreTree = $coreTree
        ownerPinManifestSha256 = $ownerManifestSha256
        ownerClosureCount = 19
        ownerPayloadCount = 11
        ownerFullPayloadCount = 30
        files = @($entries.ToArray() | Sort-Object rootKey, path)
    }
    $json = $result | ConvertTo-Json -Depth 8
    $bytes = [Text.UTF8Encoding]::new($false).GetBytes($json + [Environment]::NewLine)
    if ($bytes.Length -gt 1048576) { throw 'Generated source bindings exceed launcher bound.' }
    return [pscustomobject]@{ value = $result; bytes = $bytes; count = $entries.Count; coreCount = $corePaths.Count }
}

try {
    $bindings = New-Bindings
    if ($SelfTest) {
        [pscustomobject]@{
            schema = 'department.dev23.source-bindings-selftest.v1'
            passed = $true
            wroteFile = $false
            fileCount = $bindings.count
            coreFileCount = $bindings.coreCount
            corePinManifestSha256 = $coreManifestSha256
            ownerPinManifestSha256 = $ownerManifestSha256
        } | ConvertTo-Json -Depth 5 -Compress
        exit 0
    }

    Assert-NoReparsePath $outputPath
    if (Test-Path -LiteralPath $outputPath) {
        $existing = Get-Item -LiteralPath $outputPath -Force
        if ($existing.PSIsContainer) { throw 'Existing source-bindings output is not a file.' }
        $existingBytes = [IO.File]::ReadAllBytes($outputPath)
        if ([Convert]::ToBase64String($existingBytes) -cne [Convert]::ToBase64String($bindings.bytes)) {
            throw 'Existing source-bindings.json differs; refusing to replace it.'
        }
    } else {
        [IO.File]::WriteAllBytes($outputPath, $bindings.bytes)
    }
    [pscustomobject]@{
        schema = 'department.dev23.source-bindings-build.v1'
        passed = $true
        wroteFile = $true
        fileCount = $bindings.count
        coreFileCount = $bindings.coreCount
        sourceBindings = $outputPath
        sha256 = (Get-FileHash -LiteralPath $outputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    } | ConvertTo-Json -Depth 5 -Compress
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
