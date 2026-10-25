# Sealed Fates dev.23 pairing

Status: candidate under implementation and verification; no dev.23 Department release is accepted yet.

The candidate starts from Department prealpha.11 (`623343f7620239be4462d96d877683623b0fe9d7`), retaining Malpractice Makes Perfect dev.2, Private Conversation 0.1.23, Mind Poisoning 0.1.30 and Sworn & Scorned 0.1.12. The exact Sealed Fates input is immutable tag `correspondence-v0.1.0-dev.23`, commit `01d62982d9b10ca4fce040af9d97fe7111231ba3`. Prealpha.11 and older releases remain immutable and do not accept this new owner version.

## Changed boundary

Sealed Fates dev.23 adds automatic companion letters. Their payer is the writing NPC, their recipient is the player, and they carry no items or gold. Department historically accepted player-paid documents only. The candidate must recognize an exact recorded companion-owned document without treating arbitrary NPC-paid documents as trusted.

Ownership is per document and survives queued, stale, failed, unknown and operator-closed states. NPC opt-in alone never disables an ordinary manually authored letter or player-funded gifts. Department reconciliation remains a read of the original operation; it must not invoke a scheduler, dispatch a new delivery, retry or erase an uncertain operation. Receipt proof remains the authority for delivered mail, including valid late receipts after operator closure.

The two result flags `companionSchedulerOwned` and `operatorClosed` describe independent properties; they do not replace the existing delivery state. Catalog and compose request schemas remain unchanged. The published prealpha.11 DLL accepts these optional compose/reconcile data fields without recompilation.

## Acceptance required before publication

- Full transitive owner source and migration pins, unknown-version refusal and helper-drift refusal, with historical pin controls.
- Exact document/request/playthrough/actor ownership and empty-attachment checks; absent or mutated decision rows, mismatched identities and attempted attachment mutations refuse.
- Actual facade bootstrap, catalog, mailbox, detail, compose and read-only reconcile against a fresh private PostgreSQL fixture on TEST2.
- Restore overlap, stale authority, lease/transaction ownership, signed unknown/partial, operator close/pruning/late receipts and no resend.
- Whole original forwarded bodies: retain stock renderer behavior for the 775-character delivered example and 1,845-character queued overflow example, without truncation or modified renderer assets.
- Other owner adapters, fifth-tab assets, game binaries, PCV and Brainwash components preserved against published .11.
- Four frozen pairing receipts, raw SQL/row/queue evidence, exact source hashes and SF owner's independent acceptance.

## Isolation and limits

Use only a fresh Department-owned, PostgreSQL cluster with namespace-private loopback and an owned admin socket in a route-free network namespace. The reservation does not grant ownership of the running TEST2 distro or any preexisting database. Record actual host state before/after; stop only the exact owned cluster. Never claim a stopped distro if it remained running.

No live data, installed game files, CHIM core/AIAgent.dll, provider calls or new native compilation are authorized by these checks. Source, browser, package and synthetic PostgreSQL results do not prove actual Skyrim input, focus, audio, physical transfers or save/load behavior.

## Source and UI checkpoint

Root independently passed the exact19-file source/drift/identity/projection regression and51 browser cases plus the separate fifth-tab contract, and inspected the closed-unknown screen. Real PostgreSQL acceptance remains pending. Mailbox revisions include the bounded companion decision identity map so changed ownership cannot reuse an old page cursor.

The fixture uses full historical core0ec590b42c8087860138bb4e9aab470049ff9a2b. A read-only comparison against installed TEST2 core468584f7bfc34c2eaf76e6188efaf3294f4fd63c found eight selected reader/lifecycle/timestamp PHP files LF-equivalent and both stock renderer assets byte-identical. This establishes selected-input equivalence, not execution of the entire newer installed core.
