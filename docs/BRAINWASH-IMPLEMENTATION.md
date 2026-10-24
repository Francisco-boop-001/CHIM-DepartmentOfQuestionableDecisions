# Brainwash Department PRISMA integration

This is the Department side of the TEST2-only Brainwash candidate `0.1.0-prealpha.10`, on branch `codex/brainwash-20261024`. The Brainwash implementation remains in its separate plugin tree. The coordinator owns the installed packages, final native build, and synchronized shared-editor copies; this worktree does not change CHIM core, game files, live services, providers, or the four prior owner adapters.

## Integration

- Added the explicit `brainwash` scope to fresh native connection issuance and approval. Existing connections retain their original scope set. Inspector and other read scopes do not imply Brainwash access.
- Registered and validated the five contract operations in PHP and C++. Catalog search accepts an optional UTF-8 query of at most 128 bytes. Field selection, object keys, text sizes, response shapes, and owner label bounds are checked before transport.
- Added `server/adapters/brainwash.php` for the flat installed extension layout (`ext/brainwash/runtime.php`). The frozen 0.1.0-dev.1 closure is `api.php`, `bin/provision-passphrase.php`, `context_pre.php`, `index.php`, `lib/service.php`, `lib/web.php`, `manifest.json`, and `runtime.php`. The adapter checks the exact owner version and every pinned file before loading the runtime, maps only fixed owner faults, and passes trusted Department scope/lock callbacks. Missing or changed files and unsupported versions fail closed.
- Brainwash mutations use the owner receipt on replay. Department journal records contain only a compact receipt, so a valid large draft cannot commit and then fail the global response-size bound. The owner is re-entered after undo or scope-generation change instead of replaying an old applied response.
- Added the accessible Brainwash PRISMA tab through `window.BrainwashEditor.mount`. Native/auth/scope invalidation resets its editor state; permission loss disables it. An unknown mutation result remains unresolved and is not presented as safe to retry.
- The foundation fixture now expects PCV capability readiness to remain false until its guarded overlay preconditions are present. Its PCV permission remains separate and true for the test pair; the overlay guard was not weakened.

The coordinator synchronized `ui/brainwash-editor.js` and `.css` from the canonical Brainwash assets. Those copied assets are coordinator-owned and were not edited here. The existing four owner adapters and pins remain outside the diff.

## Verification

Department-owned checks run in the assigned TEST2 worktree:

- `server/tests/brainwash/department-contract.php`: 77 checks passed. Coverage includes all operation payloads, explicit fresh scope, old-scope refusal, installed flat-layout resolution, operation replay through the owner, replay after undo/restore fencing, oversized DTOs, fail-closed readiness, complete pinned-source acceptance, unknown-version refusal, and individual drift refusal for each pinned file.
- Baseline comparison: the same focused contract test was added to a temporary archive of baseline `089067d` and failed as expected at missing `qd_brainwash_call`. Current source passes the 77 checks. The baseline comparison was performed as an audit after implementation; it is not presented as a preserved pre-edit test transcript.
- `server/tests/foundation/run.php`: `PASS 162 server foundation assertions`. The PCV-ready expectation reflects the guarded refusal in this fixture; the overlay precondition itself remains enforced.
- `server/tests/foundation/journal.php --directory-fsync-guard`: `PASS QD01 directory-fsync guard refusal (POSIX-directory-sync)`. The unrelated 4,100-operation retention stress was not run.
- PHP syntax checks passed for the adapter, changed auth/protocol/dispatcher/journal/runtime files, foundation test, and both Brainwash Department tests.
- Focused browser contract: `ui/tests/brainwash.cjs` passed. Existing UI suite: `ui/tests/ui-functional.cjs` 50/50, `ui/tests/ui-interactions.cjs` 1/1, and `ui/tests/ui-render.cjs` produced 11 captures. The render test writes to `K:/ActorwrightExchange/worktrees/evidence/implementation`; the captures were left intact after the out-of-worktree destination was identified.

Coordinator receipts for the frozen/install candidate are under `K:/ActorwrightExchange/artifacts/brainwash-build-20261024/`:

- `native-tests-catalog-fixed.txt`: updated native build and 5/5 host tests.
- `installed-department.json`: successful installed Department facade/auth/Brainwash runtime harness, including bootstrap, catalog/card read, manual draft, same-ID apply replay, narrow undo, and same-ID apply-after-undo conflict.
- `web-workflow-source.json` and `web-workflow.json`: 12/12 source and installed browser workflow checks, respectively.
- `pin-drift.json`: valid closure, unknown version refusal, and all eight individual installed-source drift refusals.
- `test2-install.json`, `preservation-after.json`, `preservation-extra-after.json`, and `runtime-context-cleanup.json`: normal TEST2 package-manager install, exact core/NPC and installed-file preservation, unrelated extension/settings/lock/generation preservation, owned fixture cleanup, and SAS 723 resume.

The installed-runtime harness used the normal installed Department package at `ext/questionable_decisions`, with package identity/version validation, and the exact installed Brainwash source root. Root executed it once and cleaned its owned context afterward. Do not repeat it against that already-consumed fixture.

## Limits and handoff

The installed proof used a fresh CLI-provisioned TEST2 pair, the deterministic manual draft path, and the coordinator-owned fixture NPC/database. No paid AI/provider request was made. Shared core generation was read only; same-ID restore invalidation remains covered by isolated scope/generation tests rather than a real restore or save switch. No Skyrim launch, in-game focus/input/audio, save/load, physical transfer, or broad plugin-coexistence acceptance is claimed. No install, deploy, service change, or game/core write was performed by this worktree.
