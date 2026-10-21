[CmdletBinding()]
param(
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $CachePath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $SevenZipPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$lockPath = Join-Path $PSScriptRoot 'deps.lock.json'
$lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
if ($lock.schemaVersion -ne 1) { throw 'Unsupported Papyrus dependency lock schema.' }

function Get-Sha256 {
	param([Parameter(Mandatory)][string] $Path)
	return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToUpperInvariant()
}

function Resolve-File {
	param([Parameter(Mandatory)][string] $Path)
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Required tool or input is missing: $Path" }
	$item = Get-Item -LiteralPath $Path -Force
	if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'A required file cannot be a reparse point.' }
	return (Resolve-Path -LiteralPath $Path).Path
}

function Save-PinnedFile {
	param(
		[Parameter(Mandatory)][string] $Url,
		[Parameter(Mandatory)][string] $Destination,
		[Parameter(Mandatory)][string] $Sha256
	)
	Invoke-WebRequest -Uri $Url -OutFile $Destination -ErrorAction Stop
	$actual = Get-Sha256 -Path $Destination
	if ($actual -cne $Sha256.ToUpperInvariant()) { throw "Pinned Papyrus input SHA-256 mismatch: $([IO.Path]::GetFileName($Destination))" }
	return [pscustomobject]@{ name = [IO.Path]::GetFileName($Destination); bytes = (Get-Item -LiteralPath $Destination).Length; sha256 = $actual }
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
			if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'A pinned dependency tree cannot contain reparse points.' }
			if ($entry.PSIsContainer) { $pending.Enqueue($entry.FullName) }
			else { $relativePaths.Add($entry.FullName.Substring($rootPath.Length).TrimStart('\', '/').Replace('\', '/')) }
		}
	}
	$names = $relativePaths.ToArray()
	[Array]::Sort($names, [StringComparer]::Ordinal)
	$records = [Collections.Generic.List[string]]::new()
	foreach ($name in $names) {
		$filePath = Join-Path $rootPath ($name.Replace('/', [IO.Path]::DirectorySeparatorChar))
		$records.Add("$name$([char]9)$(Get-Sha256 -Path $filePath)")
	}
	$payload = [Text.Encoding]::UTF8.GetBytes(($records -join [string][char]10) + [char]10)
	return [pscustomobject]@{ FileCount = $names.Length; Sha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($payload)) }
}

$sevenZip = Resolve-File -Path $SevenZipPath
$cacheFullPath = [IO.Path]::GetFullPath($CachePath)
if (Test-Path -LiteralPath $cacheFullPath) { throw 'Papyrus dependency cache path already exists; choose a new path.' }
$parentPath = [IO.Path]::GetDirectoryName($cacheFullPath)
if (-not (Test-Path -LiteralPath $parentPath -PathType Container)) { throw 'Papyrus dependency cache parent must already exist.' }
$parent = Get-Item -LiteralPath $parentPath -Force
if (($parent.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Papyrus dependency cache parent cannot be a reparse point.' }

New-Item -ItemType Directory -Path $cacheFullPath | Out-Null
$downloads = Join-Path $cacheFullPath 'downloads'
$extracted = Join-Path $cacheFullPath 'extracted'
$capricaRoot = Join-Path $extracted 'caprica'
$skseExtractRoot = Join-Path $extracted 'skse'
$skyuiExtractRoot = Join-Path $extracted 'skyui'
$flagsRoot = Join-Path $cacheFullPath 'flags'
New-Item -ItemType Directory -Path $downloads,$extracted,$capricaRoot,$skseExtractRoot,$skyuiExtractRoot,$flagsRoot | Out-Null

$capricaArchive = Join-Path $downloads 'Caprica.v0.3.0.7z'
$skseArchive = Join-Path $downloads 'skse64-v2.0.20.zip'
$skyuiArchive = Join-Path $downloads ('skyui-' + $lock.skyuiPapyrusHeaders.commit + '.zip')
$flagsPath = Join-Path $flagsRoot 'TESV_Papyrus_Flags.flg'
$inputs = [Collections.Generic.List[object]]::new()
$inputs.Add((Save-PinnedFile -Url $lock.caprica.releaseUrl -Destination $capricaArchive -Sha256 $lock.caprica.archiveSha256))
$inputs.Add((Save-PinnedFile -Url $lock.sksePapyrus.releaseUrl -Destination $skseArchive -Sha256 $lock.sksePapyrus.archiveSha256))
$inputs.Add((Save-PinnedFile -Url $lock.skyuiPapyrusHeaders.releaseUrl -Destination $skyuiArchive -Sha256 $lock.skyuiPapyrusHeaders.archiveSha256))
$inputs.Add((Save-PinnedFile -Url $lock.flags.rawUrl -Destination $flagsPath -Sha256 $lock.flags.sha256))

$sevenZipOutput = & $sevenZip x $capricaArchive "-o$capricaRoot" '-y' 2>&1
$sevenZipExitCode = $LASTEXITCODE
if ($sevenZipExitCode -ne 0) { throw "Pinned Caprica archive extraction failed with 7-Zip exit code $sevenZipExitCode." }
$compilerMatches = @(Get-ChildItem -LiteralPath $capricaRoot -Recurse -File -Filter 'Caprica.exe')
if ($compilerMatches.Count -ne 1 -or (Get-Sha256 -Path $compilerMatches[0].FullName) -cne $lock.caprica.executableSha256) {
	throw 'Caprica archive did not contain exactly one executable with the pinned v0.3.0 hash.'
}

Expand-Archive -LiteralPath $skseArchive -DestinationPath $skseExtractRoot
$skseCandidates = @(Get-ChildItem -LiteralPath $skseExtractRoot -Directory -Recurse | Where-Object {
	$vanilla = Join-Path $_.FullName 'vanilla'
	$modified = Join-Path $_.FullName 'modified'
	(Test-Path -LiteralPath $vanilla -PathType Container) -and (Test-Path -LiteralPath $modified -PathType Container)
})
$skseCandidates = @($skseCandidates | Where-Object {
	$identity = Get-TreeIdentity -Root $_.FullName
	$identity.FileCount -eq $lock.sksePapyrus.fileCount -and $identity.Sha256 -ceq $lock.sksePapyrus.treeSha256
})
if ($skseCandidates.Count -ne 1) { throw 'SKSE archive did not contain exactly one scripts tree matching the pinned v2.0.20 identity.' }
$skseRoot = $skseCandidates[0].FullName

Expand-Archive -LiteralPath $skyuiArchive -DestinationPath $skyuiExtractRoot
$skyuiCandidates = @(Get-ChildItem -LiteralPath $skyuiExtractRoot -Directory -Recurse | Where-Object { $_.FullName.Replace('\', '/').EndsWith('/' + $lock.skyuiPapyrusHeaders.headersPath.Replace('\', '/'), [StringComparison]::Ordinal) })
$skyuiCandidates = @($skyuiCandidates | Where-Object {
	$identity = Get-TreeIdentity -Root $_.FullName
	$identity.FileCount -eq $lock.skyuiPapyrusHeaders.fileCount -and $identity.Sha256 -ceq $lock.skyuiPapyrusHeaders.treeSha256
})
if ($skyuiCandidates.Count -ne 1) { throw 'SkyUI archive did not contain exactly one header tree matching the pinned commit identity.' }
$skyuiRoot = $skyuiCandidates[0].FullName

$receipt = [ordered]@{
	schemaVersion = 1
	status = 'pinned-papyrus-inputs-fetched-and-verified'
	networkAccess = 'Anonymous HTTPS GET only; no credentials were supplied.'
	inputs = @($inputs | Sort-Object name)
	resolved = [ordered]@{
		caprica = [ordered]@{ version = $lock.caprica.version; executableSha256 = $lock.caprica.executableSha256 }
		flags = [ordered]@{ commit = $lock.flags.commit; sha256 = $lock.flags.sha256; provenance = $lock.flags.provenance }
		skse = [ordered]@{ version = $lock.sksePapyrus.version; fileCount = $lock.sksePapyrus.fileCount; treeSha256 = $lock.sksePapyrus.treeSha256 }
		skyui = [ordered]@{ commit = $lock.skyuiPapyrusHeaders.commit; fileCount = $lock.skyuiPapyrusHeaders.fileCount; treeSha256 = $lock.skyuiPapyrusHeaders.treeSha256 }
	}
}
$receiptPath = Join-Path $cacheFullPath 'fetch-receipt.json'
[IO.File]::WriteAllText($receiptPath, ($receipt | ConvertTo-Json -Depth 8) + "`n", [Text.UTF8Encoding]::new($false))
$saved = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
if ($saved.status -cne $receipt.status -or $saved.inputs.Count -ne 4) { throw 'Papyrus fetch receipt did not reopen with expected inputs.' }

Write-Output "Caprica: $($compilerMatches[0].FullName)"
Write-Output "Flags: $flagsPath"
Write-Output "SKSE scripts: $skseRoot"
Write-Output "SkyUI headers: $skyuiRoot"
Write-Output "Receipt: $receiptPath"
