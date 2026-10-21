[CmdletBinding()]
param(
	[Parameter(Mandatory)]
	[ValidateNotNullOrEmpty()]
	[string] $ReleaseDirectory,
	[Parameter(Mandatory)]
	[ValidateNotNullOrEmpty()]
	[string[]] $AssetNames
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$manifestName = 'SHA256SUMS.txt'

$seenNames = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach ($assetName in $AssetNames) {
	if ($assetName -notmatch '\A[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?\z' -or
		[string]::Equals($assetName, $manifestName, [StringComparison]::OrdinalIgnoreCase)) {
		throw "Unsafe release asset name: '$assetName'."
	}
	if (-not $seenNames.Add($assetName)) { throw "Duplicate release asset name: '$assetName'." }
}

if (-not (Test-Path -LiteralPath $ReleaseDirectory -PathType Container)) {
	throw 'The release directory must already exist.'
}
$directory = Get-Item -LiteralPath $ReleaseDirectory -Force
if (($directory.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
	throw 'The release directory cannot be a reparse point.'
}
$rootPath = [IO.Path]::GetFullPath($directory.FullName)
$entries = @(Get-ChildItem -LiteralPath $rootPath -Force)
foreach ($entry in $entries) {
	if ($entry.PSIsContainer -or ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
		throw "The release directory contains a directory or reparse point: '$($entry.Name)'."
	}
}

$expectedNames = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
foreach ($assetName in $AssetNames) { [void]$expectedNames.Add($assetName) }
$manifestEntry = @($entries | Where-Object { $_.Name -ceq $manifestName })
if ($manifestEntry.Count -gt 1) { throw 'The release directory contains duplicate checksum files.' }
if ($manifestEntry.Count -eq 1) { [void]$expectedNames.Add($manifestName) }
if ($entries.Count -ne $expectedNames.Count) {
	throw 'The release directory does not contain exactly the listed assets and optional checksum file.'
}
foreach ($entry in $entries) {
	if (-not $expectedNames.Contains($entry.Name)) {
		throw "Unexpected file in release directory: '$($entry.Name)'."
	}
}

$separators = [char[]]@([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
$rootPrefix = $rootPath.TrimEnd($separators) + [IO.Path]::DirectorySeparatorChar
$rows = foreach ($assetName in $AssetNames) {
	$entry = @($entries | Where-Object { $_.Name -ceq $assetName })
	if ($entry.Count -ne 1 -or $entry[0].PSIsContainer -or
		($entry[0].Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
		throw "Release input must be a regular file: '$assetName'."
	}
	$assetPath = [IO.Path]::GetFullPath((Join-Path $rootPath $assetName))
	if (-not $assetPath.StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase) -or
		-not [string]::Equals($assetPath, [IO.Path]::GetFullPath($entry[0].FullName), [StringComparison]::OrdinalIgnoreCase)) {
		throw "Release input is not contained in the release directory: '$assetName'."
	}
	$hash = (Get-FileHash -LiteralPath $assetPath -Algorithm SHA256).Hash.ToLowerInvariant()
	"$hash  $assetName"
}
$manifestBytes = [Text.Encoding]::ASCII.GetBytes(($rows -join "`n") + "`n")
$manifestPath = Join-Path $rootPath $manifestName

if ($manifestEntry.Count -eq 1) {
	$existingBytes = [IO.File]::ReadAllBytes($manifestPath)
	if ([Convert]::ToHexString($existingBytes) -cne [Convert]::ToHexString($manifestBytes)) {
		throw 'Refusing to replace a different existing SHA256SUMS.txt.'
	}
} else {
	$stream = [IO.File]::Open($manifestPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
	try { $stream.Write($manifestBytes, 0, $manifestBytes.Length) } finally { $stream.Dispose() }
}

Write-Output "Wrote SHA256SUMS.txt for $($AssetNames.Count) assets."
