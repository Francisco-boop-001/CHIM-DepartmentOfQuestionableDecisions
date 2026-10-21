[CmdletBinding()]
param(
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $CachePath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$lockPath = Join-Path $PSScriptRoot 'deps.lock.json'
$lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
if ($lock.schemaVersion -ne 1) { throw 'Unsupported native dependency-lock schema.' }

$cacheFullPath = [IO.Path]::GetFullPath($CachePath)
if (Test-Path -LiteralPath $cacheFullPath) { throw 'Dependency cache path already exists; choose a new path.' }
$parentPath = [IO.Path]::GetDirectoryName($cacheFullPath)
if (-not (Test-Path -LiteralPath $parentPath -PathType Container)) { throw 'Dependency cache parent must already exist.' }
$parentItem = Get-Item -LiteralPath $parentPath -Force
if (($parentItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Dependency cache parent cannot be a reparse point.' }

function Get-Sha256 {
	param([Parameter(Mandatory)][string] $Path)
	return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToUpperInvariant()
}

function Save-PinnedFile {
	param(
		[Parameter(Mandatory)][string] $Url,
		[Parameter(Mandatory)][string] $Destination,
		[Parameter(Mandatory)][string] $Sha256
	)
	Invoke-WebRequest -Uri $Url -OutFile $Destination -ErrorAction Stop
	$actual = Get-Sha256 -Path $Destination
	if ($actual -cne $Sha256.ToUpperInvariant()) { throw "Pinned dependency SHA-256 mismatch: $([IO.Path]::GetFileName($Destination))" }
	return [pscustomobject]@{ name = [IO.Path]::GetFileName($Destination); bytes = (Get-Item -LiteralPath $Destination).Length; sha256 = $actual }
}

New-Item -ItemType Directory -Path $cacheFullPath | Out-Null
$downloads = Join-Path $cacheFullPath 'downloads'
$includeRoot = Join-Path $cacheFullPath 'include'
New-Item -ItemType Directory -Path $downloads,$includeRoot | Out-Null
$pinnedFiles = [Collections.Generic.List[object]]::new()
foreach ($name in @('commonLib','fmt','spdlog','rapidcsv')) {
	$dependency = $lock.$name
	$archivePath = Join-Path $downloads $dependency.archiveName
	$pinnedFiles.Add((Save-PinnedFile -Url $dependency.url -Destination $archivePath -Sha256 $dependency.sha256))
}

$jsonHeaderPath = Join-Path $cacheFullPath $lock.nlohmannJson.relativePath
New-Item -ItemType Directory -Path (Split-Path -Parent $jsonHeaderPath) -Force | Out-Null
$pinnedFiles.Add((Save-PinnedFile -Url $lock.nlohmannJson.url -Destination $jsonHeaderPath -Sha256 $lock.nlohmannJson.sha256))
$prismaHeaderPath = Join-Path $cacheFullPath $lock.prismaUi.relativePath
$pinnedFiles.Add((Save-PinnedFile -Url $lock.prismaUi.url -Destination $prismaHeaderPath -Sha256 $lock.prismaUi.sha256))

$receipt = [ordered]@{
	schemaVersion = 1
	status = 'pinned-native-source-inputs-downloaded'
	lockSha256 = Get-Sha256 -Path $lockPath
	networkAccess = 'Anonymous HTTPS GET only; no credentials were supplied.'
	files = @($pinnedFiles | Sort-Object name)
}
$receiptPath = Join-Path $cacheFullPath 'acquisition-receipt.json'
[IO.File]::WriteAllText($receiptPath, ($receipt | ConvertTo-Json -Depth 6) + "`n", [Text.UTF8Encoding]::new($false))
$saved = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
if ($saved.status -cne $receipt.status -or $saved.files.Count -ne 6) { throw 'Native dependency acquisition receipt failed read-back.' }
Write-Output "Pinned source inputs downloaded and hash-checked in the requested cache directory. Receipt: $receiptPath"
