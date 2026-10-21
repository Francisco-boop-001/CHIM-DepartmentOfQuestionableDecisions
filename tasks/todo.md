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
