# PCV 0.1.24 paired timeout compatibility — candidate

## Status

This note describes an unpublished Department prealpha.20 candidate. Prealpha.19 remains the latest published release until the paired package, receipts, archive-consumer check, public bytes, and coordinated publication are reviewed. The selected PCV 0.1.24 owner source is commit `ce0a998773237a9fc91f6914725a327d9e621718`; the `private_conversation-v0.1.24` release tag is planned.

## Required pairing

Install the Department `0.1.0-prealpha.20` ZIP with MO2 using Replace, and update the complete Private Conversation owner to `0.1.24`. The Department ZIP already includes PCV companion `0.1.0-prealpha.11`; there is no separate companion download. PCV controls require this exact pairing. Keep only one Department version enabled and let CHIM synchronize normally. Department automatically archives a verified `.23` backup and records the `.24` originals; players do not move or delete backups or approve a website connection.

Other plugin pins remain Mind Poisoning `0.1.30`, Sworn & Scorned `0.1.12`, Sealed Fates dev.26 with its existing maps, and Malpractice Makes Perfect dev.3. The other plugin payloads, native UI, and DLL remain unchanged; acceptance includes checks against the exact prealpha.19 bytes.

## Source and migration evidence

The `.24` staged-source manifest binds the `.24` owner BASE pins to `.24` POSTIMAGE pins. The legacy `.23` owner pins are a separate verifier input and must continue to match the immutable `.23` source manifest/postimages; they must not be relabeled as `.24` base pins. The canonical migration rollup is `overlay-migration-results.json` and binds the actual migration, refusal, and rollback evidence to the tested sources.

The paired package rollup uses exactly five checks:

- `ownerTimeouts` — `pcv-owner-timeouts.json`
- `overlaidTimeouts` — `pcv-overlay-timeouts.json`
- `overlayMigration` — `overlay-migration-results.json`
- `pcvSourceContract` — `pcv-source-contract.json`
- `otherPlugins` — `other-plugin-compatibility.json`

The owner and overlaid timeout checks use an isolated test database. The package proof does not establish HTTP/provider behavior, a live CHIM server, a whole-request deadline, native startup/build, or Skyrim acceptance. Raw fixtures and receipts remain external to the installable ZIP.

## Package boundary

The candidate rebases from the exact published prealpha.19 installable. Only the Department and PCV companion DWPkgs may change; the remaining 20 installable members, including the accepted DLL and UI/game assets, must match prealpha.19 byte-for-byte. This work does not rebuild the DLL or alter the native UI. The package ZIP timestamp is planned as 2026-10-26 00:00:06, after prealpha.19.

The owner timeout suite passed 18/18 against isolated PostgreSQL; the rebased overlay timeout suite passed 6/6; migration/refusal/rollback passed 15/15 with cleanup verified. Final source-contract and all-other-plugin receipt checks, the canonical paired rollup, final package, archive-consumer review, public-byte review, and coordinated publication remain pending.
