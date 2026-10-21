[CmdletBinding()]
param(
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $CompilerPath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $FlagsPath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $SkseScriptsRoot,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $SkyuiHeadersRoot,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $OutputPath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $EvidencePath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$lockPath = Join-Path $PSScriptRoot 'deps.lock.json'
$lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
if ($lock.schemaVersion -ne 1) { throw "Unsupported Papyrus dependency lock schema." }

function Get-Sha256 {
	param([Parameter(Mandatory)][string] $Path)
	return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash
}

function Resolve-InputPath {
	param(
		[Parameter(Mandatory)][string] $Path,
		[Parameter(Mandatory)][ValidateSet('File', 'Directory')][string] $Kind
	)
	if (-not (Test-Path -LiteralPath $Path)) { throw "Required $Kind input is missing." }
	$item = Get-Item -LiteralPath $Path -Force
	if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "A required $Kind input cannot be a reparse point." }
	if ($Kind -eq 'File' -and -not $item.PSIsContainer) { return (Resolve-Path -LiteralPath $Path).Path }
	if ($Kind -eq 'Directory' -and $item.PSIsContainer) { return (Resolve-Path -LiteralPath $Path).Path }
	throw "Required input has the wrong type."
}

function Resolve-FreshOutputPath {
	param([Parameter(Mandatory)][string] $Path)
	$fullPath = [IO.Path]::GetFullPath($Path)
	if (Test-Path -LiteralPath $fullPath) { throw "Output path already exists; choose a new path." }
	$parentPath = [IO.Path]::GetDirectoryName($fullPath)
	if (-not (Test-Path -LiteralPath $parentPath -PathType Container)) { throw "The parent directory for an output path must already exist." }
	$parent = Get-Item -LiteralPath $parentPath -Force
	if (($parent.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "An output path parent cannot be a reparse point." }
	return Join-Path (Resolve-Path -LiteralPath $parentPath).Path ([IO.Path]::GetFileName($fullPath))
}

function Test-PathWithin {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Parent)
	$prefix = $Parent.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
	return $Path.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

function Get-TreeIdentity {
	param([Parameter(Mandatory)][string] $Root)
	$rootPath = [IO.Path]::GetFullPath((Resolve-Path -LiteralPath $Root).Path).TrimEnd('\', '/')
	$pending = [Collections.Generic.Queue[string]]::new()
	$pending.Enqueue($rootPath)
	$relativePaths = [Collections.Generic.List[string]]::new()
	while ($pending.Count -gt 0) {
		$directory = $pending.Dequeue()
		foreach ($entry in Get-ChildItem -LiteralPath $directory -Force) {
			if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
				throw "A pinned dependency tree cannot contain reparse points."
			}
			if ($entry.PSIsContainer) {
				$pending.Enqueue($entry.FullName)
			} else {
				$relativePaths.Add($entry.FullName.Substring($rootPath.Length).TrimStart('\', '/').Replace('\', '/'))
			}
		}
	}
	$names = $relativePaths.ToArray()
	[Array]::Sort($names, [StringComparer]::Ordinal)
	$records = [Collections.Generic.List[string]]::new()
	foreach ($name in $names) {
		$filePath = Join-Path $rootPath ($name.Replace('/', [IO.Path]::DirectorySeparatorChar))
		$records.Add("$name$([char]9)$(Get-Sha256 -Path $filePath)")
	}
	$separator = [string][char]10
	$payload = [Text.Encoding]::UTF8.GetBytes(($records -join $separator) + $separator)
	$hasher = [Security.Cryptography.SHA256]::Create()
	try {
		$treeHash = [BitConverter]::ToString($hasher.ComputeHash($payload)).Replace('-', '')
	} finally {
		$hasher.Dispose()
	}
	return [pscustomobject]@{
		FileCount = $names.Length
		Sha256 = $treeHash
	}
}

function Test-ContainsText {
	param([Parameter(Mandatory)][byte[]] $Bytes, [Parameter(Mandatory)][string] $Text)
	if ([string]::IsNullOrWhiteSpace($Text)) { return $false }
	return [Text.Encoding]::UTF8.GetString($Bytes).IndexOf($Text, [StringComparison]::OrdinalIgnoreCase) -ge 0
}

$compiler = Resolve-InputPath -Path $CompilerPath -Kind File
$flags = Resolve-InputPath -Path $FlagsPath -Kind File
$skseRoot = Resolve-InputPath -Path $SkseScriptsRoot -Kind Directory
$skyuiRoot = Resolve-InputPath -Path $SkyuiHeadersRoot -Kind Directory
$sourceRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'Source')).Path

if ((Get-Sha256 -Path $compiler) -cne $lock.caprica.executableSha256) {
	throw "Caprica does not match the pinned v0.3.0 executable."
}
if ((Get-Sha256 -Path $flags) -cne $lock.flags.sha256) {
	throw "Papyrus flags file does not match the pinned community snapshot."
}
$skseIdentity = Get-TreeIdentity -Root $skseRoot
$skyuiIdentity = Get-TreeIdentity -Root $skyuiRoot
if ($skseIdentity.FileCount -ne $lock.sksePapyrus.fileCount -or $skseIdentity.Sha256 -cne $lock.sksePapyrus.treeSha256) {
	throw "SKSE Papyrus source tree does not match the pinned v2.0.20 inputs."
}
if ($skyuiIdentity.FileCount -ne $lock.skyuiPapyrusHeaders.fileCount -or $skyuiIdentity.Sha256 -cne $lock.skyuiPapyrusHeaders.treeSha256) {
	throw "SkyUI header tree does not match the pinned commit."
}

$sourceNames = @('WorldOfDramaLlamaNative.psc', 'WorldOfDramaLlamaMCM.psc')
$sourceFiles = @(Get-ChildItem -LiteralPath $sourceRoot -File -Filter '*.psc' | Sort-Object Name)
if ($sourceFiles.Count -ne $sourceNames.Count -or @($sourceFiles.Name | Where-Object { $_ -notin $sourceNames }).Count -ne 0) {
	throw "Papyrus source directory must contain only the two companion scripts."
}

$outputRoot = Resolve-FreshOutputPath -Path $OutputPath
$evidenceRoot = Resolve-FreshOutputPath -Path $EvidencePath
if ($outputRoot -eq $evidenceRoot -or (Test-PathWithin -Path $outputRoot -Parent $evidenceRoot) -or (Test-PathWithin -Path $evidenceRoot -Parent $outputRoot)) {
	throw "Output and evidence directories must be separate."
}

$modulePath = Join-Path $PSScriptRoot 'PexMetadata.psm1'
Import-Module $modulePath -Force
$null = & (Join-Path $PSScriptRoot 'tests\PexMetadata.Tests.ps1')

New-Item -ItemType Directory -Path $evidenceRoot | Out-Null
$rawOutputRoot = Join-Path $evidenceRoot 'raw'
$neutralOutputRoot = Join-Path $evidenceRoot 'neutralized'
New-Item -ItemType Directory -Path $rawOutputRoot, $neutralOutputRoot | Out-Null

$arguments = @(
	$sourceRoot,
	'--game=skyrim',
	"--import=$sourceRoot",
	"--import=$(Join-Path $skseRoot 'vanilla')",
	"--import=$(Join-Path $skseRoot 'modified')",
	"--import=$skyuiRoot",
	"--flags=$flags",
	"--output=$rawOutputRoot",
	'--ignorecwd',
	'--strict',
	'--release',
	'--final',
	'--enable-debug-info=0'
)
$stdoutPath = Join-Path $evidenceRoot 'caprica-stdout.log'
$stderrPath = Join-Path $evidenceRoot 'caprica-stderr.log'
& $compiler @arguments 1> $stdoutPath 2> $stderrPath
$compilerExitCode = $LASTEXITCODE
$stdout = Get-Content -LiteralPath $stdoutPath -Raw
$stderr = Get-Content -LiteralPath $stderrPath -Raw
if ($compilerExitCode -ne 0) { throw "Caprica failed; inspect the preserved evidence logs." }
$diagnosticText = "$stdout$([Environment]::NewLine)$stderr"
if ($diagnosticText -match '(?im)^.*\b(?:warning|error):') {
	throw "Caprica emitted a warning or error; inspect the preserved evidence logs."
}

$rawPexFiles = @(Get-ChildItem -LiteralPath $rawOutputRoot -File -Filter '*.pex' | Sort-Object Name)
if ($rawPexFiles.Count -ne 2 -or @($rawPexFiles.Name | Where-Object { $_ -notin @('WorldOfDramaLlamaNative.pex', 'WorldOfDramaLlamaMCM.pex') }).Count -ne 0) {
	throw "Caprica did not emit exactly the two expected PEX files."
}

$sourceHashes = @{}
foreach ($sourceFile in $sourceFiles) {
	$sourceHashes[$sourceFile.Name] = Get-Sha256 -Path $sourceFile.FullName
}
$markers = @(
	$sourceRoot,
	$sourceRoot.Replace('\', '/'),
	$skseRoot,
	$skseRoot.Replace('\', '/'),
	$skyuiRoot,
	$skyuiRoot.Replace('\', '/'),
	[Environment]::UserName,
	[Environment]::MachineName
)
$scriptResults = [Collections.Generic.List[object]]::new()
foreach ($scriptName in $sourceNames) {
	$rawPex = Join-Path $rawOutputRoot ([IO.Path]::ChangeExtension($scriptName, '.pex'))
	$neutralPex = Join-Path $neutralOutputRoot ([IO.Path]::ChangeExtension($scriptName, '.pex'))
	if (-not (Test-Path -LiteralPath $rawPex -PathType Leaf)) { throw "Expected compiler output is missing." }

	$rawBytes = [IO.File]::ReadAllBytes($rawPex)
	$rawMetadata = Read-PexMetadata -Bytes $rawBytes
	$neutralBytes = ConvertTo-NeutralPexMetadata -Bytes $rawBytes -SourceFileName $scriptName
	[IO.File]::WriteAllBytes($neutralPex, $neutralBytes)
	$neutralMetadata = Read-PexMetadata -Bytes $neutralBytes
	if ($neutralMetadata.SourceFileName -cne $scriptName -or $neutralMetadata.UserName -cne '' -or $neutralMetadata.ComputerName -cne '') {
		throw "Neutral PEX metadata validation failed."
	}
	if ($neutralMetadata.SuffixOffset -gt $neutralBytes.Length -or ($rawBytes.Length - $rawMetadata.SuffixOffset) -ne ($neutralBytes.Length - $neutralMetadata.SuffixOffset)) {
		throw "PEX payload length changed during metadata neutralization."
	}
	for ($i = 0; $i -lt 16; $i++) {
		if ($rawBytes[$i] -ne $neutralBytes[$i]) { throw "PEX fixed header changed during metadata neutralization." }
	}
	for ($i = 0; $i -lt ($rawBytes.Length - $rawMetadata.SuffixOffset); $i++) {
		if ($rawBytes[$rawMetadata.SuffixOffset + $i] -ne $neutralBytes[$neutralMetadata.SuffixOffset + $i]) {
			throw "PEX payload changed during metadata neutralization."
		}
	}
	$forbiddenMarkers = [Collections.Generic.List[string]]::new()
	foreach ($marker in $markers + @($rawMetadata.UserName, $rawMetadata.ComputerName)) {
		if (-not [string]::IsNullOrWhiteSpace($marker) -and $marker.Length -ge 4 -and -not $forbiddenMarkers.Contains($marker)) {
			$forbiddenMarkers.Add($marker)
			$forbiddenMarkers.Add($marker.Replace('\', '/'))
		}
	}
	$rawSourcePath = $rawMetadata.SourceFileName.Replace('/', '\')
	$rawSourceDirectory = [IO.Path]::GetDirectoryName($rawSourcePath)
	if (-not [string]::IsNullOrWhiteSpace($rawSourceDirectory)) {
		$forbiddenMarkers.Add($rawSourceDirectory)
		$forbiddenMarkers.Add($rawSourceDirectory.Replace('\', '/'))
	}
	foreach ($marker in $forbiddenMarkers) {
		if (Test-ContainsText -Bytes $neutralBytes -Text $marker) {
			throw "A neutral PEX still contains an identity or machine-path marker."
		}
	}
	$scriptResults.Add([pscustomobject]@{
		name = [IO.Path]::ChangeExtension($scriptName, '.pex')
		sourceSha256 = $sourceHashes[$scriptName]
		rawSha256 = Get-Sha256 -Path $rawPex
		neutralSha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $neutralPex).Hash
		neutralBytes = $neutralBytes.Length
		metadataOnlyNeutralization = $true
		sourceNameOnly = $true
		userAndComputerEmpty = $true
		fixedHeaderAndPayloadPreserved = $true
		pathAndIdentityMarkersAbsent = $true
	})
}

New-Item -ItemType Directory -Path $outputRoot | Out-Null
foreach ($result in $scriptResults) {
	$sourcePex = Join-Path $neutralOutputRoot $result.name
	$targetPex = Join-Path $outputRoot $result.name
	Copy-Item -LiteralPath $sourcePex -Destination $targetPex
	if ((Get-Sha256 -Path $sourcePex) -cne (Get-Sha256 -Path $targetPex)) {
		throw "Canonical PEX output differs from the verified neutral PEX."
	}
}
$outputFiles = @(Get-ChildItem -LiteralPath $outputRoot -File | Sort-Object Name)
if ($outputFiles.Count -ne 2 -or @($outputFiles.Name | Where-Object { $_ -notin @('WorldOfDramaLlamaNative.pex', 'WorldOfDramaLlamaMCM.pex') }).Count -ne 0) {
	throw "Papyrus output directory contains an unexpected member."
}
foreach ($file in $outputFiles) {
	if (Test-ContainsText -Bytes ([IO.File]::ReadAllBytes($file.FullName)) -Text ([Environment]::UserName)) {
		throw "A published PEX contains the current user identity."
	}
	if (Test-ContainsText -Bytes ([IO.File]::ReadAllBytes($file.FullName)) -Text ([Environment]::MachineName)) {
		throw "A published PEX contains the current machine identity."
	}
}

$receipt = [pscustomobject]@{
	schemaVersion = 1
	compiler = [pscustomobject]@{
		name = 'Caprica'
		version = $lock.caprica.version
		releaseArchiveSha256 = $lock.caprica.archiveSha256
		executableSha256 = Get-Sha256 -Path $compiler
		exitCode = $compilerExitCode
		options = @('--game=skyrim', '--strict', '--release', '--final', '--ignorecwd', '--enable-debug-info=0')
	}
	inputs = [pscustomobject]@{
		flagsSnapshotCommit = $lock.flags.commit
		flagsSha256 = Get-Sha256 -Path $flags
		flagsProvenance = $lock.flags.provenance
		skseVersion = $lock.sksePapyrus.version
		skseScriptFileCount = $skseIdentity.FileCount
		skseScriptTreeSha256 = $skseIdentity.Sha256
		skyuiCommit = $lock.skyuiPapyrusHeaders.commit
		skyuiHeaderFileCount = $skyuiIdentity.FileCount
		skyuiHeaderTreeSha256 = $skyuiIdentity.Sha256
	}
	compilerWarnings = 0
	pexMetadataOnlyNeutralization = $true
	scripts = @($scriptResults)
}
$receiptPath = Join-Path $evidenceRoot 'papyrus-build-receipt.json'
[IO.File]::WriteAllText($receiptPath, ($receipt | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))

Write-Output "Papyrus compile passed. Neutral PEX files are in the requested fresh output directory; raw PEX and compiler logs remain in the requested evidence directory."
