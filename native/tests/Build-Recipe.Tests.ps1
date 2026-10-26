[CmdletBinding()]
param(
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $CMakePath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $PhpPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$nativeRoot = Split-Path -Parent $PSScriptRoot
$buildScript = Join-Path $nativeRoot 'build.ps1'
if (-not (Test-Path -LiteralPath $buildScript -PathType Leaf)) {
	throw 'The portable native build entry point is missing.'
}

$scratchRoot = Join-Path ([IO.Path]::GetTempPath()) ('dql-native-build-test-' + [Guid]::NewGuid().ToString('N'))
$dependenciesRoot = Join-Path $scratchRoot 'bad-dependencies'
$outputRoot = Join-Path $scratchRoot 'output'
$evidenceRoot = Join-Path $scratchRoot 'evidence'
if (Test-Path -LiteralPath $scratchRoot) { throw 'The unique native test scratch path already exists.' }
New-Item -ItemType Directory -Path $dependenciesRoot | Out-Null
try {
	$failed = $false
	try {
		& $buildScript -CMakePath $CMakePath -PhpPath $PhpPath -DependenciesRoot $dependenciesRoot -OutputRoot $outputRoot -EvidenceRoot $evidenceRoot
	} catch {
		$failed = $true
		if ($_.Exception.Message -notmatch '(?i)(dependency|pin|archive|source)') { throw }
	}
	if (-not $failed) { throw 'The build accepted an empty, unpinned dependency root.' }
	if ((Test-Path -LiteralPath $outputRoot) -or (Test-Path -LiteralPath $evidenceRoot)) {
		throw 'A rejected dependency root created build or evidence output.'
	}
} finally {
	if (Test-Path -LiteralPath $scratchRoot -PathType Container) {
		$separators = [char[]]@([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
		$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd($separators)
		$resolvedScratchRoot = [IO.Path]::GetFullPath($scratchRoot)
		$scratchParent = [IO.Path]::GetDirectoryName($resolvedScratchRoot).TrimEnd($separators)
		if (-not [string]::Equals($scratchParent, $tempRoot, [StringComparison]::OrdinalIgnoreCase) -or
			[IO.Path]::GetFileName($resolvedScratchRoot) -notmatch '^dql-native-build-test-[0-9a-f]{32}$') {
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

Write-Output 'PASS: invalid native dependency roots fail before creating outputs.'
