[CmdletBinding()]
param(
	[Parameter(Mandatory)][string] $OuterZipPath,
	[Parameter(Mandatory)][string] $ImplementationRoot,
	[Parameter(Mandatory)][string] $FreezePath,
	[Parameter(Mandatory)][string] $GeneratedInputsRoot,
	[Parameter(Mandatory)][string] $NativeDllInputPath,
	[Parameter(Mandatory)][string] $PapyrusMcmInputPath,
	[Parameter(Mandatory)][string] $PapyrusNativeInputPath,
	[Parameter(Mandatory)][string] $EspInputPath,
	[Parameter(Mandatory)][string] $SeqInputPath,
	[Parameter(Mandatory)][string] $SourceAllowlistPath,
	[string] $ReleaseGuidePath = 'packaging/COMPANION-ONLY-TEST-PACKAGE.md',
	[string] $AuditOutputPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression

$script:AuditOutputFullPath = $null
$script:GuardSelfTest = $null
$script:MaximumEntryBytes = 268435456L
$script:ComponentPrefix = 'WorldOfDramaLlama-department-pre-alpha'
$script:PackageGuideName = 'PRE-ALPHA-RELEASE.md'
$script:GeneratedInputsRootFullPath = $null
$script:AllowedOuterNames = @(
	'WorldOfDramaLlama-department-pre-alpha-game-data.zip'
	'WorldOfDramaLlama-department-pre-alpha-server.zip'
	'WorldOfDramaLlama-department-pre-alpha-source.zip'
	'WorldOfDramaLlama-department-pre-alpha-notices.zip'
	'PRE-ALPHA-RELEASE.md'
	'package-manifest.json'
)
$script:GameSpecs = @(
	[pscustomobject]@{ Path = 'Data/PrismaUI/views/WorldOfDramaLlama/app.css'; Source = 'ui/app.css' }
	[pscustomobject]@{ Path = 'Data/PrismaUI/views/WorldOfDramaLlama/app.js'; Source = 'ui/app.js' }
	[pscustomobject]@{ Path = 'Data/PrismaUI/views/WorldOfDramaLlama/index.html'; Source = 'ui/index.html' }
	[pscustomobject]@{ Path = 'Data/Scripts/WorldOfDramaLlamaMCM.pex'; Source = $null }
	[pscustomobject]@{ Path = 'Data/Scripts/WorldOfDramaLlamaNative.pex'; Source = $null }
	[pscustomobject]@{ Path = 'Data/SKSE/Plugins/WorldOfDramaLlama.dll'; Source = $null }
	[pscustomobject]@{ Path = 'Data/SKSE/Plugins/WorldOfDramaLlama.ini'; Source = 'packaging/companion-only/WorldOfDramaLlama.ini' }
)
$script:GameAssetSpecs = @(
	[pscustomobject]@{ Path = 'Data/WorldOfDramaLlama.esp'; Source = $null }
	[pscustomobject]@{ Path = 'Data/SEQ/WorldOfDramaLlama.seq'; Source = $null }
)
$script:ServerSpecs = @(
	[pscustomobject]@{ Path = 'ext/questionable_decisions/api.php'; Source = 'server/api.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/runtime.php'; Source = 'server/runtime.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/auth.php'; Source = 'server/lib/auth.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/dispatcher.php'; Source = 'server/lib/dispatcher.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/journal.php'; Source = 'server/lib/journal.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/protocol.php'; Source = 'server/lib/protocol.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/correspondence.php'; Source = 'server/adapters/correspondence.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/mp.php'; Source = 'server/adapters/mp.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/pcv.php'; Source = 'server/adapters/pcv.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/runtime_support.php'; Source = 'server/adapters/runtime_support.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/sas.php'; Source = 'server/adapters/sas.php' }
	[pscustomobject]@{ Path = 'ext/questionable_decisions/tools/provision.php'; Source = 'server/tools/provision.php' }
	[pscustomobject]@{ Path = 'ext/pcv_companion/companion.php'; Source = 'pcv-companion/companion.php' }
	[pscustomobject]@{ Path = 'ext/pcv_companion/globals.php'; Source = 'pcv-companion/globals.php' }
	[pscustomobject]@{ Path = 'ext/pcv_companion/preprocessing.php'; Source = 'pcv-companion/preprocessing.php' }
	[pscustomobject]@{ Path = 'ext/private_conversation/installation_guard.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/installation_guard.php' }
	[pscustomobject]@{ Path = 'ext/private_conversation/log.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/log.php' }
	[pscustomobject]@{ Path = 'ext/private_conversation/manifest.json'; Source = 'pcv-companion/private-conversation-v0.1.22/server/manifest.json' }
	[pscustomobject]@{ Path = 'ext/private_conversation/preprocessing.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/preprocessing.php' }
	[pscustomobject]@{ Path = 'ext/private_conversation/reflection.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/reflection.php' }
	[pscustomobject]@{ Path = 'ext/private_conversation/scope.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/scope.php' }
	[pscustomobject]@{ Path = 'ext/private_conversation/state.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/state.php' }
	[pscustomobject]@{ Path = 'docs/pcv-0.1.22-source-manifest.json'; Source = 'packaging/companion-only/pcv-0.1.22-source-manifest.json' }
	[pscustomobject]@{ Path = 'docs/PCV-PROVENANCE.md'; Source = 'packaging/companion-only/PCV-PROVENANCE.md' }
)
$script:NoticeSpecs = @(
	[pscustomobject]@{ Path = 'notices/CommonLibSSE-NG-3.7.0-LICENSE.txt'; Source = 'packaging/companion-only/notices/CommonLibSSE-NG-3.7.0-LICENSE.txt' }
	[pscustomobject]@{ Path = 'notices/fmt-10.2.1-LICENSE.txt'; Source = 'packaging/companion-only/notices/fmt-10.2.1-LICENSE.txt' }
	[pscustomobject]@{ Path = 'notices/Department-nlohmann-json-3.11.3-MIT.txt'; Source = 'packaging/companion-only/notices/Department-nlohmann-json-3.11.3-MIT.txt' }
	[pscustomobject]@{ Path = 'notices/rapidcsv-8.84-LICENSE.txt'; Source = 'packaging/companion-only/notices/rapidcsv-8.84-LICENSE.txt' }
	[pscustomobject]@{ Path = 'notices/spdlog-1.13.0-LICENSE.txt'; Source = 'packaging/companion-only/notices/spdlog-1.13.0-LICENSE.txt' }
	[pscustomobject]@{ Path = 'notices/PrismaUI-LICENSE.md'; Source = 'packaging/companion-only/notices/PrismaUI-LICENSE.md' }
	[pscustomobject]@{ Path = 'notices/CHIM-Plugin-MIT-LICENSE.txt'; Source = 'packaging/companion-only/notices/CHIM-Plugin-MIT-LICENSE.txt' }
)
$script:SourcePaths = @()
$script:ExpectedInputPaths = @()
$script:ForbiddenPathPatterns = @(
	'(?i)(^|/)AIAgent(?:\.|/|$)'
	'(?i)(^|/)(?:DepartmentCompanionABI|DepartmentCompanionWire|DepartmentCompanionBridge|DepartmentSendState|ChimProducerPolicy)(?:\.|/|$)'
	'(?i)(^|/)zz_companion_probe(?:/|$)'
	'(?i)(^|/)(?:companion-probe-preprocessing|test-probe)(?:\.|/|$)'
	'(?i)(^|/)(?:main\.php|stream\.php|comm\.php|gamedata\.php)$'
	'(?i)(^|/)compatibility/CHIM-'
)

function Assert-PlainRelativePath {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
	if ([string]::IsNullOrWhiteSpace($Path) -or [IO.Path]::IsPathRooted($Path) -or $Path.Contains('\') -or $Path.Contains(':') -or $Path.Contains('!') -or $Path -match '(^|/)\.\.(/|$)|(^|/)\.(?:/|$)|//') {
		throw "$Label is not a safe project-relative path: $Path"
	}
}

function Assert-NoReparseChain {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Boundary)
	$fullPath = [IO.Path]::GetFullPath($Path)
	$fullBoundary = [IO.Path]::GetFullPath($Boundary).TrimEnd('\', '/')
	$prefix = $fullBoundary + [IO.Path]::DirectorySeparatorChar
	if (-not $fullPath.Equals($fullBoundary, [StringComparison]::OrdinalIgnoreCase) -and -not $fullPath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
		throw "Path escapes the authorized implementation root: $fullPath"
	}
	$current = $fullPath
	while ($true) {
		$item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
		if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Reparse point is not allowed in an audited path: $current" }
		if ($current.Equals($fullBoundary, [StringComparison]::OrdinalIgnoreCase)) { break }
		$current = [IO.Directory]::GetParent($current).FullName
	}
}

function Resolve-ImplementationPath {
	param([Parameter(Mandatory)][string] $RelativePath, [Parameter(Mandatory)][string] $Label)
	Assert-PlainRelativePath -Path $RelativePath -Label $Label
	if ($RelativePath.StartsWith('generated/', [StringComparison]::Ordinal)) {
		if ([string]::IsNullOrWhiteSpace($script:GeneratedInputsRootFullPath)) { throw 'Generated input root was not initialized.' }
		$tail = $RelativePath.Substring('generated/'.Length)
		Assert-PlainRelativePath -Path $tail -Label 'Generated input path'
		$fullPath = [IO.Path]::GetFullPath((Join-Path $script:GeneratedInputsRootFullPath ($tail -replace '/', '\')))
		Assert-NoReparseChain -Path $fullPath -Boundary $script:GeneratedInputsRootFullPath
	} else {
		$fullPath = [IO.Path]::GetFullPath((Join-Path $script:ImplementationRoot ($RelativePath -replace '/', '\')))
		Assert-NoReparseChain -Path $fullPath -Boundary $script:ImplementationRoot
	}
	if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) { throw "$Label is missing: $RelativePath" }
	return $fullPath
}

function Assert-NoReparseChainToVolumeRoot {
	param([Parameter(Mandatory)][string] $Path)
	$current = [IO.Path]::GetFullPath($Path)
	while ($true) {
		$item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
		if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'An audited path contains a reparse point.' }
		$parent = [IO.Directory]::GetParent($current)
		if ($null -eq $parent) { break }
		$current = $parent.FullName
	}
}

function Get-BytesDigest {
	param([Parameter(Mandatory)][byte[]] $Bytes)
	return [pscustomobject]@{
		bytes = [long]$Bytes.LongLength
		sha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($Bytes)).ToLowerInvariant()
	}
}

function Read-BoundedStream {
	param([Parameter(Mandatory)][IO.Stream] $Stream, [Parameter(Mandatory)][string] $Label)
	$memory = [IO.MemoryStream]::new()
	try {
		$buffer = [byte[]]::new(65536)
		while (($read = $Stream.Read($buffer, 0, $buffer.Length)) -gt 0) {
			if (($memory.Length + $read) -gt $script:MaximumEntryBytes) { throw "Archive entry exceeds the audit size limit: $Label" }
			$memory.Write($buffer, 0, $read)
		}
		return ,$memory.ToArray()
	} finally {
		$memory.Dispose()
	}
}

function Assert-SafeArchiveName {
	param([Parameter(Mandatory)][string] $Name, [Parameter(Mandatory)][string] $Label)
	if ([string]::IsNullOrWhiteSpace($Name) -or $Name.Contains('\') -or $Name.Contains(':') -or $Name.Contains([char]0) -or $Name.StartsWith('/') -or $Name.EndsWith('/')) {
		throw "$Label contains an unsafe ZIP member name: $Name"
	}
	foreach ($part in $Name.Split('/')) {
		if ($part -in @('', '.', '..')) { throw "$Label contains a traversing or empty ZIP path component: $Name" }
	}
}

function Read-ZipArchiveRecords {
	param([Parameter(Mandatory)][IO.Stream] $Stream, [Parameter(Mandatory)][string] $Label)
	$archive = [IO.Compression.ZipArchive]::new($Stream, [IO.Compression.ZipArchiveMode]::Read, $true)
	try {
		$records = [Collections.Generic.List[object]]::new()
		$seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
		foreach ($entry in $archive.Entries) {
			Assert-SafeArchiveName -Name $entry.FullName -Label $Label
			if (-not $seen.Add($entry.FullName)) { throw "$Label contains a duplicate or case-colliding ZIP member: $($entry.FullName)" }
			if ($entry.Length -gt $script:MaximumEntryBytes) { throw "$Label contains an oversized ZIP member: $($entry.FullName)" }
			$unixMode = ([int]$entry.ExternalAttributes -shr 16) -band 0xF000
			if ($unixMode -eq 0xA000) { throw "$Label contains a symbolic-link ZIP member: $($entry.FullName)" }
			$entryStream = $entry.Open()
			try { $bytes = Read-BoundedStream -Stream $entryStream -Label "$Label!$($entry.FullName)" } finally { $entryStream.Dispose() }
			if ($bytes.LongLength -ne $entry.Length) { throw "$Label ZIP member length differs from its directory record: $($entry.FullName)" }
			$digest = Get-BytesDigest -Bytes $bytes
			$records.Add([pscustomobject]@{ path = $entry.FullName; bytes = $digest.bytes; sha256 = $digest.sha256; content = $bytes })
		}
		return ,$records.ToArray()
	} finally {
		$archive.Dispose()
	}
}

function Read-ZipFileRecords {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
	$stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
	try { return ,(Read-ZipArchiveRecords -Stream $stream -Label $Label) } finally { $stream.Dispose() }
}

function Read-ZipBytesRecords {
	param([Parameter(Mandatory)][byte[]] $Bytes, [Parameter(Mandatory)][string] $Label)
	$stream = [IO.MemoryStream]::new($Bytes, $false)
	try { return ,(Read-ZipArchiveRecords -Stream $stream -Label $Label) } finally { $stream.Dispose() }
}

function Get-ExactRecord {
	param([Parameter(Mandatory)][object[]] $Records, [Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
	$matches = @($Records | Where-Object { [string]$_.path -ceq $Path })
	if ($matches.Count -ne 1) { throw "$Label is missing or duplicates expected member $Path" }
	return $matches[0]
}

function Assert-ExactRecordPaths {
	param([Parameter(Mandatory)][object[]] $Records, [Parameter(Mandatory)][string[]] $Expected, [Parameter(Mandatory)][string] $Label)
	$expectedSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
	foreach ($path in $Expected) { $null = $expectedSet.Add($path) }
	$actualSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
	foreach ($record in $Records) { $null = $actualSet.Add([string]$record.path) }
	if ($Records.Count -ne $Expected.Count -or -not $actualSet.SetEquals($expectedSet)) {
		$missing = @($Expected | Where-Object { -not $actualSet.Contains($_) })
		$extra = @($Records | Where-Object { -not $expectedSet.Contains([string]$_.path) } | ForEach-Object { $_.path })
		throw "$Label member allowlist mismatch; missing=[$($missing -join ',')], extra=[$($extra -join ',')]"
	}
}

function Get-MapValueExact {
	param([Parameter(Mandatory)][System.Collections.IDictionary] $Map, [Parameter(Mandatory)][string] $Key, [Parameter(Mandatory)][string] $Label)
	$matches = @($Map.Keys | Where-Object { [string]$_ -ceq $Key })
	if ($matches.Count -ne 1) { throw "$Label is missing or duplicates required key $Key" }
	return $Map[$matches[0]]
}

function Get-FileDigest {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
	$stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
	try {
		$bytes = Read-BoundedStream -Stream $stream -Label $Label
		return Get-BytesDigest -Bytes $bytes
	} finally { $stream.Dispose() }
}

function Get-FrozenInput {
	param([Parameter(Mandatory)][string] $RelativePath)
	$pin = Get-MapValueExact -Map $script:Freeze.files -Key $RelativePath -Label 'Root freeze files'
	if ($pin -isnot [System.Collections.IDictionary] -or $pin.sha256 -cnotmatch '\A[0-9a-f]{64}\z' -or ($pin.bytes -isnot [long] -and $pin.bytes -isnot [int])) {
		throw "Malformed root-frozen input record: $RelativePath"
	}
	$path = Resolve-ImplementationPath -RelativePath $RelativePath -Label 'Frozen source file'
	$digest = Get-FileDigest -Path $path -Label $RelativePath
	if ($digest.sha256 -cne $pin.sha256 -or $digest.bytes -ne [long]$pin.bytes) { throw "Canonical source differs from root freeze: $RelativePath" }
	return [pscustomobject]@{ path = $RelativePath; fullPath = $path; bytes = $digest.bytes; sha256 = $digest.sha256; pin = $pin }
}

function Assert-RecordMatchesInput {
	param([Parameter(Mandatory)][object] $Record, [Parameter(Mandatory)][object] $CanonicalInput, [Parameter(Mandatory)][string] $Label)
	if ($Record.bytes -ne $CanonicalInput.bytes -or $Record.sha256 -cne $CanonicalInput.sha256) { throw "$Label differs from the root-frozen canonical source $($CanonicalInput.path)" }
}

function Convert-StrictUtf8 {
	param([Parameter(Mandatory)][byte[]] $Bytes, [Parameter(Mandatory)][string] $Label)
	try { return [Text.UTF8Encoding]::new($false, $true).GetString($Bytes) } catch { throw "$Label is not valid UTF-8." }
}

function Assert-BlankRuntimeIni {
	param([Parameter(Mandatory)][byte[]] $Bytes, [Parameter(Mandatory)][string] $Label)
	$text = Convert-StrictUtf8 -Bytes $Bytes -Label $Label
	if ($text.StartsWith([string][char]0xFEFF, [StringComparison]::Ordinal)) { throw "$Label must not have a UTF-8 BOM." }
	$lines = @($text.Replace("`r`n", "`n").Split("`n") | Where-Object { $_ -cne '' })
	$expected = @('[Server]', 'BaseUrl=', 'PairToken=', '[Input]', 'OpenKeyCode=-1')
	if ($lines.Count -ne $expected.Count) { throw "$Label is not the approved blank configuration template." }
	for ($index = 0; $index -lt $expected.Count; $index++) {
		if ($lines[$index] -cne $expected[$index]) { throw "$Label is not the approved blank configuration template." }
	}
}

function Assert-NoForbiddenArtifactPaths {
	param([Parameter(Mandatory)][string[]] $Names, [Parameter(Mandatory)][string] $Label)
	foreach ($name in $Names) {
		foreach ($pattern in $script:ForbiddenPathPatterns) {
			if ($name -match $pattern) { throw "$Label includes a forbidden CHIM/core/probe/legacy path: $name" }
		}
	}
}

function Get-CanonicalFreezeRelativePath {
	param([Parameter(Mandatory)][string] $Path)
	$fullPath = [IO.Path]::GetFullPath($Path)
	if ([IO.Path]::IsPathRooted($Path)) { $fullPath = $Path } else { $fullPath = [IO.Path]::GetFullPath((Join-Path $script:ImplementationRoot $Path)) }
	Assert-NoReparseChain -Path $fullPath -Boundary $script:ImplementationRoot
	if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) { throw "Freeze file is missing: $fullPath" }
	return [IO.Path]::GetRelativePath($script:ImplementationRoot, $fullPath).Replace('\', '/')
}

function Assert-ManifestMemberRecords {
	param([Parameter(Mandatory)][object[]] $ManifestMembers, [Parameter(Mandatory)][object[]] $ActualMembers, [Parameter(Mandatory)][string] $Label)
	if ($ManifestMembers.Count -ne $ActualMembers.Count) { throw "$Label member count does not match the actual archive." }
	$seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
	foreach ($member in $ManifestMembers) {
		if ($member -isnot [System.Collections.IDictionary] -or $member.path -isnot [string] -or $member.sha256 -cnotmatch '\A[0-9a-f]{64}\z' -or ($member.bytes -isnot [long] -and $member.bytes -isnot [int])) {
			throw "$Label contains a malformed member record."
		}
		if (-not $seen.Add([string]$member.path)) { throw "$Label contains duplicate member records." }
		$actual = Get-ExactRecord -Records $ActualMembers -Path ([string]$member.path) -Label $Label
		if ([long]$member.bytes -ne $actual.bytes -or [string]$member.sha256 -cne $actual.sha256) { throw "$Label member metadata differs from actual bytes: $($member.path)" }
	}
}

function Write-AuditJson {
	param([Parameter(Mandatory)][object] $Value, [Parameter(Mandatory)][switch] $CreateOutput)
	$json = ConvertTo-Json -InputObject $Value -Depth 24 -Compress
	if ($CreateOutput -and $script:AuditOutputFullPath) {
		$bytes = [Text.UTF8Encoding]::new($false).GetBytes($json + "`n")
		$stream = [IO.File]::Open($script:AuditOutputFullPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
		try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) } finally { $stream.Dispose() }
	}
	[Console]::Out.WriteLine($json)
}

function New-SyntheticZipBytes {
	param([Parameter(Mandatory)][object[]] $Entries)
	$memory = [IO.MemoryStream]::new()
	$archive = [IO.Compression.ZipArchive]::new($memory, [IO.Compression.ZipArchiveMode]::Create, $true)
	try {
		foreach ($item in $Entries) {
			$entry = $archive.CreateEntry([string]$item.name, [IO.Compression.CompressionLevel]::NoCompression)
			$entryStream = $entry.Open()
			try {
				$bytes = [Text.Encoding]::UTF8.GetBytes([string]$item.text)
				$entryStream.Write($bytes, 0, $bytes.Length)
			} finally { $entryStream.Dispose() }
		}
	} finally { $archive.Dispose() }
	try { return ,$memory.ToArray() } finally { $memory.Dispose() }
}

function Assert-SyntheticZipRejected {
	param([Parameter(Mandatory)][byte[]] $Bytes, [Parameter(Mandatory)][string] $Label)
	$rejected = $false
	try { $null = Read-ZipBytesRecords -Bytes $Bytes -Label $Label } catch { $rejected = $true }
	if (-not $rejected) { throw "Package auditor self-check failed to reject $Label." }
}

function Invoke-AuditGuardSelfTest {
	$validBytes = New-SyntheticZipBytes -Entries @([pscustomobject]@{ name = 'safe/member.txt'; text = 'ok' })
	$validRecords = Read-ZipBytesRecords -Bytes $validBytes -Label 'self-test valid archive'
	if ($validRecords.Count -ne 1 -or $validRecords[0].path -cne 'safe/member.txt' -or $validRecords[0].sha256 -cne '2689367b205c16ce32ed4200942b8b8b1e262dfc70d9bc9fbc77c49699a4f1df') {
		throw 'Package auditor self-check failed on a valid in-memory ZIP member.'
	}
	$traversal = New-SyntheticZipBytes -Entries @([pscustomobject]@{ name = '../escape.txt'; text = 'x' })
	$duplicate = New-SyntheticZipBytes -Entries @(
		[pscustomobject]@{ name = 'same.txt'; text = 'one' }
		[pscustomobject]@{ name = 'same.txt'; text = 'two' }
	)
	$caseCollision = New-SyntheticZipBytes -Entries @(
		[pscustomobject]@{ name = 'Folder/File.txt'; text = 'one' }
		[pscustomobject]@{ name = 'folder/file.txt'; text = 'two' }
	)
	Assert-SyntheticZipRejected -Bytes $traversal -Label 'traversal path'
	Assert-SyntheticZipRejected -Bytes $duplicate -Label 'duplicate member'
	Assert-SyntheticZipRejected -Bytes $caseCollision -Label 'case-colliding members'
	$componentNames = @($script:AllowedOuterNames | Where-Object { $_ -like "$($script:ComponentPrefix)-*.zip" })
	if ($componentNames.Count -ne 4) { throw 'Package auditor self-check found an unexpected outer component allowlist.' }
	$bareComponentArchive = New-SyntheticZipBytes -Entries @($componentNames | ForEach-Object { [pscustomobject]@{ name = $_; text = 'component' } })
	$bareComponentRecords = Read-ZipBytesRecords -Bytes $bareComponentArchive -Label 'self-test root-level component archives'
	Assert-ExactRecordPaths -Records $bareComponentRecords -Expected $componentNames -Label 'self-test root-level component archives'
	$nestedComponentArchive = New-SyntheticZipBytes -Entries @($componentNames | ForEach-Object { [pscustomobject]@{ name = "components/$_"; text = 'component' } })
	$nestedComponentRecords = Read-ZipBytesRecords -Bytes $nestedComponentArchive -Label 'self-test nested component archives'
	$nestedRejected = $false
	try { Assert-ExactRecordPaths -Records $nestedComponentRecords -Expected $componentNames -Label 'self-test nested component archives' } catch { $nestedRejected = $true }
	if (-not $nestedRejected) { throw 'Package auditor self-check failed to reject nested component paths.' }
	return [ordered]@{ validArchiveAccepted = $true; traversalRejected = $true; duplicateRejected = $true; caseCollisionRejected = $true; bareComponentNamesAccepted = $true; nestedComponentNamesRejected = $nestedRejected }
}

try {
	$script:ImplementationRoot = [IO.Path]::GetFullPath($ImplementationRoot).TrimEnd('\', '/')
	if (-not (Test-Path -LiteralPath $script:ImplementationRoot -PathType Container)) { throw 'ImplementationRoot must be an existing directory.' }
	$rootItem = Get-Item -LiteralPath $script:ImplementationRoot -Force
	if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'ImplementationRoot must not be a reparse point.' }
	$script:GuardSelfTest = Invoke-AuditGuardSelfTest

	$script:GeneratedInputsRootFullPath = if ([IO.Path]::IsPathRooted($GeneratedInputsRoot)) {
		[IO.Path]::GetFullPath($GeneratedInputsRoot)
	} else {
		[IO.Path]::GetFullPath((Join-Path (Get-Location).Path $GeneratedInputsRoot))
	}
	if (-not (Test-Path -LiteralPath $script:GeneratedInputsRootFullPath -PathType Container)) { throw 'GeneratedInputsRoot must be an existing directory.' }
	Assert-NoReparseChainToVolumeRoot -Path $script:GeneratedInputsRootFullPath
	Assert-NoReparseChainToVolumeRoot -Path $script:ImplementationRoot

	$allowlistRelative = $SourceAllowlistPath.Replace('\', '/')
	Assert-PlainRelativePath -Path $allowlistRelative -Label 'SourceAllowlistPath'
	$script:SourcePaths = @()
	$allowlistFullPath = Resolve-ImplementationPath -RelativePath $allowlistRelative -Label 'Public source allowlist'
	$allowlistText = Convert-StrictUtf8 -Bytes ([IO.File]::ReadAllBytes($allowlistFullPath)) -Label 'Public source allowlist'
	$allowlist = ConvertFrom-Json -InputObject $allowlistText -AsHashtable -Depth 8
	if ($allowlist.schemaVersion -ne 1 -or $allowlist.kind -cne 'explicit-public-source-allowlist' -or $allowlist.files -isnot [array]) {
		throw 'Public source allowlist must be schema 1 with an explicit files array.'
	}
	$allowlistSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
	foreach ($file in $allowlist.files) {
		if ($file -isnot [System.Collections.IDictionary] -or $file.path -isnot [string] -or [string]::IsNullOrWhiteSpace($file.path)) { throw 'Public source allowlist contains a malformed path record.' }
		$path = [string]$file.path
		Assert-PlainRelativePath -Path $path -Label 'Public source allowlist path'
		if ($path.StartsWith('evidence/', [StringComparison]::OrdinalIgnoreCase) -or $path.StartsWith('dist/', [StringComparison]::OrdinalIgnoreCase) -or $path.StartsWith('patches/', [StringComparison]::OrdinalIgnoreCase) -or $path -match '(?i)(^|/)(?:\.git|bin|obj|output|logs?|compatibility)(/|$)|\.(?:dll|pex|pdb|log|zip|7z)$') {
			throw 'Public source allowlist contains generated, historical, compatibility, or private material.'
		}
		if ($path.StartsWith('native/deps/', [StringComparison]::OrdinalIgnoreCase) -and $path -cne 'native/deps/PrismaUI_API.h') {
			throw 'Public source allowlist contains a vendored dependency tree.'
		}
		if ($path -in @(
			'server/tests/pcv/http_ingress.php'
			'server/tests/pcv/preprocessing_integration.php'
			'server/tests/pcv/preprocessing_worker.php'
			'server/tests/pcv/readiness.php'
			'server/tests/pcv/runtime_support.php'
			'server/tests/integration'
			'server/tests/runtime_core'
		)) { throw 'Public source allowlist contains an excluded legacy core-ingress test.' }
		if (-not $allowlistSet.Add($path)) { throw 'Public source allowlist contains duplicate or case-colliding paths.' }
		$script:SourcePaths += $path
	}
	$script:SourcePaths = @($script:SourcePaths | Sort-Object -Unique)
	if ($allowlistRelative -cnotin $script:SourcePaths) { throw 'Public source allowlist must include itself.' }
	if ($ReleaseGuidePath -cnotin $script:SourcePaths) { throw 'Public source allowlist must include the release guide.' }
	if ($script:SourcePaths.Count -lt 1) { throw 'Public source allowlist is empty.' }

	function Convert-GeneratedInputPath {
		param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
		if ([IO.Path]::IsPathRooted($Path) -or $Path.Contains(':') -or $Path.Contains('!')) { throw "$Label must be relative to GeneratedInputsRoot." }
		$normalized = $Path.Replace('\', '/')
		Assert-PlainRelativePath -Path $normalized -Label $Label
		return 'generated/' + $normalized
	}

	$nativeDllPath = Convert-GeneratedInputPath -Path $NativeDllInputPath -Label 'NativeDllInputPath'
	$papyrusMcmPath = Convert-GeneratedInputPath -Path $PapyrusMcmInputPath -Label 'PapyrusMcmInputPath'
	$papyrusNativePath = Convert-GeneratedInputPath -Path $PapyrusNativeInputPath -Label 'PapyrusNativeInputPath'
	$espPath = Convert-GeneratedInputPath -Path $EspInputPath -Label 'EspInputPath'
	$seqPath = Convert-GeneratedInputPath -Path $SeqInputPath -Label 'SeqInputPath'

	$script:GameSpecs = @(
		[pscustomobject]@{ Path = 'Data/PrismaUI/views/WorldOfDramaLlama/app.css'; Source = 'ui/app.css' }
		[pscustomobject]@{ Path = 'Data/PrismaUI/views/WorldOfDramaLlama/app.js'; Source = 'ui/app.js' }
		[pscustomobject]@{ Path = 'Data/PrismaUI/views/WorldOfDramaLlama/index.html'; Source = 'ui/index.html' }
		[pscustomobject]@{ Path = 'Data/Scripts/WorldOfDramaLlamaMCM.pex'; Source = $papyrusMcmPath }
		[pscustomobject]@{ Path = 'Data/Scripts/WorldOfDramaLlamaNative.pex'; Source = $papyrusNativePath }
		[pscustomobject]@{ Path = 'Data/SKSE/Plugins/WorldOfDramaLlama.dll'; Source = $nativeDllPath }
		[pscustomobject]@{ Path = 'Data/SKSE/Plugins/WorldOfDramaLlama.ini'; Source = 'packaging/companion-only/WorldOfDramaLlama.ini' }
	)
	$script:GameAssetSpecs = @(
		[pscustomobject]@{ Path = 'Data/WorldOfDramaLlama.esp'; Source = $espPath }
		[pscustomobject]@{ Path = 'Data/SEQ/WorldOfDramaLlama.seq'; Source = $seqPath }
	)
	$script:ServerSpecs = @(
		[pscustomobject]@{ Path = 'ext/questionable_decisions/api.php'; Source = 'server/api.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/runtime.php'; Source = 'server/runtime.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/auth.php'; Source = 'server/lib/auth.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/dispatcher.php'; Source = 'server/lib/dispatcher.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/journal.php'; Source = 'server/lib/journal.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/lib/protocol.php'; Source = 'server/lib/protocol.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/correspondence.php'; Source = 'server/adapters/correspondence.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/mp.php'; Source = 'server/adapters/mp.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/pcv.php'; Source = 'server/adapters/pcv.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/runtime_support.php'; Source = 'server/adapters/runtime_support.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/adapters/sas.php'; Source = 'server/adapters/sas.php' }
		[pscustomobject]@{ Path = 'ext/questionable_decisions/tools/provision.php'; Source = 'server/tools/provision.php' }
		[pscustomobject]@{ Path = 'ext/pcv_companion/companion.php'; Source = 'pcv-companion/companion.php' }
		[pscustomobject]@{ Path = 'ext/pcv_companion/globals.php'; Source = 'pcv-companion/globals.php' }
		[pscustomobject]@{ Path = 'ext/pcv_companion/preprocessing.php'; Source = 'pcv-companion/preprocessing.php' }
		[pscustomobject]@{ Path = 'ext/private_conversation/installation_guard.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/installation_guard.php' }
		[pscustomobject]@{ Path = 'ext/private_conversation/log.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/log.php' }
		[pscustomobject]@{ Path = 'ext/private_conversation/manifest.json'; Source = 'pcv-companion/private-conversation-v0.1.22/server/manifest.json' }
		[pscustomobject]@{ Path = 'ext/private_conversation/preprocessing.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/preprocessing.php' }
		[pscustomobject]@{ Path = 'ext/private_conversation/reflection.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/reflection.php' }
		[pscustomobject]@{ Path = 'ext/private_conversation/scope.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/scope.php' }
		[pscustomobject]@{ Path = 'ext/private_conversation/state.php'; Source = 'pcv-companion/private-conversation-v0.1.22/server/state.php' }
		[pscustomobject]@{ Path = 'docs/pcv-0.1.22-source-manifest.json'; Source = 'packaging/companion-only/pcv-0.1.22-source-manifest.json' }
		[pscustomobject]@{ Path = 'docs/PCV-PROVENANCE.md'; Source = 'packaging/companion-only/PCV-PROVENANCE.md' }
	)
	$script:NoticeSpecs = @(
		[pscustomobject]@{ Path = 'notices/CommonLibSSE-NG-3.7.0-LICENSE.txt'; Source = 'packaging/companion-only/notices/CommonLibSSE-NG-3.7.0-LICENSE.txt' }
		[pscustomobject]@{ Path = 'notices/fmt-10.2.1-LICENSE.txt'; Source = 'packaging/companion-only/notices/fmt-10.2.1-LICENSE.txt' }
		[pscustomobject]@{ Path = 'notices/Department-nlohmann-json-3.11.3-MIT.txt'; Source = 'packaging/companion-only/notices/Department-nlohmann-json-3.11.3-MIT.txt' }
		[pscustomobject]@{ Path = 'notices/rapidcsv-8.84-LICENSE.txt'; Source = 'packaging/companion-only/notices/rapidcsv-8.84-LICENSE.txt' }
		[pscustomobject]@{ Path = 'notices/spdlog-1.13.0-LICENSE.txt'; Source = 'packaging/companion-only/notices/spdlog-1.13.0-LICENSE.txt' }
		[pscustomobject]@{ Path = 'notices/PrismaUI-LICENSE.md'; Source = 'packaging/companion-only/notices/PrismaUI-LICENSE.md' }
		[pscustomobject]@{ Path = 'notices/CHIM-Plugin-MIT-LICENSE.txt'; Source = 'packaging/companion-only/notices/CHIM-Plugin-MIT-LICENSE.txt' }
	)
	if ($script:GameSpecs.Count + $script:GameAssetSpecs.Count -ne 9 -or $script:ServerSpecs.Count -ne 24 -or $script:NoticeSpecs.Count -ne 7) {
		throw 'Auditor package definitions differ from the approved 9/24/7 inventory.'
	}
	$script:ExpectedInputPaths = @(
		@($script:GameSpecs | ForEach-Object { $_.Source })
		@($script:GameAssetSpecs | ForEach-Object { $_.Source })
		@($script:ServerSpecs | ForEach-Object { $_.Source })
		@($script:NoticeSpecs | ForEach-Object { $_.Source })
		@($script:SourcePaths)
	) | Sort-Object -Unique

	$outerPath = if ([IO.Path]::IsPathRooted($OuterZipPath)) { [IO.Path]::GetFullPath($OuterZipPath) } else { [IO.Path]::GetFullPath((Join-Path $script:ImplementationRoot $OuterZipPath)) }
	Assert-NoReparseChain -Path $outerPath -Boundary $script:ImplementationRoot
	if (-not (Test-Path -LiteralPath $outerPath -PathType Leaf)) { throw 'Outer package ZIP is missing.' }

	if (-not [string]::IsNullOrWhiteSpace($AuditOutputPath)) {
		$script:AuditOutputFullPath = [IO.Path]::GetFullPath($AuditOutputPath)
		$outputParent = [IO.Path]::GetDirectoryName($script:AuditOutputFullPath)
		if ([IO.Path]::GetRelativePath($script:ImplementationRoot, $script:AuditOutputFullPath) -notmatch '^(?:\.\.[\\/]|[A-Za-z]:)') {
			throw 'AuditOutputPath must be outside the implementation root.'
		}
		if (-not (Test-Path -LiteralPath $outputParent -PathType Container)) { throw 'AuditOutputPath parent must already exist.' }
		Assert-NoReparseChainToVolumeRoot -Path $outputParent
		if (Test-Path -LiteralPath $script:AuditOutputFullPath) { throw 'AuditOutputPath must name a new file.' }
	}

	$outerDigest = Get-FileDigest -Path $outerPath -Label 'outer ZIP'
	$freezeRelative = Get-CanonicalFreezeRelativePath -Path $FreezePath
	if ($freezeRelative -ceq 'evidence/companion-only/final-input-freeze.json' -or $freezeRelative -notmatch '^evidence/companion-only/[^/]+$') {
		throw 'FreezePath must identify a new root-authorized freeze under the companion-only evidence root.'
	}
	$freezeFullPath = Join-Path $script:ImplementationRoot ($freezeRelative -replace '/', '\')
	$freezeFileDigest = Get-FileDigest -Path $freezeFullPath -Label 'root freeze'
	$freezeText = Convert-StrictUtf8 -Bytes ([IO.File]::ReadAllBytes($freezeFullPath)) -Label 'Root freeze'
	$script:Freeze = ConvertFrom-Json -InputObject $freezeText -AsHashtable -Depth 64
	if ($script:Freeze.schemaVersion -ne 1 -or $script:Freeze.status -cne 'ready' -or $script:Freeze.kind -cne 'department-pre-alpha' -or $script:Freeze.files -isnot [System.Collections.IDictionary] -or $script:Freeze.gates -isnot [System.Collections.IDictionary]) {
		throw 'Root freeze must be schema 1, ready, department-pre-alpha, with files and gates maps.'
	}
	$expectedGateNames = @('privateRoute', 'critique', 'ui', 'native', 'runtimeRelease')
	$actualGateNames = @($script:Freeze.gates.Keys | ForEach-Object { [string]$_ } | Sort-Object)
	if ($actualGateNames.Count -ne $expectedGateNames.Count -or [string]::Join(',', $actualGateNames) -cne [string]::Join(',', @($expectedGateNames | Sort-Object))) {
		throw 'Root freeze has an unexpected gate receipt set.'
	}
	foreach ($gateName in $expectedGateNames) {
		$gate = Get-MapValueExact -Map $script:Freeze.gates -Key $gateName -Label 'Root freeze gates'
		if ($gate -isnot [System.Collections.IDictionary] -or $gate.receipt -isnot [string] -or $gate.sha256 -cnotmatch '\A[0-9a-f]{64}\z') { throw 'Root freeze gate receipt pin is malformed.' }
		if (-not $gate.receipt.StartsWith('evidence/companion-only/', [StringComparison]::Ordinal) -or $gate.receipt.Contains('!') -or $gate.receipt -match '(^|/)\.\.(/|$)') {
			throw 'Root freeze gate receipt must be a project-relative companion-only evidence path.'
		}
		$receiptPath = Resolve-ImplementationPath -RelativePath $gate.receipt -Label 'Gate receipt'
		$receiptDigest = Get-FileDigest -Path $receiptPath -Label 'gate receipt'
		if ($receiptDigest.sha256 -cne $gate.sha256) { throw 'Root freeze gate receipt hash differs from its receipt file.' }
	}

	$freezeFileNames = @($script:Freeze.files.Keys | ForEach-Object { [string]$_ })
	$freezeFileSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
	$expectedFreezeFileSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
	foreach ($path in $freezeFileNames) { $null = $freezeFileSet.Add($path) }
	foreach ($path in $script:ExpectedInputPaths) { $null = $expectedFreezeFileSet.Add($path) }
	if ($freezeFileNames.Count -ne $script:ExpectedInputPaths.Count -or -not $freezeFileSet.SetEquals($expectedFreezeFileSet)) {
		throw 'Root freeze input allowlist differs from the source, package, and generated input set.'
	}
	$script:InputRecords = @{}
	foreach ($path in $script:ExpectedInputPaths) { $script:InputRecords[$path] = Get-FrozenInput -RelativePath $path }

	$acceptedAssets = @($script:Freeze.acceptedGameAssets)
	if ($acceptedAssets.Count -ne 2) { throw 'Root freeze must pin exactly two fresh game assets.' }
	foreach ($spec in $script:GameAssetSpecs) {
		$matches = @($acceptedAssets | Where-Object { $_.target -ceq $spec.Path })
		$pin = $script:InputRecords[$spec.Source]
		if ($matches.Count -ne 1 -or $matches[0].source -cne $spec.Source -or $matches[0].sha256 -cne $pin.sha256 -or [long]$matches[0].bytes -ne $pin.bytes) {
			throw 'Root freeze accepted ESP/SEQ pins differ from current generated files.'
		}
	}

	$pcvManifestInput = $script:InputRecords['packaging/companion-only/pcv-0.1.22-source-manifest.json']
	$pcvManifest = ConvertFrom-Json -InputObject (Convert-StrictUtf8 -Bytes ([IO.File]::ReadAllBytes($pcvManifestInput.fullPath)) -Label 'PCV source manifest') -AsHashtable -Depth 16
	if ($pcvManifest.schemaVersion -ne 1 -or $pcvManifest.installDirectory -cne 'ext/private_conversation' -or $pcvManifest.files -isnot [array] -or $pcvManifest.files.Count -ne 7) {
		throw 'PCV source manifest does not describe the seven-file companion input.'
	}
	$pcvFileMap = @{}
	foreach ($file in $pcvManifest.files) {
		if ($file.path -isnot [string] -or $file.path -notmatch '\A[A-Za-z0-9_.-]+\z' -or $file.postimageSha256 -cnotmatch '\A[0-9a-f]{64}\z' -or $pcvFileMap.ContainsKey($file.path)) {
			throw 'PCV source manifest contains malformed or duplicate file entries.'
		}
		$pcvFileMap[$file.path] = $file.postimageSha256
	}
	foreach ($spec in @($script:ServerSpecs | Where-Object { $_.Path.StartsWith('ext/private_conversation/', [StringComparison]::Ordinal) })) {
		$name = [IO.Path]::GetFileName($spec.Source)
		if (-not $pcvFileMap.ContainsKey($name) -or $pcvFileMap[$name] -cne $script:InputRecords[$spec.Source].sha256) {
			throw 'PCV source manifest does not match the seven exact staged server files.'
		}
		$pcvFileMap.Remove($name)
	}
	if ($pcvFileMap.Count -ne 0) { throw 'PCV source manifest includes files outside the seven-file install allowlist.' }

	$outerStream = [IO.File]::Open($outerPath, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
	try { $outerRecords = Read-ZipArchiveRecords -Stream $outerStream -Label 'outer package ZIP' } finally { $outerStream.Dispose() }
	Assert-ExactRecordPaths -Records $outerRecords -Expected $script:AllowedOuterNames -Label 'Outer package ZIP'
	Assert-NoForbiddenArtifactPaths -Names @($outerRecords | ForEach-Object { [string]$_.path }) -Label 'Outer package ZIP'

	$guideRecord = Get-ExactRecord -Records $outerRecords -Path 'PRE-ALPHA-RELEASE.md' -Label 'Outer package ZIP'
	Assert-RecordMatchesInput -Record $guideRecord -CanonicalInput $script:InputRecords[$ReleaseGuidePath] -Label 'Outer release guide'
	$manifestRecord = Get-ExactRecord -Records $outerRecords -Path 'package-manifest.json' -Label 'Outer package ZIP'
	$manifestText = Convert-StrictUtf8 -Bytes $manifestRecord.content -Label 'package-manifest.json'
	$packageManifest = ConvertFrom-Json -InputObject $manifestText -AsHashtable -Depth 64
	if ($packageManifest.schemaVersion -ne 1 -or $packageManifest.status -cne 'pre-alpha-static-candidate' -or $packageManifest.kind -cne 'department-pre-alpha') {
		throw 'Package manifest does not identify a department pre-alpha static candidate.'
	}
	if ($packageManifest.freeze -isnot [System.Collections.IDictionary] -or @($packageManifest.freeze.Keys).Count -ne 1 -or $packageManifest.freeze.sha256 -cne $freezeFileDigest.sha256) {
		throw 'Package manifest is not bound to the supplied root freeze.'
	}

	$manifestInputNames = @($packageManifest.inputs.Keys | ForEach-Object { [string]$_ })
	$manifestInputSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
	$expectedInputSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
	foreach ($path in $manifestInputNames) { $null = $manifestInputSet.Add($path) }
	foreach ($path in $script:ExpectedInputPaths) { $null = $expectedInputSet.Add($path) }
	if ($manifestInputNames.Count -ne $script:ExpectedInputPaths.Count -or -not $manifestInputSet.SetEquals($expectedInputSet)) { throw 'Package manifest input allowlist differs from the exact frozen inputs.' }
	foreach ($path in $script:ExpectedInputPaths) {
		$manifestPin = Get-MapValueExact -Map $packageManifest.inputs -Key $path -Label 'Package manifest inputs'
		$canonicalInput = $script:InputRecords[$path]
		if ($manifestPin -isnot [System.Collections.IDictionary] -or [long]$manifestPin.bytes -ne $canonicalInput.bytes -or $manifestPin.sha256 -cne $canonicalInput.sha256) {
			throw 'Package manifest input pin differs from root-frozen bytes.'
		}
	}

	$packageGateNames = @($packageManifest.gates.Keys | ForEach-Object { [string]$_ } | Sort-Object)
	if ($packageGateNames.Count -ne $expectedGateNames.Count -or [string]::Join(',', $packageGateNames) -cne [string]::Join(',', @($expectedGateNames | Sort-Object))) {
		throw 'Package manifest gate set differs from the root freeze.'
	}
	foreach ($gateName in $expectedGateNames) {
		$manifestGate = Get-MapValueExact -Map $packageManifest.gates -Key $gateName -Label 'Package manifest gates'
		$frozenGate = Get-MapValueExact -Map $script:Freeze.gates -Key $gateName -Label 'Root freeze gates'
		if ($manifestGate -isnot [System.Collections.IDictionary] -or @($manifestGate.Keys).Count -ne 1 -or $manifestGate.sha256 -cne $frozenGate.sha256) {
			throw 'Package manifest gate hash differs or contains a private receipt reference.'
		}
	}

	$componentPrefix = 'WorldOfDramaLlama-department-pre-alpha'
	$componentDefs = @(
		[pscustomobject]@{ Name = "$componentPrefix-game-data.zip"; OuterName = "$componentPrefix-game-data.zip"; Specs = $script:GameSpecs; Kind = 'game' }
		[pscustomobject]@{ Name = "$componentPrefix-server.zip"; OuterName = "$componentPrefix-server.zip"; Specs = $script:ServerSpecs; Kind = 'server' }
		[pscustomobject]@{ Name = "$componentPrefix-source.zip"; OuterName = "$componentPrefix-source.zip"; Specs = $null; Kind = 'source' }
		[pscustomobject]@{ Name = "$componentPrefix-notices.zip"; OuterName = "$componentPrefix-notices.zip"; Specs = $script:NoticeSpecs; Kind = 'notices' }
	)
	$manifestComponents = @($packageManifest.components)
	if ($manifestComponents.Count -ne 4) { throw 'Package manifest must describe exactly four nested ZIP components.' }
	$componentReports = [Collections.Generic.List[object]]::new()
	foreach ($componentDef in $componentDefs) {
		$outerRecord = Get-ExactRecord -Records $outerRecords -Path $componentDef.OuterName -Label 'Outer package ZIP'
		$innerRecords = Read-ZipBytesRecords -Bytes $outerRecord.content -Label $componentDef.Name
		Assert-NoForbiddenArtifactPaths -Names @($innerRecords | ForEach-Object { [string]$_.path }) -Label $componentDef.Name
		$componentManifestMatches = @($manifestComponents | Where-Object { $_.name -ceq $componentDef.Name })
		if ($componentManifestMatches.Count -ne 1) { throw 'Package manifest has missing or duplicate component records.' }
		$manifestComponent = $componentManifestMatches[0]
		if ([long]$manifestComponent.bytes -ne $outerRecord.bytes -or $manifestComponent.sha256 -cne $outerRecord.sha256 -or [int]$manifestComponent.memberCount -ne $innerRecords.Count) {
			throw 'Package manifest component digest or member count differs from ZIP bytes.'
		}
		Assert-ManifestMemberRecords -ManifestMembers @($manifestComponent.members) -ActualMembers $innerRecords -Label "$($componentDef.Name) manifest"

		if ($componentDef.Kind -eq 'game') {
			$expectedNames = @($script:GameSpecs.Path) + @($script:GameAssetSpecs.Path)
		} elseif ($componentDef.Kind -eq 'server') {
			$expectedNames = @($script:ServerSpecs.Path)
		} elseif ($componentDef.Kind -eq 'notices') {
			$expectedNames = @($script:NoticeSpecs.Path)
		} else {
			$expectedNames = @($script:SourcePaths) + @('source-manifest.json')
		}
		Assert-ExactRecordPaths -Records $innerRecords -Expected $expectedNames -Label $componentDef.Name

		if ($componentDef.Kind -in @('game', 'server', 'notices')) {
			foreach ($spec in $componentDef.Specs) {
				$member = Get-ExactRecord -Records $innerRecords -Path $spec.Path -Label $componentDef.Name
				Assert-RecordMatchesInput -Record $member -CanonicalInput $script:InputRecords[$spec.Source] -Label "$($componentDef.Name) member $($spec.Path)"
			}
			if ($componentDef.Kind -eq 'game') {
				$ini = Get-ExactRecord -Records $innerRecords -Path 'Data/SKSE/Plugins/WorldOfDramaLlama.ini' -Label $componentDef.Name
				Assert-BlankRuntimeIni -Bytes $ini.content -Label 'Packaged WorldOfDramaLlama.ini'
				foreach ($spec in $script:GameAssetSpecs) {
					$member = Get-ExactRecord -Records $innerRecords -Path $spec.Path -Label $componentDef.Name
					Assert-RecordMatchesInput -Record $member -CanonicalInput $script:InputRecords[$spec.Source] -Label 'Fresh generated game asset'
				}
			}
		} else {
			$sourceManifestRecord = Get-ExactRecord -Records $innerRecords -Path 'source-manifest.json' -Label $componentDef.Name
			$sourceManifest = ConvertFrom-Json -InputObject (Convert-StrictUtf8 -Bytes $sourceManifestRecord.content -Label 'source-manifest.json') -AsHashtable -Depth 32
			if ($sourceManifest.schemaVersion -ne 1 -or $sourceManifest.kind -cne 'department-pre-alpha-source-snapshot') { throw 'Source manifest has an unexpected schema or kind.' }
			$sourceManifestFiles = @($sourceManifest.files)
			if ($sourceManifestFiles.Count -ne $script:SourcePaths.Count) { throw 'Source manifest count differs from the explicit public source allowlist.' }
			$sourceManifestSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
			foreach ($sourceItem in $sourceManifestFiles) {
				if ($sourceItem -isnot [System.Collections.IDictionary] -or $sourceItem.path -isnot [string] -or $sourceItem.sha256 -cnotmatch '\A[0-9a-f]{64}\z' -or ($sourceItem.bytes -isnot [long] -and $sourceItem.bytes -isnot [int])) {
					throw 'Source manifest contains a malformed file record.'
				}
				$relative = [string]$sourceItem.path
				Assert-PlainRelativePath -Path $relative -Label 'Source manifest path'
				if (-not $sourceManifestSet.Add($relative) -or $relative -cnotin $script:SourcePaths) { throw 'Source manifest path set differs from the explicit public source allowlist.' }
				$member = Get-ExactRecord -Records $innerRecords -Path $relative -Label $componentDef.Name
				$canonicalInput = $script:InputRecords[$relative]
				if ([long]$sourceItem.bytes -ne $canonicalInput.bytes -or $sourceItem.sha256 -cne $canonicalInput.sha256 -or $member.bytes -ne $canonicalInput.bytes -or $member.sha256 -cne $canonicalInput.sha256) {
					throw 'Source ZIP member or manifest differs from root-frozen canonical bytes.'
				}
			}
		}

		$componentReports.Add([pscustomobject]@{
			name = $componentDef.Name
			bytes = $outerRecord.bytes
			sha256 = $outerRecord.sha256
			memberCount = $innerRecords.Count
			members = @($innerRecords | Sort-Object path | ForEach-Object { [pscustomobject]@{ path = $_.path; bytes = $_.bytes; sha256 = $_.sha256 } })
		})
	}

	$pcvSpecs = @($script:ServerSpecs | Where-Object { $_.Path.StartsWith('ext/private_conversation/', [StringComparison]::Ordinal) })
	$pcvFileNames = @($pcvSpecs | ForEach-Object { [IO.Path]::GetFileName($_.Path) } | Sort-Object)
	$expectedPcvFileNames = @('installation_guard.php', 'log.php', 'manifest.json', 'preprocessing.php', 'reflection.php', 'scope.php', 'state.php' | Sort-Object)
	if ($pcvSpecs.Count -ne 7 -or @($pcvSpecs | Where-Object { ($_.Path -replace '/[^/]+$', '') -cne 'ext/private_conversation' }).Count -ne 0 -or ($pcvFileNames -join ',') -cne ($expectedPcvFileNames -join ',')) {
		throw 'Server component does not install exactly seven flat PCV files.'
	}

	$outerEntryReports = @($outerRecords | Sort-Object path | ForEach-Object { [pscustomobject]@{ path = $_.path; bytes = $_.bytes; sha256 = $_.sha256 } })
	$result = [ordered]@{
		schemaVersion = 1
		status = 'pass'
		kind = 'department-pre-alpha-package-audit'
		auditedAtUtc = [DateTimeOffset]::UtcNow.ToString('o')
		freeze = [ordered]@{ sha256 = $freezeFileDigest.sha256 }
		outerArchive = [ordered]@{ name = [IO.Path]::GetFileName($outerPath); bytes = $outerDigest.bytes; sha256 = $outerDigest.sha256; entryCount = $outerRecords.Count; entries = $outerEntryReports }
		components = @($componentReports.ToArray())
		counts = [ordered]@{ game = $script:GameSpecs.Count + $script:GameAssetSpecs.Count; server = $script:ServerSpecs.Count; notices = $script:NoticeSpecs.Count; sourceFiles = $script:SourcePaths.Count; sourceWithManifest = $script:SourcePaths.Count + 1; rootInputs = $script:ExpectedInputPaths.Count; flatPcvFiles = $pcvSpecs.Count }
		checks = [ordered]@{
			inMemoryZipGuardSelfTest = $script:GuardSelfTest
			freezeAndCanonicalInputs = $true
			exactFiveGateSet = $true
			explicitSourceAllowlist = $true
			outerAndNestedAllowLists = $true
			packageManifestCrossChecked = $true
			freshGeneratedAssets = $true
			blankIni = $true
			flatSevenFilePcv = $true
			forbiddenArtifactsAbsent = $true
			noPrivatePathsInReceipt = $true
		}
		limits = @('Audit matched package bytes to the supplied root freeze and current canonical source files.', 'No installed game files were read or modified; this audit does not prove Skyrim runtime behavior, live CHIM delivery, model processing, or audio.')
	}
	Write-AuditJson -Value $result -CreateOutput
} catch {
	$errorResult = [ordered]@{
		schemaVersion = 1
		status = 'fail'
		kind = 'department-pre-alpha-package-audit'
		auditedAtUtc = [DateTimeOffset]::UtcNow.ToString('o')
		error = 'Audit failed. The receipt intentionally omits filesystem paths.'
	}
	try { Write-AuditJson -Value $errorResult -CreateOutput } catch { [Console]::Out.WriteLine((ConvertTo-Json -InputObject $errorResult -Depth 8 -Compress)) }
	exit 1
}

exit 0
