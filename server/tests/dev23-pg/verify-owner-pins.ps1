[CmdletBinding()]
param(
    [string]$OwnerRoot = 'K:\ActorwrightExchange\artifacts\chim-sealed-fates-release-dev14-20261020\source'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-NoReparsePath([string]$Path) {
    $current = [IO.Path]::GetFullPath($Path)
    while ($true) {
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw 'Owner source path contains a reparse point.'
            }
        }
        $parent = [IO.Directory]::GetParent($current)
        if ($null -eq $parent) { break }
        $current = $parent.FullName
    }
}

function Assert-ContainedFile([string]$Root, [object]$Entry) {
    $relative = [string]$Entry.path
    if ([string]::IsNullOrWhiteSpace($relative) -or $relative.Contains([char]92) -or [IO.Path]::IsPathRooted($relative)) {
        throw 'Pinned relative path is not canonical.'
    }
    $parts = $relative.Split('/')
    if (@($parts | Where-Object { $_ -in @('', '.', '..') }).Count -gt 0) { throw 'Pinned relative path is unsafe.' }
    $candidate = [IO.Path]::GetFullPath((Join-Path $Root ($relative -replace '/', '\')))
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\')
    if (-not $candidate.StartsWith($rootFull + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Pinned path escapes the owner root.' }
    Assert-NoReparsePath $candidate
    $item = Get-Item -LiteralPath $candidate -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { throw 'Pinned source is not a regular file.' }
    $actual = (Get-FileHash -LiteralPath $candidate -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -cne ([string]$Entry.sha256).ToLowerInvariant() -or [long]$item.Length -ne [long]$Entry.bytes) { throw "Pinned source mismatch: $relative" }
    return [pscustomobject]@{ path = $relative; sha256 = $actual; bytes = [long]$item.Length }
}

$manifestPath = Join-Path $PSScriptRoot 'owner-runtime-pins.json'
Assert-NoReparsePath $manifestPath
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json -Depth 8
if ($manifest.schema -cne 'department.dev23.owner-runtime-pins.v1' -or
    $manifest.ownerTag -cne 'correspondence-v0.1.0-dev.23' -or
    $manifest.ownerVersion -cne '0.1.0-dev.23' -or
    $manifest.ownerCommit -cne '01d62982d9b10ca4fce040af9d97fe7111231ba3' -or
    [int]$manifest.ownerClosureCount -ne 19 -or @($manifest.files).Count -ne 19 -or
    [int]$manifest.fullPayloadCount -ne 30 -or @($manifest.payloadFiles).Count -ne 11) {
    throw 'Unexpected immutable dev23 source closure or payload identity.'
}

$expectedOwner = [IO.Path]::GetFullPath([string]$manifest.ownerRoot).TrimEnd('\')
$providedOwner = [IO.Path]::GetFullPath($OwnerRoot).TrimEnd('\')
if (-not [string]::Equals($providedOwner, $expectedOwner, [StringComparison]::OrdinalIgnoreCase)) { throw 'Owner root must match the pinned source exactly.' }
$rootItem = Get-Item -LiteralPath $providedOwner -Force
if (-not $rootItem.PSIsContainer -or (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { throw 'Pinned owner root must be a real directory.' }
$serverManifestPath = Join-Path $providedOwner 'server\manifest.json'
Assert-NoReparsePath $serverManifestPath
$serverManifest = Get-Content -LiteralPath $serverManifestPath -Raw | ConvertFrom-Json -Depth 8
if ($serverManifest.name -cne 'correspondence' -or $serverManifest.version -cne '0.1.0-dev.23') { throw 'Owner manifest version differs from the pinned dev23 source.' }

$verifiedClosure = @($manifest.files | ForEach-Object { Assert-ContainedFile $providedOwner $_ })
$verifiedPayload = @($manifest.payloadFiles | ForEach-Object { Assert-ContainedFile $providedOwner $_ })
$allPaths = @($verifiedClosure.path) + @($verifiedPayload.path)
if ($allPaths.Count -ne 30 -or @($allPaths | Sort-Object -Unique).Count -ne 30) { throw 'Selected closure and payload pins overlap or are incomplete.' }

[pscustomobject]@{
    schema = 'department.dev23.owner-pin-preflight.v1'
    passed = $true
    ownerRoot = $providedOwner
    ownerTag = $manifest.ownerTag
    ownerCommit = $manifest.ownerCommit
    selectedClosureCount = $verifiedClosure.Count
    payloadExtraCount = $verifiedPayload.Count
    fullPayloadCount = $allPaths.Count
    pinManifestSha256 = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    verifiedClosureFiles = $verifiedClosure
    verifiedPayloadFiles = $verifiedPayload
    wslInvoked = $false
    postgresStarted = $false
    productRuntimeStarted = $false
    providerOrGameCalls = $false
} | ConvertTo-Json -Depth 7 -Compress
