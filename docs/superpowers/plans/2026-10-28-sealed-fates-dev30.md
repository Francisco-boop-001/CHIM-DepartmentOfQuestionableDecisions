# Sealed Fates dev.30 Department compatibility implementation plan

> For agentic workers: use superpowers:subagent-driven-development. Root owns review, TEST launch, Git and publication; GPT-6-LUNA/max owns product implementation.

Goal: publish Department prealpha.26 bound to exact Sealed Fates dev.30 with real isolated TEST2 adapter/storage evidence and unchanged native/UI bytes.
Architecture: add the exact 20-file owner closure, retaining previous maps and safety gates. Reuse current store adapters and existing case patterns; do not add new owner behavior or storage. New package derives from the public prealpha.25 installable and changes only Department's DWPkg.
Tech stack: PHP8, PostgreSQL15 on named TEST2, deterministic Python packaging, existing native17/UI payloads.
Spec: frozen owner1eb58d999698002f8aeaa05b21464f7608717794 and owner-binding.json in K:/ActorwrightExchange/artifacts/department-sf30-20261028; confirmed human coordination request in Sealed Fates chat.

Global constraints: TEST2 only; fresh PG15 at 127.0.0.1:5432 inside a private network namespace (sole loopback interface, no external/default routes)/synthetic rows, owned flock/identity/cleanup receipts. No live/installed-game access, installed-core changes or provider calls. No native/UI rebuild. Previous pins and strict unknown-source refusal remain. Preserve existing unrelated tasks/todo.md edits; do not stage them with product changes.

Review focus:
- Tampered or unknown source refuses instead of selecting a weaker lifecycle path.
- Temporary BGL/player/form unavailability defers; resolved identity/opt-out/death/episode changes cancel; fresh recovery and manual bypass are distinguished.
- Both production reader and writer adapter stores satisfy transaction memo and actual service calls without nested transaction side effects.
- Restore overlap, stale identity, funds/replay/unknown/partial sends keep authoritative locks and no-resend semantics.
- Full forwarded text and companion scheduler ownership survive the pin update; no backend/model/gameplay success is inferred.

### Task1: exact owner binding and focused regressions
Files: server/adapters/correspondence.php; server/tests/correspondence/dev23-source-contract.php; new dev30-source-contract.php and focused dev30 adapter cases if needed.
- [x] Reproduce current unsupported-owner readiness against immutable30; retain red receipt.
- [x] Add exact20pins (only3 differ), version gates and focused drift/source/callback checks; retain29/28.
- [x] Verify actual30 service and both adapter interfaces; no owner/native/UI edits.
- [x] Root reviews diff and independent focused results.

### Task2: TEST2 production-adapter evidence
Files: new server/tests/dev30-pg/ focused fixture/runner; reuse pinned existing helpers only where behavior is exercised.
- [x] Prepare bounded cases, manifest/source-binding and safe fresh-cluster launcher; root inspects before launch.
- [x] After focused source/package guards and fixture lint pass, root commits intended candidate source (excluding unrelated debug notes), then acquires reserved TEST2 flock/window and executes that exact frozen commit with fresh PG15 synthetic data, no providers.
- [x] Verify requested availability/safety cases and failure traces; stop exact owned processes/cluster and record removal + release.
- [x] Send owner immutable inputs/results/shutdown for independent review.

### Task3: package26 and publication
Files: server/manifest.json, packaging/build-installable-prealpha26.py, packaging/tests/test_installable_prealpha26.py, explicit source allowlist, docs/BUILD.md, docs/DEV30-COMPATIBILITY.md, README.md.
- [x] Bind exact baseline25ZIP, frozen30map and source/TEST receipts; real PG evidence must not be mislabeled offline-only or gameplay proof.
- [x] Root verifies focused package guards, 21 unchanged outer payloads/4changed Department members, source/proof allowlists, official archive consumer and clean-source reproducibility.
- [x] Independent final bughunt and owner review of frozen candidate/PG evidence; if fixes change tested inputs, commit a new candidate and repeat affected verification before packaging/publication.
- [x] Root commits/pushes/new immutable tag; draft/download/hash/publish/public-byte verification; retain PRE-ALPHA and old releases. Fast-forward clean primary and send owner closure.

Review: pending. No implementation or TEST execution at plan creation.

Preflight replan: pinned CHIM ptp_connect hardcodes localhost:5432, so socket-only would not test the real adapter. SF owner approved private-loopback TCP with no external/default routes; receipts state socketOnly=false and privateLoopbackOnly=true. Require exact canonical scratch/postgres/data and scratch/socket before owner/API helpers and matching systemID/PID/path/netns before cleanup. Both findings came from independent source review before TEST entry.

Final review: completed with immutable562f77f actual16/71, independently approved by owner, all4assets reproduced and public-byte verified; no native/UI change. Earlier failed fixture runs remain retained and are not relabeled as passing. PCV25 remains separately unpaired.
