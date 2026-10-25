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

## Install-and-use onboarding correction — 2026-10-23

Goal: ordinary game-mod plus CHIM server-plugin installation must connect without Linux commands, worker environment setup, or manually copying pairing tokens. Preserve companion-only ownership, original PCV fallback, private operation replay/restore fences, and all live/core files. Reuse existing development worktree; preserve its untracked dist/evidence.

- [x] Verify actual CHIM endpoint, authorization, plugin-install and private storage extension points from pinned source.
- [x] Record the smallest complete connection/setup design and exact changed contracts before coding.
- [x] Delegate implementation and focused behavioral regressions to GPT-6-LUNA/max workers with separate file ownership.
- [x] Independently review native/server/UI/package integration and verify a fresh-install scenario in isolated workspace/TEST only.
- [x] Produce the corrected installable candidate and a short user guide; distinguish package/automated checks from Skyrim acceptance.

Review: investigation in progress. Current live game reports successful MCM registration and view rendering, but the shipped blank companion configuration blocks all backend requests. No live server request or change is authorized. Do not repair usability by issuing anonymous mutation credentials or weakening replay/restore authority.
Onboarding execution ledger: native endpoint/trust implementation and pairing are owned by /root/onboarding_native; server/default storage/approval/setup by /root/onboarding_server; game UI diagnostics and Connect controls by /root/onboarding_package, all GPT-6-LUNA/max. Root reviewed the initial UI error fix and requested preserving generic non-bootstrap timeout messages and clearing old failures on successful rebootstrap. The first UI regression run reproduced the two intended failures; after the minimal fix all 42 cases passed with the existing native refusal fixture. Root reviewed endpoint-bound HTTP changes and required arbitrary configured CHIM installation paths, not a hardcoded HerikaServer folder. Protocol was narrowed so claimId stays native-only. Ruling: use stable core data/questionable_decisions-private guarded storage rather than extension mutable paths, because the actual core installer copies mutable paths during activation and could split active locks. No live/backend/game installation changes.
Onboarding integration review (in progress): UI suite reached 49/49 after plain-language refinements; mock screenshots are evidence/dev20-connection-onboarding/pending.png and setup-required.png. Root inspected both earlier screenshots and required removing developer-style authentication/operator terminology. UI/package worker now additionally owns only server/lib/overlay_setup.php and server/tests/onboarding/overlay.php; backend worker retains other server/** files and server/index.php. Native-only claimId contract is fixed. Root caught and reproduced a malformed PHP origin regex; backend fixed it and root independently passed actual same-origin/foreign-port/malformed-host checks with warnings promoted to exceptions. Root caught server begin returning approvalPath for approved/expired, conflicting with strict native parser; producer is fixed, actual PHP wire fixtures (beginPending/beginApproved/beginExpired/statusApproved) and native optional fixture consumption are being added. Root required webRoot default to actual trusted DOCUMENT_ROOT, rather than narrower CHIM root, while protecting alias-served core files. Native must block only actual HTTP writes, not retained unknown operations, during reconnect; preserve history and same bearer/pairId. Explicit endpoint derivation/trust slice is implemented; CHIM no-INI proxy/default discovery still needs completion before install-and-use acceptance. New native protocol target raises CTest count from four to five. No new DLL build, archive, commit, installation or publication has occurred yet. All work remains in the isolated checkout; live/core are untouched.

Independent checkpoint: root reran the actual PHP onboarding test (88 checks), emitted real begin/status wire responses to the isolated prealpha8 artifact directory, and independently passed the loopback guarded-private-file HTTP404 probe. Root ran all 49 browser UI cases using the existing C++ refusal fixture and inspected the updated pending-approval screenshot; both passed. Root caught two more integration faults before building: successful approval hid the optional PCV setup form, and absent-INI discovery was not yet carried through bootstrap/companion dispatch trust. Both are assigned back to their Luna/max owners; native review also caught its missing direct ConnectionProtocol header. No live HTTP requests or installed file changes.
Independent integration review: the successful-approval response now retains the PCV setup form using the freshly rotated CSRF, and root reran all 97 server onboarding checks successfully. Root independently reran the five real-overlay fixture cases; fresh installation, idempotent retry with unchanged backup, already-enabled detection, drift/missing-stage refusal, and injected mid-write rollback all passed. Root reviewed the corrected lock/rollback order and OPcache invalidation. Native 5/5 fixture-backed host tests passed; final DLL build started against pinned cached dependencies only. Root spotted and the native worker fixed one local array/template compile mismatch before Plugin.cpp compilation. That correction is tracked as a source-freeze delta; package acceptance must compare the final source to the compiled inputs. Live/core/game installs remain untouched.
Source freeze checkpoint: root independently passed 97 server onboarding checks, five overlay install/refusal/rollback cases, 49 browser cases and eight packaging regressions. The rebuilt native suite passed 5/5 with actual PHP wire fixtures; final DLL is 737280 bytes, SHA-256 8c65d467cc3a733af204bb529f31ac5dec94b04ed4889f098069ad1b27bcb32f. Full compilation exposed two missing MAXULONG constants after root's earlier array fix; Luna replaced them with numeric_limits and only the failed plugin target was rebuilt using the same dependency outputs. The unchanged original receipt phase verified the actual pinned compiler/SDK and five-test result. Root checked expected SKSE exports, Windows/runtime imports, path-scan absence and byte-identical regenerated UI refusal fixture. A second read-only Luna review found no remaining concrete native onboarding blocker. The exact frozen source is committed next solely so the deterministic builder can consume Git blobs; actual core-package activation, archive audit, public publication and Skyrim acceptance are not claimed by this checkpoint.

### Onboarding candidate review and publication completed

Published v0.1.0-prealpha.8 at 2026-10-23T11:52:09Z, release ID 421782612, frozen source c3d5e8cee660a9596f28917f12d7df32b52446c5. Root independently matched all 150 allowlisted source files, all 18 normal MO2 members, the 25 Department and four companion payload files, required notices, reused ESP/SEQ/PEX, and the new DLL to the frozen inputs. The primary download is WorldOfDramaLlama-0.1.0-prealpha.8.zip (539310 bytes); source ZIP and ordinary LF SHA256SUMS are the only other public assets. Both embedded schema-4 packages were accepted by the actual pinned CHIM manager in fresh temporary TEST layouts. Root's independent Linux fixture passed default path resolution without QD environment overrides, explicit approval/auth/bootstrap, guarded journal complete/replay, repeated extension replacement with the same private lock inode, and replay from a clean PHP process. Profile/lease/migration callbacks were synthetic; no database or provider was used.

All three anonymous public downloads matched their local bytes and GitHub upload digests. Ordinary sha256sum passed both public ZIPs, and the new tag peels to the frozen source; seven earlier releases' 71 asset identities/digests and 14 older remote tag refs remain unchanged. Native 5/5, server onboarding 97, UI 49/49, overlay five cases and packaging 8/8 are the focused evidence; preserved initial failures and the localized incremental DLL correction remain in artifacts/department-install-and-use-prealpha8-20261023. The existing untracked dist/evidence directories are preserved; only the two new Python cache files from this work were removed.

No live request, live/server installation, CHIM core/AIAgent.dll change, installed game/MO2 change, provider/database start or in-Skyrim execution was performed. The already-running TEST distro was used only for temporary CLI fixtures and checksum checks. MCM registration/view rendering in the earlier installed candidate was reported by the user; the new candidate's Skyrim behavior, actual in-game Credential Manager persistence, focus, NPC/audio/physical transfer and save/load acceptance remain pending. Absent-INI discovery remains a source-equivalent startup snapshot, not a getter for CHIM's cached native endpoint. Unknown/replay history and the original PCV fallback remain preserved. This completion-record commit does not move the release tag or alter its assets.

## Browser approval rejection investigation — 2026-10-24

Scope: diagnose the user-reported approval rejection and compare SHARMAT's existing integration; local source and isolated loopback browser only. No live endpoint access, installed changes, implementation or publication in this investigation.

- [x] Trace the exact rejection predicate and response headers.
- [x] Reproduce the browser request using the production PHP header/render/origin helpers.
- [x] Compare the local SHARMAT game/server setup with the separate Department pairing layer.
- [x] Establish and implement an automatic CHIM setup route; preserve the separate Skyrim acceptance limit.

Review: the actual browser POST under the production no-referrer policy carries Origin: null and is rejected even though Sec-Fetch-Site is same-origin. A single-variable same-origin referrer-policy control carries the correct Origin and passes the same production validator. Evidence: artifacts/department-approval-rejection-20261024/browser-origin-results.json. Earlier approval tests injected HTTP_ORIGIN manually and therefore did not exercise browser header generation. The screenshot reaches the same rejection page; no live request headers were inspected, so additional session/CSRF faults in the user's environment are not ruled out. Browser approval is a Department design choice, not a Skyrim/PRISMA requirement. Local SHARMAT source uses AIAgentFunctions.logMessage/logMessageForActor and CHIM server configuration directly; its native DLL/current installed runtime were not inspected. A form-only fix does not resolve the user's objection to manual pairing. Automatic setup feasibility and its authorization boundary remain to be established without weakening replay/restore protections or editing CHIM core.

## Automatic CHIM setup — 2026-10-24

Goal: install the Department and open its in-game interface without browser approval, manual token/INI provisioning, or a separate setup button. Use GPT-6-LUNA/max implementation agents; root reviews, verifies and publishes a new immutable TEST candidate. Preserve CHIM core/AIAgent.dll, live, owner fallback and operation/replay/restore history.

- [x] Verify the exact stock game-to-server request and plugin-hook route, its early exits, logging and available reply semantics.
- [x] Freeze the smallest automatic setup protocol and trust boundary; coordinate native/server/UI callers before coding.
- [x] Luna/max: implement automatic native/server/UI setup, optional PCV preparation and focused regressions; remove browser approval from the normal flow.
- [x] Root: independently verify actual producer-to-hook setup, retry/restart/revocation behavior, private state and package replacement; review the UI visually.
- [x] Build only the changed companion DLL, package/audit, commit/push/publish and verify public downloads. Preserve old releases.

Scope: reuse this isolated development checkout and cached pinned tools. Synthetic loopback/TEST temporary fixtures only; no live calls or installed modifications. A successful package or synthetic CHIM request does not prove actual Skyrim input/audio or final delivery. Keep prealpha.8 and its assets immutable.

### Source freeze review

Root independently reviewed native producer/VM dispatch and endpoint/single-flight guards, the owned server hook and claim transitions, authenticated overlay ordering, UI polling/recovery and package allowlists. Review found and assigned fixes for missing recovery after later auth/context loss, stale manual PCV setup documentation, GET/timestamp validation and terminal claim handling; the corrected focused checks pass. GPT-6-LUNA/max workers own all product code. Server regressions were written after initial implementation, not presented as test-first evidence.

The one final companion build passed all five native host targets against actual PHP-produced response fixtures, then compiled Plugin.cpp and linked the 737280-byte DLL on the pinned cached toolchain. DLL SHA-256: c8c77d9534d9a4df0acd18e3451718235b57bdea727b2c86770a31af49590b36. All 34 native inputs match the pre-build freeze; SKSE exports, imports, source-path scan and unchanged refusal fixture passed. Shell32 is no longer linked. Root passed connect 102, stock ingress 31, the optional-overlay refusal check, UI 50/50 and shared/new packaging 8+3. Connecting, stopped failure with Retry, and connected/recovery screenshots were visually inspected. Earlier stale .8 wire-fixture rejection is preserved as a correct strict-schema refusal, not counted as a source defect or passing .9 result.

Package installation/replacement and producer-to-installed-hook proof remain pending at this source checkpoint. TEST is currently registered as DwemerAI4Skyrim3-test2 (E:\WSL\DwemerAI4Skyrim3-test2); root started that initially stopped distro solely for later /tmp CLI fixtures. No live request, installed game/MO2 change or CHIM core/AIAgent.dll change was made. Fresh Skyrim execution, Credential Manager/game persistence, focus, NPC/audio and physical transfer acceptance remain untested.

### Completed review and publication

Published v0.1.0-prealpha.9 at 2026-10-24T12:46:01Z, release ID 422727072, immutable source 06769f0e4f3517fef83e9bc2e93284371dbd993d. Root independently matched all 155 source ZIP files, 18 MO2 members, 26 Department and four companion payload files, notices and unchanged ESP/SEQ/PEX to frozen Git/pinned assets and the final native receipt. WorldOfDramaLlama-0.1.0-prealpha.9.zip is 537073 bytes, SHA-256 feb591c6f273d1d803c8cfd07b55656c9d47149873cd35462884d5d84a1159f1. The two ZIPs and LF SHA256SUMS are the only public assets; anonymous bytes and GitHub digests match, and ordinary GNU checksums pass. All eight earlier releases, 74 assets and 16 older tag refs are unchanged.

The actual pinned CHIM installer accepted both DWPkgs in /tmp/qd-department-prealpha9-installer-20261024T124029Z on TEST2. It verified default paths without QD overrides, direct JSON-approval refusal, stock hook approval, automatic PCV setup before profile/scope/readiness callbacks, all exact postimages and three exact original-file backups. It completed/replayed a guarded mutation once, preserved private bytes and the held lock inode across extension replacement, then replayed from a clean PHP process without redispatch. Root additionally consumed the C++ producer's actual emitted event through the installed production preprocessing hook: HTTP begin alone and browser-shaped requests cannot approve it; a duplicate event preserves pair identity and physics_raw early-exit type. Full core main/DB/provider/game execution was not part of those synthetic checks.

Six protected cached core/client source files remain identical, as do the original PCV route/owner overlays, adapter pins, auth/journal/restore helpers and old packaging helper. No live call or installed game/server change was made. Primary investigation notes were copied into this source before the primary checkout was fast-forwarded; original snapshots remain in the artifact directory. Existing dist/evidence are preserved and own new Python cache files were removed. TEST2 remains running because an active PostgreSQL process is outside these CLI fixtures; no termination or database access was attempted. This completion commit changes only records; it does not move the published tag or alter assets. Skyrim acceptance remains pending.
## Brainwash Department PRISMA integration — 2026-10-24

- [x] Add literal PHP operation/scope/source-refusal, native payload/response, and browser tab/transport/reset regressions; compare against baseline and run current contracts green.
- [x] Add the Brainwash explicit scope to fresh connection issuance only; preserve old tokens and all four existing owner pins.
- [x] Add the fail-closed Brainwash runtime adapter, PHP operation/capability validation, native contract validation, and fifth PRISMA tab using the shared editor mount API.
- [x] Run focused Department PHP/UI/native host checks; record exact results and limits in `docs/BRAINWASH-IMPLEMENTATION.md`.
- [x] Review the diff and update this checklist with a short handoff review.

### Review

The focused Department contract passes 77 checks, including every frozen owner-file drift refusal, and the foundation suite passes 162 assertions with PCV readiness correctly refused until its existing overlay precondition is met. The directory-fsync guard passes. Browser functional/interaction checks pass 50/50 and 1/1; render captured 11 states. Coordinator receipts record native 5/5 and the installed TEST2/owner workflow and preservation checks. The installed harness fixture has already been consumed and cleaned. Restore-generation behavior was verified with isolated owner/dispatcher tests without mutating shared core generation. No Skyrim acceptance is claimed. Exact evidence and limits are in `docs/BRAINWASH-IMPLEMENTATION.md`.

## Sealed Fates dev.23 compatibility — 2026-10-24

Goal: pair the exact published SF dev.23 with a new Department candidate; keep PCV0.1.23, MP0.1.30, S&S0.1.12, old pins, automatic setup and original fallback. Requested Luna/max workers implement; root reviews, verifies and publishes after independent SF owner acceptance. Plan: docs/superpowers/plans/2026-10-24-sealed-fates-dev23-compatibility.md.

- [x] Freeze minimal adapter/UI contract and full dev.23 source closure.
- [x] Implement focused source/lifecycle/UI changes with exact positive/refusal regressions.
- [x] Verify actual facade on a fresh owned route-free TEST2 PG fixture; preserve shared/campaign/live/core and truthful distro lifecycle evidence.
- [ ] Root independently review; obtain SF owner's independent frozen-pair acceptance.
- [ ] Reuse .12 game binaries and unchanged Malpractice dev.3/PCV components, audit .13 archives/checksums, commit/push/publish and verify public downloads and old release preservation.

Review: investigation only. Published prealpha.9 supports SFdev.22; dev.23 is not yet accepted. Existing dist/evidence trees are preserved. No TEST2 runtime launched for this task, no live/core/game access or new DLL build.

Baseline correction before product edits: fetched published .11/623343f, preserved the new Malpractice dev.2 fifth tab and both task records. Next candidate .12; published .11 native/Brainwash/PCV assets must remain byte-identical. The original .10 plan from .9 is superseded. Runtime window remains unused.

Review checkpoint: root independently passed all51 browser cases and the separate fifth-tab contract, inspected the actual closed-unknown screenshot, and confirmed the other three adapters/PCV setup are byte-identical across .9 to .11. The unchanged Brainwash dev.2 adapter accepts all8 exact frozen source files. Its existing77-check script requires an installed Linux absolute source path and was not run to completion on Windows; an initial direct source check lacked its runtime_support loader, then passed with the actual shared helper. These prerequisite failures are not product failures or passing runtime evidence. No TEST2 runtime, live access or native compilation has occurred for dev.23.

Isolation correction before runtime: pinned core ptp_product has no DB override and ptp_connect hardcodes localhost:5432. Preserve that core unchanged. The fixture therefore requires a route-free lo-only network namespace with a private127.0.0.1:5432 listener and unique admin socket/datadir. SF owner reservation update requested before launch; never describe this as socket-only. No TEST2 runtime launched.

Independent source checkpoint: root passed the final dev.23 focused source/identity/projection test and readiness probe (19/19 pins). Review found that new decision-row authority also affects mailbox pagination; the revision now binds a canonical bounded ownership map, with deletion/mutation/order regressions. TEST2 source-only hashing observed current core468584f; eight relevant PHP files match the cached0ec590b inputs after supported LF/CRLF normalization and both renderer assets match raw bytes. Actual PG execution will still name core0ec590b and claim only this bounded current-input equivalence. Initial unrelated missing-path and helper-loader prerequisite failures remain distinct from passing checks. Guarded runtime and both-owner acceptance are still pending.

Runtime freeze checkpoint: root reviewed the actual companion identity, payload, tick, lease, receipt and facade paths. All eight runner contracts, the no-WSL host self-test and the 33-case/19-pin/30-payload harness preflight pass. The 17-file frozen map is retained privately in runtime-inputs-reviewed.json; source commit precedes actual execution. No PG has run; root and independent SF owner acceptance remain pending.

## Malpractice Makes Perfect direct access - 2026-10-24
- [x] Preserve the upstream prealpha.12 / MMP dev.3 source and package changes.
Review: upstream owner verified the exact owner contract (76), PRISMA browser50/50 and focused tab contract, package/readback on TEST2. These remain their evidence, not a fresh SF dev.23 or Skyrim result. Upstream states no further Department release is planned; our next candidate is prealpha.13, preserving .11 and .12.

First isolated run 20261025T002354Z-da0f6d84 failed before case1 because the relocated runner passed its source directory as runtime-root rather than the artifact root. Eight host prechecks passed; the original catch hid the reason. Root verified exact owned PG PID17170/system7700395566356427528 stopped, source postcheck passed, TEST2 remained running and protected cached bytes matched. All raw files and the original freeze (SHA256 aa7a61cbaefeefde1674d71292ef6f96180618e2d995ba5a1231ffeeadb58ff7) are retained. The Windows-owner-path hypothesis was disproven by actual PHP comparison. Luna fixed only the caller argument and bounded preflight diagnostics; root reviewed and passed11 focused contracts plus PHP lint. This is fixture-failure evidence, not a product incompatibility or passing runtime result. Fresh freeze and independent recheck precede retry.

Second isolated run 20261025T003853Z-3e234a42 reached the case driver but its www-data children refused the fixture guard because /proc/1/ns/net is not readable by UID33. Root confirmed that OS permission boundary directly. Its 35 attempted/3 passed cases are not accepted product evidence; dependent failures cascaded before API behavior. Root verified exact owned PG PID17856/system7700399459830212009 stopped, source postcheck passed, TEST2 remained running and protected bytes matched. The second original freeze (SHA2569bc8b2b6cad4fd956d4f2b7b86458541ed1cacfb0c1250c680c26d31a8a47c70) and all raw records are preserved. SF owner accepted a protected root-issued initial-namespace reference for the unprivileged guard, retaining strict current namespace/path/ownership checks and requiring actual inside-positive/outside-negative role probes before PG. Production source remains unchanged.

Role-guard retry checkpoint: root reviewed the four-file fixture-only change and passed12 runner contracts, both PHP lint checks, Bash syntax, host lifecycle self-test and33-case harness preflight. The marker now records the protected root-observed initial namespace; UID33 must match the private namespace and differ from that reference. Actual www-data inside-positive and initial-namespace negative probes precede initdb. No production logic changed; both failed runs remain preserved. Fresh source/map and independent SF acceptance precede retry.

Third isolated run 20261025T010425Z-1d87b3fc passed37/38, including all5 dev.23 companion cases and both actual UID33 namespace probes. Sole failure: controller_pause_with_held_lease timed out waiting15s for paused; source/artifact evidence identifies its only runtimeRoot-as-source caller launching nonexistent artifactroot/controller_switch.php. Exact child stderr was not preserved and is not invented. Root verified ownPG PID20459/system7700406071428857825 stopped with source postcheck, TEST2 running preservation and protected bytes true. Original freeze SHA816c6e3204e10e47b3134fbbbe9ff25292cca581044037d00985c605c6018caa is retained in the raw run. Luna owns minimal caller/diagnostic regression; no product or timeout/assertion change. Both owners agreed SF will execute the next fresh full38 run and root will independently review its raw SQL/receipts/source/cleanup plus both gates, avoiding another duplicate full run after that if all pass.

Final retry freeze: root reviewed the testRoot controller caller and durable controller outcome, grepped every runtimeRoot helper use, and independently passed13 contracts, PHP lint and whitespace. No production source, case assertions or timeouts changed. The failed R3 raw receipt was correctly refused by the root acceptance gate without writing an acceptance. SF owns the next single fresh full run after exact source/map handoff.

Independent final run 20261025T011803Z-95a75e6d at79b7e2c passed38/38 plus8 prechecks. SF executor independently rehashed all3231 frozen inputs and38 SQL chunks/deltas. Exact privatePG PID22699/system7700409541917907104 stopped after identity check; TEST2 running/running preserved with protectedbytestrue/provider0. Freeze SHA06ccd015eda271136ec5d590a0f2e8045593ce74d820b7efacb948d5cbba2657. Root manually checked controller exit0/no timeout/empty stderr and both UID33 namespace probes. Raw runs remain immutable. Private acceptance-oracle corrections follow source-supported repeated restore checks and multiple read-only profile-metadata connections per API label; no product/runtime changes or another server run. Final fourJSON, archive acceptance and publication remain pending.

Root final acceptance: all38 actual cases,3231 bound inputs, raw SQL/deltas, exact owned cleanup and protected sources passed. Corrected private evidence oracles now enforce source-supported repeated restore samples and one fenced owner backend plus seven exact read-only metadata backends per API; positive/negative selfchecks and root captured missing-fence refusal pass. Final fourJSON were generated once from R4 and the pinned public SF tested-paired gate passed38. Publication remains gated on SF proof/archive reproduction and final package audit.
