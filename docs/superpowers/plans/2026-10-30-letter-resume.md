# Queued-letter resume implementation plan

Goal: explicit safe recovery of an existing queued manual letter with truthful reason. Architecture: new Department write wraps existing SF30 owner dispatch under its writer fence; original owner request/document identities persist. Tech: existing PHP/PostgreSQL, JS, C++ companion. Spec: ../specs/2026-10-30-letter-resume-design.md.

- [x] Confirm human authorization and component ownership; isolate worktree at 3342e2d, preserve mouse records.
- [x] Trace owner dispatch, exact protocol, native transport, journal and UI; coordinate short design.
- [ ] Backend LUNA/max RED/GREEN: read-only restart pending discovery, orphan-operation refusal, explicit resume mutation/attempt identity, transaction/ownership/fence, bounded reason+canResume projection, unchanged read-only reconcile.
- [ ] UI LUNA/max RED/GREEN: restart discovery and failed/limited transfer fence, explicit same-letter action, truthful reasons, legacy absence, duplicate/unknown/closed/ownership/context guards.
- [ ] Native LUNA/max RED/GREEN: exact operation allowlist/payload, true write/access handling, emitted binary/source receipts; no input/mouse changes.
- [ ] Root review and focused independent bughunt; fix findings and rerun affected checks only.
- [ ] Coordinate isolated TEST2 owner integration/concurrency/read-only trace and cleanup; obtain owner acceptance.
- [ ] Build fresh prealpha28 package, exact source/current pins/archive/checksum/clean rebuild verification and publication workflow.
- [ ] Commit/push/publish after both reviews; anonymous public bytes and historical asset preservation, task/doc closure.

Review: pending. Primary/old worktree clean product baseline preserved; live/game/core untouched. Runtime mouse fix is outside this change.

Replan: owner rejected permanent first-N-history transfer block. Add bounded explicit cursor continuation, private per-context bootstrap MAC key, end-of-scan transfer gate, >26 resolved/older500-gold tests. First native emission is preserved prototype only. Final build waits for all14bootstrapfixture inputs and final cursor/native boundary tests.
