# Department owner pairing check

## Sealed Fates dev.24 source candidate

Prealpha.15 adds Sealed Fates dev.24 while retaining the dev.23 map and every earlier supported owner pin. The exact owner input is tag `correspondence-v0.1.0-dev.24`, commit `80821db59d3e1c299bee56b4496cf20a144984fd`. The dev.24 source-contract check requires the dev.23 root as a comparison control for the manifest-replacement refusal case:

```powershell
& $php -n server/tests/correspondence/dev23-source-contract.php `
  --owner-version 0.1.0-dev.24 `
  --owner-root $dev24ServerRoot `
  --legacy-owner-root $dev23ServerRoot
```

Run the source pairing-readiness command below with `$ownerServerRoot` set to the same exact dev.24 source directory and `$departmentServerRoot` set to the selected Department server source.

The focused dev.24 source contract, dev.23 regression control, source pairing readiness and protected-payload comparison passed. The resulting compatibility input binds those four receipt hashes to the selected Department commit and exact 19-file dev.24 map in `server/adapters/correspondence.php`. The package builder refuses mismatched owner pins or changed adapter/protocol source.

This is source and pairing-readiness evidence. PHP source/projection checks use isolated fixtures. They do not rerun the dev.23 PostgreSQL suite for dev.24 and do not prove a dev.24 database, HTTP/provider, actual CHIM-server or Skyrim result. Do not present the historical dev.23 38-case run as dev.24 proof. The archived [dev.23 acceptance record](DEV23-COMPATIBILITY.md) remains scoped to its named version and run.

## Sealed Fates dev.23 pairing

Department prealpha.13 adds the exact dev.23 source closure, including companion-letter runtime and migrations, while retaining the earlier supported pins. The independent 38-case TEST run and proof limits are recorded in the [dev.23 acceptance record](DEV23-COMPATIBILITY.md). Run `server/tests/correspondence/dev23-source-contract.php --owner-root <exact-dev.23-server>` and the source pairing command below. These host commands establish source readiness only; the release additionally carries the separately accepted PostgreSQL pairing evidence.

Companion ownership belongs to an exact recorded document. An opted-in NPC can still author or sign ordinary player-paid letters with items or gold. An uncertain delivery remains locked; reconciliation checks the same letter without resending it. Scheduler ownership and operator closure are separate metadata and do not establish delivery.

Run the source pairing check from the Department checkout with the exact Sealed Fates server source directory that will ship. That directory must contain the Correspondence `manifest.json` and `lib/` tree.

```powershell
$pairingReceiptLines = & $php server/tests/correspondence/check-owner-pairing.php --owner-root $ownerServerRoot --department-root $departmentServerRoot
$pairingExitCode = $LASTEXITCODE
$pairingReceiptText = ($pairingReceiptLines -join [char]10) + [char]10
[IO.File]::WriteAllText('source-readiness.json', $pairingReceiptText, [Text.UTF8Encoding]::new($false))
if ($pairingExitCode -ne 0) { throw "Pairing check failed with exit code $pairingExitCode" }
```

Set `$php` to a PHP 8.1 or newer CLI, `$ownerServerRoot` to the exact candidate source root, and `$departmentServerRoot` to the exact Department `server/` source root. The Department root defaults to the `server/` directory containing the check when `--department-root` is omitted. Keep `source-readiness.json` with the owner release record; the JSON contains no local paths or timestamps.

Exit code 0 means the real Department adapter selected and verified the exact owner source closure, returned a current source identity, and passed `qd_correspondence_runtime_ready()` against a temporary copy of that closure in the production `ext/correspondence` layout. The check refuses unsupported versions, changed closure bytes, unsafe paths, and source changes observed during the run. Refusals still emit JSON and return nonzero.

The v1 receipt records the owner version and selected closure pins, the Department protocol and adapter hashes, five readiness checks (`adapterLoaded`, `manifestReady`, `sourceIdentityReady`, `runtimeReady`, and `stagedClosureMatches`), and a stable `errorReason`. Its five limits state that the result is source-only and used no database, HTTP, Skyrim, or owner runtime. Cleanup and source-stability checks gate `ready` internally. A green source pairing does not prove PostgreSQL behavior, request handling, native transfers, gameplay, or provider output. Keep the separate PostgreSQL/runtime acceptance evidence for those claims.

The owner candidate source root is staged only in a uniquely named temporary directory so the unmodified production readiness function can resolve it as `core/ext/correspondence`. The check copies only files selected by the loaded Department adapter, rechecks their hashes around the readiness calls, and removes its own temporary tree before returning.

A focused native-PHP self-check covers the current dev.20 candidate, the prealpha.3 refusal, the dev.14 fixture control, deterministic JSON, closure corruption, unsupported versions, owner-runtime load refusal, and the source-only limits:

```powershell
& $php server/tests/correspondence/pairing-selfcheck.php `
  --owner-root $ownerServerRoot `
  --department-root $departmentServerRoot `
  --published-department-root $publishedDepartmentServerRoot `
  --dev14-root server/tests/correspondence/fixtures/installed-dev14-core/ext/correspondence
```

`$publishedDepartmentServerRoot` must contain the published prealpha.3 adapter at `adapters/correspondence.php` and its matching protocol at `lib/protocol.php`. The self-check creates private temporary owner copies for negative cases and removes them afterward; it does not edit the shared candidate. One harmless fake adapter intentionally includes a staged owner stub to confirm the checker sets ownerRuntimeLoaded and refuses readiness.

## Dev.21 pin update

Prealpha.5 added exact dev.21 to the existing dev.20 restore-fence, signed-unknown and operator-close selections. The 13-file closure differs only in `manifest.json` and `lib/offscreen.php`; the latter removes NPC metadata shape checks that could defer otherwise eligible mail. Runtime/store/bootstrap/migrations remain byte-identical to the accepted dev.20 pins. Do not edit the owner manifest to bypass version refusal. Published prealpha.4 remains unchanged and refuses dev.21.

Run the focused regression with the exact dev.21 server directory, then the normal source pairing command above:

```powershell
& $php -n server/tests/correspondence/dev21-source-contract.php --owner-root $ownerServerRoot
```

These checks establish exact source readiness, capability selection and fail-closed drift handling. They do not rerun the historical dev.20 PostgreSQL gate or certify dev.21 gameplay. No native recompilation is needed.

## Prealpha.6 Mind Poisoning 0.1.30 source check

Run the focused contract against the exact frozen Mind Poisoning 0.1.30 server source that will be packaged:

~~~powershell
php -n server/tests/mp/source-contract.php --owner-root <frozen MP server>
~~~

The accepted source identity is commit 3ce96b5640796db948dae33444dd403ea4ed74a6. A successful source-readiness receipt binds the exact Department protocol and adapters/mp.php hashes plus seven ordered owner pins: manifest.json, dashboard_data.php, store.php, influence.php, intimacy.php, logging.php, and restore_policy.php. Its checks exactSourceReady, storeReadAccepted, oldVersionRefused, unsupportedVersionRefused, and everyPinDriftRefused must all be true.

The prealpha.6 probe passed all seven pins and all five checks. It loads only the pinned pure reader sources and the Department read-only inspector interface. The receipt records sourceOnly=true, database=false, http=false, skyrim=false, ownerRuntimeLoaded=false, and ownerPureReadersLoaded=true. This is exact source/read-only contract evidence; it does not execute the owner runtime, database, HTTP/provider path, or Skyrim gameplay.

## Prealpha.7 owner source matrix

| Owner | Exact supported source | Department source gate |
| --- | --- | --- |
| Private Conversation | private_conversation-v0.1.23, commit 77265202d7f4a47b88e1eb88131b21f4b0752020 | server/tests/pcv/owner_source_contract.php; checks the seven-file .23 overlay, adapter pins, owner reader shape, callback locks, and preserved original input fallback. |
| Mind Poisoning | 0.1.30, commit 3ce96b5640796db948dae33444dd403ea4ed74a6 | Retained from prealpha.6. The package requires its adapter bytes to match the prealpha.6 server member; the old receipt stays historical. |
| Sworn & Scorned | sworn_and_scorned-v0.1.12, commit 9479f407c5936c008884442be49bced37ccbd06e | server/tests/sas/source-contract.php; six exact owner pins and the focused adapter/reader refusal checks. |
| Sealed Fates | correspondence-v0.1.0-dev.22, commit 4989d9e5fe4f75d4e235c1233e715f65a0c71378 | server/tests/correspondence/check-owner-pairing.php; the exact 13-file closure and Department protocol/adapter pairing. |

Run the three current source checks against their exact inputs:

~~~powershell
php -n server/tests/pcv/owner_source_contract.php
php -n server/tests/sas/source-contract.php --owner-root <SAS-0.1.12-server-root>
php -n server/tests/correspondence/check-owner-pairing.php --owner-root <Sealed-Fates-dev.22-server-root> --department-root <Department-server-root>
~~~

The PCV checker reads the committed .23 staged owner source and packaging/companion-only/pcv-0.1.23-source-manifest.json. That manifest lists the seven installed filenames and each upstream baseSha256 and shipped postimageSha256. Before installing the overlay, compare every existing file under ext/private_conversation/ with its base hash; stop on any mismatch. Install the complete PCV 0.1.23 owner release first, then apply the seven overlay files. The overlay does not replace CHIM core or the complete owner release. The original PCV input method remains available when the Department companion is disabled.

The combined package receipt extracts the three owner JSON reports and binds them to the exact Department adapter source hashes and hashes for changed installed production inputs. PCV and SAS receipts are focused source contracts; Correspondence uses the full 13-pin pairing CLI. MP 0.1.30 has no new pairing probe in this repack: its adapter is byte-compared with the prealpha.6 server payload and its earlier readiness receipt is preserved as historical. These checks establish source identity and adapter pairing only. They do not execute owner runtimes, databases, HTTP/provider paths, native code, or Skyrim gameplay.
