[CmdletBinding()]
param(
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $CMakePath,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $DependenciesRoot,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $OutputRoot,
	[Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $EvidenceRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$lockPath = Join-Path $PSScriptRoot 'deps.lock.json'
$lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
if ($lock.schemaVersion -ne 1) { throw 'Unsupported native dependency-lock schema.' }

function Get-Sha256 {
	param([Parameter(Mandatory)][string] $Path)
	return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToUpperInvariant()
}

function Resolve-InputFile {
	param([Parameter(Mandatory)][string] $Path)
	if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Required build tool is missing: $Path" }
	$item = Get-Item -LiteralPath $Path -Force
	if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'A required build tool cannot be a reparse point.' }
	return (Resolve-Path -LiteralPath $Path).Path
}

function Assert-DependencyCache {
	param([Parameter(Mandatory)][string] $Root)
	if (-not (Test-Path -LiteralPath $Root -PathType Container)) { throw 'Pinned native dependency cache is missing.' }
	$rootItem = Get-Item -LiteralPath $Root -Force
	if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Pinned native dependency cache cannot be a reparse point.' }
	$items = @(
		@{ File = $lock.commonLib.archiveName; Sha256 = $lock.commonLib.sha256 },
		@{ File = $lock.fmt.archiveName; Sha256 = $lock.fmt.sha256 },
		@{ File = $lock.spdlog.archiveName; Sha256 = $lock.spdlog.sha256 },
		@{ File = $lock.rapidcsv.archiveName; Sha256 = $lock.rapidcsv.sha256 }
	)
	foreach ($item in $items) {
		$path = Join-Path (Join-Path $Root 'downloads') $item.File
		if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or (Get-Sha256 -Path $path) -cne $item.Sha256) {
			throw "Native dependency archive is missing or has the wrong pin: $($item.File)"
		}
	}
	foreach ($item in @(
		@{ File = $lock.nlohmannJson.relativePath; Sha256 = $lock.nlohmannJson.sha256 },
		@{ File = $lock.prismaUi.relativePath; Sha256 = $lock.prismaUi.sha256 }
	)) {
		$path = Join-Path $Root $item.File
		if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or (Get-Sha256 -Path $path) -cne $item.Sha256) {
			throw "Native dependency header is missing or has the wrong pin: $($item.File)"
		}
	}
}

function Resolve-FreshOutputPath {
	param([Parameter(Mandatory)][string] $Path)
	$fullPath = [IO.Path]::GetFullPath($Path)
	if (Test-Path -LiteralPath $fullPath) { throw 'Build output path already exists; choose a fresh directory.' }
	$parentPath = [IO.Path]::GetDirectoryName($fullPath)
	if (-not (Test-Path -LiteralPath $parentPath -PathType Container)) { throw 'Build output parent must already exist.' }
	$parent = Get-Item -LiteralPath $parentPath -Force
	if (($parent.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Build output parent cannot be a reparse point.' }
	return Join-Path (Resolve-Path -LiteralPath $parentPath).Path ([IO.Path]::GetFileName($fullPath))
}

function Invoke-CMake {
	param(
		[Parameter(Mandatory)][string[]] $Arguments,
		[Parameter(Mandatory)][string] $LogPath,
		[Parameter(Mandatory)][string] $Description
	)
	$display = @($Arguments | ForEach-Object { '"' + $_.Replace('"', '\"') + '"' }) -join ' '
	Add-Content -LiteralPath $LogPath -Value "`r`n> cmake $display"
	$output = & $script:cmake @Arguments 2>&1
	$exitCode = $LASTEXITCODE
	$output | Add-Content -LiteralPath $LogPath
	if ($exitCode -ne 0) { throw "$Description failed with CMake exit code $exitCode; see the requested evidence log." }
}

function Get-ArchiveSource {
	param(
		[Parameter(Mandatory)][string] $ArchivePath,
		[Parameter(Mandatory)][string] $DestinationRoot,
		[Parameter(Mandatory)][string] $ExpectedDirectory
	)
	$extractRoot = Join-Path $DestinationRoot ([IO.Path]::GetFileNameWithoutExtension($ArchivePath))
	New-Item -ItemType Directory -Path $extractRoot | Out-Null
	Expand-Archive -LiteralPath $ArchivePath -DestinationPath $extractRoot
	$source = Join-Path $extractRoot $ExpectedDirectory
	if (-not (Test-Path -LiteralPath (Join-Path $source 'CMakeLists.txt') -PathType Leaf)) {
		throw "Pinned dependency archive did not extract the expected source root: $ExpectedDirectory"
	}
	return $source
}

$script:cmake = Resolve-InputFile -Path $CMakePath
$script:ctest = Resolve-InputFile -Path (Join-Path (Split-Path -Parent $script:cmake) 'ctest.exe')
$dependencies = [IO.Path]::GetFullPath((Resolve-Path -LiteralPath $DependenciesRoot).Path)
Assert-DependencyCache -Root $dependencies
$cmakeVersion = (& $script:cmake --version | Select-Object -First 1).Trim()
if ($cmakeVersion -cne "cmake version $($lock.toolchain.cmakeVersion)-msvc11" -and $cmakeVersion -cne "cmake version $($lock.toolchain.cmakeVersion)") {
	throw "CMake version must be $($lock.toolchain.cmakeVersion); found '$cmakeVersion'."
}
$ctestVersion = (& $script:ctest --version | Select-Object -First 1).Trim()
if ($ctestVersion -notmatch "^ctest version $([regex]::Escape($lock.toolchain.cmakeVersion))(?:-[A-Za-z0-9.]+)?$") {
	throw "CTest version must match CMake $($lock.toolchain.cmakeVersion); found '$ctestVersion'."
}
$productHeader = Join-Path $PSScriptRoot 'deps\PrismaUI_API.h'
if (-not (Test-Path -LiteralPath $productHeader -PathType Leaf) -or (Get-Sha256 -Path $productHeader) -cne $lock.prismaUi.sha256) {
	throw 'The staged PrismaUI API header does not match the commit-pinned public CHIM header.'
}
$output = Resolve-FreshOutputPath -Path $OutputRoot
$evidence = Resolve-FreshOutputPath -Path $EvidenceRoot
if ($output.Equals($evidence, [StringComparison]::OrdinalIgnoreCase) -or
	$output.StartsWith($evidence.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or
	$evidence.StartsWith($output.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
	throw 'Native build output and evidence must be separate directories.'
}

New-Item -ItemType Directory -Path $output,$evidence | Out-Null
$unpackedRoot = Join-Path $output 'sources'
$installRoot = Join-Path $output 'dependency-install'
$logRoot = Join-Path $evidence 'logs'
New-Item -ItemType Directory -Path $unpackedRoot,$installRoot,$logRoot | Out-Null
$commonLibRoot = Get-ArchiveSource -ArchivePath (Join-Path (Join-Path $dependencies 'downloads') $lock.commonLib.archiveName) -DestinationRoot $unpackedRoot -ExpectedDirectory $lock.commonLib.sourceDirectory
$fmtRoot = Get-ArchiveSource -ArchivePath (Join-Path (Join-Path $dependencies 'downloads') $lock.fmt.archiveName) -DestinationRoot $unpackedRoot -ExpectedDirectory $lock.fmt.sourceDirectory
$spdlogRoot = Get-ArchiveSource -ArchivePath (Join-Path (Join-Path $dependencies 'downloads') $lock.spdlog.archiveName) -DestinationRoot $unpackedRoot -ExpectedDirectory $lock.spdlog.sourceDirectory
$rapidcsvRoot = Get-ArchiveSource -ArchivePath (Join-Path (Join-Path $dependencies 'downloads') $lock.rapidcsv.archiveName) -DestinationRoot $unpackedRoot -ExpectedDirectory $lock.rapidcsv.sourceDirectory
$nativeRoot = $PSScriptRoot
$nlohmannRoot = Join-Path $dependencies 'include'
$fmtCompilerFlags = '/experimental:deterministic /pathmap:"' + $fmtRoot + '"=deps/fmt'
$spdlogCompilerFlags = '/experimental:deterministic /pathmap:"' + $spdlogRoot + '"=deps/spdlog /pathmap:"' + $installRoot + '"=deps/install'
$platformArgument = $lock.toolchain.platform + ',version=' + $lock.toolchain.windowsSdkVersion
$toolsetArgument = $lock.toolchain.msvcPlatformToolset
$commonArgs = @('-G', $lock.toolchain.generator, '-A', $platformArgument, '-T', $toolsetArgument)
$dependencyLog = Join-Path $logRoot 'dependency-build.log'
[IO.File]::WriteAllText($dependencyLog, '', [Text.UTF8Encoding]::new($false))

$fmtBuild = Join-Path $output 'build-fmt'
Invoke-CMake -Arguments (@('-S', $fmtRoot, '-B', $fmtBuild) + $commonArgs + @(
	"-DCMAKE_INSTALL_PREFIX=$installRoot", '-DCMAKE_MSVC_RUNTIME_LIBRARY=MultiThreadedDLL', "-DCMAKE_CXX_FLAGS=$fmtCompilerFlags", '-DFMT_TEST=OFF', '-DFMT_DOC=OFF'
)) -LogPath $dependencyLog -Description 'fmt configure'
$fmtCachePath = Join-Path $fmtBuild 'CMakeCache.txt'
$fmtCache = Get-Content -LiteralPath $fmtCachePath -Raw
$generatorInstanceMatch = [regex]::Match($fmtCache, '(?m)^CMAKE_GENERATOR_INSTANCE:INTERNAL=([^\r\n]+)\r?$')
$generatorToolsetMatch = [regex]::Match($fmtCache, '(?m)^CMAKE_GENERATOR_TOOLSET:INTERNAL=([^\r\n]+)\r?$')
if (-not $generatorInstanceMatch.Success -or -not $generatorToolsetMatch.Success) {
	throw 'CMake did not record the selected Visual Studio instance and v143 toolset.'
}
if ($generatorToolsetMatch.Groups[1].Value -cne $lock.toolchain.msvcPlatformToolset) {
	throw 'CMake selected a different MSVC platform toolset than the lock.'
}
$visualStudioInstance = [IO.Path]::GetFullPath($generatorInstanceMatch.Groups[1].Value.Replace('/', '\'))
$toolsetVersionFile = Join-Path $visualStudioInstance "VC\Auxiliary\Build\Microsoft.VCToolsVersion.$($lock.toolchain.msvcPlatformToolset).default.txt"
if (-not (Test-Path -LiteralPath $toolsetVersionFile -PathType Leaf)) {
	throw 'The selected Visual Studio instance does not expose the locked platform toolset default-version file.'
}
$selectedToolsVersion = (Get-Content -LiteralPath $toolsetVersionFile -Raw).Trim()
if ($selectedToolsVersion -cne $lock.toolchain.msvcToolsVersion) {
	throw 'The selected Visual Studio instance default toolset version differs from the exact lock.'
}
$selectedToolsDirectory = Join-Path (Join-Path $visualStudioInstance 'VC\Tools\MSVC') $selectedToolsVersion
if (-not (Test-Path -LiteralPath $selectedToolsDirectory -PathType Container)) {
	throw 'The locked MSVC toolset directory is missing from the selected Visual Studio instance.'
}
Invoke-CMake -Arguments (@('--build', $fmtBuild, '--config', $lock.toolchain.configuration, '--parallel') ) -LogPath $dependencyLog -Description 'fmt build'
Invoke-CMake -Arguments (@('--install', $fmtBuild, '--config', $lock.toolchain.configuration) ) -LogPath $dependencyLog -Description 'fmt install'

$spdlogBuild = Join-Path $output 'build-spdlog'
Invoke-CMake -Arguments (@('-S', $spdlogRoot, '-B', $spdlogBuild) + $commonArgs + @(
	"-DCMAKE_INSTALL_PREFIX=$installRoot", "-DCMAKE_PREFIX_PATH=$installRoot", '-DCMAKE_MSVC_RUNTIME_LIBRARY=MultiThreadedDLL', "-DCMAKE_CXX_FLAGS=$spdlogCompilerFlags",
	'-DSPDLOG_BUILD_TESTS=OFF', '-DSPDLOG_BUILD_SHARED=OFF', '-DSPDLOG_FMT_EXTERNAL=ON'
)) -LogPath $dependencyLog -Description 'spdlog configure'
Invoke-CMake -Arguments (@('--build', $spdlogBuild, '--config', $lock.toolchain.configuration, '--parallel') ) -LogPath $dependencyLog -Description 'spdlog build'
Invoke-CMake -Arguments (@('--install', $spdlogBuild, '--config', $lock.toolchain.configuration) ) -LogPath $dependencyLog -Description 'spdlog install'

$hostBuild = Join-Path $output 'build-host'
$hostLog = Join-Path $logRoot 'host-build-test.log'
[IO.File]::WriteAllText($hostLog, '', [Text.UTF8Encoding]::new($false))
Invoke-CMake -Arguments (@('-S', $nativeRoot, '-B', $hostBuild) + $commonArgs + @(
	'-DBUILD_TESTING=ON', '-DDQL_BUILD_SKSE_PLUGIN=OFF', "-DDQL_NLOHMANN_INCLUDE_ROOT=$nlohmannRoot"
)) -LogPath $hostLog -Description 'host-test configure'
Invoke-CMake -Arguments @('--build', $hostBuild, '--config', $lock.toolchain.configuration, '--parallel') -LogPath $hostLog -Description 'host-test build'
$ctestArguments = @('--test-dir', $hostBuild, '-C', $lock.toolchain.configuration, '--output-on-failure')
$ctestDisplay = @($ctestArguments | ForEach-Object { '"' + $_.Replace('"', '\"') + '"' }) -join ' '
Add-Content -LiteralPath $hostLog -Value "`r`n> ctest $ctestDisplay"
$ctestOutput = & $script:ctest @ctestArguments 2>&1
$ctestExitCode = $LASTEXITCODE
$ctestOutput | Add-Content -LiteralPath $hostLog
if ($ctestExitCode -ne 0) { throw "Host tests failed with CTest exit code $ctestExitCode; see the requested evidence log." }

$pluginOutput = Join-Path $output 'plugin-output'
$pluginBuild = Join-Path $output 'build-plugin'
$pluginLog = Join-Path $logRoot 'plugin-build.log'
[IO.File]::WriteAllText($pluginLog, '', [Text.UTF8Encoding]::new($false))
Invoke-CMake -Arguments (@('-S', $nativeRoot, '-B', $pluginBuild) + $commonArgs + @(
	'-DBUILD_TESTING=OFF', '-DDQL_BUILD_SKSE_PLUGIN=ON', "-DCMAKE_PREFIX_PATH=$installRoot",
	"-DDQL_COMMONLIB_SOURCE=$commonLibRoot", "-DDQL_RAPIDCSV_INCLUDE=$(Join-Path $rapidcsvRoot 'src')",
	"-DDQL_NLOHMANN_INCLUDE_ROOT=$nlohmannRoot", "-DDQL_PLUGIN_OUTPUT_DIRECTORY=$pluginOutput"
)) -LogPath $pluginLog -Description 'plugin configure'
Invoke-CMake -Arguments @('--build', $pluginBuild, '--config', $lock.toolchain.configuration, '--target', 'dql_skse', '--parallel') -LogPath $pluginLog -Description 'plugin build'

$dllPath = Join-Path $pluginOutput 'WorldOfDramaLlama.dll'
if (-not (Test-Path -LiteralPath $dllPath -PathType Leaf)) { throw 'The native plugin target did not produce its DLL.' }
$dllItem = Get-Item -LiteralPath $dllPath
$compilerMetadataFiles = @(Get-ChildItem -LiteralPath (Join-Path $pluginBuild 'CMakeFiles') -Directory | ForEach-Object {
	$path = Join-Path $_.FullName 'CMakeCXXCompiler.cmake'
	if (Test-Path -LiteralPath $path -PathType Leaf) { Get-Item -LiteralPath $path }
})
if ($compilerMetadataFiles.Count -ne 1) { throw 'Generated CMake compiler metadata was missing or ambiguous.' }
$compilerMetadata = Get-Content -LiteralPath $compilerMetadataFiles[0].FullName -Raw
$compilerVersionMatch = [regex]::Match($compilerMetadata, '(?m)^set\(CMAKE_CXX_COMPILER_VERSION "([^"]+)"\)\r?$')
$compilerPathMatch = [regex]::Match($compilerMetadata, '(?m)^set\(CMAKE_CXX_COMPILER "([^"]+)"\)\r?$')
if (-not $compilerVersionMatch.Success -or -not $compilerPathMatch.Success) { throw 'Generated CMake compiler metadata did not report the MSVC compiler identity.' }
$compilerVersion = $compilerVersionMatch.Groups[1].Value
$msvcToolsVersionMatch = [regex]::Match($compilerPathMatch.Groups[1].Value, '[\\/]MSVC[\\/]([^\\/]+)[\\/]bin[\\/]')
if (-not $msvcToolsVersionMatch.Success) { throw 'Generated compiler path did not identify the MSVC toolset version.' }
$msvcToolsVersion = $msvcToolsVersionMatch.Groups[1].Value
if ($compilerVersion -cne $lock.toolchain.msvcCompilerVersion -or $msvcToolsVersion -cne $lock.toolchain.msvcToolsVersion) {
	throw 'Generated compiler identity differs from the exact pinned MSVC compiler/toolset.'
}
$pluginProjectPath = Join-Path $pluginBuild 'dql_skse.vcxproj'
if (-not (Test-Path -LiteralPath $pluginProjectPath -PathType Leaf)) { throw 'Generated plugin project was missing.' }
$pluginProject = [xml](Get-Content -LiteralPath $pluginProjectPath -Raw)
$sdkNodes = @($pluginProject.SelectNodes('//*[local-name()="WindowsTargetPlatformVersion"]'))
$toolsetNodes = @($pluginProject.SelectNodes('//*[local-name()="PlatformToolset"]'))
$sdkVersions = @($sdkNodes | ForEach-Object { $_.InnerText } | Sort-Object -Unique)
$toolsets = @($toolsetNodes | ForEach-Object { $_.InnerText } | Sort-Object -Unique)
if ($sdkVersions.Count -ne 1 -or $toolsets.Count -ne 1) { throw 'Generated plugin project did not contain unique Windows SDK and MSVC platform-toolset versions.' }
if ($sdkVersions[0] -cne $lock.toolchain.windowsSdkVersion -or $toolsets[0] -cne $lock.toolchain.msvcPlatformToolset) {
	throw 'Generated plugin project differs from the exact pinned Windows SDK/platform toolset.'
}
$linkOptionsConfigured = [bool](Select-String -LiteralPath $pluginProjectPath -SimpleMatch '/Brepro' -Quiet)
if (-not $linkOptionsConfigured) { throw 'The generated plugin project did not contain the deterministic /Brepro linker option.' }
$testLine = Select-String -LiteralPath $hostLog -Pattern '100%\s+tests passed,\s+0 tests failed out of (\d+)' | Select-Object -Last 1
if (-not $testLine -or [int]$testLine.Matches[0].Groups[1].Value -ne 5) { throw 'Host CTest result did not confirm all five expected tests passed.' }
$testCount = [int]$testLine.Matches[0].Groups[1].Value
$receipt = [ordered]@{
	schemaVersion = 1
	status = 'native-plugin-and-host-tests-built'
	toolchain = [ordered]@{
		cmakeVersion = $cmakeVersion
		ctestVersion = $ctestVersion
		generator = $lock.toolchain.generator
		platform = $lock.toolchain.platform
		configuration = $lock.toolchain.configuration
		msvcCompilerVersion = $compilerVersion
		msvcToolsVersion = $msvcToolsVersion
		msvcPlatformToolset = $toolsets[0]
		windowsSdkVersion = $sdkVersions[0]
	}
	inputs = [ordered]@{
		commonLib = [ordered]@{ version = $lock.commonLib.version; commit = $lock.commonLib.commit; archiveSha256 = $lock.commonLib.sha256 }
		fmt = [ordered]@{ version = $lock.fmt.version; archiveSha256 = $lock.fmt.sha256 }
		spdlog = [ordered]@{ version = $lock.spdlog.version; archiveSha256 = $lock.spdlog.sha256 }
		rapidcsv = [ordered]@{ version = $lock.rapidcsv.version; archiveSha256 = $lock.rapidcsv.sha256 }
		nlohmannJson = [ordered]@{ version = $lock.nlohmannJson.version; sha256 = $lock.nlohmannJson.sha256 }
		prismaUi = [ordered]@{ repository = $lock.prismaUi.repository; commit = $lock.prismaUi.commit; sha256 = $lock.prismaUi.sha256 }
	}
	hostTests = [ordered]@{ result = $testLine.Line.Trim(); count = $testCount }
	plugin = [ordered]@{ name = $dllItem.Name; bytes = $dllItem.Length; sha256 = Get-Sha256 -Path $dllPath; linkOptionsConfigured = $linkOptionsConfigured }
	buildAccess = [ordered]@{ receiptOmitsLocalPaths = $true; installedGameFilesRead = $false; dllPathScanIsSeparateEvidence = $true }
}
$receiptPath = Join-Path $evidence 'native-build-receipt.json'
[IO.File]::WriteAllText($receiptPath, ($receipt | ConvertTo-Json -Depth 10) + "`n", [Text.UTF8Encoding]::new($false))
$savedReceipt = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
if ($savedReceipt.plugin.sha256 -cne $receipt.plugin.sha256 -or $savedReceipt.hostTests.count -ne 5) { throw 'Native build receipt did not reopen with expected outputs.' }
Write-Output "Native host tests and DLL build passed. DLL SHA-256: $($receipt.plugin.sha256)"
Write-Output "Outputs: $output"
Write-Output "Evidence: $evidence"
