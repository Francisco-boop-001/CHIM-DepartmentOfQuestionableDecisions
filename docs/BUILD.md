# Portable source build

This guide builds the companion DLL and host tests, the two Department Papyrus scripts, and the own ESP/SEQ from a clean source checkout. It uses explicit dependency, output, and evidence roots. The scripts do not install files into Skyrim or CHIM. Successful builds and synthetic tests do not establish gameplay, final NPC routing, delivery, audio, or save/load behavior.

## Toolchains

- Windows 11/10 x64, PowerShell 7, Visual Studio 2022 C++ build tools, Windows SDK, and CMake/CTest. The build locks CMake/CTest 3.28.3, MSVC 19.41.34123.0, tools 14.41.34120, platform toolset v143, and SDK 10.0.22621.0. The Visual Studio generator selects the SDK with `-A x64,version=10.0.22621.0` and the toolset with `-T v143`. This installation exposes its exact 14.41.34120 selection in `VC/Auxiliary/Build/Microsoft.VCToolsVersion.v143.default.txt`; the script checks that selected-instance file before building, then checks the generated compiler identity and toolset path. CMake's `version=` toolset option names a version-specific MSBuild props file, which this installation does not provide for that default toolset.
- 7-Zip for the Caprica release archive; the Papyrus recipe separately pins the downloaded compiler and source/flag inputs.
- .NET SDK 10.0.301 for the Mutagen authoring project. The verified executable was the `dotnet.exe` from that SDK archive (version `10.0.301`). Its archive is [the official Microsoft SDK archive](https://builds.dotnet.microsoft.com/dotnet/Sdk/10.0.301/dotnet-sdk-10.0.301-win-x64.zip), SHA-512 `38456E992C4DF0FF0AC9FC5F28FF09A88543C0FC4E4DEEDFFDA9C4EBAF852C4519ADDACF28814EA77EA42CE2D37DB812FAE5BA1FE25F06364CA5A6027036387F`; the staged archive and all extracted files were hash-compared to the official release metadata. `packaging/authoring/build.ps1` requires the .NET 10 SDK and verifies the package lock.
- PHP CLI for server tests. Journal mutation/recovery requires Linux with PHP directory-handle `fsync` support; unsupported filesystems fail closed. PHP 8.2.29 passed the isolated capability check. This requirement applies to the journal's directory sync and does not prove physical power-loss recovery.
- Node.js, Playwright, and a local Chromium-compatible browser for the UI browser tests. Supply their paths explicitly through the environment variables below; no browser or Node dependency is bundled.

## Native DLL and host tests

The native lock pins CommonLibSSE-NG v3.7.0 at commit `c4ab853d095e81e3390b282d7ba01ab2f24ebf25`, fmt 10.2.1, spdlog v1.13.0, rapidcsv v8.84, nlohmann/json v3.11.3, and the exact PrismaUI V1 header from Dwemer-Dynamics/CHIM commit `5ca1bc361d1e11a3c58067599b494b1101c2c77b`. Every upstream URL and SHA-256 is in `native/deps.lock.json`: CommonLib archive `6777B38C42CBEB82776BCC51A03F2128C3D231A635D08467144E31B08FA48554`; fmt `D368F9C39A33A3AEF800F5BE372EC1DF1C12AD57ADA1F60ADC62F24C0E348469`; spdlog `9F6763BB76FFF7DB371F5733626C83352EDD7C57899501AB00248FAFAD9CC504`; rapidcsv `6226DF921E392EB5FA88DD1EFAF26A1A7CFDC23BC00C08A7337FC0314BF8B5A8`; nlohmann/json header `9BEA4C8066EF4A1C206B2BE5A36302F8926F7FDC6087AF5D20B417D0CF103EA6`; PrismaUI header `46F77238A8C645C84E26DAC72467471B666D64FB28F143F1D369313A41175927`. The CommonLib archive's 2,067 files match the staged tree; its internal CMake metadata says 3.6.0, which is stale metadata, not the public v3.7.0 source pin. The CHIM PrismaUI header is MIT-licensed; its upstream Plugin license is in the notices component.

Run from the source root in PowerShell 7. Choose a new empty scratch root each time. The native fetcher downloads only the lockfile URLs and rejects any hash mismatch.

```powershell
$buildRoot = Join-Path $env:TEMP ('dql-build-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $buildRoot | Out-Null
$nativeDeps = Join-Path $buildRoot 'native-dependencies'
$nativeOutput = Join-Path $buildRoot 'native-output'
$nativeEvidence = Join-Path $buildRoot 'native-evidence'
$cmake = 'C:\path\to\cmake.exe' # Set this to the installed, pinned CMake 3.28.3 executable.

& .\native\fetch-dependencies.ps1 -CachePath $nativeDeps
& .\native\build.ps1 -CMakePath $cmake -DependenciesRoot $nativeDeps `
  -OutputRoot $nativeOutput -EvidenceRoot $nativeEvidence
```

The native host build runs the four CTest targets. Portable build A produced a 690,688-byte DLL with SHA-256 `1c6d0a0370a2f9e8133deef574be2f2ecc0e7ac1065e9eedddd7cc8554aea13`; the coordinator's independent clean-source build B2 produced the same DLL bytes and passed all four host tests. Its recorded compiler, toolset and SDK match the pins above; this does not establish Skyrim runtime behavior. `nativeOutput` also receives two synthetic refusal JSON fixtures from the linked production formatter. They contain only generic messages and synthetic correlations; the compose payload sentinel is omitted. To run the UI's native-producer refusal case, pass `NATIVE_REFUSAL_FIXTURE` the exact `c2-02-refusal-fixture.json` path from that fresh output. The UI test rebinds only `requestId`, `operation`, and `context` to the live synthetic browser request; it preserves the fixture's producer error object. This exercises a formatter-shaped response in the browser test; it does not invoke SKSE or a native transport.

The negative dependency preflight can be checked with:

```powershell
& .\native\tests\Build-Recipe.Tests.ps1 -CMakePath $cmake
```

## Papyrus scripts

`native/papyrus/deps.lock.json` pins official Caprica v0.3.0 (archive SHA-256 `0037E54CF3A1021E976278D9A637E8AE541AAF9EBEB8D274B6C2CFE32F4EE559`, executable SHA-256 `389FEF19A803C36342FA7F733C96B6E27EF10FCD0B171AF10427E29CBE153EC6`), the SKSE Papyrus sources at v2.0.20 (archive SHA-256 `BF6BCBD3953BD6CDCECF58763EC0CA3C58D1766B958F17ACC54BAF360C84AE01`, 128-file tree SHA-256 `C4077F1E2269E36011DDFECDF7190E37AC010E36B2885FD0C9038A8BB110D621`), and the SkyUI headers at commit `835428728e2305865e220fdfc99d791434955eb1` (two-file tree SHA-256 `80F328B9057FCFCA3F3F1734089D1748B7A15E7BE7612BC30407478078C195C6`). The Skyrim script flags are a pinned public community snapshot from commit `4ba71332b4851985240f582a76017216a3bd452f` (SHA-256 `DB28D5AE6C57AA9C4797F3E571F3E22B2443348D20CE730D0AF358F2746B1666`); they are not Bethesda-provided inputs. `fetch-dependencies.ps1` verifies all downloads and extracted trees before the build.

```powershell
$papyrusDeps = Join-Path $buildRoot 'papyrus-dependencies'
$sevenZip = 'C:\path\to\7z.exe' # Set this to an installed 7-Zip executable.
& .\native\papyrus\fetch-dependencies.ps1 -CachePath $papyrusDeps -SevenZipPath $sevenZip

$caprica = (Get-ChildItem (Join-Path $papyrusDeps 'extracted\caprica') -Filter Caprica.exe -Recurse | Select-Object -First 1).FullName
$flags = Join-Path $papyrusDeps 'flags\TESV_Papyrus_Flags.flg'
$skseScripts = (Get-ChildItem (Join-Path $papyrusDeps 'extracted\skse') -Directory -Recurse |
  Where-Object { Test-Path (Join-Path $_.FullName 'vanilla') } | Select-Object -First 1).FullName
$skyuiHeaders = (Get-ChildItem (Join-Path $papyrusDeps 'extracted\skyui') -Directory -Recurse |
  Where-Object { $_.FullName.Replace('\','/').EndsWith('/dist/Data/Scripts/Headers') } | Select-Object -First 1).FullName
$papyrusOutput = Join-Path $buildRoot 'papyrus-output'
$papyrusEvidence = Join-Path $buildRoot 'papyrus-evidence'
& .\native\papyrus\build.ps1 -CompilerPath $caprica -FlagsPath $flags `
  -SkseScriptsRoot $skseScripts -SkyuiHeadersRoot $skyuiHeaders `
  -OutputPath $papyrusOutput -EvidencePath $papyrusEvidence
```

The recipe uses Caprica's supported strict/release/final options and the existing metadata neutralizer. One verified run emitted `WorldOfDramaLlamaMCM.pex` at 2,300 bytes (SHA-256 `831af1fbdeaeae71f10f5828f706454a8e648951dfcd4992f646c091f44a43cd`) and `WorldOfDramaLlamaNative.pex` at 519 bytes (SHA-256 `50bf6d1390d7908eedf3f685c6a9f7f65f304e686e72811e51d46710c81fa84d`). An independent clean-source build produced MCM SHA-256 `bb5a339f2102ca0f300de787add4c949d86b684b192cf4a75a19fba4dca8d672` and native SHA-256 `92e3745e4787d05b0a7a96f08a2f33a5654efba029badab9fec2e8dc5ebd87f7`; binary comparison found changes only at offsets 14 and 15, within the preserved eight-byte PEX compile-time field at offsets 8–15. The neutralizer intentionally preserves this metadata, so PEX output is not byte-identical across runs. For build comparison, normalize offsets 8–15 in memory; do not rewrite the package bytes. The new PEX files are not byte-identical to the older approved files; the local API/behavior inspector reported equivalence with no differences, but that inspector is not a public build dependency and does not establish runtime execution. The build compiles only the two owned `.psc` files and does not redistribute game scripts or raw compiler outputs in the source ZIP.

## ESP and SEQ

`packaging/authoring/packages.lock.json` pins Mutagen.Bethesda.Skyrim 0.52.0 and every resolved NuGet dependency by content hash (lock SHA-256 `68264B6D8196B722789CFA4ACB7202ABEC338C0BECE3266FB13979EA01C24020`). The project restores in locked mode from the sole configured nuget.org v3 source.

```powershell
$dotnet = 'C:\path\to\dotnet.exe' # Set this to the installed .NET 10 SDK host.
$assetOutput = Join-Path $buildRoot 'game-assets'
$assetEvidence = Join-Path $buildRoot 'authoring-evidence'
& .\packaging\authoring\build.ps1 -DotNetPath $dotnet `
  -OutputRoot $assetOutput -EvidenceRoot $assetEvidence
```

The authoring script reads back and checks its fresh 185-byte ESP (SHA-256 `b4097ba98c3cd5b5227e89812b46ca2f6e2583a1953b6c25c6a4ceab8dcf0dbe`) and 4-byte SEQ (SHA-256 `cbfd218787df784c39fbc4f4fad92a8f28e2a880430c210b6d0d8dc133c07e66`). It does not open an installed game. Run its negative tool-path preflight with:

```powershell
& .\packaging\authoring\tests\Build.Tests.ps1
```

## UI and server tests

The UI tests use a locally installed Playwright module and browser. Set paths to your existing local tools and keep results in the fresh build scratch directory:

```powershell
$env:PLAYWRIGHT_MODULE_ROOT = '<absolute path to a node_modules directory containing playwright>'
$env:PRISMA_TEST_BROWSER = '<absolute path to the Chromium-compatible browser executable>'
$env:NATIVE_REFUSAL_FIXTURE = Join-Path $nativeOutput 'c2-02-refusal-fixture.json'
$env:UI_TEST_RESULTS = Join-Path $buildRoot 'ui-functional-results.json'
$env:UI_TEST_SCREENSHOT = Join-Path $buildRoot 'ui-functional.png'
$env:UI_NATIVE_REFUSAL_SCREENSHOT = Join-Path $buildRoot 'ui-native-refusal.png'
$env:UI_NATIVE_RECOVERY_SCREENSHOT = Join-Path $buildRoot 'ui-native-recovery.png'
$node = 'C:\path\to\node.exe' # Set this to the installed Node.js executable.
& $node .\ui\tests\ui-functional.cjs
& $node .\ui\tests\ui-interactions.cjs
```

`ui-render.cjs` is an additional visual fixture check; set `UI_RENDER_RESULTS_DIR` to a new temporary directory before running it. These tests stub network and native dispatch. Their screenshots show synthetic fixtures only.

On Linux with `mbstring`, `ctype`, and a PHP build that supports syncing directory handles, run the synthetic suites with `pgsql` disabled. If `ctype` is built into PHP, omit its extension-loading flag:

```sh
php -n -d extension=mbstring -d extension=ctype server/tests/foundation/run.php
php -n -d extension=mbstring -d extension=ctype server/tests/foundation/journal.php
php -n -d extension=mbstring -d extension=ctype server/tests/pcv/adapter.php
php -n -d extension=mbstring -d extension=ctype server/tests/pcv/companion_owner_seam.php
php -n -d extension=mbstring -d extension=ctype server/tests/pcv/companion_presence_reader.php
php -n -d extension=mbstring -d extension=ctype server/tests/pcv/companion_store.php
```

The older complete correspondence and inspector integration suites use separately preserved, version-pinned owner snapshots. This source ZIP omits their bulk fixture trees and full legacy drivers; those complete suites are not runnable from this source ZIP. It now includes focused correspondence tests and four exact dev.14 source fixtures for the read-store interface probe. These fixtures are test inputs, not a complete owner installation; their ownership and licensing status remain with the [Sealed Fates project](https://github.com/Francisco-boop-001/CHIM-SealedFates). No dependency license is inferred from inclusion. The shipped foundation and PCV tests above remain available. The Windows provision CGI/CLI regression additionally requires matching local PHP CLI and CGI executables; pass them as `QD_TEST_PHP_CLI` and `QD_TEST_PHP_CGI` when running `php -n -d extension=mbstring server/tests/provision-cgi.php`. It creates and removes only a fresh temporary fixture beneath the server test directory.

## Focused dev.18 checks

Extract the exact separately published Sealed Fates dev.18 source release and pass its `server/` directory as `--owner-root`; the test verifies all 13 required owner files before copying them into an isolated temporary fixture. The source-contract test also loads dev.18, then corrupts a pinned source or replaces the isolated tree with the valid dev.14 fixture, and checks that routing refuses both changes. It does not edit the supplied owner tree.

```sh
php server/tests/correspondence/dev18-source-contract.php --owner-root /absolute/path/to/SealedFates/server
php server/tests/correspondence/dev18-ingress-authority.php
php server/tests/correspondence/dev18-generation-reader.php
php server/tests/correspondence/dev18-lease-identity.php
php server/tests/correspondence/dev18-owned-close.php
php -n server/tests/correspondence/dev18-physical-mapping.php --owner-root /absolute/path/to/SealedFates/server
php -n -d extension=mbstring -d extension=ctype server/tests/correspondence/dev14-interface-compatibility.php
```

The physical-mapping probe disables `pgsql`, uses the pinned owner interface, and checks 18 form-ID/mapping cases with a SELECT-only fake database. It checks mapping semantics and connection identity; it does not execute PostgreSQL. The owned-close probe requires `pgsql` and checks that a cleanup TypeError cannot replace the primary failure. It is not a real closed-connection test. The inode-replacement assertion executes on Linux and explicitly skips that assertion on Windows. The dev.14 probe must run without `pgsql` because it supplies a synthetic database boundary.

`server/tests/dev18-pg/` supplies the synthetic SQL fixture, direct PHP API-entry driver, owner-lifecycle fixture, receipt seeder, shared fixture guard and case specification. The driver invokes the actual `qd_api_handle` with production runtime dependencies; it does not prove HTTP transport or Skyrim behavior. The guard refuses ordinary host/live invocations before loading core code. The root TEST launcher must create a network namespace different from PID 1, then create the exact fresh `/var/tmp/department-dev18-RUN_ID/stage/core` clone and a bounded `fixture-marker.json` outside that clone. The scratch root and marker must be root-owned and not writable by group or others. Unprivileged fixtures compare their own namespace with that protected marker; they do not need permission to inspect PID 1. The marker must have exactly `schema` (`department.dev18.fixture.v1`), `runId`, `coreRoot` and the current `networkNamespace`. Set `QD_DEV18_RUN_ID`, `QD_DEV18_SCRATCH_ROOT`, `QD_DEV18_FIXTURE_MARKER` and `QD_CHIM_ROOT` to those fixture values. Use only a new PostgreSQL cluster in a loopback-only namespace; the pinned core database reader uses localhost:5432/database dwemer, so changing environment database settings alone does not isolate it. Run measured API calls as an unprivileged OS user and the non-superuser database role dwemer. The owner fixture initializes only that private database after the no-initialization read check. The machine-specific TEST orchestration and raw receipts are kept separately; the release receipts identify executed cases, the static legacy-pin case and reused evidence separately.

## Release candidate validation

The candidate builder defaults to validation only. A release candidate requires a new root-owned `department-pre-alpha` freeze with exactly the accepted `privateRoute`, `critique`, `ui`, `native`, and `runtimeRelease` gates. Pass fresh DLL, PEX, ESP, and SEQ paths below one generated-input root, an explicit public-source allowlist, a new freeze path, and a new candidate output directory. The builder compares each input to the freeze and refuses stale or additional files. The independent auditor reopens the outer archive and all four component ZIPs, validates their exact members and canonical source bytes, verifies the blank INI and seven-file PCV overlay, and rejects unsafe archive entries. Package creation neither installs nor publishes anything. The release supplies `input-freeze.json` and `verification-receipts.zip`; place the freeze and five receipts at their recorded source-relative paths when auditing a release. Use the five canonical generated assets extracted from its game-data ZIP under one generated-input root. Freshly compiled PEX timestamps differ, as described above, so a fresh compile cannot be substituted silently for a frozen asset.

## Identity and acceptance limits

The generated assets are a 185-byte ESP and a 4-byte SEQ; they use one light-plugin slot and no full-plugin slot. The candidate bundles nine game files, 24 server files, seven notices, and the explicit source allowlist at the archive root. It does not reuse an older game-data ZIP. The PCV 0.1.22 overlay is seven flat files and must be installed over the complete supported owner release.

Before testing, read the [project overview](../README.md), [test-profile setup](TEST-PROFILE-SETUP.md), [state-recovery policy](STATE-RECOVERY-POLICY.md), and [verification record](VERIFICATION.md). The verification record and package guide retain the stock router's same-name final body-selection limitation. Consumed PCV text is acceptance at the owner preprocessing boundary; it does not prove model processing or audio. Queued correspondence is not receipt-confirmed delivery or an observed transfer. The eight game checks in [the release guide](../packaging/COMPANION-ONLY-TEST-PACKAGE.md) remain pending until performed in Skyrim.
