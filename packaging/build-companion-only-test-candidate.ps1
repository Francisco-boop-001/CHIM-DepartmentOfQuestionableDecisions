[CmdletBinding()]
param(
	[switch] $CreateArchive,
	[switch] $TestFreezeTimestamp,
	[switch] $PreAlpha,
	[string] $FreezePath = 'evidence/companion-only/final-input-freeze.json',
	[string] $CandidateOutputRoot,
	[string] $GeneratedInputsRoot,
	[string] $NativeDllInputPath,
	[string] $PapyrusMcmInputPath,
	[string] $PapyrusNativeInputPath,
	[string] $EspInputPath,
	[string] $SeqInputPath,
	[string] $SourceAllowlistPath,
	[string] $ReleaseGuidePath = 'packaging/COMPANION-ONLY-TEST-PACKAGE.md'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$freezeRelative = $FreezePath.Replace([char]92, '/')
$freezeFullPath = Join-Path $repoRoot ($freezeRelative -replace '/', '\')
if ($PreAlpha) {
	if ($freezeRelative -ceq 'evidence/companion-only/final-input-freeze.json' -or [IO.Path]::IsPathRooted($FreezePath) -or $freezeRelative.Contains('!') -or $freezeRelative -match '(^|[/\\])\.\.([/\\]|$)') {
		throw 'PRE-ALPHA mode requires an explicit new implementation-relative freeze path.'
	}
	if ([string]::IsNullOrWhiteSpace($CandidateOutputRoot) -or [IO.Path]::IsPathRooted($CandidateOutputRoot) -or $CandidateOutputRoot.Contains('!') -or $CandidateOutputRoot -match '(^|[/\\])\.\.([/\\]|$)') {
		throw 'PRE-ALPHA mode requires an explicit candidate output directory inside the implementation root.'
	}
} elseif ([string]::IsNullOrWhiteSpace($CandidateOutputRoot)) {
	$CandidateOutputRoot = 'dist/companion-only-candidates'
}
$gameZipRelative = 'dist/WorldOfDramaLlama-test-candidate-final-20261020T070000Z-r4/components/WorldOfDramaLlama-game-data.zip'
$gameZipSha256 = '3c44d23b140d8521a7be0f52c656f8e9b5a2d02f3cb9ad5cb761d711743471e1'
$gameZipMemberSpecs = @(
	[pscustomobject]@{ Source = "$gameZipRelative!Data/WorldOfDramaLlama.esp"; Target = 'Data/WorldOfDramaLlama.esp'; Bytes = 185; Sha256 = 'b4097ba98c3cd5b5227e89812b46ca2f6e2583a1953b6c25c6a4ceab8dcf0dbe' }
	[pscustomobject]@{ Source = "$gameZipRelative!Data/SEQ/WorldOfDramaLlama.seq"; Target = 'Data/SEQ/WorldOfDramaLlama.seq'; Bytes = 4; Sha256 = 'cbfd218787df784c39fbc4f4fad92a8f28e2a880430c210b6d0d8dc133c07e66' }
)
$gameSpecs = @(
	[pscustomobject]@{ Source = 'ui/app.css'; Target = 'Data/PrismaUI/views/WorldOfDramaLlama/app.css' }
	[pscustomobject]@{ Source = 'ui/app.js'; Target = 'Data/PrismaUI/views/WorldOfDramaLlama/app.js' }
	[pscustomobject]@{ Source = 'ui/index.html'; Target = 'Data/PrismaUI/views/WorldOfDramaLlama/index.html' }
	[pscustomobject]@{ Source = 'native/papyrus/output/WorldOfDramaLlamaMCM.pex'; Target = 'Data/Scripts/WorldOfDramaLlamaMCM.pex' }
	[pscustomobject]@{ Source = 'native/papyrus/output/WorldOfDramaLlamaNative.pex'; Target = 'Data/Scripts/WorldOfDramaLlamaNative.pex' }
	[pscustomobject]@{ Source = 'evidence/companion-only/native/20261020T-native-proof/dll-output-catalog-contract/WorldOfDramaLlama.dll'; Target = 'Data/SKSE/Plugins/WorldOfDramaLlama.dll' }
	[pscustomobject]@{ Source = 'packaging/companion-only/WorldOfDramaLlama.ini'; Target = 'Data/SKSE/Plugins/WorldOfDramaLlama.ini' }
)
$serverSpecs = @(
	[pscustomobject]@{ Source = 'server/api.php'; Target = 'ext/questionable_decisions/api.php' }
	[pscustomobject]@{ Source = 'server/runtime.php'; Target = 'ext/questionable_decisions/runtime.php' }
	[pscustomobject]@{ Source = 'server/lib/auth.php'; Target = 'ext/questionable_decisions/lib/auth.php' }
	[pscustomobject]@{ Source = 'server/lib/dispatcher.php'; Target = 'ext/questionable_decisions/lib/dispatcher.php' }
	[pscustomobject]@{ Source = 'server/lib/journal.php'; Target = 'ext/questionable_decisions/lib/journal.php' }
	[pscustomobject]@{ Source = 'server/lib/protocol.php'; Target = 'ext/questionable_decisions/lib/protocol.php' }
	[pscustomobject]@{ Source = 'server/adapters/correspondence.php'; Target = 'ext/questionable_decisions/adapters/correspondence.php' }
	[pscustomobject]@{ Source = 'server/adapters/mp.php'; Target = 'ext/questionable_decisions/adapters/mp.php' }
	[pscustomobject]@{ Source = 'server/adapters/pcv.php'; Target = 'ext/questionable_decisions/adapters/pcv.php' }
	[pscustomobject]@{ Source = 'server/adapters/runtime_support.php'; Target = 'ext/questionable_decisions/adapters/runtime_support.php' }
	[pscustomobject]@{ Source = 'server/adapters/sas.php'; Target = 'ext/questionable_decisions/adapters/sas.php' }
	[pscustomobject]@{ Source = 'server/tools/provision.php'; Target = 'ext/questionable_decisions/tools/provision.php' }
	[pscustomobject]@{ Source = 'pcv-companion/companion.php'; Target = 'ext/pcv_companion/companion.php' }
	[pscustomobject]@{ Source = 'pcv-companion/globals.php'; Target = 'ext/pcv_companion/globals.php' }
	[pscustomobject]@{ Source = 'pcv-companion/preprocessing.php'; Target = 'ext/pcv_companion/preprocessing.php' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/installation_guard.php'; Target = 'ext/private_conversation/installation_guard.php' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/log.php'; Target = 'ext/private_conversation/log.php' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/manifest.json'; Target = 'ext/private_conversation/manifest.json' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/preprocessing.php'; Target = 'ext/private_conversation/preprocessing.php' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/reflection.php'; Target = 'ext/private_conversation/reflection.php' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/scope.php'; Target = 'ext/private_conversation/scope.php' }
	[pscustomobject]@{ Source = 'pcv-companion/private-conversation-v0.1.22/server/state.php'; Target = 'ext/private_conversation/state.php' }
	[pscustomobject]@{ Source = 'evidence/companion-only/server/pcv-0.1.22-source-manifest.json'; Target = 'docs/pcv-0.1.22-source-manifest.json' }
	[pscustomobject]@{ Source = 'packaging/companion-only/PCV-PROVENANCE.md'; Target = 'docs/PCV-PROVENANCE.md' }
)
$noticeSpecs = @(
	[pscustomobject]@{ Source = 'packaging/companion-only/notices/CommonLibSSE-NG-3.7.0-LICENSE.txt'; Target = 'notices/CommonLibSSE-NG-3.7.0-LICENSE.txt' }
	[pscustomobject]@{ Source = 'packaging/companion-only/notices/fmt-10.2.1-LICENSE.txt'; Target = 'notices/fmt-10.2.1-LICENSE.txt' }
	[pscustomobject]@{ Source = 'packaging/companion-only/notices/Department-nlohmann-json-3.11.3-MIT.txt'; Target = 'notices/Department-nlohmann-json-3.11.3-MIT.txt' }
	[pscustomobject]@{ Source = 'packaging/companion-only/notices/rapidcsv-8.84-LICENSE.txt'; Target = 'notices/rapidcsv-8.84-LICENSE.txt' }
	[pscustomobject]@{ Source = 'packaging/companion-only/notices/spdlog-1.13.0-LICENSE.txt'; Target = 'notices/spdlog-1.13.0-LICENSE.txt' }
	[pscustomobject]@{ Source = 'packaging/companion-only/notices/PrismaUI-LICENSE.md'; Target = 'notices/PrismaUI-LICENSE.md' }
)
$freshGameAssetSpecs = @()
$script:generatedInputsRootFullPath = $null
if ($PreAlpha) {
	$noticeSpecs += [pscustomobject]@{ Source = 'packaging/companion-only/notices/CHIM-Plugin-MIT-LICENSE.txt'; Target = 'notices/CHIM-Plugin-MIT-LICENSE.txt' }
	$serverSpecs = @($serverSpecs | ForEach-Object {
		$source = if ($_.Source -ceq 'evidence/companion-only/server/pcv-0.1.22-source-manifest.json') {
			'packaging/companion-only/pcv-0.1.22-source-manifest.json'
		} else { $_.Source }
		[pscustomobject]@{ Source = $source; Target = $_.Target }
	})
	$requiredFreshArguments = @{
		GeneratedInputsRoot = $GeneratedInputsRoot
		NativeDllInputPath = $NativeDllInputPath
		PapyrusMcmInputPath = $PapyrusMcmInputPath
		PapyrusNativeInputPath = $PapyrusNativeInputPath
		EspInputPath = $EspInputPath
		SeqInputPath = $SeqInputPath
		SourceAllowlistPath = $SourceAllowlistPath
	}
	foreach ($entry in $requiredFreshArguments.GetEnumerator()) {
		if ([string]::IsNullOrWhiteSpace([string]$entry.Value)) { throw "PRE-ALPHA mode requires -$($entry.Key)." }
	}
	if ([IO.Path]::IsPathRooted($GeneratedInputsRoot)) {
		$script:generatedInputsRootFullPath = [IO.Path]::GetFullPath($GeneratedInputsRoot)
	} else {
		$script:generatedInputsRootFullPath = [IO.Path]::GetFullPath((Join-Path (Get-Location).Path $GeneratedInputsRoot))
	}
	if (-not (Test-Path -LiteralPath $script:generatedInputsRootFullPath -PathType Container)) { throw 'GeneratedInputsRoot must be an existing directory.' }
	$generatedRootItem = Get-Item -LiteralPath $script:generatedInputsRootFullPath -Force
	if (($generatedRootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'GeneratedInputsRoot cannot be a reparse point.' }
	foreach ($inputPath in @($NativeDllInputPath, $PapyrusMcmInputPath, $PapyrusNativeInputPath, $EspInputPath, $SeqInputPath)) {
		if ([IO.Path]::IsPathRooted($inputPath) -or $inputPath.Contains(':') -or $inputPath.Contains('!') -or $inputPath -match '(^|[/\\])\.\.([/\\]|$)') {
			throw "PRE-ALPHA generated input must be relative to GeneratedInputsRoot: $inputPath"
		}
	}
	$NativeDllInputPath = 'generated/' + $NativeDllInputPath.Replace([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar).Replace([IO.Path]::AltDirectorySeparatorChar, '/')
	$PapyrusMcmInputPath = 'generated/' + $PapyrusMcmInputPath.Replace([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar).Replace([IO.Path]::AltDirectorySeparatorChar, '/')
	$PapyrusNativeInputPath = 'generated/' + $PapyrusNativeInputPath.Replace([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar).Replace([IO.Path]::AltDirectorySeparatorChar, '/')
	$EspInputPath = 'generated/' + $EspInputPath.Replace([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar).Replace([IO.Path]::AltDirectorySeparatorChar, '/')
	$SeqInputPath = 'generated/' + $SeqInputPath.Replace([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar).Replace([IO.Path]::AltDirectorySeparatorChar, '/')
	$gameSpecs = @($gameSpecs | ForEach-Object {
		$gameSpec = $_
		$source = switch ($gameSpec.Target) {
			'Data/Scripts/WorldOfDramaLlamaMCM.pex' { $PapyrusMcmInputPath; break }
			'Data/Scripts/WorldOfDramaLlamaNative.pex' { $PapyrusNativeInputPath; break }
			'Data/SKSE/Plugins/WorldOfDramaLlama.dll' { $NativeDllInputPath; break }
			default { $gameSpec.Source }
		}
		[pscustomobject]@{ Source = $source; Target = $gameSpec.Target }
	})
	$gameZipRelative = $null
	$gameZipSha256 = $null
	$gameZipMemberSpecs = @()
	$freshGameAssetSpecs = @(
		[pscustomobject]@{ Source = $EspInputPath; Target = 'Data/WorldOfDramaLlama.esp' }
		[pscustomobject]@{ Source = $SeqInputPath; Target = 'Data/SEQ/WorldOfDramaLlama.seq' }
	)
	if ([IO.Path]::IsPathRooted($SourceAllowlistPath) -or $SourceAllowlistPath.Contains('!') -or $SourceAllowlistPath -match '(^|[/\\])\.\.([/\\]|$)') {
		throw 'SourceAllowlistPath must be a plain implementation-relative file path.'
	}
	$allowlistFullPath = [IO.Path]::GetFullPath((Join-Path $repoRoot $SourceAllowlistPath))
	$implementationPrefix = $repoRoot.TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
	if (-not $allowlistFullPath.StartsWith($implementationPrefix, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $allowlistFullPath -PathType Leaf)) {
		throw 'SourceAllowlistPath must resolve to an existing file inside the implementation root.'
	}
	$allowlist = Get-Content -LiteralPath $allowlistFullPath -Raw | ConvertFrom-Json -AsHashtable -Depth 8
	if ($allowlist.schemaVersion -ne 1 -or $allowlist.kind -cne 'explicit-public-source-allowlist' -or $allowlist.files -isnot [array]) {
		throw 'Public source allowlist must be schema 1 with an explicit files array.'
	}
	$sourceRelativePaths = @()
	$allowlistSet = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
	foreach ($file in $allowlist.files) {
		if ($file -isnot [System.Collections.IDictionary] -or $file.path -isnot [string] -or [string]::IsNullOrWhiteSpace($file.path)) { throw 'Public source allowlist contains a malformed path record.' }
		$path = [string]$file.path
		if ([IO.Path]::IsPathRooted($path) -or $path.Contains('\') -or $path.Contains(':') -or $path.Contains('!') -or $path.Contains('*') -or $path.Contains('?') -or $path -match '(^|/)\.\.(/|$)|(^|/)\.(?:/|$)|//') {
			throw "Public source allowlist contains an unsafe path: $path"
		}
		if ($path.StartsWith('evidence/', [StringComparison]::OrdinalIgnoreCase) -or $path.StartsWith('dist/', [StringComparison]::OrdinalIgnoreCase) -or $path.StartsWith('patches/', [StringComparison]::OrdinalIgnoreCase) -or $path -match '(?i)(^|/)(?:\.git|bin|obj|output|logs?)(/|$)|\.(?:dll|pex|pdb|log|zip|7z)$') {
			throw "Public source allowlist contains generated, historical, or private material: $path"
		}
		if ($path.StartsWith('native/deps/', [StringComparison]::OrdinalIgnoreCase) -and $path -cne 'native/deps/PrismaUI_API.h') {
			throw "Public source allowlist contains a vendored dependency tree: $path"
		}
		if ($path -in @(
			'server/tests/pcv/http_ingress.php'
			'server/tests/pcv/preprocessing_integration.php'
			'server/tests/pcv/preprocessing_worker.php'
			'server/tests/pcv/readiness.php'
			'server/tests/pcv/runtime_support.php'
		)) {
			throw "Public source allowlist contains an excluded legacy core-ingress test: $path"
		}
		if (-not $allowlistSet.Add($path)) { throw "Public source allowlist contains a duplicate or case-colliding path: $path" }
		$sourceRelativePaths += $path
	}
	if ($SourceAllowlistPath -cnotin $sourceRelativePaths) { throw 'The public source allowlist must include itself.' }
	$sourceRelativePaths = @($sourceRelativePaths | Sort-Object -Unique)
	$requiredSourcePaths = @($gameSpecs | Where-Object { $_.Source -notlike 'generated/*' } | ForEach-Object { $_.Source }) + @($serverSpecs | ForEach-Object { $_.Source }) + @($noticeSpecs | ForEach-Object { $_.Source }) + @($ReleaseGuidePath)
	$missingSourcePaths = @($requiredSourcePaths | Sort-Object -Unique | Where-Object { $_ -cnotin $sourceRelativePaths })
	if ($missingSourcePaths.Count -gt 0) { throw "Public source allowlist omits package inputs: $($missingSourcePaths -join ', ')" }
} else {
$sourceRelativePaths = @(
	'native/CMakeLists.txt'
	'native/src/Contract.cpp'
	'native/src/Contract.h'
	'native/src/HttpTransport.cpp'
	'native/src/HttpTransport.h'
	'native/src/NativePolicy.cpp'
	'native/src/NativePolicy.h'
	'native/src/PcvCompanionPolicy.cpp'
	'native/src/PcvCompanionPolicy.h'
	'native/src/Plugin.cpp'
	'native/tests/ContractTests.cpp'
	'native/tests/http-transport-tests.cpp'
	'native/tests/PcvCompanionPolicyTests.cpp'
	'native/tests/emit-pcv-companion-fixtures.php'
	'native/tests/fixtures/php-companion-json-fixtures.json'
	'server/tests/provision-cgi.php'
	'server/tests/pcv/adapter.php'
	'server/tests/pcv/companion_owner_seam.php'
	'server/tests/pcv/companion_presence_reader.php'
	'server/tests/pcv/companion_store.php'
	'native/papyrus/build.ps1'
	'native/papyrus/PexMetadata.psm1'
	'native/papyrus/Source/WorldOfDramaLlamaMCM.psc'
	'native/papyrus/Source/WorldOfDramaLlamaNative.psc'
	'native/papyrus/tests/PexMetadata.Tests.ps1'
	'packaging/authoring/Program.cs'
	'packaging/authoring/WorldOfDramaLlamaGamePlugin.csproj'
	'packaging/authoring/NuGet.Config'
	'packaging/COMPANION-ONLY-TEST-PACKAGE.md'
	'packaging/build-companion-only-test-candidate.ps1'
	'packaging/companion-only/WorldOfDramaLlama.ini'
	'packaging/companion-only/PCV-PROVENANCE.md'
)
	$sourceRelativePaths += @($gameSpecs | Where-Object { $_.Source -like 'ui/*' } | ForEach-Object { $_.Source })
	$sourceRelativePaths += @($serverSpecs | ForEach-Object { $_.Source })
	$sourceRelativePaths += @($noticeSpecs | ForEach-Object { $_.Source })
	$sourceRelativePaths = @($sourceRelativePaths | Sort-Object -Unique)
}
$requiredInputPaths = @(
	$gameSpecs | ForEach-Object { $_.Source }
	$freshGameAssetSpecs | ForEach-Object { $_.Source }
	$gameZipRelative | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
	$serverSpecs | ForEach-Object { $_.Source }
	$noticeSpecs | ForEach-Object { $_.Source }
	$sourceRelativePaths
) | Sort-Object -Unique

function Resolve-ProjectFile {
	param([Parameter(Mandatory)][string] $RelativePath)
	if ([IO.Path]::IsPathRooted($RelativePath) -or $RelativePath.Contains('!') -or $RelativePath -match '(^|[/\\])\.\.([/\\]|$)') {
		throw "Input path is not a plain project-relative file: $RelativePath"
	}
	$isGeneratedInput = $RelativePath.StartsWith('generated/', [StringComparison]::Ordinal)
	if ($isGeneratedInput) {
		if ([string]::IsNullOrWhiteSpace($script:generatedInputsRootFullPath)) { throw 'Generated input path used without -GeneratedInputsRoot.' }
		$root = [IO.Path]::GetFullPath($script:generatedInputsRootFullPath)
		Get-FullPathWithoutReparsePoints -Path $root | Out-Null
		$normalized = $RelativePath.Substring('generated/'.Length).Replace('/', '\')
		if ([string]::IsNullOrWhiteSpace($normalized)) { throw 'Generated input path does not name a file.' }
		$rootPrefix = $root.TrimEnd('\') + '\'
		$fullPath = [IO.Path]::GetFullPath((Join-Path $root $normalized))
		if (-not $fullPath.StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
			throw "Generated input resolves outside GeneratedInputsRoot: $RelativePath"
		}
		$current = $root.TrimEnd('\')
	} else {
		$normalized = $RelativePath.Replace('/', '\')
		$fullPath = [IO.Path]::GetFullPath((Join-Path $repoRoot $normalized))
		$rootPrefix = $repoRoot.TrimEnd('\') + '\'
		if (-not $fullPath.StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
			throw "Input resolves outside the implementation root: $RelativePath"
		}
		$current = $repoRoot.TrimEnd('\')
	}
	foreach ($part in $normalized.Split('\')) {
		$current = Join-Path $current $part
		if (Test-Path -LiteralPath $current) {
			$item = Get-Item -LiteralPath $current -Force
			if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
				throw "Input path contains a reparse point: $RelativePath"
			}
		}
	}
	if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
		throw "Required package input is missing: $RelativePath"
	}
	return $fullPath
}

function Get-FullPathWithoutReparsePoints {
	param([Parameter(Mandatory)][string] $Path)
	$fullPath = [IO.Path]::GetFullPath($Path)
	$root = [IO.Path]::GetPathRoot($fullPath)
	$current = $root
	$remaining = $fullPath.Substring($root.Length)
	foreach ($part in $remaining.Split([IO.Path]::DirectorySeparatorChar, [StringSplitOptions]::RemoveEmptyEntries)) {
		$current = Join-Path $current $part
		if (Test-Path -LiteralPath $current) {
			$item = Get-Item -LiteralPath $current -Force
			if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
				throw "Output path contains an unexpected reparse point: $current"
			}
		}
	}
	return $fullPath
}

function Assert-PathUnderDirectory {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Parent)
	$fullParent = [IO.Path]::GetFullPath($Parent).TrimEnd('\')
	$fullPath = [IO.Path]::GetFullPath($Path)
	if (-not $fullPath.StartsWith($fullParent + '\', [StringComparison]::OrdinalIgnoreCase)) {
		throw "Output path is outside its designated directory: $Path"
	}
}

function Get-FileDigest {
	param([Parameter(Mandatory)][string] $Path)
	$item = Get-Item -LiteralPath $Path
	return [pscustomobject]@{
		bytes = [long]$item.Length
		sha256 = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
	}
}

function Assert-FrozenFile {
	param([Parameter(Mandatory)][string] $RelativePath, [Parameter(Mandatory)][System.Collections.IDictionary] $Freeze)
	if (-not $Freeze.files.Contains($RelativePath)) {
		throw "The root freeze does not pin required input: $RelativePath"
	}
	$entry = $Freeze.files[$RelativePath]
	if ($entry -isnot [System.Collections.IDictionary] -or $entry.sha256 -cnotmatch '\A[0-9a-f]{64}\z' -or $entry.bytes -isnot [long] -and $entry.bytes -isnot [int]) {
		throw "The root freeze entry is malformed: $RelativePath"
	}
	$path = Resolve-ProjectFile $RelativePath
	$actual = Get-FileDigest $path
	if ($actual.sha256 -cne $entry.sha256 -or $actual.bytes -ne [long]$entry.bytes) {
		throw "Frozen input hash or length mismatch: $RelativePath"
	}
	return $path
}

function Read-ZipMemberBytes {
	param([Parameter(Mandatory)][string] $ArchivePath, [Parameter(Mandatory)][string] $MemberPath)
	$archive = [IO.Compression.ZipFile]::OpenRead($ArchivePath)
	try {
		$matches = @($archive.Entries | Where-Object { $_.FullName -ceq $MemberPath })
		if ($matches.Count -ne 1 -or $matches[0].FullName.EndsWith('/')) {
			throw "ZIP member is absent, duplicated, or not a file: $MemberPath"
		}
		$stream = $matches[0].Open()
		try {
			$memory = [IO.MemoryStream]::new()
			try {
				$stream.CopyTo($memory)
				return ,$memory.ToArray()
			} finally {
				$memory.Dispose()
			}
		} finally {
			$stream.Dispose()
		}
	} finally {
		$archive.Dispose()
	}
}

function ConvertTo-FreezeTimestamp {
	param([Parameter(Mandatory)][AllowNull()] $Value)
	if ($Value -is [DateTimeOffset]) {
		return $Value.ToUniversalTime()
	}
	if ($Value -is [DateTime]) {
		if ($Value.Kind -eq [DateTimeKind]::Unspecified) {
			throw 'The root freeze createdAtUtc DateTime has no timezone kind.'
		}
		return ([DateTimeOffset]$Value).ToUniversalTime()
	}
	if ($Value -is [string]) {
		if ($Value -cnotmatch '\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})\z') {
			throw 'The root freeze createdAtUtc string must be timezone-qualified ISO 8601.'
		}
		$parsed = [DateTimeOffset]::MinValue
		$styles = [Globalization.DateTimeStyles]::RoundtripKind
		if (-not [DateTimeOffset]::TryParse($Value, [Globalization.CultureInfo]::InvariantCulture, $styles, [ref]$parsed)) {
			throw 'The root freeze createdAtUtc string is not a timestamp.'
		}
		return $parsed.ToUniversalTime()
	}
	throw 'The root freeze createdAtUtc must be an ISO string or timezone-aware date value.'
}

function Test-FreezeTimestampParser {
	$iso = '2026-10-20T19:14:25.4865692+00:00'
	$expected = [DateTimeOffset]::ParseExact($iso, 'O', [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::RoundtripKind).UtcDateTime.Ticks
	$literalResult = ConvertTo-FreezeTimestamp $iso
	if ($literalResult.UtcDateTime.Ticks -ne $expected) {
		throw 'The freeze timestamp parser changed a literal ISO timestamp.'
	}
	$json = '{"createdAtUtc":"' + $iso + '"}' | ConvertFrom-Json -AsHashtable -Depth 2
	if ($json.createdAtUtc -isnot [DateTime]) {
		throw 'This PowerShell JSON parser did not produce the DateTime regression fixture.'
	}
	$jsonResult = ConvertTo-FreezeTimestamp $json.createdAtUtc
	if ($jsonResult.UtcDateTime.Ticks -ne $expected) {
		throw 'The freeze timestamp parser changed the JSON-parsed DateTime instant.'
	}
	$rejected = $false
	try { $null = ConvertTo-FreezeTimestamp '20/10/2026 3:14:25 p.m.' } catch { $rejected = $true }
	if (-not $rejected) { throw 'The freeze timestamp parser accepted a locale-formatted string.' }
	Write-Output "PASS: literal ISO and JSON DateTime preserve the same UTC instant under culture $((Get-Culture).Name)."
}

function Assert-RootFreeze {
	if (-not (Test-Path -LiteralPath $freezeFullPath -PathType Leaf)) {
		throw "The root proof/input freeze is not present: $freezeRelative"
	}
	$freezeItem = Get-Item -LiteralPath $freezeFullPath -Force
	if (($freezeItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
		throw 'The root proof/input freeze must not be a reparse point.'
	}
	try {
		$freeze = Get-Content -LiteralPath $freezeFullPath -Raw | ConvertFrom-Json -AsHashtable -Depth 32
	} catch {
		throw "The root proof/input freeze is not valid JSON: $($_.Exception.Message)"
	}
	$expectedFreezeKind = if ($PreAlpha) { 'department-pre-alpha' } else { 'companion-only-test' }
	if ($freeze.schemaVersion -ne 1 -or $freeze.status -cne 'ready' -or $freeze.kind -cne $expectedFreezeKind -or $freeze.files -isnot [System.Collections.IDictionary] -or $freeze.gates -isnot [System.Collections.IDictionary]) {
		throw "The root proof/input freeze must be schema 1, ready, $expectedFreezeKind, and contain files plus gates."
	}
	$requiredGates = @('privateRoute', 'critique', 'ui', 'native', 'runtimeRelease')
	$actualGateNames = [string]::Join(',', @($freeze.gates.Keys | Sort-Object))
	$requiredGateNames = [string]::Join(',', @($requiredGates | Sort-Object))
	if ($actualGateNames -cne $requiredGateNames) {
		throw 'The root freeze does not contain exactly the required accepted gate receipts.'
	}
	$gatePins = [ordered]@{}
	foreach ($name in $requiredGates) {
		$gate = $freeze.gates[$name]
		if ($gate -isnot [System.Collections.IDictionary] -or -not ($gate.receipt -is [string]) -or $gate.sha256 -cnotmatch '\A[0-9a-f]{64}\z') {
			throw "The root freeze gate receipt is malformed: $name"
		}
		if (-not $gate.receipt.StartsWith('evidence/companion-only/', [StringComparison]::Ordinal) -or $gate.receipt.Contains('!') -or $gate.receipt -match '(^|/)\.\.(/|$)') {
			throw "Gate receipt must be a project-relative companion-only evidence path: $name"
		}
		$receiptPath = Resolve-ProjectFile $gate.receipt
		$receiptDigest = Get-FileDigest $receiptPath
		if ($receiptDigest.sha256 -cne $gate.sha256) {
			throw "Gate receipt hash mismatch: $name"
		}
		$gatePins[$name] = [ordered]@{ receipt = $gate.receipt; sha256 = $gate.sha256 }
	}
	$inputPins = [ordered]@{}
	foreach ($relative in $requiredInputPaths) {
		$path = Assert-FrozenFile -RelativePath $relative -Freeze $freeze
		$inputPins[$relative] = [ordered]@{ bytes = [long]$freeze.files[$relative].bytes; sha256 = $freeze.files[$relative].sha256 }
	}
	if ($PreAlpha) {
		$freezeInputNames = @($freeze.files.Keys | ForEach-Object { [string]$_ } | Sort-Object)
		$expectedInputNames = @($requiredInputPaths | Sort-Object)
		if ([string]::Join([Environment]::NewLine, $freezeInputNames) -cne [string]::Join([Environment]::NewLine, $expectedInputNames)) {
			throw 'PRE-ALPHA root freeze file set differs from the exact package input allowlist.'
		}
	}
	$pcvManifestRelative = if ($PreAlpha) { 'packaging/companion-only/pcv-0.1.22-source-manifest.json' } else { 'evidence/companion-only/server/pcv-0.1.22-source-manifest.json' }
	$pcvManifest = Get-Content -LiteralPath (Resolve-ProjectFile $pcvManifestRelative) -Raw | ConvertFrom-Json -AsHashtable -Depth 16
	$pcvSpecs = @($serverSpecs | Where-Object { $_.Source.StartsWith('pcv-companion/private-conversation-v0.1.22/server/', [StringComparison]::Ordinal) })
	$pcvInstallDirectory = [string]$pcvManifest.installDirectory
	if ($pcvInstallDirectory -cne 'ext/private_conversation' -or $pcvSpecs.Count -ne 7 -or $pcvManifest.files -isnot [array] -or $pcvManifest.files.Count -ne 7) {
		throw 'The PCV 0.1.22 install manifest or seven-file allowlist is malformed.'
	}
	$manifestFiles = @{}
	foreach ($file in $pcvManifest.files) {
		if ($file.path -isnot [string] -or $file.path -notmatch '\A[A-Za-z0-9_.-]+\z' -or $file.postimageSha256 -cnotmatch '\A[0-9a-f]{64}\z' -or $manifestFiles.ContainsKey($file.path)) {
			throw 'The PCV 0.1.22 source manifest has a malformed or duplicate file entry.'
		}
		$manifestFiles[$file.path] = $file.postimageSha256
	}
	foreach ($spec in $pcvSpecs) {
		$sourceName = [IO.Path]::GetFileName($spec.Source)
		$targetParent = ($spec.Target -replace '/[^/]+\z', '')
		if ($targetParent -cne $pcvInstallDirectory -or [IO.Path]::GetFileName($spec.Target) -cne $sourceName -or -not $manifestFiles.ContainsKey($sourceName) -or $manifestFiles[$sourceName] -cne $inputPins[$spec.Source].sha256) {
			throw "PCV source manifest and package install target disagree: $sourceName"
		}
		$manifestFiles.Remove($sourceName)
	}
	if ($manifestFiles.Count -ne 0) { throw 'The PCV source manifest contains files outside the seven-file package allowlist.' }
	if ($PreAlpha) {
		if ($freeze.acceptedGameAssets -isnot [array] -or $freeze.acceptedGameAssets.Count -ne $freshGameAssetSpecs.Count) {
			throw 'The PRE-ALPHA root freeze must pin exactly the two fresh game assets.'
		}
		foreach ($spec in $freshGameAssetSpecs) {
			$asset = @($freeze.acceptedGameAssets | Where-Object { $_.target -ceq $spec.Target })
			$pin = $inputPins[$spec.Source]
			if ($asset.Count -ne 1 -or $asset[0].source -cne $spec.Source -or $asset[0].sha256 -cne $pin.sha256 -or [long]$asset[0].bytes -ne $pin.bytes) {
				throw "The PRE-ALPHA root freeze does not match the fresh game asset: $($spec.Target)"
			}
		}
	} else {
		$archiveEntry = $inputPins[$gameZipRelative]
		if ($archiveEntry.sha256 -cne $gameZipSha256) {
			throw 'The accepted ESP/SEQ source archive hash is not the root-approved input.'
		}
		if ($freeze.acceptedGameAssets -isnot [array] -or $freeze.acceptedGameAssets.Count -ne $gameZipMemberSpecs.Count) {
			throw 'The root freeze must contain exactly the two approved game assets.'
		}
		foreach ($spec in $gameZipMemberSpecs) {
			$asset = @($freeze.acceptedGameAssets | Where-Object { $_.target -ceq $spec.Target })
			if ($asset.Count -ne 1 -or $asset[0].source -cne $spec.Source -or $asset[0].sha256 -cne $spec.Sha256 -or [long]$asset[0].bytes -ne $spec.Bytes) {
				throw "The root freeze does not match the approved game asset: $($spec.Target)"
			}
			$member = $spec.Source.Substring($spec.Source.IndexOf('!') + 1)
			$bytes = Read-ZipMemberBytes -ArchivePath (Resolve-ProjectFile $gameZipRelative) -MemberPath $member
			$actualSha = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
			if ($bytes.Length -ne $spec.Bytes -or $actualSha -cne $spec.Sha256) {
				throw "The approved game asset member failed its independent hash check: $($spec.Target)"
			}
		}
	}
	try {
		$createdAt = ConvertTo-FreezeTimestamp $freeze.createdAtUtc
	} catch {
		throw "The root freeze createdAtUtc is not a valid timezone-aware timestamp: $($_.Exception.Message)"
	}
	return [pscustomobject]@{ Value = $freeze; CreatedAtUtc = $createdAt; GatePins = $gatePins; InputPins = $inputPins; Sha256 = (Get-FileDigest $freezeFullPath).sha256 }
}

function Copy-PinnedFile {
	param([Parameter(Mandatory)][string] $RelativePath, [Parameter(Mandatory)][string] $TargetPath)
	$source = Resolve-ProjectFile $RelativePath
	if ($null -eq $script:currentFreeze -or -not $script:currentFreeze.InputPins.Contains($RelativePath)) {
		throw "Copied file is not pinned by the validated root freeze: $RelativePath"
	}
	$destination = Join-Path $script:currentStage ($TargetPath -replace '/', '\')
	$parent = Split-Path -Parent $destination
	New-Item -ItemType Directory -Path $parent -Force | Out-Null
	Copy-Item -LiteralPath $source -Destination $destination
	$expected = $script:currentFreeze.InputPins[$RelativePath]
	$copied = Get-FileDigest $destination
	if ($copied.sha256 -cne $expected.sha256 -or $copied.bytes -ne [long]$expected.bytes) {
		throw "Copied file differs from the root-frozen input: $RelativePath"
	}
}

function Write-ZipMember {
	param(
		[Parameter(Mandatory)][byte[]] $Bytes,
		[Parameter(Mandatory)][string] $TargetPath,
		[Parameter(Mandatory)][long] $ExpectedBytes,
		[Parameter(Mandatory)][string] $ExpectedSha256
	)
	$actualSha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($Bytes)).ToLowerInvariant()
	if ($Bytes.Length -ne $ExpectedBytes -or $actualSha256 -cne $ExpectedSha256) {
		throw "Fresh ZIP member data failed its approved hash check: $TargetPath"
	}
	$destination = Join-Path $script:currentStage ($TargetPath -replace '/', '\')
	$parent = Split-Path -Parent $destination
	New-Item -ItemType Directory -Path $parent -Force | Out-Null
	[IO.File]::WriteAllBytes($destination, $Bytes)
	$written = Get-FileDigest $destination
	if ($written.bytes -ne $ExpectedBytes -or $written.sha256 -cne $ExpectedSha256) {
		throw "Written ZIP member differs from its approved bytes: $TargetPath"
	}
}

function New-ZipFromDirectory {
	param([Parameter(Mandatory)][string] $Directory, [Parameter(Mandatory)][string] $Path)
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$archive = [IO.Compression.ZipFile]::Open($Path, [IO.Compression.ZipArchiveMode]::Create)
	try {
		$root = [IO.Path]::GetFullPath($Directory).TrimEnd('\') + '\'
		foreach ($file in Get-ChildItem -LiteralPath $Directory -Recurse -File | Sort-Object FullName) {
			if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Staged package contains a reparse point: $($file.FullName)" }
			$name = $file.FullName.Substring($root.Length).Replace('\', '/')
			$null = [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $name, [IO.Compression.CompressionLevel]::Optimal)
		}
	} finally {
		$archive.Dispose()
	}
}

function Get-ZipMemberRecords {
	param([Parameter(Mandatory)][string] $Path)
	$archive = [IO.Compression.ZipFile]::OpenRead($Path)
	try {
		$records = @()
		foreach ($entry in $archive.Entries) {
			if ($entry.FullName.EndsWith('/')) { throw "Unexpected directory entry in archive: $($entry.FullName)" }
			$bytes = Read-ZipMemberBytes -ArchivePath $Path -MemberPath $entry.FullName
			$records += [pscustomobject]@{
				path = $entry.FullName
				bytes = $bytes.Length
				sha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
			}
		}
		return ,@($records | Sort-Object path)
	} finally {
		$archive.Dispose()
	}
}

function Assert-ZipMembers {
	param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string[]] $ExpectedMembers)
	$actual = @(Get-ZipMemberRecords -Path $Path | ForEach-Object { $_.path } | Sort-Object)
	$expected = @($ExpectedMembers | Sort-Object)
	if ($actual.Count -ne $expected.Count -or ($actual -join "`n") -cne ($expected -join "`n")) {
		throw "Archive member allowlist mismatch: $([IO.Path]::GetFileName($Path))"
	}
}

function Add-SpecFiles {
	param([Parameter(Mandatory)][object[]] $Specs)
	foreach ($spec in $Specs) { Copy-PinnedFile -RelativePath $spec.Source -TargetPath $spec.Target }
}

if ($TestFreezeTimestamp) {
	if ($CreateArchive) { throw 'Timestamp self-test cannot be combined with -CreateArchive.' }
	Test-FreezeTimestampParser
	return
}

$frozen = Assert-RootFreeze
if (-not $CreateArchive) {
	$profileLabel = if ($PreAlpha) { 'department PRE-ALPHA' } else { 'companion-only' }
	Write-Output "PASS: root freeze and all $profileLabel inputs verified ($($frozen.InputPins.Count) files); no archive created."
	Write-Output 'Invoke this script with -CreateArchive only after the root accepts the exact freeze.'
	return
}
$script:currentFreeze = $frozen

Add-Type -AssemblyName System.IO.Compression.FileSystem
$timestamp = $frozen.CreatedAtUtc.UtcDateTime.ToString('yyyyMMddTHHmmssZ')
$candidateType = if ($PreAlpha) { 'department-pre-alpha-candidate' } else { 'companion-only-test-candidate' }
$candidateName = "WorldOfDramaLlama-$candidateType-$timestamp-$($frozen.Sha256.Substring(0, 10))"
$stagingName = if ($PreAlpha) { '.department-pre-alpha-staging' } else { '.companion-only-staging' }
$stagingRoot = Join-Path $PSScriptRoot $stagingName
$finalRoot = Join-Path $repoRoot ($CandidateOutputRoot -replace '/', '\')
$candidatePath = Join-Path $finalRoot $candidateName
if (Test-Path -LiteralPath $candidatePath) { throw "Candidate output already exists; refusing to overwrite: $candidatePath" }
Get-FullPathWithoutReparsePoints -Path $stagingRoot | Out-Null
Get-FullPathWithoutReparsePoints -Path $finalRoot | Out-Null
Get-FullPathWithoutReparsePoints -Path $candidatePath | Out-Null
Assert-PathUnderDirectory -Path $stagingRoot -Parent $PSScriptRoot
Assert-PathUnderDirectory -Path $finalRoot -Parent $repoRoot
Assert-PathUnderDirectory -Path $candidatePath -Parent $finalRoot
New-Item -ItemType Directory -Path $stagingRoot -Force | Out-Null
Get-FullPathWithoutReparsePoints -Path $stagingRoot | Out-Null
$script:currentStage = Join-Path $stagingRoot ($candidateName + '-' + [Guid]::NewGuid().ToString('N'))
Assert-PathUnderDirectory -Path $script:currentStage -Parent $stagingRoot
New-Item -ItemType Directory -Path $script:currentStage | Out-Null
Get-FullPathWithoutReparsePoints -Path $script:currentStage | Out-Null
$componentsRoot = Join-Path $script:currentStage 'components'
$workRoot = Join-Path $script:currentStage '.work'
$null = New-Item -ItemType Directory -Path $componentsRoot,$workRoot -Force

$gameRoot = Join-Path $workRoot 'game'
$serverRoot = Join-Path $workRoot 'server'
$noticeRoot = Join-Path $workRoot 'notices'
$sourceRoot = Join-Path $workRoot 'source'
New-Item -ItemType Directory -Path $gameRoot,$serverRoot,$noticeRoot,$sourceRoot -Force | Out-Null
foreach ($spec in $gameSpecs) { Copy-PinnedFile -RelativePath $spec.Source -TargetPath (Join-Path '.work/game' $spec.Target) }
foreach ($spec in $freshGameAssetSpecs) { Copy-PinnedFile -RelativePath $spec.Source -TargetPath (Join-Path '.work/game' $spec.Target) }
foreach ($spec in $gameZipMemberSpecs) {
	$member = $spec.Source.Substring($spec.Source.IndexOf('!') + 1)
	$bytes = Read-ZipMemberBytes -ArchivePath (Resolve-ProjectFile $gameZipRelative) -MemberPath $member
	Write-ZipMember -Bytes $bytes -TargetPath (Join-Path '.work/game' $spec.Target) -ExpectedBytes $spec.Bytes -ExpectedSha256 $spec.Sha256
}
foreach ($spec in $serverSpecs) { Copy-PinnedFile -RelativePath $spec.Source -TargetPath (Join-Path '.work/server' $spec.Target) }
foreach ($spec in $noticeSpecs) { Copy-PinnedFile -RelativePath $spec.Source -TargetPath (Join-Path '.work/notices' $spec.Target) }

$sourceInventory = @()
foreach ($relative in $sourceRelativePaths) {
	$target = if ($PreAlpha) { $relative } else { 'implementation/' + $relative }
	Copy-PinnedFile -RelativePath $relative -TargetPath (Join-Path '.work/source' $target)
	$sourceInventory += [pscustomobject]@{
		path = $target
		bytes = [long]$frozen.InputPins[$relative].bytes
		sha256 = $frozen.InputPins[$relative].sha256
	}
}
$sourceManifest = [ordered]@{
	schemaVersion = 1
	kind = if ($PreAlpha) { 'department-pre-alpha-source-snapshot' } else { 'companion-only-owned-source-snapshot' }
	files = @($sourceInventory | Sort-Object path)
}
$sourceManifestPath = Join-Path $sourceRoot 'source-manifest.json'
[IO.File]::WriteAllText($sourceManifestPath, ($sourceManifest | ConvertTo-Json -Depth 8) + "`n", [Text.UTF8Encoding]::new($false))

$packageGuideName = if ($PreAlpha) { 'PRE-ALPHA-RELEASE.md' } else { 'COMPANION-ONLY-TEST-PACKAGE.md' }
Copy-PinnedFile -RelativePath $ReleaseGuidePath -TargetPath $packageGuideName
$readmePath = Join-Path $script:currentStage $packageGuideName
$componentPrefix = if ($PreAlpha) { 'WorldOfDramaLlama-department-pre-alpha' } else { 'WorldOfDramaLlama-companion-only' }
$componentFiles = @(
	[pscustomobject]@{ Name = "$componentPrefix-game-data.zip"; Root = $gameRoot }
	[pscustomobject]@{ Name = "$componentPrefix-server.zip"; Root = $serverRoot }
	[pscustomobject]@{ Name = "$componentPrefix-source.zip"; Root = $sourceRoot }
	[pscustomobject]@{ Name = "$componentPrefix-notices.zip"; Root = $noticeRoot }
)
foreach ($component in $componentFiles) {
	New-ZipFromDirectory -Directory $component.Root -Path (Join-Path $componentsRoot $component.Name)
}

$gameExpected = @($gameSpecs | ForEach-Object { $_.Target }) + @($freshGameAssetSpecs | ForEach-Object { $_.Target }) + @($gameZipMemberSpecs | ForEach-Object { $_.Target })
$serverExpected = @($serverSpecs.Target)
$noticeExpected = @($noticeSpecs.Target)
$sourceExpected = @($sourceInventory | ForEach-Object { $_.path }) + @('source-manifest.json')
Assert-ZipMembers -Path (Join-Path $componentsRoot $componentFiles[0].Name) -ExpectedMembers $gameExpected
Assert-ZipMembers -Path (Join-Path $componentsRoot $componentFiles[1].Name) -ExpectedMembers $serverExpected
Assert-ZipMembers -Path (Join-Path $componentsRoot $componentFiles[2].Name) -ExpectedMembers $sourceExpected
Assert-ZipMembers -Path (Join-Path $componentsRoot $componentFiles[3].Name) -ExpectedMembers $noticeExpected
$sourceArchivePath = Join-Path $componentsRoot $componentFiles[2].Name
$sourceArchiveRecords = Get-ZipMemberRecords -Path $sourceArchivePath
foreach ($expected in $sourceInventory) {
	$actual = @($sourceArchiveRecords | Where-Object { $_.path -ceq $expected.path })
	if ($actual.Count -ne 1 -or $actual[0].bytes -ne $expected.bytes -or $actual[0].sha256 -cne $expected.sha256) {
		throw "Source archive member differs from the root-frozen inventory: $($expected.path)"
	}
}

$componentManifest = @()
foreach ($component in $componentFiles) {
	$path = Join-Path $componentsRoot $component.Name
	$digest = Get-FileDigest $path
	$members = Get-ZipMemberRecords -Path $path
	$componentManifest += [pscustomobject]@{
		name = $component.Name
		bytes = $digest.bytes
		sha256 = $digest.sha256
		memberCount = $members.Count
		members = $members
	}
}
$manifestGates = $frozen.GatePins
$manifestFreeze = [ordered]@{ path = $freezeRelative; sha256 = $frozen.Sha256 }
if ($PreAlpha) {
	$manifestGates = [ordered]@{}
	foreach ($name in @($frozen.GatePins.Keys | Sort-Object)) {
		$manifestGates[$name] = [ordered]@{ sha256 = $frozen.GatePins[$name].sha256 }
	}
	$manifestFreeze = [ordered]@{ sha256 = $frozen.Sha256 }
}
$packageManifest = [ordered]@{
	schemaVersion = 1
	status = if ($PreAlpha) { 'pre-alpha-static-candidate' } else { 'private-static-test-candidate' }
	kind = if ($PreAlpha) { 'department-pre-alpha' } else { 'companion-only-test' }
	freeze = $manifestFreeze
	gates = $manifestGates
	inputs = $frozen.InputPins
	components = $componentManifest
	limitations = if ($PreAlpha) { @(
		'No Skyrim runtime or gameplay verification is claimed by this package build.'
		'Package and source identity checks do not establish live CHIM delivery or audio.'
		'Publication and installation remain separate actions.'
	) } else { @(
		'No Skyrim invocation was performed by this builder.'
		'Consumed means PCV accepted the text; it does not confirm model processing, delivery, or audio.'
		'No publication is authorized.'
	) }
}
$manifestPath = Join-Path $script:currentStage 'package-manifest.json'
[IO.File]::WriteAllText($manifestPath, ($packageManifest | ConvertTo-Json -Depth 16) + "`n", [Text.UTF8Encoding]::new($false))
$outerName = if ($PreAlpha) { 'WorldOfDramaLlama-department-pre-alpha-candidate.zip' } else { 'WorldOfDramaLlama-companion-only-test-candidate.zip' }
$outerPath = Join-Path $script:currentStage $outerName
New-ZipFromDirectory -Directory $componentsRoot -Path $outerPath
# The root package documentation and manifest are outside the component directory by design.
$outer = [IO.Compression.ZipFile]::Open($outerPath, [IO.Compression.ZipArchiveMode]::Update)
try {
	$null = [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($outer, $readmePath, $packageGuideName, [IO.Compression.CompressionLevel]::Optimal)
	$null = [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($outer, $manifestPath, 'package-manifest.json', [IO.Compression.CompressionLevel]::Optimal)
} finally {
	$outer.Dispose()
}
$outerExpected = @($componentFiles.Name) + @($packageGuideName, 'package-manifest.json')
Assert-ZipMembers -Path $outerPath -ExpectedMembers $outerExpected

$buildReceipt = [ordered]@{
	schemaVersion = 1
	status = if ($PreAlpha) { 'verified-static-pre-alpha-candidate' } else { 'verified-static-private-candidate' }
	candidate = $candidateName
	freezeSha256 = $frozen.Sha256
	outerArchive = [ordered]@{ name = [IO.Path]::GetFileName($outerPath); bytes = (Get-FileDigest $outerPath).bytes; sha256 = (Get-FileDigest $outerPath).sha256 }
	components = $componentManifest
	verifiedAtUtc = [DateTimeOffset]::UtcNow.ToString('o')
}
$buildReceiptPath = Join-Path $script:currentStage 'BUILD-RECEIPT.json'
[IO.File]::WriteAllText($buildReceiptPath, ($buildReceipt | ConvertTo-Json -Depth 12) + "`n", [Text.UTF8Encoding]::new($false))

$stagePrefix = [IO.Path]::GetFullPath($script:currentStage).TrimEnd('\') + '\'
$workFullPath = [IO.Path]::GetFullPath($workRoot)
if (-not $workFullPath.StartsWith($stagePrefix, [StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($workFullPath) -cne '.work') {
	throw 'Refusing to remove an unrecognized or out-of-stage work directory.'
}
Get-FullPathWithoutReparsePoints -Path $workFullPath | Out-Null
Remove-Item -LiteralPath $workFullPath -Recurse -Force
Get-FullPathWithoutReparsePoints -Path $finalRoot | Out-Null
Assert-PathUnderDirectory -Path $script:currentStage -Parent $stagingRoot
Assert-PathUnderDirectory -Path $candidatePath -Parent $finalRoot
New-Item -ItemType Directory -Path $finalRoot -Force | Out-Null
Get-FullPathWithoutReparsePoints -Path $stagingRoot | Out-Null
Get-FullPathWithoutReparsePoints -Path $script:currentStage | Out-Null
Get-FullPathWithoutReparsePoints -Path $finalRoot | Out-Null
Assert-PathUnderDirectory -Path $script:currentStage -Parent $stagingRoot
Assert-PathUnderDirectory -Path $candidatePath -Parent $finalRoot
if (Test-Path -LiteralPath $candidatePath) { throw "Candidate output appeared during the build; refusing to overwrite: $candidatePath" }
Move-Item -LiteralPath $script:currentStage -Destination $candidatePath
if ($PreAlpha) {
	Write-Output "Verified static PRE-ALPHA candidate: $candidatePath"
} else {
	Write-Output "Verified static private candidate: $candidatePath"
}
Write-Output "Outer archive SHA-256: $($buildReceipt.outerArchive.sha256)"
Write-Output 'Runtime/game delivery remains unverified; package creation does not publish or install it.'
