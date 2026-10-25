# World of Drama Llama: The Department of Questionable Decisions

*Conversations, relationships, grudges, and suspicious mail.*

An experimental companion mod that puts the World of Drama Llama plugins in their own PRISMA UI window inside Skyrim. It supplies its own SKSE DLL and view. It does not replace `AIAgent.dll` or patch CHIM core.

The five tabs cover Private Conversation, Mind Poisoning, Sworn & Scorned, Sealed Fates and Malpractice Makes Perfect. Controls depend on the installed owner's supported version, the current perspective and permissions. Unsupported source versions refuse access instead of guessing their contracts.

Private Conversation offers nearby eligible actor selection and its own armed text box through stock CHIM's public Papyrus route. The original Private Conversation input method remains available when the Department is disabled. Detectable duplicate-name ambiguity is refused; the stock router's final body selection is not an atomic identity guarantee.

This is a PRE-ALPHA for a disposable test profile, save and separate server/database. It has not been accepted in Skyrim. Browser, native contract and synthetic server checks cannot prove game focus, controller behavior, audio, final NPC delivery, transfers or save/load behavior.

## Requirements

Skyrim SE/AE, matching SKSE64 and Address Library, PRISMA UI with its V1 API, SkyUI, CHIM, and the supported complete owner plugin releases. VR is not a supported build target. Install the owner plugins' own required game components as well.

The masterless ESP is ESL-flagged: **one light-plugin slot, no full-plugin slot**. This is not a zero-slot mod. Historical prealpha.4 supported exact Sealed Fates dev.14–18 and dev.20; the current matrix is listed below. Dev.18 requires serialized restore/init as described in [dev.18 compatibility](docs/DEV18-COMPATIBILITY.md); the separately verified [dev.20 pairing](docs/DEV20-COMPATIBILITY.md) covers controlled restore overlap. Prealpha.3 remains limited to dev.14–18. The complete Sealed Fates owner remains a separate dependency; dev.19 is not a supported pairing.

The [prealpha.5 download](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.5) additionally accepts exact [Sealed Fates dev.21](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.21), source `bec4066b509361489daf12c59a882b6c426b8460`, through the existing dev.20 capability paths. This is a server-adapter pin update: its game/DLL and notices archives are reused byte-for-byte from prealpha.4. The focused source/readiness check covers the new pairing and drift refusal; this release adds package verification, not a new PostgreSQL or Skyrim acceptance result. Prealpha.4 remains unchanged and refuses dev.21. See [the pairing check](docs/PAIRING-CHECK.md).

Prealpha.6 was a source/package-only update from prealpha.5 for exact Mind Poisoning 0.1.30. At that release, only the MP adapter changed; PCV 0.1.22, SAS 0.1.11 and Sealed Fates dev.21 remained pinned. That matrix was superseded by prealpha.7 below. Its source-readiness probe did not establish database, HTTP, provider, native or Skyrim proof; the earlier candidate remains immutable.

## Current prealpha.12 TEST candidate

Prealpha.12 pins Malpractice Makes Perfect dev.3. Its standalone web editor opens directly, without a separate plugin login, password file or CLI setup. Session-bound CSRF and exact-origin checks remain required for web requests. PRISMA keeps its normal automatic CHIM connection and permission checks.

Prealpha.12 includes [Malpractice Makes Perfect](https://github.com/Francisco-boop-001/CHIM-MalpracticeMakesPerfect/releases/tag/v0.1.0-dev.3) for draft/review/selective apply/undo of NPC profiles, with separate fictional beliefs and the explicit `brainwash` permission. It keeps the PCV 0.1.23, Mind Poisoning 0.1.30, Sworn & Scorned 0.1.12, and source-pinned Sealed Fates matrix described in [the pairing record](docs/PAIRING-CHECK.md). It starts native CHIM connection setup automatically when the game UI opens and checks the result in the background. The single MO2 archive contains the game mod and three schema-4 CHIM server packages. CHIM automatically prepares the seven-file PCV compatibility overlay when it finds the complete supported PCV owner release. The package does not contain or replace that owner plugin, and the original PCV input path remains available when the Department companion is disabled.

This is still a TEST PRE-ALPHA. The normal TEST2 package installer and authenticated web/Department draft/apply/undo workflows passed, along with browser/native checks. Actual behavior inside Skyrim and real-model AI drafting remain unverified. See [the integration checks](docs/BRAINWASH-IMPLEMENTATION.md) and [earlier limits](docs/VERIFICATION.md).

The next candidate, prealpha.13, adds exact Sealed Fates dev.23 support and retains all five tabs and Malpractice dev.3. It is under verification; use the published package above until the [dev.23 pairing record](docs/DEV23-COMPATIBILITY.md) records acceptance and publication. Do not install an older combined Malpractice package over a newer Department release.

## Installation and connection

Download [WorldOfDramaLlama-0.1.0-prealpha.12.zip](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.12/WorldOfDramaLlama-0.1.0-prealpha.12.zip) and install it through MO2 as an ordinary mod. Enable it with its required Skyrim/SKSE, Address Library, PRISMA UI, SkyUI, CHIM, and owner-plugin requirements. Run CHIM's normal game/plugin synchronization so it detects the bundled server packages, then open the Department UI in Skyrim. Connection setup starts automatically; no browser step, copied token, or manual INI edit is part of normal setup. If CHIM cannot confirm the game connection, use **Retry connection** in the UI. The package has no `AIAgent.dll` and does not patch CHIM core.

The Department ZIP and the Malpractice Makes Perfect TEST ZIP contain the same matched mod payload. Install one of them, replacing the previous Department mod; do not enable both copies.

The native mod derives the server destination from CHIM's startup configuration and stores the stable connection credential in Windows Credential Manager. The shipped `WorldOfDramaLlama.ini` keeps `BaseUrl` and `PairToken` blank. Install the complete supported PCV 0.1.23 owner plugin separately. CHIM applies the Department's pinned seven-file PCV overlay automatically only when the installed owner files match the supported source; a mismatch leaves the owner files untouched and the other Department tabs available. See the [install-and-use guide](packaging/COMPANION-ONLY-TEST-PACKAGE.md) for the supported test setup.

When CHIM's `AIAgent.ini` is absent, the native mod uses CHIM's pinned local discovery request and matching default. This is a one-time source-equivalent startup snapshot: CHIM exposes no public getter for its already-cached destination. A present but malformed or incompatible `AIAgent.ini` is refused rather than bypassed. Restart Skyrim after changing CHIM's endpoint. Plain HTTP is accepted only for the exact CHIM-derived destination and provides no confidentiality from other systems on that network; use a trusted network.

Send once. If a write or text send becomes uncertain, use its status/reconciliation control and the [state recovery policy](docs/STATE-RECOVERY-POLICY.md). Do not resend it or delete its state to remove a lock. A consumed text token records acceptance by the PCV route, not audible delivery. Durable replay protection means private server history grows and needs disk monitoring.

Sealed Fates retains up to three unresolved letter IDs. New letter / discard draft clears the editor while preserving those IDs for reconciliation. It refuses the same writer/recipient/text while unresolved and serializes letters carrying items or gold. A missing receipt never authorizes resend. These local guards survive ordinary view reopen, not a full UI/process restart; preserve uncertain server state.

Use only the test distribution. Keep the live profile, shared CHIM files and live server untouched.

## Source and evidence

The [public Git repository](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions) is the source of truth. Use `main` for current development and an immutable release tag for reproducing a downloaded candidate. Named Git worktrees are isolated checkouts of that same repository; use `git worktree list` to identify them. The earlier unpublished `CHIM-DramaLlama-Interface/implementation` tree is a historical snapshot, not a current build source. Historical receipts and frozen inputs remain evidence for the revisions they name.

This repository contains the reviewed companion source projection and reproducible build instructions. Third-party dependencies are acquired separately at pinned identities; their notices accompany the release. Historical experimental client replacements, raw private evidence, credentials and server state do not belong in this repository.

Release verification distinguishes source/build/package identity, synthetic behavior and pending gameplay acceptance. Read the [verification record and known limitations](docs/VERIFICATION.md) before testing.
