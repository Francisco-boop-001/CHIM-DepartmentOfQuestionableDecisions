# World of Drama Llama: The Department of Questionable Decisions

*Conversations, relationships, grudges, and suspicious mail.*

An experimental companion mod that puts the World of Drama Llama plugins in their own PRISMA UI window inside Skyrim. It supplies its own SKSE DLL and view. It does not replace `AIAgent.dll` or patch CHIM core.

The four tabs cover Private Conversation, Mind Poisoning, Sworn & Scorned and Sealed Fates. Controls depend on the installed owner's supported version, the current perspective and pairing permissions. Unsupported source versions refuse access instead of guessing their contracts.

Private Conversation offers nearby eligible actor selection and its own armed text box through stock CHIM's public Papyrus route. The original Private Conversation input method remains available when the Department is disabled. Detectable duplicate-name ambiguity is refused; the stock router's final body selection is not an atomic identity guarantee.

This is a PRE-ALPHA for a disposable test profile, save and separate server/database. It has not been accepted in Skyrim. Browser, native contract and synthetic server checks cannot prove game focus, controller behavior, audio, final NPC delivery, transfers or save/load behavior.

## Requirements

Skyrim SE/AE, matching SKSE64 and Address Library, PRISMA UI with its V1 API, SkyUI, CHIM, and the supported complete owner plugin releases. VR is not a supported build target. Install the owner plugins' own required game components as well.

The masterless ESP is ESL-flagged: **one light-plugin slot, no full-plugin slot**. This is not a zero-slot mod. Historical prealpha.4 supported exact Sealed Fates dev.14–18 and dev.20; the current matrix is listed below. Dev.18 requires serialized restore/init as described in [dev.18 compatibility](docs/DEV18-COMPATIBILITY.md); the separately verified [dev.20 pairing](docs/DEV20-COMPATIBILITY.md) covers controlled restore overlap. Prealpha.3 remains limited to dev.14–18. The complete Sealed Fates owner remains a separate dependency; dev.19 is not a supported pairing.

The [prealpha.5 download](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.5) additionally accepts exact [Sealed Fates dev.21](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.21), source `bec4066b509361489daf12c59a882b6c426b8460`, through the existing dev.20 capability paths. This is a server-adapter pin update: its game/DLL and notices archives are reused byte-for-byte from prealpha.4. The focused source/readiness check covers the new pairing and drift refusal; this release adds package verification, not a new PostgreSQL or Skyrim acceptance result. Prealpha.4 remains unchanged and refuses dev.21. See [the pairing check](docs/PAIRING-CHECK.md).

Prealpha.6 was a source/package-only update from prealpha.5 for exact Mind Poisoning 0.1.30. At that release, only the MP adapter changed; PCV 0.1.22, SAS 0.1.11 and Sealed Fates dev.21 remained pinned. That matrix was superseded by prealpha.7 below. Its source-readiness probe did not establish database, HTTP, provider, native or Skyrim proof; the earlier candidate remains immutable.

## Current prealpha.7 source matrix

Prealpha.7 pairs Private Conversation 0.1.23 (with the required seven-file Department overlay), Mind Poisoning 0.1.30, Sworn & Scorned 0.1.12, and exact source-pinned Sealed Fates dev.14–18 plus dev.20, dev.21, and dev.22. The PCV, SAS, and Correspondence adapters use focused source-only readiness receipts; the MP 0.1.30 adapter remains byte-identical to prealpha.6. The package reuses prealpha.6 game/DLL and notices bytes. This is source/package evidence, not database, HTTP/provider, native, Skyrim, or gameplay acceptance. The original PCV input path remains available when the Department companion is disabled. Previously recorded critique findings and known limits remain unresolved. See [the pairing record](docs/PAIRING-CHECK.md), [verification record](docs/VERIFICATION.md), and [test-package guide](packaging/COMPANION-ONLY-TEST-PACKAGE.md).

## Installation and testing

Follow the [installation guide](packaging/COMPANION-ONLY-TEST-PACKAGE.md) and [test-profile setup](docs/TEST-PROFILE-SETUP.md) before installing anything. Use a profile-local endpoint override and a new test pairing. The private textbox currently requires a matching literal `http://127.0.0.1` destination for both stock CHIM and the companion, with verified Windows-to-test-server forwarding and ordinary CHIM streaming. Restart Skyrim after endpoint changes.

Send once. If a write or text send becomes uncertain, use its status/reconciliation control and the [state recovery policy](docs/STATE-RECOVERY-POLICY.md). Do not resend it or delete its state to remove a lock. A consumed text token records acceptance by the PCV route, not audible delivery. Durable replay protection means private server history grows and needs disk monitoring.

Sealed Fates retains up to three unresolved letter IDs. New letter / discard draft clears the editor while preserving those IDs for reconciliation. It refuses the same writer/recipient/text while unresolved and serializes letters carrying items or gold. A missing receipt never authorizes resend. These local guards survive ordinary view reopen, not a full UI/process restart; preserve uncertain server state.

Use only the test distribution. Keep the live profile, shared CHIM files and live server untouched.

## Source and evidence

The [public Git repository](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions) is the source of truth. Use `main` for current development and an immutable release tag for reproducing a downloaded candidate. Named Git worktrees are isolated checkouts of that same repository; use `git worktree list` to identify them. The earlier unpublished `CHIM-DramaLlama-Interface/implementation` tree is a historical snapshot, not a current build source. Historical receipts and frozen inputs remain evidence for the revisions they name.

This repository contains the reviewed companion source projection and reproducible build instructions. Third-party dependencies are acquired separately at pinned identities; their notices accompany the release. Historical experimental client replacements, raw private evidence, credentials and server state do not belong in this repository.

Release verification distinguishes source/build/package identity, synthetic behavior and pending gameplay acceptance. Read the [verification record and known limitations](docs/VERIFICATION.md) before testing.
