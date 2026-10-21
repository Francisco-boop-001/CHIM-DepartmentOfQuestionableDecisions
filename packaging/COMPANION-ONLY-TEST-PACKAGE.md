# Department pre-alpha release guide

This guide covers the Department pre-alpha candidate, including the separately verified dev.18 server compatibility update. The coordinator may create it only after a new root-owned `department-pre-alpha` freeze is ready and pins every source, generated asset, and accepted gate. The builder and auditor do not modify an installed game or CHIM tree. Test only with a disposable CHIM server/database, Skyrim profile, and save. Candidate creation, publication, and installation are separate actions.

The game Data component is exactly nine files: the current UI triplet, two neutral-metadata PEX files, the own DLL, the blank plugin INI, and the 185-byte ESP/4-byte SEQ pair. This server-only candidate reuses those five published binaries after verifying all native/Papyrus/authoring inputs and bytes unchanged. The builder binds them to the new freeze and creates new component and outer archives. The source ZIP uses the same root layout as the reviewed source projection (`native/`, `server/`, `ui/`, `packaging/`, and `docs/`) and contains only the explicit allowlist.

Use Skyrim SE/AE with matching SKSE64 and Address Library, PRISMA UI exposing its V1 API, and SkyUI for the MCM key binding. VR is not a supported build target. The masterless ESP is ESL-flagged: it uses one light-plugin slot and no full-plugin slot. The server adapters target Private Conversation 0.1.22, Mind Poisoning 0.1.29, Sworn & Scorned 0.1.11, and Sealed Fates correspondence 0.1.0-dev.14, 0.1.0-dev.15, 0.1.0-dev.16, or the exact pinned [0.1.0-dev.17](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.17); source pins refuse unsupported installations. This separate candidate adds exact Sealed Fates 0.1.0-dev.18 after a passing 27-case restore-fence and receipt integration run. Dev.18 means the exact separately published [Sealed Fates PRE-ALPHA](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.18), not arbitrary source with the same version string; its complete owner package is a separate dependency. See docs/DEV18-COMPATIBILITY.md in the source component. The owner has a source-confirmed overlapping-init marker race that this companion cannot repair; serialize restore/init operations in TEST. Overlapping initialization is not certified.

Install the complete supported owner releases and their documented requirements, including Sealed Fates game-side transfer components. Stop and restart the disposable PHP worker after installing or updating owner/Department source; disk hashes do not prove that an already loaded class or opcode cache contains the new bytes. Do not clear operation state or replay uncertainty as an upgrade step. This interface package supplements those plugins.

The server component contains the own Questionable Decisions facade, runtime libraries/adapters/provision CLI, `pcv_companion` hooks, seven staged PCV 0.1.22 files, and their base/postimage source manifest. Install or retain the complete supported Private Conversation 0.1.22 release first. These seven files form an overlay under `ext/private_conversation/`; they do not replace CHIM core and are not a complete PCV release. They rely on unchanged owner files such as `reflection_receipt.php`. The paired PCV source snapshot has no standalone license declaration; see `docs/PCV-PROVENANCE.md` inside the server component.

Before testing, extract the matching source ZIP and read its root `README.md`, `docs/TEST-PROFILE-SETUP.md`, `docs/STATE-RECOVERY-POLICY.md`, `docs/BUILD.md`, and `docs/VERIFICATION.md`. Browser, native-contract, and synthetic-server checks do not establish game focus, controller behavior, final NPC routing, audio, transfers, or save/load behavior.

## Set up a disposable test server

1. Confirm the complete supported PCV 0.1.22 release is installed in the disposable test tree. Back up the exact destination files and record their hashes. Confirm the seven PCV base hashes against `docs/pcv-0.1.22-source-manifest.json`; stop if any differ. Install the server component at the test CHIM root so the facade is served at `/ext/questionable_decisions/api.php`, including its `lib/journal.php` dependency, the companion hooks at `/ext/pcv_companion/`, and the seven PCV files directly in `/ext/private_conversation/`. Retain the other PCV files unchanged.
2. Use Linux with PHP 8.1 or newer, PostgreSQL, and the `pgsql`, `mbstring`, and `ctype` extensions. Sealed Fates letter rendering also requires PHP GD with FreeType/PNG support and its documented font/background assets. Journal mutations require directory-handle `fsync`; unsupported filesystem/runtime combinations fail closed. This requirement covers the Department journal only and does not establish physical power-loss recovery for other stores. Set these variables on the PHP web worker that serves the facade, then restart that worker:

   | Variable | Value |
   | --- | --- |
   | `QD_CHIM_ROOT` | Absolute path to the disposable CHIM root. |
   | `QD_PCV_ROOT` | That tree's `ext/private_conversation` directory. |
   | `QD_MP_ROOT` | That tree's `ext/mind_poisoning` directory. |
   | `QD_SAS_ROOT` | That tree's `ext/sworn_and_scorned` directory. |
   | `QD_PAIRING_FILE` | Absolute path to the private pairing file, outside the document root. |
   | `QD_SERVER_STATE_FILE` | Absolute path to the private server-state file, outside the document root. |
   | `QD_WEB_ROOT` | The existing document root used by the PHP application server. |

   The PHP worker must be able to read the installed extensions and write the pairing file, state file, and sibling `<QD_SERVER_STATE_FILE>.journal` directory. Choose a new, empty, test-only `QD_SERVER_STATE_FILE` path. This version refuses nonempty legacy operation state instead of attempting an unsafe migration; preserve any previous state file and `.journal` directory unchanged and point the isolated test server at its fresh path. The journal retains up to 128 detailed results plus compact non-replay tombstones, so its private directory continues to grow and needs disk-space monitoring. Keep all pairing/state/journal paths outside `QD_WEB_ROOT`. Correspondence derives from `QD_CHIM_ROOT/ext/correspondence`; there is no supported `QD_CORRESPONDENCE_ROOT` override.

3. From the disposable CHIM root, run the provision CLI as the same operating-system user as the PHP worker so its private files remain readable and writable by that worker. Issue the scopes needed by the four tabs. The normal command is:

   Replace the example absolute paths with the exact configured paths:

   ```text
   php ext/questionable_decisions/tools/provision.php issue "/private/path/pairing.json" "/path/to/web-root" "pcv,inspector,compose"
   ```

   To grant the optional recorded-author detail view, include `author_detail` as well:

   ```text
   php ext/questionable_decisions/tools/provision.php issue "/private/path/pairing.json" "/path/to/web-root" "pcv,inspector,compose,author_detail"
   ```

   The CLI arguments must resolve to the same pairing file and web root configured for the worker. Handle the one-time JSON output as a secret. Put the token only in the test profile's `Data/SKSE/Plugins/WorldOfDramaLlama.ini` under `[Server] PairToken=`. Never save command output in a log, archive, screenshot, or shared history.

4. For this candidate's supported private-textbox setup, use `http://127.0.0.1:<port>/<HerikaServer-installation-path>` in `BaseUrl`, without an `api.php` suffix; the client appends `/ext/questionable_decisions/api.php`. The generic facade transport accepts HTTPS and loopback forms, but the stock-send destination guard accepts only plain HTTP and cannot parse IPv6 `::1`; generic HTTPS support does not make HTTPS eligible for the companion textbox route. The base URL must exactly match the explicit `Data/SKSE/Plugins/AIAgent.ini` that CHIM reads from the game's working directory: `SERVER=127.0.0.1`, the same `PORT`, and a `PATH` ending in `/HerikaServer/comm.php`. The companion compares this destination with `BaseUrl` and refuses missing or mismatched configuration. Keep CHIM in Standard mode for the private textbox; other modes are refused and the companion does not change the global mode. Use an already isolated test profile/server endpoint; the current installed profile's `172.x` destination is not evidence of the test endpoint. Never edit the live/shared INI or CHIM configuration. CHIM caches this destination, and no public getter proves the already-cached runtime value, so restart Skyrim after any deliberate test configuration change and treat the unchanged explicit INI as a startup assumption.

## Skyrim acceptance — eight checks

Read the current verification record before the game checklist. The release supplies `input-freeze.json` and `verification-receipts.zip`: the freeze and five sanitized accepted gate JSON files can be hashed against `package-manifest.json`. These records establish bounded source/build/server/UI checks; they do not replace an in-game test. Use a disposable mod-manager profile and save.

1. Inspect conflicts: the candidate must provide no `AIAgent.dll`, CHIM PEX/UI overrides, or CHIM core PHP replacement. Assign the Department key in MCM and open it; all four tabs should appear.
2. Confirm the test-server identity and pairing, and verify the unchanged explicit `AIAgent.ini` destination matches the supported `http://127.0.0.1` `BaseUrl` as described above. Do not edit a shared/live INI to make it match.
3. With CHIM in Standard mode, allow its normal nearby background updates after loading, then open Private Conversation and refresh until the intended actors are eligible. A fresh presence baseline needs a later background heartbeat. Choose an unambiguous eligible cast and Arm a scene. Confirm Armed/Pending before the first accepted turn. Type a short distinctive line and click Send once. Verify the selected scene activates and one intended turn is accepted. `consumed` means PCV accepted the text only; audible delivery needs your observation.
4. Try punctuation, a literal vertical bar, an accented character, and a newline. Confirm typing does not move the player or trigger gameplay keys. Test Close, Escape, controller Back, and ordinary CHIM input. Do not resend an unknown result; use Refresh send status. Discarding an unknown draft removes local text only and does not cancel or unlock the operation.
5. Check refusals by letting a target leave range, changing the scene before sending, and using a mismatched companion server configuration in the disposable profile. Confirm no private-text leak, alternate target, or automatic resend.
6. Disable only the companion game mod while leaving the original PCV installation present. Use PCV's existing selection/input method; it should work without opening the Department or CHIM in-game interface.
7. Compare a known Mind Poisoning relationship change and a Sworn & Scorned issue with their owner records. Switch perspective during refresh and confirm late results do not appear under the new selection; history, current values, direction, grievances, and promises remain distinct.
8. In Sealed Fates, use disposable items/gold. Review writer, recipient, claimed signature, body, and attachments, then send once. Check actual payment/transfer and receipt-confirmed mailbox delivery. A second send requires a fresh review. Change inventory after review and confirm stale attachments are refused. On connection loss, reconcile the original operation instead of sending another letter.

Record the Skyrim/SKSE/PRISMA UI/CHIM/plugin versions, test save, steps, and expected versus actual behavior. Keep pairing tokens, provider credentials, and private message bodies out of public logs. Actual rendering, focus, VM calls, audio, item transfer, save/load behavior, and conflicts remain unproven until these checks are performed; static/source/server evidence does not establish them.

## Rollback

1. Revoke the test pairing before removing the provision CLI or server facade. From the disposable CHIM root, run `php ext/questionable_decisions/tools/provision.php revoke "/private/path/pairing.json" "/path/to/web-root" "PAIR_ID_FROM_ISSUE_OUTPUT"`, replacing the example paths. Keep the pair ID and token out of shared logs; the token is not an argument to revoke.
2. Clear `PairToken` from the disposable profile INI and disable the companion mod in the test mod manager. Stop the test game and PHP worker.
3. Restore the seven backed-up PCV 0.1.22 files byte-for-byte and remove only the newly installed `pcv_companion` and Questionable Decisions facade files. Do not restore or replace CHIM core, install an old fork, or change live CHIM files.
4. Remove the companion-only game mod from the disposable profile and restore any pre-install conflicting files from the recorded backups. Verify every restored hash against the pre-install record.

The builder's default mode validates frozen inputs without creating an archive. `-CreateArchive` is reserved for the root after all gates are frozen and accepted. The package manifest records gate receipt hashes and component member hashes. The builder does not invoke Skyrim.

The Linux test distribution passed the journal directory-sync capability check. The journal syncs its parent on acquisition and its directory after atomic replacement or deletion; unsupported operations refuse the mutation. This is scoped to that journal and test distribution. It does not certify physical power-loss durability or change PCV, CHIM, database, or other store persistence. Preserve uncertain operation IDs and do not treat a machine crash as proof that a send or transfer did not occur. See the state-recovery policy for permitted recovery and retained-history limits.

## Included dependency notices

The notice component contains seven applicable notices: CommonLibSSE-NG v3.7.0, fmt 10.2.1, nlohmann/json 3.11.3, rapidcsv 8.84, spdlog 1.13, PrismaUI, and the exact MIT license for the pinned CHIM `PrismaUI_API.h` source. It excludes the old AIAgent/CHIM compatibility notices and their aggregate summary.

