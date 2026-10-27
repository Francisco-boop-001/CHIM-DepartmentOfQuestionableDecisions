# Portable source build

## Prealpha.22 Sealed Fates dev.27 pairing

Prealpha.22 starts from the exact published prealpha.21 installable ZIP (SHA-256 `53cc3fcf7d625a8e2694a5ab857c39b488854d6060bcf87bf346fdc67a2081d0`). It replaces only the 30-member Department DWPkg. Its two version manifests, `server/adapters/correspondence.php`, and regenerated `checksums.sha256` are the complete DWPkg delta; the Department `server/globals.php` bytes stay unchanged. The other 21 of 22 MO2 members remain byte-identical, including the DLL (`bb901e6e423c19f63eb804f8303012dbfd8f759274ad719d31969076218cb6ef`), UI, PCV companion 11, Brainwash dev.3, and other owner payloads. ZIP entries use 2026-10-26 00:00:10, after prealpha.21's 00:00:08.

The exact Sealed Fates dev.27 owner is commit `d1eaf714a7661a1f785a4d8c2131c3565a6eddee`, with manifest SHA-256 `157610b7d2d79cf68d6203c61fb7768399b3d5b6d4e0bdfc56651d2d0ab3b22d`. The adapter keeps the exact 19-file runtime closure; only the manifest pin changes from dev.26, while the other 18 runtime hashes remain identical. The separate `lib/view.php` change is outside that closure.

`packaging/build-installable-prealpha22.py` accepts one canonical `compatibility-checks.json` file, a full Department source commit that must equal `HEAD`, and the exact baseline ZIP. The receipt has exactly `schemaVersion`, `sourceCommit`, `ownerVersion`, `ownerCommit`, `ownerClosurePins`, `adapterSha256`, `protocolSha256`, `checks`, and `limits`. Its four check rows must be `dev27SourceContract`, `dev26Regression`, `pairingReadiness`, and `protectedPayload`, each with `status: "passed"` and a receipt SHA-256. The owner closure must match the selected adapter's literal 19-file dev.27 map. Limits are fixed to `sourceOnly: true`, `database: false`, `http: false`, and `skyrim: false`. Unknown fields, other source/owner pins and unpassed checks are rejected. The proof ZIP contains only the canonical aggregate receipt and fixed package provenance; raw receipts and artifacts remain outside the installable and source archive.

Build and audit into a new output directory outside the repository:

```powershell
$Git = 'C:\Program Files\Git\cmd\git.exe'
$Python = 'C:\Program Files\Python312\python.exe'
$Commit = '<frozen-source-commit>'
$Baseline = 'K:\ActorwrightExchange\artifacts\department-sharmat-connect21-20261026\public-downloads\WorldOfDramaLlama-0.1.0-prealpha.21.zip'
$Checks = 'K:\ActorwrightExchange\artifacts\department-dev27-20261026\compatibility-checks.json'
$Output = 'K:\ActorwrightExchange\artifacts\department-dev27-20261026\package-prealpha22'
& $Python -B packaging/build-installable-prealpha22.py build --prealpha21-zip $Baseline --compatibility-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
& $Python -B packaging/build-installable-prealpha22.py audit --prealpha21-zip $Baseline --compatibility-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
```

The output has the regular installable ZIP, explicit-allowlist source ZIP, compatibility-proof ZIP and GNU-compatible LF `SHA256SUMS.txt`. The source archive is rebuilt from Git blobs at the selected commit. This source/package gate does not rerun HTTP, database, provider or Skyrim behavior; the prior prealpha.21 handshake evidence remains limited to its unchanged ingress and DLL path. Final archive-consumer, clean-source rebuild and public-byte checks are pending.

## Prealpha.21 SHARMAT connection repair

Prealpha.21 starts from the exact published prealpha.20 installable ZIP (SHA-256 `2a60c5ac9d012c7ee30d6286b876ec3d117566ea1374ff2e9801bed34416b720`). It changes only the Department DWPkg: its two version manifests, new `server/globals.php`, and regenerated checksum file. That DWPkg has 30 members. The other 21 of 22 top-level MO2 payloads remain byte-identical, including the bundled PCV companion 11, all owner pins, UI/game assets, and the prealpha.16 DLL (`bb901e6e423c19f63eb804f8303012dbfd8f759274ad719d31969076218cb6ef`). Entries use timestamp 2026-10-26 00:00:08, after prealpha.20's 00:00:06. This is a server-only connection-ingress fix; it does not rebuild a DLL or edit CHIM/SHARMAT core or an owner adapter.

The builder is `packaging/build-installable-prealpha21.py`. It requires the canonical `sharmat-checks.json`, `ingress-verification.json`, `compatibility.json`, and `focused-checks.json` in `--checks-dir`. It reads only those four named files, binds their hashes and exact source hashes to the selected immutable source commit, and copies only those receipts plus package provenance into the proof ZIP. Raw fixtures, credentials, logs, and unrelated files in the evidence directory are never traversed or copied.

The aggregate receipt has exactly `schemaVersion`, `status`, `sourceHashes`, `checks`, `limits`, and `fullHostMain`. Its three `checks` rows bind ingress, compatibility and focused evidence by fixed filename and SHA-256; all source hashes must match the selected commit. `limits` has the fixed isolated-test-only HTTP/native-validator values, with Skyrim, provider, native build and live checks false. `fullHostMain` must be true. Each child receipt has a closed field set and passed schema-1 status; its nonempty checks map contains only safe names mapped to `true`, limits contain only booleans, and `rawEvidence` is a nonempty safe-filename-to-SHA-256 map. The ingress receipt also binds `http: true`, `nativeValidator: true`, the accepted DLL hash, `fullHostMain: true`, CHIM host commit `0ec590b42c8087860138bb4e9aab470049ff9a2b`, and SHARMAT commit `d6c6da595eb961f338245d6e4d044fe6e86279f0`. Compatibility evidence requires exactly PCV 0.1.24, MP 0.1.30, SAS 0.1.12, Sealed Fates dev.26 and Malpractice dev.3 all marked ready, plus `protectedSourceCount: 75`. Focused evidence binds the global hook and its regression test. Unknown fields are rejected. These JSON records bind reported results and sources; the separate raw verifier evidence remains the authority for executed checks.

The input receipts bind the accepted private TEST2 main.php/SHARMAT HTTP run, unchanged DLL wire validation, five-owner source contracts and focused regressions. The HTTP fixture uses minimal synthetic schema/profile rows and PHP test mode; all owner capability values are false, so it does not certify owner operations or Skyrim startup. The immutable source checkpoint precedes publication; final package, clean-rebuild and public-byte closure are recorded below. Do not use placeholder receipts to build a release. The source ZIP follows `packaging/public-source-allowlist.json`; evidence is not an installable payload. The regular ZIP, source ZIP, SHARMAT proof ZIP, and `SHA256SUMS.txt` are separate outputs.

After the frozen source commit and real evidence directory are available, build and audit into a new directory outside the repository:

```powershell
$Git = 'C:\Program Files\Git\cmd\git.exe'
$Python = 'C:\Program Files\Python312\python.exe'
$Commit = '<frozen-source-commit>'
$Baseline = 'K:\ActorwrightExchange\artifacts\department-pcv24-20261026\public-downloads\WorldOfDramaLlama-0.1.0-prealpha.20.zip'
$Checks = 'K:\ActorwrightExchange\artifacts\department-sharmat-connect21-20261026'
$Output = 'K:\ActorwrightExchange\artifacts\department-sharmat-connect21-20261026\package-prealpha21'
& $Python -B packaging/build-installable-prealpha21.py build --prealpha20-zip $Baseline --checks-dir $Checks --source-commit $Commit --output-dir $Output --git $Git
& $Python -B packaging/build-installable-prealpha21.py audit --prealpha20-zip $Baseline --checks-dir $Checks --source-commit $Commit --output-dir $Output --git $Git
```

Published [prealpha.21](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.21) at 2026-10-27T00:17:19Z from immutable source `b34c86f59d5cebc3de84a420c5f108b056e865e1`. Root verified 22 installable members with 21 unchanged payloads, the four-file Department DWPkg delta, all 231 public source members against Git, five proof members and all three bundled archives through the unmodified CHIM validator without activation. A clean detached-source rebuild reproduced all four assets byte for byte. Installable: 619778 bytes, SHA-256 `53cc3fcf7d625a8e2694a5ab857c39b488854d6060bcf87bf346fdc67a2081d0`. Draft and anonymous public downloads match local bytes, GitHub digests/sizes and GNU checksums; all 19 prior releases, 115 assets and 38 tag refs remain unchanged. Actual isolated HTTP/SHARMAT connection and unchanged DLL wire validation passed; five-owner compatibility is source/readiness evidence, not owner-operation or Skyrim acceptance. Live/core/running game were untouched and no DLL was rebuilt. TEST2 is Stopped and the owned cluster/scratch were removed. This docs-only completion leaves the immutable source tag and release assets unchanged.

To reproduce the published bytes, use a clean checkout of tag `v0.1.0-prealpha.21`, extract the four canonical input receipts from the published SHARMAT proof ZIP into a separate checks directory, and run this recipe with HEAD/source-commit equal to `b34c86f59d5cebc3de84a420c5f108b056e865e1`. The final publication record is maintained in [verification](VERIFICATION.md). See [the install guide](../packaging/COMPANION-ONLY-TEST-PACKAGE.md).

## Last published paired installable recipe: prealpha.20

The published builder starts from the exact published prealpha.19 ZIP (SHA-256 `d7481f55074025650628644df7e914ad78bc1f6cf1762faac3d3fac7a3e55a93`) and replaces only the Department DWPkg and PCV companion DWPkg. The other 20 of 22 installable members must remain byte-identical, including the accepted DLL at SHA-256 `bb901e6e423c19f63eb804f8303012dbfd8f759274ad719d31969076218cb6ef` and all UI/game assets. Canonical entries use 2026-10-26 00:00:06. The release pairs Department `0.1.0-prealpha.20`, companion `0.1.0-prealpha.11`, and PCV owner `0.1.24` at source commit `ce0a998773237a9fc91f6914725a327d9e621718` (release tag `private_conversation-v0.1.24`; companion 11 is bundled, and PCV controls require owner24). It does not support running the new Department timeout path against owner `0.1.23`. Other plugin pins remain Mind Poisoning `0.1.30`, Sworn & Scorned `0.1.12`, Sealed Fates dev.26 with its existing maps, and Malpractice Makes Perfect dev.3. This release does not rebuild the DLL or change the native UI or other plugin payloads.

The frozen-source build requires `paired-checks.json`, the exact frozen owner commit and manifest pins, plus five passing checks: `ownerTimeouts`, `overlaidTimeouts`, `overlayMigration`, `pcvSourceContract`, and `otherPlugins`. The canonical raw evidence names are `pcv-owner-timeouts.json`, `pcv-overlay-timeouts.json`, `overlay-migration-results.json`, `pcv-source-contract.json`, and `other-plugin-compatibility.json`. The overlay migration proof separately binds the preserved `.23` backup pins and the `.24` base-to-postimage source manifest. Test evidence stays outside the installable. Build outputs are the regular installable ZIP, source ZIP, `-paired-proof.zip`, and `SHA256SUMS.txt`.

The package and source checks do not imply an HTTP/provider operation, Skyrim acceptance, native build, or whole-request deadline. The focused owner and overlaid timeout checks use an isolated test database. The upgrade preserves the existing verified `.23` backup automatically; no manual backup handling or browser/Linux approval is part of this pairing.

Published [prealpha.20](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.20) at 2026-10-26T20:53:15Z from immutable source `cc33082885aa938d588eeaf5462e67b557ab282e`, paired with PCV 0.1.24 at `ce0a998773237a9fc91f6914725a327d9e621718` and bundled PCV companion 11. Root verified 22 installable members with 20 unchanged payloads, 227 Git-matched public source files, all three new DWPkgs through the unmodified CHIM archive validator, and all four draft/anonymous-public asset downloads against local bytes, GitHub digests and GNU checksums. Installable: 618763 bytes, SHA-256 `2a60c5ac9d012c7ee30d6286b876ec3d117566ea1374ff2e9801bed34416b720`. All 18 prior releases, 111 assets and 36 tag refs remain unchanged. This docs-only completion leaves the tag and assets immutable.

See [the PCV 0.1.24 compatibility note](PCV24-COMPATIBILITY.md) and [verification record](VERIFICATION.md).

To reproduce the published bytes, check out immutable tag `v0.1.0-prealpha.20` (source `cc33082885aa938d588eeaf5462e67b557ab282e`) in a clean checkout and supply the published `paired-checks.json` extracted from the paired-proof ZIP. The source commit must equal `HEAD`; use a new output directory outside the repository. The baseline path below is the immutable published prealpha.19 archive.

```powershell
$Git = 'C:\Program Files\Git\cmd\git.exe'
$Python = 'C:\Program Files\Python312\python.exe'
$Root = (Get-Location).Path
$Commit = (& $Git -c "safe.directory=$Root" rev-parse HEAD).Trim()
$Baseline = 'K:\ActorwrightExchange\artifacts\department-dev26-20261026\public-downloads\WorldOfDramaLlama-0.1.0-prealpha.19.zip'
$Checks = 'K:\ActorwrightExchange\artifacts\department-pcv24-20261026\paired-checks.json'
$Output = 'K:\ActorwrightExchange\artifacts\department-pcv24-20261026\package-prealpha20'
& $Python -B packaging/build-installable-prealpha20.py build --prealpha19-zip $Baseline --paired-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
& $Python -B packaging/build-installable-prealpha20.py audit --prealpha19-zip $Baseline --paired-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
```

## Historical published prealpha.19 recipe

The .19 builder starts from the exact published prealpha.18 installable ZIP (SHA-256 `24c558a938db919fc39ac5b16ca0a15e46738b0d9d82e4332c6711f3c6c0bd02`). It replaces only the Department DWPkg; only its two version manifests, `server/adapters/correspondence.php`, and regenerated checksums may change. The other 21 installable payloads stay byte-identical, including the prealpha.18 DLL and PCV companion package. Every output ZIP entry uses timestamp 2026-10-26 00:00:04, two seconds later than prealpha.18, so CHIM's mtime-only package selection prefers the new Department package when both versions are visible. The builder does not compile a DLL. The build writes `WorldOfDramaLlama-0.1.0-prealpha.19.zip`, `WorldOfDramaLlama-0.1.0-prealpha.19-source.zip`, `WorldOfDramaLlama-0.1.0-prealpha.19-compatibility-proof.zip`, and `SHA256SUMS.txt`.

To reproduce the published bytes, check out immutable tag `v0.1.0-prealpha.19` (source `138f6d19acc5b4751af16c67b276949e6169c02c`), then run after the coordinator supplies `compatibility-checks.json`. The 40-character source commit must equal `HEAD`; the output must be a new directory outside the repository.

```powershell
$Git = 'C:\Program Files\Git\cmd\git.exe'
$Python = 'C:\Program Files\Python312\python.exe'
$Root = (Get-Location).Path
$Commit = (& $Git -c "safe.directory=$Root" rev-parse HEAD).Trim()
$Baseline = 'K:\ActorwrightExchange\artifacts\department-maintainer-fixes-20261026\public-downloads\WorldOfDramaLlama-0.1.0-prealpha.18.zip'
$Checks = 'K:\ActorwrightExchange\artifacts\department-dev26-20261026\compatibility-checks.json'
$Output = 'K:\ActorwrightExchange\artifacts\department-dev26-20261026\prealpha19'
& $Python -B packaging/build-installable-prealpha19.py build --prealpha18-zip $Baseline --compatibility-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
& $Python -B packaging/build-installable-prealpha19.py audit --prealpha18-zip $Baseline --compatibility-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
```

The compatibility receipt is canonical UTF-8 JSON with LF endings and no BOM. Its exact top-level fields are `schemaVersion`, `sourceCommit`, `ownerVersion`, `ownerCommit`, `ownerClosurePins`, `adapterSha256`, `protocolSha256`, `checks`, and `limits`. The owner identity is dev.26 at commit `7fd78802348d5ee7dabee9506ff6ede1c25c0657`; `ownerClosurePins` must exactly match all 19 literal pins in the selected committed adapter. Adapter and protocol hashes are checked against that same Git commit. The four check rows are `dev26SourceContract`, `dev25Regression`, `pairingReadiness`, and `protectedPayload`; each row has only `name`, `status`, and `receiptSha256`, and all four must pass. Limits are fixed to `sourceOnly: true`, `database: false`, `http: false`, and `skyrim: false`. Raw check evidence remains under `artifacts/department-dev26-20261026` and is not copied into the installable.

This is source/package pairing evidence. It does not establish a live CHIM server, HTTP/provider or database behavior, newer dev.26 poll-lock runtime behavior, native startup, or Skyrim acceptance. The owner release's newer poll-lock runtime proof remains unperformed. [Prealpha.19](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.19) is published after those checks; later main changes only record publication. See the [dev.26 compatibility note](DEV26-COMPATIBILITY.md) and [verification record](VERIFICATION.md).

## Historical published prealpha.18 recipe

The .18 builder reuses the exact published .17 installable ZIP and the unchanged accepted DLL. The installable ZIP entries use deterministic timestamp 2026-10-26 00:00:02, two seconds later than the exact .17 Department/PCV entry timestamps, so CHIM's mtime-only package selection prefers the new version when old and new paths coexist. It updates the Department package to `.18`, the PCV companion package to `.10`, and `app.js`; it requires the other 19 MO2 member payloads to remain byte-identical; their ZIP entry metadata uses the release timestamp. It also requires the canonical maintenance receipt for the frozen source commit. The source ZIP contains only the committed public-source allowlist; the proof ZIP contains the compact receipt and package provenance, not the raw audit fixtures.

Run from a clean, committed checkout after the coordinator supplies `maintenance-checks-prealpha18.json`. The source commit must be the checkout's exact `HEAD`; output must be a new directory outside the repository.

```powershell
$Git = 'C:\Program Files\Git\cmd\git.exe'
$Python = 'C:\Program Files\Python312\python.exe'
$Root = (Get-Location).Path
$Commit = (& $Git -c "safe.directory=$Root" rev-parse HEAD).Trim()
$Baseline = 'K:\ActorwrightExchange\artifacts\department-dev25-20261026\public-downloads\WorldOfDramaLlama-0.1.0-prealpha.17.zip'
$Checks = 'K:\ActorwrightExchange\artifacts\department-maintainer-fixes-20261026\maintenance-checks-prealpha18.json'
$Output = 'K:\ActorwrightExchange\artifacts\department-maintainer-fixes-20261026\prealpha18'
& $Python -B packaging/build-installable-prealpha18.py build --prealpha17-zip $Baseline --maintenance-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
& $Python -B packaging/build-installable-prealpha18.py audit --prealpha17-zip $Baseline --maintenance-checks $Checks --source-commit $Commit --output-dir $Output --git $Git
```

The receipt is canonical UTF-8 JSON with LF endings and no BOM. It binds the full 40-character source commit to the exact `.17` baseline and hashes of the selected UI, runtime, overlay, PCV pin, package manifest, builder, and focused test sources. It carries fixed check records for UI startup 28/28, UI behavior 51/51, PostgreSQL read bounds 11/11, the PCV adapter PostgreSQL probe 5/5, and overlay upgrade 7/7. Its two regression controls are recorded as `expected-red` (4/8 and 3/5); they are not passing checks. The builder validates those names, counts, evidence filenames, digest shapes, limits, and source hashes against Git. Raw evidence remains under `K:\ActorwrightExchange\artifacts\department-maintainer-fixes-20261026` and is not copied into the installable ZIP.

The builder's limits are explicit: no production database or known production PostgreSQL version, live server, HTTP/provider, Skyrim, `SKSEPlugin_Load`, or native DLL build is claimed. The PCV `qd_pcv_stock_main_matches_pin()` guard rejects the supplied server 3.4.2 branch commit `d3945ecb65d4338a63978c81c3d9b09a936da585`: its `main.php` SHA-256 is `ef2ff9a50203820ca5416f334504f352793ee5413053e3b7beee1a222c8e721b`, while the accepted LF and CRLF hashes are `3619049e6d54493a77b19c09f8b70420e3c35427545a8bb6e95e93c6fe57ebac` and `9686a446bb4a2e2c02def2b59c87190734b30ba4382781bd71ea0df96baf6ff9`. This is a refusal of that public source snapshot; no immutable stable installer was available for comparison. See [the current verification record](VERIFICATION.md) for evidence and boundaries.

## Historical: prealpha.12 compatibility package

This candidate reuses the exact published prealpha.12 game binaries and embedded PCV and Brainwash packages. It changes the Department server package and the letter-state UI; do not rebuild the DLL for this update. The builder requires the four accepted pairing receipts from the isolated dev.23 run. See [the acceptance record](DEV23-COMPATIBILITY.md) for current status and proof limits.

The archive builder checks package identity and receipt consistency. Publication additionally requires both owners' acceptance and the [Sealed Fates release preflight gate](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/29866b20925c4d1b3e0aa49b6014332dcc868e7f/scripts/release-preflight.py), including required coverage, case results, owned-database cleanup and the preserved-running TEST distro lifecycle. Building an archive does not replace those runtime checks.

Run from the immutable release source, with a verified prealpha.12 MO2 ZIP, the accepted receipt directory, and a new output directory outside this checkout:

```powershell
py -3 -B packaging/build-installable-prealpha13.py build --prealpha12-zip $baseZip --pairing-dir $acceptedReceipts --source-commit $releaseCommit --output-dir $newOutput --git $git
py -3 -B packaging/build-installable-prealpha13.py audit --prealpha12-zip $baseZip --pairing-dir $acceptedReceipts --source-commit $releaseCommit --output-dir $newOutput --git $git
```

The normal installation uses only `WorldOfDramaLlama-0.1.0-prealpha.13.zip`. The source ZIP and four-JSON pairing ZIP are optional developer downloads. `SHA256SUMS.txt` uses ordinary LF checksum lines. The full native/Papyrus build sections below are historical recipes for releases that changed those files; prealpha.18 does not use them or rebuild those assets.

This guide builds the companion DLL and host tests, the two Department Papyrus scripts, and the own ESP/SEQ from a clean source checkout. It uses explicit dependency, output, and evidence roots. The scripts do not install files into Skyrim or CHIM. Successful builds and synthetic tests do not establish gameplay, final NPC routing, delivery, audio, or save/load behavior.

## Toolchains

- Windows 11/10 x64, PowerShell 7, Visual Studio 2022 C++ build tools, Windows SDK, and CMake/CTest. The build locks CMake/CTest 3.28.3, MSVC 19.41.34123.0, tools 14.41.34120, platform toolset v143, and SDK 10.0.22621.0. The Visual Studio generator selects the SDK with `-A x64,version=10.0.22621.0` and the toolset with `-T v143`. This installation exposes its exact 14.41.34120 selection in `VC/Auxiliary/Build/Microsoft.VCToolsVersion.v143.default.txt`; the script checks that selected-instance file before building, then checks the generated compiler identity and toolset path. CMake's `version=` toolset option names a version-specific MSBuild props file, which this installation does not provide for that default toolset.
- 7-Zip for the Caprica release archive; the Papyrus recipe separately pins the downloaded compiler and source/flag inputs.
- .NET SDK 10.0.301 for the Mutagen authoring project. The verified executable was the `dotnet.exe` from that SDK archive (version `10.0.301`). Its archive is [the official Microsoft SDK archive](https://builds.dotnet.microsoft.com/dotnet/Sdk/10.0.301/dotnet-sdk-10.0.301-win-x64.zip), SHA-512 `38456E992C4DF0FF0AC9FC5F28FF09A88543C0FC4E4DEEDFFDA9C4EBAF852C4519ADDACF28814EA77EA42CE2D37DB812FAE5BA1FE25F06364CA5A6027036387F`; the staged archive and all extracted files were hash-compared to the official release metadata. `packaging/authoring/build.ps1` requires the .NET 10 SDK and verifies the package lock.
- PHP CLI for server tests. Journal mutation/recovery requires Linux with PHP directory-handle `fsync` support; unsupported filesystems fail closed. PHP 8.2.29 passed the isolated capability check. This requirement applies to the journal's directory sync and does not prove physical power-loss recovery.
- Node.js, Playwright, and a local Chromium-compatible browser for the UI browser tests. Supply their paths explicitly through the environment variables below; no browser or Node dependency is bundled.

## Native DLL and host tests

The native lock pins CommonLibSSE-NG v3.7.0 at commit `c4ab853d095e81e3390b282d7ba01ab2f24ebf25`, fmt 10.2.1, spdlog v1.13.0, rapidcsv v8.84, nlohmann/json v3.11.3, and the exact PrismaUI V1 header from Dwemer-Dynamics/CHIM commit `5ca1bc361d1e11a3c58067599b494b1101c2c77b`. Every upstream URL and SHA-256 is in `native/deps.lock.json`: CommonLib archive `6777B38C42CBEB82776BCC51A03F2128C3D231A635D08467144E31B08FA48554`; fmt `D368F9C39A33A3AEF800F5BE372EC1DF1C12AD57ADA1F60ADC62F24C0E348469`; spdlog `9F6763BB76FFF7DB371F5733626C83352EDD7C57899501AB00248FAFAD9CC504`; rapidcsv `6226DF921E392EB5FA88DD1EFAF26A1A7CFDC23BC00C08A7337FC0314BF8B5A8`; nlohmann/json header `9BEA4C8066EF4A1C206B2BE5A36302F8926F7FDC6087AF5D20B417D0CF103EA6`; PrismaUI header `46F77238A8C645C84E26DAC72467471B666D64FB28F143F1D369313A41175927`. The CommonLib archive's 2,067 files match the staged tree; its internal CMake metadata says 3.6.0, which is stale metadata, not the public v3.7.0 source pin. The CHIM PrismaUI header is MIT-licensed; its upstream Plugin license is in the notices component.

For prealpha.16, `SKSEPluginInfo::PluginVersion` is `0.1.16.0`: the four-component packed SKSE version cannot represent `0.1.0.16`. The package and release identity remains `0.1.0-prealpha.16`.

Run from the source root in PowerShell 7. Choose a new empty scratch root each time. The native fetcher downloads only the lockfile URLs and rejects any hash mismatch.

```powershell
$buildRoot = Join-Path $env:TEMP ('dql-build-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $buildRoot | Out-Null
$nativeDeps = Join-Path $buildRoot 'native-dependencies'
$nativeOutput = Join-Path $buildRoot 'native-output'
$nativeEvidence = Join-Path $buildRoot 'native-evidence'
$cmake = 'C:\path\to\cmake.exe' # Set this to the installed, pinned CMake 3.28.3 executable.
$php = (Get-Command php -ErrorAction Stop).Source # Must resolve to the pinned PHP 8.3.35 CLI.

& .\native\fetch-dependencies.ps1 -CachePath $nativeDeps
& .\native\build.ps1 -CMakePath $cmake -PhpPath $php -DependenciesRoot $nativeDeps `
  -OutputRoot $nativeOutput -EvidenceRoot $nativeEvidence
```

The prealpha.7 native host build ran four CTest targets. Portable build A produced a 690,688-byte DLL with SHA-256 `1c6d0a0370a2f9e8133deef574be2f2ecc0e7ac1065e9eedddd7cc8554aea13`; the coordinator's independent clean-source build B2 produced the same DLL bytes and passed all four host tests. Prealpha.8 adds the connection protocol target; the prealpha.16 bootstrap-validator change adds a sixth CTest target, so the current native host suite has six CTest targets. Compiler, toolset and SDK pins remain as recorded above; these source/build checks do not establish Skyrim runtime behavior. `nativeOutput` also receives synthetic refusal JSON fixtures from the linked production formatter. They contain only generic messages and synthetic correlations; the compose payload sentinel is omitted. To run the UI's native-producer refusal case, pass `NATIVE_REFUSAL_FIXTURE` the exact `c2-02-refusal-fixture.json` path from that fresh output. The UI test rebinds only `requestId`, `operation`, and `context` to the live synthetic browser request; it preserves the fixture's producer error object. This exercises a formatter-shaped response in the browser test; it does not invoke SKSE or a native transport.

The negative dependency preflight can be checked with:

```powershell
& .\native\tests\Build-Recipe.Tests.ps1 -CMakePath $cmake -PhpPath $php
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

## Exact dev.20 checks and pairing

For this separate candidate, use the exact Sealed Fates `correspondence-v0.1.0-dev.20` source, commit `39d797673dbede1147cc990dd7b23f25668bb4c6`. Pass its `server/` directory to these host probes:

```sh
php -n -d extension=mbstring server/tests/correspondence/dev20-source-contract.php --owner-root /absolute/path/to/SealedFates/server
php -n -d extension=mbstring server/tests/correspondence/dev20-closed-unknown.php --owner-root /absolute/path/to/SealedFates/server
php -n -d extension=mbstring server/tests/correspondence/dev20-physical-mapping.php --owner-root /absolute/path/to/SealedFates/server
php -n server/tests/correspondence/check-owner-pairing.php --owner-root /absolute/path/to/SealedFates/server
```

The first three probes check source identity, closed-document evidence and physical-mapping semantics without PostgreSQL. The pairing CLI runs the production readiness checks without loading owner runtime; [PAIRING-CHECK.md](PAIRING-CHECK.md) specifies its deterministic output and eight-case self-check. Readiness alone is not runtime compatibility.

`server/tests/dev20-pg/` supplies the dev.20 private-fixture helpers and 33-case specification. It follows the dev.18 isolation contract above, with `dev20` in scratch paths, schema and environment variable names. The machine-specific guarded launcher and SQL scenarios are retained with the identified execution receipts; these six helpers alone are not a complete PostgreSQL runner. The [compatibility record](DEV20-COMPATIBILITY.md) distinguishes actual facade execution, unchanged evidence reuse and pending gameplay checks. No dev.19 support is inferred.

Owner releases must explicitly select a tested Department pairing or web-only publication. A tested-pair receipt binds source readiness, the actual PostgreSQL source-input freeze and its accepted runtime gate; a source-only pass cannot substitute for it. Existing release tags remain immutable.

## Release candidate validation

The candidate builder defaults to validation only. A release candidate requires a new root-owned `department-pre-alpha` freeze with exactly the accepted `privateRoute`, `critique`, `ui`, `native`, and `runtimeRelease` gates. Pass fresh DLL, PEX, ESP, and SEQ paths below one generated-input root, an explicit public-source allowlist, a new freeze path, and a new candidate output directory. The builder compares each input to the freeze and refuses stale or additional files. The independent auditor reopens the outer archive and all four component ZIPs, validates their exact members and canonical source bytes, verifies the blank INI and seven-file PCV overlay, and rejects unsafe archive entries. Package creation neither installs nor publishes anything. The release supplies `input-freeze.json` and `verification-receipts.zip`; place the freeze and five receipts at their recorded source-relative paths when auditing a release. Use the five canonical generated assets extracted from its game-data ZIP under one generated-input root. Freshly compiled PEX timestamps differ, as described above, so a fresh compile cannot be substituted silently for a frozen asset.

### Release checksum manifest

Stage the nine release files below in a fresh, flat directory, then write its checksum manifest. The helper accepts only the explicit file set, excludes `SHA256SUMS.txt` itself, writes lowercase hashes as ASCII LF lines, and refuses to replace a different existing manifest.

```powershell
$releaseAssetsDirectory = 'C:\path\to\fresh-release-assets'
& .\packaging\write-release-checksums.ps1 -ReleaseDirectory $releaseAssetsDirectory -AssetNames @(
  'input-freeze.json'
  'package-manifest.json'
  'PRE-ALPHA-RELEASE.md'
  'verification-receipts.zip'
  'WorldOfDramaLlama-department-pre-alpha-candidate.zip'
  'WorldOfDramaLlama-department-pre-alpha-game-data.zip'
  'WorldOfDramaLlama-department-pre-alpha-notices.zip'
  'WorldOfDramaLlama-department-pre-alpha-server.zip'
  'WorldOfDramaLlama-department-pre-alpha-source.zip'
)

# Optional: set SHA256SUM_EXE to an explicit sha256sum executable to run GNU verification too.
& .\packaging\tests\ReleaseChecksums.Tests.ps1
```

## Identity and acceptance limits

The generated assets are a 185-byte ESP and a 4-byte SEQ; they use one light-plugin slot and no full-plugin slot. The candidate bundles nine game files, 24 server files, seven notices, and the explicit source allowlist at the archive root. It does not reuse an older game-data ZIP. The PCV 0.1.23 overlay is seven flat files and must be installed over the complete supported owner release.

Before testing, read the [project overview](../README.md), [test-profile setup](TEST-PROFILE-SETUP.md), [state-recovery policy](STATE-RECOVERY-POLICY.md), and [verification record](VERIFICATION.md). The verification record and package guide retain the stock router's same-name final body-selection limitation. Consumed PCV text is acceptance at the owner preprocessing boundary; it does not prove model processing or audio. Queued correspondence is not receipt-confirmed delivery or an observed transfer. The eight game checks in [the release guide](../packaging/COMPANION-ONLY-TEST-PACKAGE.md) remain pending until performed in Skyrim.

## Build the installable prealpha.8 package

Use a reviewed prealpha.8 x64 DLL, the verified prealpha.7 game-data ZIP, and the full commit ID of the current source checkout. The builder checks the base ZIP and reuses only the Department ESP, SEQ, and two Papyrus files; it takes the UI, blank INI, notices, server components, and source ZIP from committed allowlisted blobs. It writes a normal MO2 mod-root ZIP, a source ZIP, two standalone DWPkg audit copies, and `SHA256SUMS.txt`. The checksum manifest covers only the two flat ZIP assets intended for publication; each DWPkg has its own embedded manifest and checksum file. The standalone component copies are audit evidence, while the MO2 ZIP embeds those same packages at the CHIM synchronization paths. The builder does not compile the supplied DLL, install the mod, or publish assets.

Run from the source root in PowerShell and choose a new output directory outside the checkout:

~~~powershell
$python = 'C:\path\to\python.exe' # Python 3.12 or later.
$git = 'C:\path\to\git.exe'
$dll = 'C:\path\to\reviewed\WorldOfDramaLlama.dll'
$prealpha7GameZip = 'C:\path\to\WorldOfDramaLlama-department-pre-alpha-game-data.zip'
$releaseCommit = (& $git rev-parse HEAD).Trim()
$packageOutput = Join-Path $env:TEMP ('dql-prealpha8-package-' + [guid]::NewGuid().ToString('N'))

& $python .\packaging\build-installable-prealpha8.py build `
  --dll $dll `
  --prealpha7-game-zip $prealpha7GameZip `
  --source-commit $releaseCommit `
  --output-dir $packageOutput `
  --git $git
if ($LASTEXITCODE -ne 0) { throw "Package build failed with exit code $LASTEXITCODE" }

& $python .\packaging\build-installable-prealpha8.py audit `
  --source-commit $releaseCommit `
  --output-dir $packageOutput `
  --git $git
if ($LASTEXITCODE -ne 0) { throw "Package audit failed with exit code $LASTEXITCODE" }
~~~

The main archive is `WorldOfDramaLlama-0.1.0-prealpha.8.zip`; install that single ZIP as an ordinary MO2 mod. The source archive is `WorldOfDramaLlama-0.1.0-prealpha.8-source.zip`. The two component audit copies are in `component-packages/`. The independent audit reopens all archives, verifies exact members and source-canonical payloads, compares the reused game assets to pinned hashes, validates the DLL as x64 PE, and checks both the outer ZIP checksum manifest and each DWPkg's internal checksums. Source/package checks do not establish CHIM installation, in-game connection, or Skyrim behavior.

Prealpha.5 through prealpha.7 reproduction recipes below refer to their original repack-script versions and base assets. Reproduce those releases from their matching immutable tags; they are historical procedures.

## Reproduce the prealpha.5 source-compatibility repack

This release reuses exact public prealpha.4 game/notices archives and replaces only the committed Correspondence adapter in its server archive. It does not invoke the native/Papyrus/full-candidate builders or create new runtime acceptance evidence. Preserve the downloaded base assets and verify their original SHA256SUMS.txt first.

```powershell
py -3 packaging/repack-source-compatibility.py --base-assets $prealpha4Assets --source-commit $releaseCommit --readiness $sourceReadinessJson --output $newReleaseDirectory
```

Use the immutable prealpha.5 source commit and its published source-readiness receipt (inside verification-receipts.zip). The source archive follows the committed explicit allowlist; untracked files are excluded. Output must be a new directory. The repack records current package/source hashes and keeps the old receipts/freeze under historical-prealpha4/ only. Ordinary sha256sum --check SHA256SUMS.txt verifies the nine canonical assets. This is source/package evidence; dev.21 PostgreSQL, HTTP, providers and Skyrim are not rerun or certified.

## Reproduce the prealpha.6 MP source-compatibility repack

Use the exact frozen Mind Poisoning 0.1.30 owner source and a fresh source-readiness receipt. The probe is source-only and requires no database, HTTP/provider, Skyrim, or native build:

~~~powershell
$readinessLines = & php -n server/tests/mp/source-contract.php --owner-root $mpServerRoot
$readinessExitCode = $LASTEXITCODE
$readinessText = ($readinessLines -join [char]10) + [char]10
[IO.File]::WriteAllText($sourceReadinessJson, $readinessText, [Text.UTF8Encoding]::new($false))
if ($readinessExitCode -ne 0) { throw "MP source check failed with exit code $readinessExitCode" }

py -3 packaging/repack-source-compatibility.py --base-assets $prealpha5Assets --source-commit $releaseCommit --readiness $sourceReadinessJson --output $newReleaseDirectory
~~~

The base must be the exact public prealpha.5 release assets, and output must be a new directory. The repack verifies the old checksums and unchanged game/DLL/notices payloads, admits only committed source from the allowlist, and records the MP receipt with the current source identity. It preserves the prealpha.5 freeze and seven old receipt members under historical-prealpha5/ without recertifying them. This creates source/package evidence only; it does not build native or Papyrus components or establish runtime/gameplay behavior.

## Reproduce the prealpha.7 multi-owner source/package repack

Use exact owner source roots for Private Conversation 0.1.23, Sworn & Scorned 0.1.12, and Sealed Fates dev.22, plus the Department source tree at the frozen commit. MP 0.1.30 is carried forward unchanged from prealpha.6. The focused checks produce source-only JSON receipts:

~~~powershell
$pcvReceiptLines = & php -n server/tests/pcv/owner_source_contract.php
if ($LASTEXITCODE -ne 0) { throw "PCV source check failed" }

$sasReceiptLines = & php -n server/tests/sas/source-contract.php --owner-root $sasServerRoot
if ($LASTEXITCODE -ne 0) { throw "SAS source check failed" }

$departmentServerRoot = (Resolve-Path server).Path
$sfReceiptLines = & php -n server/tests/correspondence/check-owner-pairing.php --owner-root $sfServerRoot --department-root $departmentServerRoot
if ($LASTEXITCODE -ne 0) { throw "Correspondence pairing check failed" }
~~~

The PCV probe uses the committed .23 staged source and its seven-file source manifest. The SAS probe requires the exact .12 owner server directory. The Correspondence check requires the exact dev.22 owner server and this Department server directory. Capture their JSON outputs without altering the owner fields. The combined UTF-8 readiness receipt passed as $currentSourceReadinessJson has ready, owners.pcv, owners.sas, owners.correspondence, department.adapters, department.runtimeFiles, and the exact outer limits sourceOnly=true, database=false, http=false, skyrim=false, ownerRuntimeLoaded=false. The adapter map binds all three adapter source hashes; runtimeFiles binds changed server-component source inputs to their committed hashes, including the production files and packaged PCV provenance. The repack rejects a false check, pin mismatch, unbound adapter, or changed installed source omitted from that map.

~~~powershell
py -3 packaging/repack-source-compatibility.py --base-assets $prealpha6Assets --source-commit $releaseCommit --readiness $currentSourceReadinessJson --output $newReleaseDirectory
~~~

Use the exact public prealpha.6 assets and a new output directory outside the source worktree and base-assets directory. The source archive uses only the exact committed tracked tree covered by the explicit allowlist. The server archive is rebuilt from the explicit source-to-member map: it carries PCV 0.1.23, SAS 0.1.12, Correspondence dev.22, and the seven PCV overlay postimages, while the MP 0.1.30 adapter must equal the prealpha.6 bytes. The repack verifies the PCV manifest base/postimage hashes, the exact changed/added/removed server members, archive/member hashes, and unchanged game/DLL/notices bytes. It retains all nine prealpha.6 receipt members plus that freeze under historical-prealpha6/ byte-for-byte; their prealpha.5 history remains nested and historical. Four current receipt files contain the combined readiness input and the extracted PCV, SAS, and Correspondence receipts. The package establishes source/package identity only: it does not compile native/Papyrus components or establish database, HTTP/provider, Skyrim, or gameplay behavior.
