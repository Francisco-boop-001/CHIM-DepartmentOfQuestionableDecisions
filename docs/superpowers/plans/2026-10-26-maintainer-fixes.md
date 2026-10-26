# Maintainer fixes implementation plan

> For agentic workers: use superpowers:subagent-driven-development. Coordinator owns review, integration and publication.

**Goal:** Fix all four confirmed audit issues, hunt for regressions and publish prealpha.18 only after scoped checks/review pass.
**Architecture:** Keep the existing companion DLL and five-field bootstrap schema. Correct existing UI gates/error mapping; bound owned SQL reads using transaction-local limits; maintain exact source/overlay upgrade pins. Reuse the existing deterministic packaging helpers.
**Tech Stack:** PHP/pgsql, JavaScript/Playwright, Python ZIP packaging, existing Windows companion DLL.
**Spec:** docs/audits/2026-10-26-maintainer-world-findings.md and the user's explicit fix/bug-hunt/publish request.

## Global Constraints
- Product implementation uses GPT-6-LUNA/max workers; coordinator reviews, verifies, commits, pushes and publishes.
- TEST/disposable only. Never access live, running Skyrim, installed CHIM core, real credentials or model providers. Existing campaign databases untouched.
- No DLL recompilation, CHIM core replacement, new dependencies, bootstrap schema changes, auth/restore/replay weakening or unreviewed host pins. PCV fallback remains.
- Preserve per-file line endings and UTF-8; checksum/JSON evidence uses explicit UTF-8 without BOM/LF where required.
- No broad test churn: run changed-boundary tests, focused retained bootstrap/owner checks, real SQL contention and package consumers. No impossible claim of zero bugs or gameplay certification.
- Release v0.1.0-prealpha.18 from exact published17 baseline; prior tags/assets immutable; public bytes verified before advancing main.

## Review Focus
- Authenticated+permission but capability false must say dependency unavailable, not auth denied (Task1 test).
- Missing permission must remain denied; wire errors must not trigger automatic mutation retry (Task1 test).
- Existing caller transaction with shorter limits must stay protected; success/error cleanup must preserve its transaction/work (Task2 test).
- Prior exact overlay must upgrade safely, unknown source must refuse; fallback remains (Task2 test).
- Installable must preserve DLL/game assets and owner pins; checksums/version/source export must match exact release (Task3 test).

### Task 1: UI access and error diagnosis
**Files:** ui/app.js; focused existing/new ui/tests/*.cjs.
**Interfaces:** Same native initialization/bootstrap/response schema, fields and bridge. No server field additions.
- [x] Read callers/renderers/error consumers and existing startup tests. Add failing real-page browser cases for allowed-but-unready PCV, permission-denied PCV, not-authenticated PCV, every declared protocol error and no automatic resend.
- [x] Run RED on current behavior, preserve evidence; make minimal gate/copy/mapping fixes. Readiness copy should name PCV/CHIM supported dependency installation without browser/manual-token advice. Leave controls safely gated.
- [x] Run GREEN and retained startup/operation-unknown tests relevant to changed error handling. Capture screenshots for coordinator review.
- [x] Self-review and report. Coordinator reviewed verified files; commit deferred until final bug hunt per user request.

### Task 2: Bound profile reads and preserve upgrade/transaction contracts
**Files:** server/adapters/runtime_support.php; relevant PHP tests. If fixing bundled PCV's owned identity connection requires changing pcv-companion/private-conversation-v0.1.23/server/state.php, update only exact related source pins/provenance/overlay transition helpers/tests in server/adapters/pcv.php, server/lib/overlay_setup.php, pcv-companion/companion.php and packaging/companion-only source manifest. Record exact packaging paths changed for Task3.
**Interfaces:** qd_runtime_active_playthrough(connection) still returns same authoritative identity and never owns caller work. Default maximum lock wait2000ms, statement3000ms; never raise an existing shorter nonzero limit. No mutation of CHIM core.
- [x] Read all callers and working host bounded-transaction pattern. Write failing regression covering initial pth_state before any query-limit guard. Test owns vs caller transaction, existing shorter bounds, timeout/error cleanup and restored caller limits. Real PG test may be authored for coordinator-run isolated cluster; preserve source/shim vs real-PG distinction.
- [x] Apply minimal correct limits before first blocking profile/player read. Protect both the Department reader and bundled PCV-owned identity reader; keep caller transaction state/work and source pins valid. No global env mutation to redirect host DB.
- [x] If overlay bytes change, support clean base, previous exact shipped overlay and repeated current overlay safely. Unknown/drifted source must still refuse; old backups retained and verified. An interrupted upgrade must restore exact pre-attempt bytes, including prior postimages, rather than mixing a base state file into a previously working overlay. Update all postimage consumers/provenance, not upstream base hash.
- [x] Run focused tests/source readiness and report the exact real-PG command needed. Coordinator independently runs actual SQL contention and rollback cases, reviews/commits.

### Task 3: Current build docs and deterministic prealpha.18 package
**Files:** packaging incremental builder/tests, allowlist, relevant manifest/version docs, README, docs/BUILD.md, package guide/verification/release notes. Include only needed new audit/plan/test sources in source export, never audit fixtures in installable.
**Interfaces:** Consume exact17 ZIP SHA2560ba15af00904aa776c8186064d15d72608a8aeccd34a0aa70023be3857354197. Publish18 zip/source/proof/checksums; unchanged native DLL SHA256bb901e6e423c19f63eb804f8303012dbfd8f759274ad719d31969076218cb6ef. Task2 may revise PCV source/DWPkg; package only reviewed files and coherent version identity.
- [x] Add focused failing packaging tests for changed UI/server/overlay payload allowlist, preserved DLL/assets, baseline17 hash, complete checksum closure and receipt/source binding. Reuse existing packaging helpers, avoid copying historical builders wholesale if a small extension works.
- [x] Implement minimal deterministic18 builder; make docs/BUILD.md lead with current reproducible command/required receipts and mark full historical rebuild recipe historical. State exact tested host matrix/3.4.2 refusal and remaining real-game limits.
- [x] Run focused packaging checks. Coordinator freezes source, builds/audits via actual host consumer, generates fresh server-bootstrap fixture and feeds unchanged final DLL validator, inspects browser, runs final bug hunt/review.
- [x] If no unresolved release-blocking bugs remain, coordinator commits/pushes immutable18 tag, creates draft release, verifies uploaded/public bytes, then fast-forwards main and reports installable URL.

## Completion review

Published [prealpha.18](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.18) at 2026-10-26T18:06:34Z from immutable source `b0c577cb8db58e7453cd7b4819c5c437849b79b2`. Root verified 22 installable members (19 unchanged payloads), all 210 source files against Git blobs, and all three bundled DWPkgs through the unmodified official CHIM archive validator without activation or migration. Installable: 617088 bytes, SHA-256 `24c558a938db919fc39ac5b16ca0a15e46738b0d9d82e4332c6711f3c6c0bd02`. Draft and anonymous public downloads match local bytes, GitHub digests/sizes and GNU checksums. All 16 previous releases, 103 assets and 32 tag refs remain unchanged. The unchanged DLL pure-validator checks remain distinct from Skyrim startup. This docs-only completion does not alter the immutable tag or assets. Independent final review and root verification left no unresolved known release-blocking findings. The final review caught and fixed mtime upgrade selection and real permission-denied bootstrap diagnosis. Focused checks: packaging9/9, startup28/28, functional51/51, isolated PostgreSQL11/11 plus adapter5/5 and identity/fallback3/3, overlay7/7. Real in-game startup, MO2 execution, providers and production database behavior were not checked.
