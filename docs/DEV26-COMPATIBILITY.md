# Sealed Fates dev.26 compatibility

The Department source adapter accepts Sealed Fates `0.1.0-dev.26` only when its exact 19-file runtime closure matches the pinned hashes. The owner snapshot is commit `7fd78802348d5ee7dabee9506ff6ede1c25c0657`; its manifest SHA-256 is `bee3cd6fea49e49c5e631d8da86073c02d2c931100a4da942c42d4488ce2e0f8`.

The owner changed four of its 30 server files from dev.25. Within the Department's transitive runtime closure, only `manifest.json` and `lib/companion.php` changed; the other 17 pinned files remain byte-identical. The other changed files, `index.php` and `lib/view.php`, implement the dashboard's companion-owned safe-close control and do not enter the Department runtime closure. The `lib/companion.php` pin is `6d9b7f5599aa7097e9193a819f0e0b9e8f2744ebbce9c74353601f8a0090be6f`.

The Department keeps its existing dev.23, dev.24, and dev.25 maps and enables the existing restore-fence, signed-unknown, operator-close, and companion-scheduler gates for exact dev.26. Dev.19 remains unsupported, and dev.27 has no pin or capability gates. Mailbox revisions continue to include companion decision ownership; NPC-payer compose requires an exact owner decision identity and rejects attachments; receipt projection preserves `unknown` delivery state separately from operator closure.

The dev.26 owner adds an early read-transaction poll cooldown check, rechecks it under the writer transaction, and exposes its owned document IDs to the dashboard. Its manual guard blocks retry and dispatch for companion-owned documents while allowing safe close. The Department does not call the new polling or dashboard methods; it consumes the pinned companion decision rows through the existing read, compose, and reconciliation paths.

## Verification

Before the adapter edit, `check-owner-pairing.php` refused the exact dev.26 owner with `owner_version_unsupported` and no selected closure pins. After the edit, the focused source contract passed for dev.26 and the retained dev.25 and dev.24 owner snapshots. Each run checked the exact map, drift refusal, retained historical pins and capabilities, and the Department's owner-identity and receipt projections.

The source-only pairing checker returned `ready: true` for dev.26, dev.25, and dev.24, with 19 matching selected pins and all five pairing checks true. The dev.26 owner safe-close regression and companion syntax check also passed. These checks do not exercise PostgreSQL, HTTP/provider calls, an installed CHIM server, or Skyrim.

Raw adapter receipts and commands are retained under `artifacts/department-dev26-20261026/adapter-evidence/`. The owner safe-close and companion syntax check receipts are `artifacts/department-dev26-20261026/root-owner-safe-close.json` and `artifacts/department-dev26-20261026/root-owner-companion-lint.json` under `artifacts/department-dev26-20261026/`.
