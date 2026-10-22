# Department dev.18 candidate

Goal: publish a separate TEST-only companion candidate with exact Sealed Fates dev.18 restore fencing and signed-unknown handling. Preserve CHIM core, AIAgent.dll, live data, the original PCV fallback and prealpha.1.

- [x] Pin the public owner dependency and stock core; document the owner overlapping-init limitation.
- [x] Delegate product fixes to GPT-6-LUNA/max agents; root reviews callers, source identity, restore authority, commit ordering and cleanup.
- [x] Pass focused host checks and review the isolated harness; preserve failed preflight evidence.
- [x] Execute and independently review the fresh TEST PostgreSQL/facade run, source preservation and owned cleanup.
- [x] Bind unchanged native/UI/PCV evidence, finalize the verification record, build and independently audit new archives.
- [x] Commit, push, publish a new prerelease and verify anonymous public downloads against the frozen inputs.

## Review

Product changes are limited to the correspondence adapter and dedicated correspondence helpers. Root review fixed a source-version downgrade and required effective failure injection, row fingerprints, actual lock observations and durable journal evidence. Failed launcher, environment, path-mapping and argument-parser attempts are preserved with their exact inputs. The first private PostgreSQL run passed 162 foundation assertions and all focused Linux probes, then stopped before scenario execution because of an unused CLI option. Its owned PostgreSQL/TEST cleanup and source/protected-state checks passed. The parser and unprivileged namespace-marker guard are fixed; full scenario acceptance and publication remain pending.

Run 6 reproduced a dev.18 compose deadlock: a context assertion reacquired the state lock already held by its caller. Luna moved the fenced preflight before that lock; root reviewed the minimal change and reran all six focused host probes successfully. The failed run also exposed a PostgreSQL log delimiter error, stale activity observations and orphaned PHP children after harness timeouts. It was intentionally stopped after 21 cases; source preservation, owned PostgreSQL shutdown and protected-state checks passed. A follow-up process probe briefly restarted TEST and then auto-stopped; the final stopped state was verified before handing TEST to Sealed Fates. The corrected harness and fresh 27-case acceptance remain pending.

The exact dev.18 owner can erase a newer pending marker during overlapping initialization. This external defect remains documented: serialize restore/init in TEST; no overlapping-init certification or dev.19 compatibility is claimed. Native/UI and the earlier stock PCV route are reused only after input/body/asset hash checks. No Skyrim or physical-transfer acceptance is inferred.

Run 7 stopped at the process-group self-test; an exited/absent-group observation race was corrected, then three isolated process-only probes and run 8's full process self-test passed. Run 8 executed all 27 facade cases: 23 passed. The remaining cases exposed invalid presence setup, a missing core bootstrap in the receipt helper and incorrect accounting of a rolled-back event anchor. Its complete source, SQL/API/journal evidence and failed summary are preserved; source postchecks, owned PostgreSQL stop, TEST stop and protected baselines passed. These failures are not accepted as a green integration result.


Before run 9, root corrected a launcher argument: LeaseReceipt requires the coordination label, while the coordination JSON lives at the launcher's fixed path. The wrong argument was refused before creating a run or starting WSL; its separate output is retained. The final binding changes exactly six entries: two test helpers in both server/test projections, the scenario driver and the renderer preflight. Product adapter/core/owner bytes remain unchanged from run 8.


Run 9 passed all nine prechecks and 25/27 facade cases. Normal compose, reconciliation and no-resend passed. The two owner-accepted signed-unknown cases exposed a real adapter defect: physical-form lookup constructed an uninitialized owner store, which began a nested read transaction and rolled back the caller's snapshot before validation returned unknown. Its raw SQL and failed result are preserved. Full source postchecks, PostgreSQL stop, TEST stop and protected-state checks passed. A SELECT-only lookup correction and transaction-boundary regression are required before acceptance.


## Accepted run and release checkpoint

Run 20261021T135003Z-9fa7580a passed ten prechecks (including 162 foundation assertions) and all 27 PostgreSQL/facade cases. Root independently parsed both signed-unknown SQL traces, checked mapping inside a single read transaction, no reconcile mutation SQL, all 27 trace hashes, and the no-resend result. The initial root comparison of whole-case snapshots was too broad: those cases intentionally compose and seed a receipt first; per-reconcile row equality is checked inside the executed harness and independently supported by the SQL trace. Root also reproduced the old snapshot-loss behavior and verified the fixed refusal before COMMIT. Seven focused host probes and 42 PHP syntax checks passed.

Final source checks, owned PostgreSQL stop, TEST stop and protected-state checks passed at 2026-10-21T13:59:59Z. Other running distros remained unchanged. TEST was explicitly released to Sealed Fates before packaging; no further Department runtime is queued. Native/UI/PCV and journal evidence is reused only after current input hashes match. Root audit found no remaining blocker within exact dev.18 serialized-init TEST scope; Skyrim and broad coexistence remain pending.

This source snapshot is the pre-publication checkpoint. The archive audit, commit/push, publication and anonymous download checks must finish before the final hand-off; the release tag will retain its frozen source identity.

## Publication completed

Published [v0.1.0-prealpha.2](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.2) at source commit ec6868073f66721050fad3781a8268cbb85fd2df. The independent archive audit passed: 118 frozen inputs, 113 canonical source files, 9 game files, 24 server files and 7 notices. All 113 Git index blobs matched the frozen source before commit. Ten authenticated draft downloads matched before publication; ten anonymous public downloads, all five gate receipts, all nine checksum entries and all 113 tagged GitHub source files matched afterward. The prior release tag and ten assets are unchanged.

This documentation-only follow-up records completion without moving the frozen release tag or replacing any asset. The release source retains its explicit pre-publication checkpoint. Local dist/evidence directories remain untracked verification artifacts. No Department runtime is queued; Skyrim checks remain for the user in an isolated TEST profile/save/server.

## Critique 3 fixes: prealpha.3

Goal: clarify the authoritative repository, publish portable LF checksums, and allow a distinct letter draft while keeping every unresolved operation available for same-ID reconciliation. Preserve all server/native replay rules, exact dev.18 pairing, CHIM core, installed game and live server. Earlier releases remain immutable.

- [x] Clarify source authority; fast-forward the clean primary checkout and mark the older implementation snapshot historical.
- [x] Luna/max: implement a bounded unresolved-letter UI, explicit unresolved-not-found messaging and discriminating regressions.
- [x] Luna/max: add a minimal LF checksum writer and a real GNU consumer check.
- [x] Root review code/callers, run browser/interaction/visual checks and confirm unchanged native/server replay inputs against accepted evidence.
- [x] Update verification/recovery docs and explicit allowlist; freeze, build and independently audit a new candidate.
- [x] Commit, push, publish prealpha.3, verify anonymous downloads with ordinary sha256sum, synchronize the clean primary checkout and finalize the review.

Ownership: letter_recovery owns UI and its browser tests; checksum_fix owns the checksum helper/test and BUILD usage; replay_review reads the existing native/server contract. Root owns source authority, task records, independent review/execution, evidence, packaging and publication. No implementation conflict or backend change is intended. No WSL runtime is needed unless a newly discovered changed boundary requires it; TEST ownership must be coordinated before any start.

Success requires: earlier queued/unknown IDs remain reconcilable after another draft or send; not_found never clears uncertainty; no implicit resend; delayed replies cannot overwrite a different draft; auth loss removes private text without forgetting IDs; genuine authenticated scope change clears only that scope's local UI; bounded capacity refuses explicitly without eviction; GNU sha256sum verifies the untouched published checksum file.

Design review added two guards before implementation: refuse same writer/recipient/whitespace-normalized text even with new IDs/revisions/attachment choices; serialize transfer-bearing operations because current inventory checks do not reserve pending quantities. A bounded local fingerprint may over-refuse on collision and is never a crypto/privacy claim. No backend or native code change is needed. Root independently rehashed all 3,144 accepted server/core/owner/harness entries, 25 native inputs and five assets; all matched. The checksum self-check passed under root with the real GNU consumer, including exact anchors for trailing-newline rejection.

### Critique 3 review checkpoint

Root reviewed the final Luna UI/checksum diff and the independent replay contract review. All nine recorded review findings are addressed, including detached old replies, visible not_found at capacity and auth-negative bootstrap text erasure. Root executed 39/39 functional cases, one interaction case and 11 render captures; visual review includes four tabs, unresolved recovery and compact review. The new recovery case fails against prealpha.2. GNU checksum self-check passes; actual staged/public consumer verification remains required. No backend/native input changed and no WSL runtime was started. Archive/publication remain pending at this immutable source checkpoint.

### Critique 3 publication completed

Published [v0.1.0-prealpha.3](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.3) from frozen source 259d7b2ff4441d88e011be25a896468ea32edb42. Root archive audit passed: 120 inputs, 115 source files, 9 game files, 24 server files and 7 notices; all 115 Git index blobs matched the freeze. Ten authenticated draft downloads and ten anonymous public downloads match, five gate receipt hashes match, all 115 tagged GitHub source files match, and ordinary GNU sha256sum verifies all nine untouched LF checksum entries. Both earlier releases retain their original tag objects and ten asset identities. The clean primary checkout was fast-forwarded to the published source.

This documentation-only completion record does not move the release tag or replace frozen assets. No Department WSL session was started; native/server evidence was reused only after the 3,144 input and 25 native/five-asset rechecks. Protected AIAgent.dll and live VHD metadata match their baselines; live VHD contents were not read. Actual Skyrim acceptance remains pending, and exact dev.18 restore/init must remain serialized.

## Exact dev.20 companion candidate - prealpha.4 source checkpoint

- [x] Port exact source-pinned lifecycle and operator-close handling with Luna/max workers.
- [x] Add real PostgreSQL facade cases and a repeatable source pairing gate.
- [x] Root independently review, execute TEST-only checks and verify owned cleanup/protected roots.
- [x] Freeze and audit the private preparation candidate; preserve earlier releases and obtain independent source/behavior paired acceptance.
- [x] Complete the final source/package closure audit, commit, push, publish prealpha.4 and verify anonymous downloads.


First dev.20 run 20261021T195433Z-0f980029 executed all 33 cases, with 9 passing. Eleven prechecks passed. Root identified fixture errors: drift attempted to edit root-owned staged source as www-data; copied initialization/epoch-rotation omitted dev.20 matching pending sentinels. Root also found an undefined Department helper in the new close fixture and inconsistent boolean text projection. Failed source inputs and raw SQL/API evidence are preserved; source postchecks, owned PostgreSQL shutdown, TEST stop and protected baselines passed. Luna is correcting only fixture/setup code and strengthening the existing drift/close cases; no accepted pairing or product defect is inferred from this failed run.

Second dev.20 run 20261021T202839Z-e91e8656 passed 29/33 cases and all 11 prechecks. Remaining failures are fixture-only: dev20_restore_state cast PostgreSQL booleans to words while strict callers expected libpq f/t; the DDL case recreated an empty presence table before three positive compose cases. Root preserved all 55 inputs and exact 223e freeze after successful source postcheck, owned PG shutdown, TEST stop and protected-baseline checks. Luna is fixing representation and restoring exact fixture rows, with explicit owner-fixture refresh before positive sends. Dispatch, no-resend and read-only assertions remain strict; this run is not accepted.

Third dev.20 run 20261021T205512Z-85898b18 passed 30/33 and all 11 prechecks. Exact close-identity rejection now passes. Remaining failures are under read-only diagnosis: overlap child blocked in an eager fixture readiness call before init; close/long-detail cases reuse a signed-UNKNOWN physical-mapping oracle; forwarded compose may exceed the unchanged stock renderer geometry despite the2000-codepoint input bound. Frozen inputs and raw evidence are preserved; source postchecks, owned PG/TEST stop and protected baselines passed at 21:05:07Z. No production change or acceptance is inferred.

Fourth actual dev.20 run 20261021T213201Z-754ad897 passed 32/33 cases and all 11 prechecks. Restore overlap and close/pruned/mismatched-close/late-positive branches passed. Case32 queried a presumed operation_id column; the owner stores that field in its document JSON. Further review caught the distinction between client request IDs and owner operation UUIDs, plus the intentional detail-refusal rollback path. Frozen inputs and raw SQL/API evidence are preserved. Source postcheck, owned PostgreSQL/TEST shutdown and protected baselines passed at 21:41:40Z. Luna owns a narrow fixture correction; no accepted pairing or product defect is claimed.

Root accepted exact dev.20 run 20261021T215250Z-ed346ab8: 33/33 actual facade cases and 11/11 prechecks; independent raw SQL/source/cleanup review passed. All 139 owner source files and 53 preserved native/UI/PCV/generated inputs match. All four earlier failed fixture runs remain preserved. Paired review and private package audit remain separate final gates; no remote or Skyrim acceptance is claimed.

Prealpha.4 source checkpoint: the exact source/behavior pair passed independent Sealed Fates review. Root corrected a guide-heading encoding error found during final Git review. CRLF bytes remain intentional; per-call cr-at-eol permits those terminators while retaining real trailing-space checks. Fresh final freeze/archive/Git closure and public verification are pending; private preparation artifacts remain preserved.

### Dev.20 review

Luna/max implemented the bounded adapter and fixture changes. Root and Sealed Fates independently reviewed the exact runtime source and raw SQL; all 33 facade cases and 11 prechecks passed, including no-resend and signed-unknown refusal. Game files, native/UI/private route, CHIM core and live state remain unchanged. Four failed fixture runs are preserved. Final package/Git closure, publication and anonymous verification are the remaining gates at this source checkpoint.

### Prealpha.4 publication completed

Published [v0.1.0-prealpha.4](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.4) from frozen source 08680e31635a4ed91bd9efcb46d7d9b8f30d95e1 at 2026-10-21T22:34:20Z. Root archive/Git closure and independent Sealed Fates review passed: 128 sources, 133 inputs, five gates, 33 actual facade cases and 11 prechecks. All eleven anonymous asset downloads and 128 tagged source files match. Ordinary GNU checks pass all nine canonical entries and all four entries extracted from the public pairing sidecar. The three older tags and thirty assets are unchanged. Metadata checks used the authenticated GitHub CLI after the anonymous API rate limit; asset and source-archive downloads remained anonymous.

This documentation-only completion commit leaves the frozen tag and every published asset unchanged. Native/UI/private-route execution remains reused after input checks; actual Skyrim acceptance is pending. CHIM core, installed game and live server remain untouched. TEST and the owned PostgreSQL cluster were stopped; no further Department WSL run is queued.

## Sealed Fates dev.21 pin update

Goal: accept exact dev.21 sources through the existing dev.20 behavior paths, with no native/UI/core or owner changes. Reuse this idle worktree; no new artifact tree or WSL runtime.

- [x] Luna/max: add exact dev.21 closure pins and the three existing capability selections, plus one focused regression.
- [x] Root: review the complete small diff; verify exact source pairing, drift refusal, unchanged dev.20 acceptance and unsupported-version refusal.
- [x] Record verification limits and prepare the bounded source update for commit/push; no release repackaging.

### Review
Root compared all 26 owner server files against dev.20: only manifest.json and lib/offscreen.php changed. The unmodified adapter refused dev.21 with owner_version_unsupported. The updated adapter passed the focused dev21 source-contract regression, the unchanged dev20 source-contract control, and the real source-only pairing CLI (13/13 closure pins, all five readiness checks, no owner runtime/database/HTTP/Skyrim). The regression checks both changed-file drift refusals and unlisted dev.19/dev.22 capability/version refusal. Only the new exact source table and three existing capability selections change production behavior. README and pairing notes distinguish current main from the immutable prealpha.4 package. This is a source commit checkpoint; commit/push follows verification. No new artifact tree, WSL/runtime start, native build, owner/core/live change or installation occurred. Historical dev.20 behavior evidence is not a newly executed dev.21 PostgreSQL or gameplay result.

## Prealpha.5 dev.21 downloadable repack

- [x] Prepare the deterministic repack script and source checkpoint using public prealpha.4 inputs; archive construction follows this commit.
- [x] Root review: validate archive paths/member hashes, exact one-file server delta, unchanged game/DLL bytes and ordinary checksums; no broad suites or runtime starts.
- [x] Publish a new immutable pre-release, download its assets once and confirm hashes; leave earlier releases unchanged.

### Review
Source checkpoint: Luna/max implemented the bounded repack and root reviewed its complete data flow. Root caught and fixed the checksum asset-set split before execution. Exact committed blobs and the source allowlist gate the source archive; old component/member hashes gate reused input. Package execution, independent archive/checksum review and public verification follow this immutable source checkpoint. Publication is authorized; installation is not performed. Historical dev.20 runtime evidence remains historical.

### Publication completed

Published [v0.1.0-prealpha.5](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.5) from immutable source 2847e21541b7f241ee48c55ee441b12b82e46f39 at 2026-10-22T01:00:11Z. Root independently verified all 130 committed source files, 24 server members with only the correspondence adapter changed, nine unchanged game files/DLL, seven unchanged notices, and isolated historical evidence. Ordinary GNU sha256sum passed all nine entries. GitHub draft upload digests matched all ten assets, then one anonymous public download pass matched all ten assets and checksum entries. All four older releases and 41 asset identities/digests remain unchanged. GitHub draft metadata was read by release ID after its tag endpoint returned 404; no draft/upload was duplicated. No native/Papyrus recompilation, broad suite, WSL/server/database/provider start, installation, CHIM core or live change occurred. Package/publication evidence is in artifacts/department-prealpha5-dev21. This documentation-only completion record does not move the frozen release tag or modify any release asset.

## MP 0.1.30 pin and prealpha.6 package

Goal: replace only the exact MP source identity with reviewed 0.1.30, retaining PCV 0.1.22, S&S 0.1.11 and SF dev.21. Reuse prealpha.5 game/DLL/notices bytes, preserve fail-closed drift checks and publish a new immutable TEST-only pre-release. No WSL, compilation, core/live/installed changes or broad suite reruns.

- [x] Luna/max: update MP pins, focused source-contract probe and current package/docs.
- [x] Luna/max: adapt the existing deterministic repack for the MP-only delta.
- [x] Root: review complete diff, run narrow source readiness/drift and unchanged-owner checks, and record owner coordination.
- [x] Root: commit frozen source, build/audit exact archives and ordinary checksums, push/tag and publish.
- [x] Root: verify public downloads and prior release immutability; synchronize clean primary and finish review.

Scope: implementation workers own pins/test/docs and repack separately; root owns task records, independent verification, Git and publication. Known critique findings remain outside this compatibility update. Source-only proof must not be presented as new database or Skyrim acceptance.

### Review checkpoint

Root reviewed the complete MP production diff (version plus four changed hashes), the standalone source regression and deterministic repack changes. The new probe passed all seven exact pins and five checks: source acceptance, pure StoreDb reads/write refusal, old/unsupported version refusal and individual drift refusal for all seven files. The same probe against the unchanged prealpha.5 adapter refused MP0.1.30. The new adapter SHA-256 is a86e04435796400023b6f9d7aa484a6e1cda7a4005f11e795e58b9214f895f37.

MP, S&S and Sealed Fates owners reported no concrete new source-contract conflict. Root checked six exact S&S0.1.11 tag pins, thirteen SFdev.21 frozen pins, and all seven retained PCV0.1.22 companion-overlay pins. PCV stock files are intentionally different from the documented companion overlay; no stock-only pairing is claimed. Automatic approval review rejected messaging PCV, citing a restriction to leave PCV to its own task; its retained contract was checked locally. PCV0.1.23, S&S0.1.12 and SFdev.22 remain unpinned/refused. No compatibility claim is made for those newer versions.

The ten prealpha.5 base files match their published GitHub digests. Source/package proof remains separate from historical runtime receipts. Known critique defects remain unresolved. Frozen archive construction, independent checksum/member review, publication and public verification follow this source checkpoint. No WSL, database/server/provider/gameplay execution, installed modifications or CHIM core/live changes occurred.

### Prealpha.6 publication completed

Published [v0.1.0-prealpha.6](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.6) from immutable source 1c50d3eb9fd07ad8ebae95629964488bb315a29d at 2026-10-22T19:55:49Z. Root independently verified 131 clean Git source files, all 24 server members with only adapters/mp.php changed, nine byte-identical game files including the DLL, seven unchanged notices, and nine receipt members with historical evidence preserved. Ordinary GNU sha256sum passed all nine entries. All ten uploaded digests and ten anonymous public downloads match. Five earlier releases, 51 asset identities/digests and ten existing remote tag refs remain unchanged.

This documentation-only completion record does not move the frozen tag or change any asset. No DLL/Papyrus compilation, broad suite, WSL, server/database/provider/gameplay start, installation, CHIM core or live change occurred. MP0.1.30 is now the exact accepted MP identity; PCV0.1.22 with its documented companion overlay, S&S0.1.11 and SFdev.21 remain unchanged. Newer PCV0.1.23/S&S0.1.12/SFdev.22 are not accepted. Known critique defects remain; runtime/Skyrim acceptance is still pending. Evidence is in artifacts/department-prealpha6-mp30.

## Latest owner pairing: PCV0.1.23 / S&S0.1.12 / SFdev.22

Goal: support the three newly published exact owner versions while retaining MP0.1.30, companion-only design, original PCV fallback and fail-closed source identities. Use Luna/max implementation workers; root reviews, verifies, commits/pushes and publishes a new immutable TEST-only package. No CHIM core/live changes, broad suite churn or unneeded compilation.

- [x] Review exact immutable owner diffs; port PCV companion overlay and update adapter pin/capability selections only where necessary.
- [x] Run focused positive/refusal and changed-boundary compatibility checks; preserve historical/runtime proof limits.
- [x] Update deterministic package construction, source allowlist and user upgrade documentation for the actual server delta.
- [x] Root independently review and audit, freeze source, construct package, verify ordinary checksums and publish.
- [x] Verify anonymous downloads and previous release immutability; synchronize clean primary and record completion.

Scope: preserve prealpha.6 and earlier tags/assets, unresolved operation history, replay/restore protection, game files and fallback. A source pairing cannot establish Skyrim or new database behavior. The previously recorded critique defects are outside this compatibility update unless a changed owner boundary makes one necessary to solve here. Existing untracked dist/evidence trees are preserved.

### Compatibility review checkpoint

Root verified the three published immutable tag targets, reviewed the complete adapter changes and the stock-PCV0.1.23-to-Department overlay diff, and checked every PCV base/postimage hash against the immutable owner Git bytes. The original fallback, locked commit callbacks, token route, and new owner scene-recovery/wrap-up changes remain in the overlay; no CHIM core or native wire contract changes were made.

Focused host verification passed: PCV source/temporary-state receipt (7 pins/8 checks), actual owner callback/scene seam with mbstring, and presence-reader check; S&S0.1.12 source/reader/refusal receipt (6 pins); SFdev22 source pairing (13 pins/5 checks), capability/drift regression, and dev21 control. The new S&S probe initially misclassified two pinned pure helper files as runtime; the corrected helper graph and actual empty-ledger projection pass. The first PCV seam run lacked mbstring and refused its Unicode case; enabling the real extension passed. Failed runs are preserved. A reused 1,100-operation retention test was started inadvertently, then stopped as outside this change; it is not accepted as a passing check. Its single owned scratch directory was verified and removed. The missing historical installed-dev14-core adapter fixture was not fabricated or counted as passing.

Root inspected all changed PCV callback code and confirmed the three stock .23 owner changes are retained. The merged preprocessing file and the two mechanically updated PowerShell source maps parse without invoking builders/compilers. MP0.1.30 and native/UI inputs remain unchanged. S&S and SF owners report no new Department-facing contract issue. No WSL, database, HTTP/provider, installed-server or Skyrim execution occurred. Prior critique findings and runtime limits remain. Frozen archive audit and publication follow the final source checkpoint.

Final source checkpoint: root reviewed all production and packaging deltas, corrected the server manifest delta expectation before building, and ran a bounded in-memory repack gate self-check. All three false owner receipts and an adapter hash mismatch were refused. The explicit allowlist is 134 paths; stale .22 overlay copies are retired from current source while old tags remain immutable. The upgrade guide preserves separate verified stock .23 rollback copies. Archive construction/audit, commit push and public verification follow this checkpoint.

### Prealpha.7 publication completed

Published [v0.1.0-prealpha.7](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.7) from immutable source 56368f42590894388f7151405fe74429af9ff358 at 2026-10-22T20:42:52Z. Root independently checked all 134 committed source files, all 24 server members, the exact nine changed/one added/one removed server delta, all 14 receipt members, nine unchanged game files/DLL and seven unchanged notices. MP0.1.30 remains byte-identical. Ordinary GNU sha256sum passed all nine entries; all ten uploaded digests and all ten anonymous public downloads matched. Six earlier releases' 61 assets and twelve prior remote tag refs remain unchanged.

Two older release descriptions showed dash-to-replacement-character text drift during the final comparison; that metadata was not edited by this task, and no blanket body-immutability claim is made. The first API list after draft creation was briefly stale; the existing single draft was resolved by ID without recreating it or duplicating assets. Evidence is in artifacts/department-prealpha7-owners, including the preserved failed/prerequisite runs and stopped-retention cleanup record.

This documentation-only completion record does not move the release tag or change any asset. The supported matrix is PCV0.1.23 with the matched seven-file overlay, MP0.1.30, S&S0.1.12 and SFdev.22 (earlier supported SF pins retained). The original PCV fallback and owner .23 fixes are preserved. No DLL/Papyrus/UI compilation, WSL/server/database/provider/gameplay start, installation, CHIM core or live change occurred. Focused source/temporary-state checks establish the reviewed compatibility boundary, not Skyrim or physical-transfer acceptance. Previously recorded critique defects remain outside this update.
