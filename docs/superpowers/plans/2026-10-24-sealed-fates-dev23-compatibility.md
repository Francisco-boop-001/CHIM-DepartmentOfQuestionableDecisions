# Sealed Fates dev.23 Compatibility Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development with the requested GPT-6-LUNA/max workers. Root owns review, verification and publication.

**Goal:** Publish a Department candidate accepting exact Sealed Fates dev.23 while preserving the other three plugin pairings and automatic CHIM setup.

**Architecture:** Extend the existing correspondence adapter and its strict source identities. Preserve ordinary manual letters and project per-document scheduler ownership without dispatching or retrying scheduler-owned letters. Reuse published prealpha.11 native/game binaries if the unchanged wire contract permits it.

**Tech Stack:** Existing PHP adapter, PostgreSQL TEST2 fixture, JavaScript UI, Python deterministic packaging, unchanged SKSE companion.

**Spec:** Human request verified in Sealed fates plugin chat 01a153a5-4a01-74b3-99b2-690b8f93838e: coordinate dev.23 compatibility, verify the frozen pairing on test2, preserve safety checks, publish after independent owner acceptance.

## Global Constraints

- TEST2 only; no live, installed game, campaign data, provider traffic, CHIM core or AIAgent.dll modification.
- Use only a fresh owned PostgreSQL fixture with namespace-private loopback and an owned admin socket in a route-free network namespace. Preserve the already-running shared distro and stop only the exact owned cluster.
- Preserve PCV0.1.23, MP0.1.30, S&S0.1.12, Malpractice Makes Perfect dev.2 and all existing SF pins, automatic setup, fallback, unknown operations and immutable old releases.
- SF ownership is per document, for every decision state; opted-in actors can still send ordinary manual letters with player-funded items/gold.
- Preserve replay, restore/freshness, signed-unknown/partial, operator-close and late-receipt safeguards; Department reconcile stays read-only.
- Keep existing four pairing receipt schemas unless the owner explicitly accepts an additive change. Never claim a stopped distro when it stayed running.
- Preserve preexisting untracked dist/ and evidence/.

## Review Focus

- New helper drift must refuse every facade operation; pin the full transitive dev.23 runtime closure.
- Closed/unknown companion records must not become retryable, delivered or ordinary because a projection loses ownership.
- Fenced reads must not nest transactions or mutate scheduler state.
- Stock note overflow must retain the whole private queued body without operation, physical note or transfer.
- Frozen receipts must bind actual executed source and truthful fixture/distro cleanup.

### Task 1: Adapter pairing and lifecycle

**Files:** server/adapters/correspondence.php; server/tests/correspondence/check-owner-pairing.php; focused dev23 source/behavior checks.
**Interfaces:** Existing bootstrap/catalog/mailbox/detail/compose/reconcile operations and envelopes; no new compose request field.

- [x] Add a failing exact dev.23 source pairing and helper-drift refusal check.
- [x] Add the complete dev.23 source closure and existing version capability groups.
- [x] Cover per-document ownership, queued/unknown/closed projections and ordinary manual compose for opted-in actors using minimal existing shapes.
- [x] Verify legacy positive control, unknown-version refusal and new/old helper drift refusal.

### Task 2: UI and native boundary

**Files:** ui/app.js; ui/tests/ui-functional.cjs only if actual changed response metadata requires them.
**Interfaces:** Preserve exact native catalog/compose schemas and same-ID reconcile; generic response extensions only if .11 DLL accepts them.

- [x] Confirm native response validation before freezing optional UI fields.
- [x] Reproduce and minimally fix any required user-visible ownership/closed-state gap; retain receipt-confirmed mailbox/privacy and unresolved locks.
- [x] Run the relevant focused browser checks; inspect changed UI visually if rendered behavior changes.

### Task 3: Real TEST2 facade proof

**Files:** focused dev23 runtime runner/harness reusing server/tests/dev20-pg and existing source fixtures.
**Interfaces:** Four sibling receipts department-owner-pairing.json, source-readiness.json, runtime-release.json and runtime-input-freeze.json; existing owner coverage names retained.

- [x] Record initial host distro state and exact fresh fixture ownership before launch.
- [x] Run the actual facade against frozen owner dev.23, covering bootstrap/catalog/mailbox/detail/compose/reconcile, restore overlap/stale authority/read-fence lease, operator close/pruning/late receipt, signed unknown/partial/no resend, legacy pins and full forward-body fit/overflow.
- [x] Include companion ownership and no-manual-dispatch/retry checks without providers or shared DB state.
- [x] Stop only owned PG, record final distro state, source/protected baselines and bounded rerun instructions.
- [x] Obtain SF owner's independent rerun/acceptance of the exact frozen pair before publication.

### Task 4: Package and publication

**Files:** packaging/build-installable-prealpha13.py; focused package check; manifests, README, BUILD/upgrade/release records and tasks/todo.md.
**Interfaces:** v0.1.0-prealpha.13 MO2 ZIP, source ZIP, four-receipt pairing ZIP and LF SHA256SUMS; unchanged published .12 DLL, game assets, PCV component and Malpractice dev.3 component.

- [x] Update minimal manifests/pairing docs/source allowlist and deterministic wrapper; assert reused .12 DLL identity.
- [x] Root review all diffs and run the lightest changed-boundary checks; record exact limitations.
- [ ] Freeze source commit, build/audit archive closure and ordinary external checksums.
- [ ] Commit/push/publish only after both owners accept frozen evidence; verify anonymous downloads and prior release/tag/asset preservation.
- [ ] Fast-forward clean primary and record review/completion without moving the release tag.

Baseline correction: remote prealpha.11 (623343f7620239be4462d96d877683623b0fe9d7) was published by the Malpractice owner while this checkout still used .9. All workers paused before product edits, root fetched and fast-forwarded the isolated branch, and both task records were preserved. The fifth tab and its native contract are mandatory. Next free candidate is .12, subject to active publisher coordination. Published .11 ZIP SHA256 66a85ee031f98d7f65344a159fd24722a1fb927409010d9fc0019a485032d621; DLL SHA256 1451c64dc8285be897d6eeac8754980a0b90a3228f75c8780d2f5566aeacb4c9. No new compilation is needed for the correspondence response metadata.

Second baseline correction before runtime: published prealpha.12/Malpractice dev.3 advanced origin/main to06788b4. Its owner acknowledged no further Department publication; root merged the minimal upstream adapter/manifest/CSS/docs delta and preserved both ledgers. The next candidate is prealpha.13, using the exact .12 archive (SHA256 c20f752f1a63483886bb528f07ae536129e3f6ee10f3d964524c404386efd704), existing DLL and embedded PCV/Malpractice components. Earlier .12 build/name steps in this plan are superseded by .13; no additional native build. Preserve .11 and .12 assets/tags.

Final runtime checkpoint: independent R4 run20261025T011803Z-95a75e6d passed38/38+8prechecks at79b7e2c. Root raw acceptance and pinned public SF gate pass; no additional runtime launch. See docs/DEV23-COMPATIBILITY.md for input/cleanup/proof pins and limits. Final package/proof reproduction and publication follow.
