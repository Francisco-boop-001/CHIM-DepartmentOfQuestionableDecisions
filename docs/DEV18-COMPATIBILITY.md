# Sealed Fates dev.18 compatibility candidate

This is a separate Department candidate. The published v0.1.0-prealpha.1 release and its dev.14–17 source pins remain unchanged.

## Dependency and scope

Dev.18 support targets the exact Sealed Fates dev.18 PRE-ALPHA release, not an arbitrary version with the same label. Install the complete matching owner package and its required game components in a disposable test profile/server. The Department package does not distribute or initialize the owner from a read operation. The separate paired owner dependency is [CHIM-Sealed-Fates-0.1.0-dev.18.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.18/CHIM-Sealed-Fates-0.1.0-dev.18.zip) (2,792,708 bytes; SHA-256 d5226ebe217e4801a95002bf7a620ca2da72a0fa18841bd5494eb1ec3ef85e70). It is published separately in the [Sealed Fates dev.18 PRE-ALPHA release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.18), at source commit da09ddc46cece79a06e22671f3de69238cd3cd71. The coordinator independently downloaded all four owner assets anonymously and verified that their bytes match the frozen dependency used here. The Department release does not redistribute those assets. Its bridge bytes match dev.17 and retain their original build metadata. Dev.14–17 support remains version-specific; unknown sources refuse access. Once an owner source set has loaded, the adapter binds its root, version and manifest identity. Corruption or replacement with another supported version in that PHP process refuses access instead of selecting the legacy path.

The change is server-only. The companion DLL, UI, ESP/SEQ and Papyrus assets retain their published identities. Native/browser checks can be reused only after their actual inputs and asset bytes are verified unchanged; that reuse is not new game evidence.

## Known owner limitation: overlapping initialization

The exact Sealed Fates dev.18 owner has a source-confirmed race outside the companion: initialization B can write a newer pending sentinel, fail before changing the database epoch, and then have initialization A overwrite that marker with clear(A). The database and sentinel can again agree even though B failed. The Department cannot reconstruct the erased intent from those current values. Its checks do not close this owner defect.

Use this candidate only in a disposable TEST setup with restore/init operations serialized. Overlapping initialization is not certified; this candidate does not fix that scenario. An owner repair requires its own release, new pins and new pairing tests. The companion does not modify the immutable dev.18 owner or claim compatibility with a later version. Source: the owner [initialization lifecycle](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/correspondence-v0.1.0-dev.18/server/lib/bootstrap.php) and [restore completion](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/correspondence-v0.1.0-dev.18/server/lib/store.php).

## Restore authority

Reads capture the real core generation under the already-held trusted profile lease. They acquire the Sealed Fates shared PostgreSQL advisory fence before beginning the repeatable-read snapshot, validate the existing sentinel and database epoch, and revalidate captured authority before returning. Reads do not call the owner factory, run migrations, create a restore row or repair a sentinel.

Writes preserve the request's original generation and epoch. They cross the Department beginCommit boundary before invoking owner lifecycle initialization, including ensureSchema. The integration lends only the genuine, matching shared work.lock handle already owned by the trusted core helper, keeps it alive throughout owner work/readback, and restores prior globals exactly. It never creates authority by setting a controller flag, substitutes the latest eventlog timestamp for native freshness, refreshes an old request across restore or closes a borrowed lease.

A failed advisory unlock closes only the dedicated owned database connection. Missing, pending, malformed or mismatched authority refuses work. A failure after beginCommit remains uncertain, including initialization failure without a visible letter.

## Unknown receipts

Only exact dev.17/dev.18 source permits signed unknown quantities representing recipient-count deltas. Counter ranges, delta equality, operation/receipt anchors and attachment identity remain required. Delivered and failed receipts retain their stricter rules. Unknown/partial is unconfirmed and does not permit automatic resend.

## Verification gate

The coordinator accepted TEST run 20261021T135003Z-9fa7580a: all ten prechecks and 27/27 direct Department facade cases passed against private PostgreSQL and the complete pinned owner. The run covers normal reads/compose/reconcile, source drift refusal, signed-unknown negatives/overshoots, read-only row fingerprints, stale/pending/mismatch refusal, an unrelated event during preflight, restore/read lock ordering, controller-pause contention, exact globals/borrowed-handle restoration and failed-unlock cleanup. Root independently checked the raw signed-unknown SQL: mapping uses the existing read snapshot, with one shared lock, one repeatable-read BEGIN, one COMMIT, one unlock and no ROLLBACK. Source/protected-state preservation and owned PostgreSQL/TEST cleanup passed. See [verification scope and limitations](VERIFICATION.md); this is direct PHP entry testing, not HTTP or game acceptance.

No Skyrim, native delivery, provider generation, audible output, physical transfer, performance or broad mod-coexistence acceptance is implied. Use the installation guide's game checks after the server candidate passes.
