# Sealed Fates dev.24 compatibility

Status: source-only Department prealpha.15 compatibility candidate. TEST use only; Skyrim acceptance remains pending.

## Exact owner source and delta

The supported Sealed Fates input is tag `correspondence-v0.1.0-dev.24`, commit `80821db59d3e1c299bee56b4496cf20a144984fd`. Its manifest identifies `0.1.0-dev.24`. The reviewed owner-source delta from dev.23 is limited to `manifest.json` and one predicate in `lib/runtime.php`: the maximum companion claim time is restricted to `claimed_gamets <= $gamets` before the cooldown is derived. A claim from the future therefore cannot make a query at an earlier game time appear to have no prior claim.

The Department adds the exact 19-file dev.24 owner map to `server/adapters/correspondence.php`. Existing dev.23 and earlier supported maps remain pinned. The source-contract check exercises the dev.24 closure and uses the exact dev.23 source as the manifest-replacement regression control. It also retains the existing companion compose/detail/reconcile gates and refuses source drift.

## Evidence

The four focused checks passed: `dev24SourceContract`, `dev23Regression`, `pairingReadiness`, and `protectedPayload`. The package compatibility input records each check's receipt hash and binds them to the selected Department source commit, the adapter and protocol hashes, the immutable owner commit, and the exact dev.24 pin map. The package builder rejects a receipt whose owner pin differs from the adapter's literal dev.24 map.

The PHP source and projection checks exercise the selected owner helpers against isolated fixtures; they establish source identity, compatibility selection, pairing readiness and the protected-payload comparison. They do not establish a dev.24 PostgreSQL, HTTP/provider, actual CHIM-server, or Skyrim result. In particular, the historical dev.23 38-case PostgreSQL run is evidence for dev.23 only; it is not dev.24 proof.

## Prealpha.15 package boundary

The package builder starts from the verified prealpha.14 archive (SHA-256 `1a68afa602760860e9a7b9be5c1b234264b9668f340367b5f0f1f7f90be529a6`) and permits replacing only the Department DWPkg. Its outer and server manifests advance to prealpha.15, the correspondence adapter carries the new owner pin, and the DWPkg checksum list is regenerated. All 21 other installable members remain byte-identical. The source ZIP, compatibility-proof ZIP and `SHA256SUMS.txt` remain separate from the MO2 installable.

No native rebuild is needed for this server-adapter update. Use the prealpha.15 candidate only in a disposable test profile, save and server. Do not treat source/package verification as gameplay acceptance.
