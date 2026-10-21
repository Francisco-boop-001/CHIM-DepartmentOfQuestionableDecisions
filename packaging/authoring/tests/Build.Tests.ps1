[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$authoringRoot = Split-Path -Parent $PSScriptRoot
$buildScript = Join-Path $authoringRoot 'build.ps1'
if (-not (Test-Path -LiteralPath $buildScript -PathType Leaf)) {
	throw 'The portable authoring build entry point is missing.'
}

$scratchRoot = Join-Path ([IO.Path]::GetTempPath()) ('dql-authoring-build-test-' + [Guid]::NewGuid().ToString('N'))
$outputRoot = Join-Path $scratchRoot 'output'
$evidenceRoot = Join-Path $scratchRoot 'evidence'
if (Test-Path -LiteralPath $scratchRoot) { throw 'The unique authoring test scratch path already exists.' }
New-Item -ItemType Directory -Path $scratchRoot | Out-Null
try {
	$failed = $false
	try {
		& $buildScript -DotNetPath (Join-Path $scratchRoot 'missing-dotnet.exe') -OutputRoot $outputRoot -EvidenceRoot $evidenceRoot
	} catch {
		$failed = $true
		if ($_.Exception.Message -notmatch '(?i)(dotnet|tool)') { throw }
	}
	if (-not $failed) { throw 'The authoring build accepted a missing .NET tool.' }
	if ((Test-Path -LiteralPath $outputRoot) -or (Test-Path -LiteralPath $evidenceRoot)) {
		throw 'A rejected tool path created authoring build or evidence output.'
	}
} finally {
	if (Test-Path -LiteralPath $scratchRoot -PathType Container) {
		$separators = [char[]]@([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
		$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd($separators)
		$resolvedScratchRoot = [IO.Path]::GetFullPath($scratchRoot)
		$scratchParent = [IO.Path]::GetDirectoryName($resolvedScratchRoot).TrimEnd($separators)
		if (-not [string]::Equals($scratchParent, $tempRoot, [StringComparison]::OrdinalIgnoreCase) -or
			[IO.Path]::GetFileName($resolvedScratchRoot) -notmatch '^dql-authoring-build-test-[0-9a-f]{32}$') {
			throw 'Refusing to remove a scratch path outside the exact unique test child of the system temp directory.'
		}
		$tempItem = Get-Item -LiteralPath $tempRoot -Force
		$scratchItem = Get-Item -LiteralPath $resolvedScratchRoot -Force
		if (($tempItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
			($scratchItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
			throw 'Refusing to remove test scratch through a reparse point.'
		}
		$reparseItems = @(Get-ChildItem -LiteralPath $resolvedScratchRoot -Recurse -Force |
			Where-Object { ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 })
		if ($reparseItems.Count -gt 0) { throw 'Refusing to remove test scratch containing reparse points.' }
		Remove-Item -LiteralPath $resolvedScratchRoot -Recurse -Force
	}
}

Write-Output 'PASS: a missing .NET tool fails before creating authoring outputs.'
