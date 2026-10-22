# World of Drama Llama: The Department of Questionable Decisions

*Conversations, relationships, grudges, and suspicious mail.*

An experimental companion mod that puts the World of Drama Llama plugins in their own PRISMA UI window inside Skyrim. It supplies its own SKSE DLL and view. It does not replace `AIAgent.dll` or patch CHIM core.

The four tabs cover Private Conversation, Mind Poisoning, Sworn & Scorned and Sealed Fates. Controls depend on the installed owner's supported version, the current perspective and pairing permissions. Unsupported source versions refuse access instead of guessing their contracts.

Private Conversation offers nearby eligible actor selection and its own armed text box through stock CHIM's public Papyrus route. The original Private Conversation input method remains available when the Department is disabled. Detectable duplicate-name ambiguity is refused; the stock router's final body selection is not an atomic identity guarantee.

This is a PRE-ALPHA for a disposable test profile, save and separate server/database. It has not been accepted in Skyrim. Browser, native contract and synthetic server checks cannot prove game focus, controller behavior, audio, final NPC delivery, transfers or save/load behavior.

## Requirements

Skyrim SE/AE, matching SKSE64 and Address Library, PRISMA UI with its V1 API, SkyUI, CHIM, and the supported complete owner plugin releases. VR is not a supported build target. Install the owner plugins' own required game components as well.

The masterless ESP is ESL-flagged: **one light-plugin slot, no full-plugin slot**. This is not a zero-slot mod. Exact package size and supported version pins belong to the release manifest. This prealpha.4 candidate supports exact Sealed Fates dev.14–18 and dev.20. Dev.18 requires serialized restore/init as described in [dev.18 compatibility](docs/DEV18-COMPATIBILITY.md); the separately verified [dev.20 pairing](docs/DEV20-COMPATIBILITY.md) covers controlled restore overlap. Prealpha.3 remains limited to dev.14–18. The complete Sealed Fates owner remains a separate dependency; dev.19 is not a supported pairing.

Current `main` also accepts exact [Sealed Fates dev.21](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.21), source `bec4066b509361489daf12c59a882b6c426b8460`, through the existing dev.20 capability paths. This is a server-adapter pin update; it changes no DLL, UI, owner source or CHIM core. The focused source/readiness check covers the new pairing and drift refusal; it is not a new PostgreSQL or Skyrim acceptance result. The immutable prealpha.4 download still contains its original dev.20 pins. See [the pairing check](docs/PAIRING-CHECK.md) for the dev.21 command.

## Installation and testing

Follow the [installation guide](packaging/COMPANION-ONLY-TEST-PACKAGE.md) and [test-profile setup](docs/TEST-PROFILE-SETUP.md) before installing anything. Use a profile-local endpoint override and a new test pairing. The private textbox currently requires a matching literal `http://127.0.0.1` destination for both stock CHIM and the companion, with verified Windows-to-test-server forwarding and ordinary CHIM streaming. Restart Skyrim after endpoint changes.

Send once. If a write or text send becomes uncertain, use its status/reconciliation control and the [state recovery policy](docs/STATE-RECOVERY-POLICY.md). Do not resend it or delete its state to remove a lock. A consumed text token records acceptance by the PCV route, not audible delivery. Durable replay protection means private server history grows and needs disk monitoring.

Sealed Fates retains up to three unresolved letter IDs. New letter / discard draft clears the editor while preserving those IDs for reconciliation. It refuses the same writer/recipient/text while unresolved and serializes letters carrying items or gold. A missing receipt never authorizes resend. These local guards survive ordinary view reopen, not a full UI/process restart; preserve uncertain server state.

Use only the test distribution. Keep the live profile, shared CHIM files and live server untouched.

## Source and evidence

The [public Git repository](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions) is the source of truth. Use `main` for current development and an immutable release tag for reproducing a downloaded candidate. Named Git worktrees are isolated checkouts of that same repository; use `git worktree list` to identify them. The earlier unpublished `CHIM-DramaLlama-Interface/implementation` tree is a historical snapshot, not a current build source. Historical receipts and frozen inputs remain evidence for the revisions they name.

This repository contains the reviewed companion source projection and reproducible build instructions. Third-party dependencies are acquired separately at pinned identities; their notices accompany the release. Historical experimental client replacements, raw private evidence, credentials and server state do not belong in this repository.

Release verification distinguishes source/build/package identity, synthetic behavior and pending gameplay acceptance. Read the [verification record and known limitations](docs/VERIFICATION.md) before testing.
