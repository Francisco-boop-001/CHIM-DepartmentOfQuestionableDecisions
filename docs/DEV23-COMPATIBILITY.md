# Sealed Fates dev.23 pairing

Status: prealpha.13 server pairing verified in independent TEST run `20261025T011803Z-95a75e6d`, using frozen runtime source `79b7e2c3e74b8a05285744c4f5f0d489743468d9`. Skyrim acceptance remains pending.

The candidate starts from Department prealpha.12 (`06788b40a257e75f0adce5c7aa507fd1ea45a8eb`), retaining Malpractice Makes Perfect dev.3, Private Conversation 0.1.23, Mind Poisoning 0.1.30 and Sworn & Scorned 0.1.12. The exact Sealed Fates input is immutable tag `correspondence-v0.1.0-dev.23`, commit `01d62982d9b10ca4fce040af9d97fe7111231ba3`. Prealpha.12 and older releases remain immutable and do not accept this new owner version.

## Changed boundary

Sealed Fates dev.23 adds automatic companion letters. Their payer is the writing NPC, their recipient is the player, and they carry no items or gold. Department historically accepted player-paid documents only. The candidate must recognize an exact recorded companion-owned document without treating arbitrary NPC-paid documents as trusted.

Ownership is per document and survives queued, stale, failed, unknown and operator-closed states. NPC opt-in alone never disables an ordinary manually authored letter or player-funded gifts. Department reconciliation remains a read of the original operation; it must not invoke a scheduler, dispatch a new delivery, retry or erase an uncertain operation. Receipt proof remains the authority for delivered mail, including valid late receipts after operator closure.

The two result flags `companionSchedulerOwned` and `operatorClosed` describe independent properties; they do not replace the existing delivery state. Catalog and compose request schemas remain unchanged. The published prealpha.12 DLL accepts these optional compose/reconcile data fields without recompilation.

## Acceptance coverage

- Full transitive owner source and migration pins, unknown-version refusal and helper-drift refusal, with historical pin controls.
- Exact document/request/playthrough/actor ownership and empty-attachment checks; absent or mutated decision rows, mismatched identities and attempted attachment mutations refuse.
- Actual facade bootstrap, catalog, mailbox, detail, compose and read-only reconcile against a fresh private PostgreSQL fixture on TEST2.
- Restore overlap, stale authority, lease/transaction ownership, signed unknown/partial, operator close/pruning/late receipts and no resend.
- Whole original forwarded bodies: retain stock renderer behavior for the 775-character delivered example and 1,845-character queued overflow example, without truncation or modified renderer assets.
- Other owner adapters, fifth-tab assets, game binaries, PCV and Brainwash components preserved against published .12.
- Four frozen pairing receipts, raw SQL/row/queue evidence, exact source hashes and SF owner's independent acceptance.

## Isolation and limits

Use only a fresh Department-owned, PostgreSQL cluster with namespace-private loopback and an owned admin socket in a route-free network namespace. The reservation does not grant ownership of the running TEST2 distro or any preexisting database. Record actual host state before/after; stop only the exact owned cluster. Never claim a stopped distro if it remained running.

No live data, installed game files, CHIM core/AIAgent.dll, provider calls or new native compilation are authorized by these checks. Source, browser, package and synthetic PostgreSQL results do not prove actual Skyrim input, focus, audio, physical transfers or save/load behavior.

## Source and UI checkpoint

Root independently passed the exact19-file source/drift/identity/projection regression and51 browser cases plus the separate fifth-tab contract, and inspected the closed-unknown screen. The independent direct PHP/PostgreSQL run passed all 33 facade and five companion case groups, plus eight fresh prechecks. Mailbox revisions include the bounded companion decision identity map so changed ownership cannot reuse an old page cursor.

The fixture uses full historical core0ec590b42c8087860138bb4e9aab470049ff9a2b. A read-only comparison against installed TEST2 core468584f7bfc34c2eaf76e6188efaf3294f4fd63c found eight selected reader/lifecycle/timestamp PHP files LF-equivalent and both stock renderer assets byte-identical. This establishes selected-input equivalence, not execution of the entire newer installed core.

Baseline handoff: the Malpractice owner confirmed prealpha.12 remains immutable and no further Department release is planned there. Root merged its exact five-file delta, checked all seven Malpractice dev.3 pins against the real source, and reserved prealpha.13. The .12 binary remains unchanged; the new server/UI source freeze precedes runtime. Both old releases remain available.

## Frozen runtime and release evidence

Accepted run `20261025T011803Z-95a75e6d` binds 3,231 source entries: 3,081 pinned historical CHIM core inputs, 86 Department server inputs, 19 owner-closure inputs plus 11 remaining owner payload inputs, and two 17-file harness maps. The raw input freeze SHA-256 is `06ccd015eda271136ec5d590a0f2e8045593ce74d820b7efacb948d5cbba2657`. The full owner payload remains the published dev.23 tag.

The private PostgreSQL instance (PID 22699, system identifier 7700409541917907104) was stopped only after its owned datadir/socket/identity were verified. TEST2 remained running before and after; the strict lifecycle receipt records `testDistroStopVerified:false` and preserved-running state. Source postchecks, protected cached bytes, all 38 SQL chunk hashes and row deltas passed. No provider, live, shared/campaign database, installed game or CHIM core mutation occurred. Failed earlier fixtures remain separate from this accepted run.

Root independently reviewed the raw evidence, actual controller/probe outputs and source-supported read fences. The [published SF gate at 29866b](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/29866b20925c4d1b3e0aa49b6014332dcc868e7f/scripts/release-preflight.py) accepted the final tested-paired evidence with 38 cases. The optional [pairing-proof ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.13/WorldOfDramaLlama-0.1.0-prealpha.13-pairing.zip) contains exactly the four sibling JSON receipts. It contains no raw private server state or credentials.

## Publication

[Prealpha.13](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.13) was published at 2026-10-25T01:48:19Z from immutable source `ada38ef84f7cb4e8a17684c4a5dfda4cd528d5a6`. Both owners accepted the exact proof and archives. All four anonymous downloads match the accepted files and GitHub digests; the 11 earlier releases, 83 assets and 22 tag references remain unchanged. The primary checkout has been fast-forwarded; this later documentation record does not alter the published tag or archives.
