[CmdletBinding()]
param(
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $DotNetPath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $OutputRoot,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $EvidenceRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-InputFile {
	param([Parameter(Mandatory)][string] $Path)
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Required .NET build tool is missing: $Path" }
	$item = Get-Item -LiteralPath $Path -Force
	if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'The .NET build tool cannot be a reparse point.' }
	return (Resolve-Path -LiteralPath $Path).Path
}

function Resolve-FreshOutputPath {
	param([Parameter(Mandatory)][string] $Path)
	$fullPath = [IO.Path]::GetFullPath($Path)
	if (Test-Path -LiteralPath $fullPath) { throw 'Authoring output path already exists; choose a fresh directory.' }
	$parentPath = [IO.Path]::GetDirectoryName($fullPath)
	if (-not (Test-Path -LiteralPath $parentPath -PathType Container)) { throw 'Authoring output parent must already exist.' }
	$parent = Get-Item -LiteralPath $parentPath -Force
	if (($parent.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Authoring output parent cannot be a reparse point.' }
	return Join-Path (Resolve-Path -LiteralPath $parentPath).Path ([IO.Path]::GetFileName($fullPath))
}

function Invoke-DotNet {
	param(
		[Parameter(Mandatory)][string[]] $Arguments,
		[Parameter(Mandatory)][string] $LogPath,
		[Parameter(Mandatory)][string] $Description
	)
	$display = @($Arguments | ForEach-Object { '"' + $_.Replace('"', '\"') + '"' }) -join ' '
	Add-Content -LiteralPath $LogPath -Value "`r`n> dotnet $display"
	$output = & $script:dotnet @Arguments 2>&1
	$exitCode = $LASTEXITCODE
	$output | Add-Content -LiteralPath $LogPath
	if ($exitCode -ne 0) { throw "$Description failed with .NET exit code $exitCode; see the requested evidence log." }
}

$script:dotnet = Resolve-InputFile -Path $DotNetPath
$projectPath = Join-Path $PSScriptRoot 'WorldOfDramaLlamaGamePlugin.csproj'
$nugetConfig = Join-Path $PSScriptRoot 'NuGet.Config'
$lockPath = Join-Path $PSScriptRoot 'packages.lock.json'
if (-not (Test-Path -LiteralPath $projectPath -PathType Leaf) -or -not (Test-Path -LiteralPath $nugetConfig -PathType Leaf)) {
	throw 'The Mutagen authoring project or pinned NuGet configuration is missing.'
}
if (-not (Test-Path -LiteralPath $lockPath -PathType Leaf)) { throw 'The NuGet package lock is missing; restore and review it before building.' }
$packageLock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
$directPackage = $packageLock.dependencies.'net10.0'.'Mutagen.Bethesda.Skyrim'
if ($directPackage.resolved -cne '0.52.0' -or [string]::IsNullOrWhiteSpace($directPackage.contentHash)) {
	throw 'The NuGet lock does not pin the reviewed Mutagen.Bethesda.Skyrim 0.52.0 package bytes.'
}
$sdkVersion = (& $script:dotnet --version | Select-Object -First 1).Trim()
if ($sdkVersion -notmatch '^10\.') { throw "The authoring project requires the .NET 10 SDK; found '$sdkVersion'." }
$output = Resolve-FreshOutputPath -Path $OutputRoot
$evidence = Resolve-FreshOutputPath -Path $EvidenceRoot
if ($output.Equals($evidence, [StringComparison]::OrdinalIgnoreCase) -or
	$output.StartsWith($evidence.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or
	$evidence.StartsWith($output.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
	throw 'Authoring output and evidence must be separate directories.'
}

New-Item -ItemType Directory -Path $output,$evidence | Out-Null
$buildRoot = Join-Path $evidence 'build'
$intermediateRoot = Join-Path $buildRoot 'obj'
$outputRoot = Join-Path $buildRoot 'bin'
$nugetRoot = Join-Path $evidence 'nuget-packages'
$dotnetHome = Join-Path $evidence 'dotnet-home'
New-Item -ItemType Directory -Path $buildRoot,$intermediateRoot,$outputRoot,$nugetRoot,$dotnetHome | Out-Null
$env:DOTNET_CLI_HOME = $dotnetHome
$env:NUGET_PACKAGES = $nugetRoot
$env:DOTNET_SKIP_FIRST_TIME_EXPERIENCE = '1'
$env:DOTNET_CLI_TELEMETRY_OPTOUT = '1'
$env:DOTNET_NOLOGO = '1'

$restoreLog = Join-Path $evidence 'restore-build.log'
[IO.File]::WriteAllText($restoreLog, '', [Text.UTF8Encoding]::new($false))
$extensionPath = $intermediateRoot.TrimEnd('\') + '\'
Invoke-DotNet -Arguments @(
	'restore', $projectPath, '--configfile', $nugetConfig, '--locked-mode',
	"-p:MSBuildProjectExtensionsPath=$extensionPath", "-p:BaseIntermediateOutputPath=$extensionPath"
) -LogPath $restoreLog -Description 'locked Mutagen package restore'
Invoke-DotNet -Arguments @(
	'build', $projectPath, '--no-restore', '--configuration', 'Release',
	"-p:MSBuildProjectExtensionsPath=$extensionPath", "-p:BaseIntermediateOutputPath=$extensionPath", "-p:BaseOutputPath=$($outputRoot.TrimEnd('\'))\"
) -LogPath $restoreLog -Description 'Mutagen authoring build'

$assemblyPath = Join-Path $outputRoot 'Release\net10.0\WorldOfDramaLlamaGamePlugin.dll'
if (-not (Test-Path -LiteralPath $assemblyPath -PathType Leaf)) { throw 'The Mutagen authoring build did not produce its executable assembly.' }
$espPath = Join-Path $output 'WorldOfDramaLlama.esp'
$seqPath = Join-Path $output 'WorldOfDramaLlama.seq'
$authoringReceiptPath = Join-Path $evidence 'mutagen-authoring-report.json'
$authoringErrorPath = Join-Path $evidence 'mutagen-authoring-stderr.log'
$authoringOutput = & $script:dotnet $assemblyPath $espPath $seqPath 2> $authoringErrorPath
$authoringExitCode = $LASTEXITCODE
if ($authoringExitCode -ne 0) { throw "Mutagen plugin authoring failed with .NET exit code $authoringExitCode." }
$authoringOutput | Set-Content -LiteralPath $authoringReceiptPath -Encoding utf8NoBOM
$authoringReport = Get-Content -LiteralPath $authoringReceiptPath -Raw | ConvertFrom-Json
$esp = Get-Item -LiteralPath $espPath
$seq = Get-Item -LiteralPath $seqPath
if ($esp.Length -ne 185 -or $seq.Length -ne 4 -or $authoringReport.validation -notmatch 'read-back passed') {
	throw 'Fresh ESP/SEQ outputs failed the expected Mutagen read-back contract.'
}
$receipt = [ordered]@{
	schemaVersion = 1
	status = 'own-game-assets-authored-and-read-back-verified'
	toolchain = [ordered]@{ dotnetSdk = $sdkVersion; targetFramework = 'net10.0' }
	mutagen = [ordered]@{
		package = 'Mutagen.Bethesda.Skyrim'
		version = $directPackage.resolved
		contentHash = $directPackage.contentHash
		lockSha256 = (Get-FileHash -LiteralPath $lockPath -Algorithm SHA256).Hash.ToUpperInvariant()
		packageConfig = 'NuGet v3 source: https://api.nuget.org/v3/index.json'
	}
	outputs = @(
		[ordered]@{ name = $esp.Name; bytes = $esp.Length; sha256 = (Get-FileHash -LiteralPath $esp.FullName -Algorithm SHA256).Hash.ToUpperInvariant() },
		[ordered]@{ name = $seq.Name; bytes = $seq.Length; sha256 = (Get-FileHash -LiteralPath $seq.FullName -Algorithm SHA256).Hash.ToUpperInvariant() }
	)
	checks = [ordered]@{
		masterCount = $authoringReport.masterCount
		majorRecordCount = $authoringReport.majorRecordCount
		questLocalFormId = $authoringReport.questLocalFormId
		sequenceEntryCount = $authoringReport.sequenceEntryCount
		readBack = $authoringReport.validation
		noInstalledGameAccess = $true
	}
}
$receiptPath = Join-Path $evidence 'mutagen-build-receipt.json'
[IO.File]::WriteAllText($receiptPath, ($receipt | ConvertTo-Json -Depth 8) + "`n", [Text.UTF8Encoding]::new($false))
$savedReceipt = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
if ($savedReceipt.outputs.Count -ne 2 -or $savedReceipt.mutagen.version -cne '0.52.0') { throw 'Mutagen build receipt did not reopen with expected output pins.' }
Write-Output "Mutagen produced the requested fresh 185-byte ESP and 4-byte SEQ."
Write-Output "Outputs: $output"
Write-Output "Evidence: $evidence"
