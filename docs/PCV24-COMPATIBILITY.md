# PCV 0.1.24 paired timeout compatibility — published

## Status

Published [prealpha.20](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.20) at 2026-10-26T20:53:15Z from immutable source `cc33082885aa938d588eeaf5462e67b557ab282e`, paired with PCV 0.1.24 at `ce0a998773237a9fc91f6914725a327d9e621718` and bundled PCV companion 11. Root verified 22 installable members with 20 unchanged payloads, 227 Git-matched public source files, all three new DWPkgs through the unmodified CHIM archive validator, and all four draft/anonymous-public asset downloads against local bytes, GitHub digests and GNU checksums. Installable: 618763 bytes, SHA-256 `2a60c5ac9d012c7ee30d6286b876ec3d117566ea1374ff2e9801bed34416b720`. All 18 prior releases, 111 assets and 36 tag refs remain unchanged. This docs-only completion leaves the tag and assets immutable.

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

Only the Department and PCV companion DWPkgs changed from prealpha.19; all other 20 of 22 installable payloads, including DLL/UI/game assets, are byte-identical. ZIP timestamp 2026-10-26 00:00:06 sorts after prealpha.19. No DLL rebuild.

Actual isolated PostgreSQL 15.14/PHP 8.2.35: owner 18/18 and exact overlay 6/6 passed, including 2s lock bounds, 3s statement cancellation, stricter positive settings, shared caller-state preservation, full native catalog parity and official restore-barrier refusal. Root final literal-pinned migration 15/15, six focused PHP checks, 17 PHP syntax checks and 7 packaging checks passed. All 39 other-owner source pins and 61 protected sources match. The interrupted retention stress is not a pass; its production store/fallback implementation is byte-identical after the owner pin header. No HTTP/provider operation, whole-request deadline, native startup/build or Skyrim acceptance is inferred; production database behavior is unverified. Both disposable clusters were removed and TEST2 returned Stopped. Live, installed CHIM core, campaign databases and the running game were left alone.
