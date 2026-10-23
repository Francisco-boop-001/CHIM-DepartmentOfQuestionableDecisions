# Install-and-use Department onboarding

Date: 2026-10-23. Status: implementation in isolated development worktree; no installed/live changes.

## Outcome

One MO2 installable game ZIP carries the companion, its existing light ESP/Papyrus/UI, and two standard CHIM schema-4 DWPkgs for the owned questionable_decisions and pcv_companion extensions. Existing owner plugins remain dependencies. CHIM performs its normal automatic server-plugin sync. The user opens the Department, selects Connect to CHIM, approves that game on the familiar CHIM Plugin Page, and returns to the connected interface. No Linux commands, worker environment edits, token copying, or shared/default secret.

The coordinator selects this implementation from the user's explicit install-and-use requirement. Existing live/core protections remain binding. This is a correction to delivery and setup, not a general server/auth redesign.

## Source-backed approach

Pinned CHIM native 5ca1bc361d1e11a3c58067599b494b1101c2c77b discovers its server from explicit AIAgent.ini or its local discovery service/default. There is no exported connection/auth getter. Mirror the actual discovery behavior read-only; bind Department HTTP to the exact native-derived startup destination. Never widen trust to every private address, accept an endpoint from JavaScript, or invent an alternative server when present configuration is malformed. Retain startup/current destination mismatch guards for private text dispatch. Plain HTTP inherits the configured CHIM trusted-network boundary; it is not encrypted transport.

Pinned HerikaServer 0ec590b42c8087860138bb4e9aab470049ff9a2b provides schema-4 package sync and config_url pages. Its management pages use session CSRF but have no verified account/role authentication. Therefore approval is explicit consent by a person with access to that existing control panel, not proof of an authenticated administrator account.

Alternatives rejected: manual CLI/INI setup fails the user requirement; anonymous credential issuance removes the authorization boundary; putting a partial PCV overlay into a private_conversation DWPkg would replace another owner's complete extension.

## Connection protocol

- Native creates a qd1_ bearer containing 32 CSPRNG bytes only when no per-endpoint credential exists. Persist it through Windows Credential Manager, outside release files, saves, browser URLs and JavaScript. Reuse it across mod updates and reconnects.
- POST the fixed connect.php route with action begin, claimId UUID and SHA-256 tokenHash. Server stores a bounded pending claim for ten minutes, grants no privilege, and never receives the raw token through this flow.
- Native opens the fixed Department Plugin Page on a user Connect click. The page uses same-origin checks, session CSRF and an explicit approval POST. It grants the existing pcv/inspector/compose scopes; recorded-author detail stays opt-in/off by default.
- Status requests contain action status and claimId only. Responses contain fixed pending/approved/expired state and the same claimId. Native validates the response and keeps credentials out of the UI.
- Existing nonrevoked token hashes retain the exact pairId. Revoked hashes are not silently restored. Approval never clears journal/state, unknown operations, or replay fences.
- In-game operations connection.begin and connection.status take empty payloads. Native returns state and approvalOpened for begin, state only for status; claimId remains native-only and is validated on native/server replies. After approval the UI performs a fresh bootstrap, retaining view/generation guards.

## Automatic paths and persistence

Derive CHIM and owner roots from the installed extension layout. Preserve explicit legacy external QD_* overrides. Default private files live only within the canonical owned CHIM-root/data/questionable_decisions-private directory, outside extension replacement. Core already uses its data directory for package state. DWPkg mutable_paths is empty: its mutable-path preservation copies data, which could split active locks or lose concurrent journal writes if runtime state lived within the replaced extension. Do not relax arbitrary external-file validation. Managed data, journal records and temporary writes use PHP-guarded files; direct HTTP requests must exit without exposing JSON. Retain restrictive permissions, containment and symlink checks, atomic writes, directory fsync, locking, restore fencing and retained replay tombstones. No ephemeral temporary-directory default.

The setup action may activate the already-approved seven-file PCV0.1.23 overlay only after exact base/postimage checks, backup and serialized all-or-rollback installation. Staged overlay bytes remain version-pinned. It must never replace a different owner revision blindly. A missing/incompatible optional owner remains unavailable without breaking other tabs. The owned pcv_companion extension is installed by its own DWPkg.

## Ownership and verification

- GPT-6-LUNA/max onboarding_native: native/**, endpoint discovery/trust, credential storage, fixed connection route and host regressions.
- GPT-6-LUNA/max onboarding_server: server/** and pcv-companion/companion.php, Plugin Page, default paths, guarded persistence, claim approval and focused PHP/HTTP tests.
- GPT-6-LUNA/max onboarding_package: ui/** first, then coordinated packaging only; preserve bootstrap failure causes and add minimal Connect/check-connection controls.
- Coordinator: independent review, integration decisions, documentation/ledger, exact source and archive verification, final candidate. Preserve the existing untracked dist/evidence trees.

Success requires a fresh-install rehearsal using ordinary package activation, no QD_* environment values, no prefilled INI/token and no pre-existing credential; successful explicit approval and authenticated bootstrap; persistence across restart/update; refusal of expired/mismatched/revoked claims and stale endpoint/view replies; direct HTTP refusal of guarded private files; unchanged replay/restore behavior. Exercise only new/changed boundaries, then one native DLL build after review. A browser/host test is not Skyrim input/audio acceptance. Never use live, replace AIAgent.dll or modify CHIM core.
Discovery proof limit: without AIAgent.ini the companion mirrors the pinned CHIM local discovery/default behavior and captures one startup result. The pinned CHIM binary exposes no getter for its own cached endpoint. Source equivalence does not prove that two startup reads saw the same discovery-service value. Explicit INI installations keep the existing startup/current byte and destination guard. No repeated discovery polling or CHIM binary patch is introduced.
