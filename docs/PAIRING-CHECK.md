# Department owner pairing check

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
