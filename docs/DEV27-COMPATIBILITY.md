# Sealed Fates dev.27 compatibility

The published prealpha.22 Department recognizes the exact Sealed Fates `0.1.0-dev.27` server closure. It keeps the adapter's existing 19-file source boundary and extends the same four version gates already used by dev.26.

The dev.27 closure differs from dev.26 only in `manifest.json` (`157610b7d2d79cf68d6203c61fb7768399b3d5b6d4e0bdfc56651d2d0ab3b22d`). The other 18 pinned runtime files retain their dev.26 hashes. The owner release also changes `lib/view.php`; that browser page is outside this adapter's runtime closure.

The version-specific capabilities remain independently gated: restore fencing, signed unknown receipts, operator closure, and companion scheduler ownership. Historical dev.23 through dev.26 pins and gates remain in place; dev.19 and unknown dev.28 remain refused.

`server/tests/correspondence/dev27-source-contract.php` runs the source-only contract against an exact dev.27 server tree and the immutable dev.23 manifest-replacement fixture. It checks all 19 pins, per-file drift refusal, identity, retained maps, and owner-only projections. The retained dev.26 contract remains available through `dev26-source-contract.php`.

These checks use downloaded source and a temporary local staging directory. They do not run a CHIM database, live server request, provider call, CHIM client, or Skyrim session, and do not require DLL recompilation.

Verification completed: root matched all30 public owner server files to tag commit `d1eaf714a7661a1f785a4d8c2131c3565a6eddee`, executed exact27 and retained26/25 source/drift/identity/payer/receipt checks, and confirmed two ready19-pin source probes. Source, deterministic package, official changed-archive reader and anonymous public-download checks passed. See [the published verification record](VERIFICATION.md). No new DB/HTTP/Skyrim result is claimed.
