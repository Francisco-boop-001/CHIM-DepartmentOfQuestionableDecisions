# Automatic companion startup handshake implementation plan

> **For agentic workers:** Use superpowers:subagent-driven-development for the scoped tasks below. Root owns review, integration, Git and publication.

**Goal:** Opening the Department in Skyrim automatically initializes its companion bridge and starts existing CHIM setup, without browser approval, helper websites, copied tokens or CHIM core changes.
**Architecture:** Keep the existing companion, UI and server protocol. Remove the unrelated asynchronous focus dependency from initialization; use PRISMA Invoke's callback to observe page acceptance with stale-view/generation fencing. Keep initialization idempotent for the same active view so any repeated delivery cannot restart bootstrap or replay actions. Add bounded, reason-coded native diagnostics and distinguish local startup failure from server authentication in the UI.
**Tech stack:** Existing C++20/CommonLib/PRISMA V1, plain JavaScript, existing PowerShell/Python packaging tools.
**Spec:** User instruction in this chat and the reproduced startup evidence in K:/ActorwrightExchange/artifacts/department-startup-20261025/ROOT-CAUSE.md. User has authorized this scoped fix, review, verification and publication.

## Global constraints
- TEST/isolated workspace only. No installed game/MO2 writes, CHIM core or AIAgent.dll changes, live requests, provider calls or live database access.
- Preserve all current owner pins (PCV0.1.23, MP0.1.30, SAS0.1.12, SFdev.23, MMPdev.3), existing fallback and authentication/replay/restore protection.
- TEST2 is reserved by the Sealed Fates owner audit; do not start or alter a fixture there. Existing immutable .13 and owner sources are frozen.
- No new dependency, polling framework, gameplay feature, server code change, or browser provisioning route. Never automatically replay gameplay actions.
- Logs must exclude bearer/claim/view/scope tokens, endpoints/query strings, NPC identities, dialogue, letter text, inventories and raw responses. Log only fixed stage/reason/status and safe numeric HTTP code/attempt counters.
- Logging failure must not break the mod. Keep logs bounded/rotating using existing CommonLib/spdlog facilities.
- Product implementation by GPT-6-LUNA/max. Workers are not alone; preserve others' changes. Root alone commits, pushes and publishes.

## Review focus
- DOM ready before focus, focus before DOM ready, and view never focused: initialization must not wait on focus.
- Duplicate initialization must acknowledge the current active view without clearing state, starting another bootstrap or replaying any action.
- Close/reopen, destroyed views and changed generations must ignore stale acknowledgement callbacks.
- Missing handler, handler rejection/exception and missing native bridge must produce useful bounded diagnostics; pre-bridge UI text must not imply a server outage or authentication refusal.
- Fresh setup and retained credentials must follow the same existing stock CHIM route, with no browser step. Authentication and owner readiness failures stay explicit.

## Task 1 — Native handshake and logs
Owner: native/src/Plugin.cpp, native/src/NativePolicy.h and directly related native tests/build metadata. Test the reproduced focus race first (failure), then remove focus dependency and observe initialization acceptance using PRISMA Invoke callback. Fence callback results to the current view token and generation. Add a bounded WorldOfDramaLlama.log for startup, initialization result, configured-destination availability, bootstrap/connect stages and fixed error reasons. Reuse existing lifecycle, security and request handling. Version the changed DLL as0.1.0.14. Do not build a release DLL until root review; host checks are permitted. Save focused red/green receipts in the isolated artifact folder.

## Task 2 — UI idempotence and startup diagnostics
Owner: ui/app.js and focused UI tests; index.html/app.css only if required for existing status elements. First add failures for same-view repeated initialization and truthful local-bridge startup state. Same active token/generation should return true without clearing/restarting anything; previously closed/replayed tokens still refuse. Use existing UI status elements to distinguish Starting game interface from Connecting to CHIM; provide bounded local-startup timeout guidance that needs no website. If native Invoke callback needs any return detail coordinate with native owner; retain boolean initializer contract. Confirm existing automatic setup lifecycle and fallback unchanged. Do not change server contracts.

## Task 3 — Minimal new installable release
Owner: packaging build/audit tool and release documentation only (README.md, docs/VERIFICATION.md, packaging/COMPANION-ONLY-TEST-PACKAGE.md, packaging/public-source-allowlist.json if needed). Prepare0.1.0-prealpha.14 from exact .13 baseline, replacing changed native/UI members only. Preserve all three bundled server packages byte-for-byte, all owner pins, other game files and notices. Include new exact source/provenance and focused startup evidence in developer material, never installable game data. Extend/reuse existing strict builder instead of copying another large builder. Do not invent an executed proof or version bump unchanged server payloads. Root provides reviewed DLL and test receipts before packaging.

## Root verification and publication
- [ ] Review native and UI diff and evidence; fix found faults through workers.
- [ ] Build one clean release DLL using pinned cached dependencies; run relevant host tests.
- [ ] Exercise exact UI scripts and native-shaped initialization in the installed Ultralight engine, isolated from Skyrim/server; exercise actual production request/response UI automatic setup harness.
- [ ] Verify no website/approval route introduced; owner/server components unchanged; package/public bytes and checksums match source.
- [ ] Publish new immutable GitHub prerelease after review; preserve all old tags/assets. Update main by fast-forward only after public asset validation.
- [ ] Give installable ZIP and simple Skyrim test steps, name actual gameplay acceptance still pending.
