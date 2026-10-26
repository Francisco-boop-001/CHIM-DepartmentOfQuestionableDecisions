# Department maintainer audit: external contracts

Review World of Drama Llama: The Department of Questionable Decisions as its maintainer. Audit the shipped companion mod and its server facade, PCV companion overlay, PRISMA UI, MCM and packaging. Do not mistake a mocked bootstrap, DLL validator, package hash or passing test for a working Skyrim connection.

This is an audit, not a repair or release. Do not modify production code, build DLLs, publish, read issues/PRs, attach to the running game, contact model providers, use real credentials or touch CHIM core/live. Use existing tools. Keep evidence under K:/ActorwrightExchange/artifacts/department-maintainer-audit-20261026. Record the plan/review in tasks/todo.md. Write numbered issue-sized findings, with small issues batched, ordered by what an ordinary player sees first. End with done well.

## Frozen inputs

- Department checkout: K:/ActorwrightExchange/projects/CHIM-DepartmentOfQuestionableDecisions, main b3857b0f5d08154b46b725450f0634fa590ba3cb. Shipped prealpha.17 source b70a2951453d5c94f371a90bbed9876c5db11689. Establish which reviewed files match the release.
- Public first-party CHIM server/client snapshots are listed below. Inspect raw source at these exact commits, including the real schema updater and package manager. Use the last public 3.4.2 branch snapshot as a comparison; do not silently call it a proven stable installer. No immutable historical stable server/client archives were supplied. Mark every installed/stable-build-dependent conclusion accordingly.
- Compare both prealpha.16 and prealpha.17 installables already downloaded under K:/ActorwrightExchange/artifacts/department-dev25-20261026, and distinguish their server packages from their Skyrim files.
- Latest exact owner boundaries: PCV0.1.23, MP0.1.30, SAS0.1.12, Sealed Fates dev.25 plus retained pins, Brainwash/Malpractice dev.3. Check actual owner sources, not their summaries. Owners remain separate components; delegated effects still need tracing.
- User supplied TEST bed DwemerAI4Skyrim3-test2, not a separate database. Live DwemerAI4Skyrim3 is untouchable. First inventory state without starting stopped distros. Use a fresh, uniquely named PostgreSQL cluster inside TEST2, a private socket and no existing campaign database. Check its engine/version; production's exact version is unavailable and must stay an explicit limitation. Stop only owned cluster processes after identity checks and restore any audit-owned distro startup to its original stopped state.

| Input | Exact commit |
|---|---|
| server-dev (Dwemer-Dynamics/HerikaServer) | `0ec590b42c8087860138bb4e9aab470049ff9a2b` |
| server-current (Dwemer-Dynamics/HerikaServer) | `57f292415b008cfaf1a4531f0654d2563362ce70` |
| server-unstable (Dwemer-Dynamics/HerikaServer) | `864d3de109849f775748c1a20a74690e3f47c760` |
| server-beta342 (Dwemer-Dynamics/HerikaServer) | `d3945ecb65d4338a63978c81c3d9b09a936da585` |
| client-dev (Dwemer-Dynamics/CHIM) | `5ca1bc361d1e11a3c58067599b494b1101c2c77b` |
| client-main (Dwemer-Dynamics/CHIM) | `e0deb10e7169fbaae95dea3f17af268c23cad608` |
| client-beta342 (Dwemer-Dynamics/CHIM) | `3e3d40e6030ed848eddf44f2adf87eaa81220bc5` |

## Execute every lens

1. Inventory host functions, methods/classes, globals, configuration, SQL relations, hooks/events, commands, forms and Papyrus/SKSE/PRISMA calls. Locate definitions separately in each supplied snapshot and mark present/absent/different. For each guard/fallback, record the player outcome and whether the interface still implies availability. List actual test doubles and extracted fixtures separately; treat their external behavior as unverified by those tests.
2. Trace automatic connection, roster selection, player text, PCV state, relationship/issues inspection, letter/note/inventory effects and profile drafts through the host's own implementations. Compare steps and comments. Do not count delegating to the owner's real implementation as skipping that implementation.
3. Explain the player consequences for fresh and long saves: light-plugin records/MCM, quest/equipped/enchanted/owned/stolen items, stable/dynamic form IDs, deleted/disabled actors, native delivery, co-save/JDB and save/load. Source assertions and receipts cannot establish actual engine outcomes. State missing runtime evidence.
4. For every shipped hook/default-off path, list includes, connections, schema work, locks, writes and files before return, in order. Distinguish the tiny Department hook from the installed PCV overlay and host's own baseline. Execute bounded actual production SQL/readers on the private database while a second session holds a conflicting lock. Report configured timeouts and measured waits, not invented production timings.
5. Inventory every growing table/file/journal/co-save structure and per-request scans, limits/indexes/pruning and loops. Estimate a 200-hour save using explicit rates. Inspect the host updater as well as its default SQL before calling an index absent. Measure a focused representative query; do not manufacture a 200-hour gameplay claim.
6. Map every wire error to visible UI copy; inspect capability/permission and enabled/running/armed states, buttons, recovery and no-op paths. Render a real browser copy with controlled native replies where useful and inspect it. This is UI evidence, not Skyrim execution. Check the exact native validator boundary without claiming full game startup from it.
7. Inspect actual native package selection and server activation, filenames, version comparisons, timestamps, schema/checksums/mutable paths. Compare two consecutive shipped archives, current README/changelog/install/build instructions, file list, notices/license/artwork and compressed/expanded sizes. No publication side effects.

## Evidence and output

Cite verified file:line in the Department and the relevant host/owner for each finding. Quote the host code that supplies the contract. Label each finding ran (give command/result and the narrow boundary executed) or read. Suggest the smallest concrete fix, preferably using the host pattern. Do not turn style preferences, documented safety limits or unperformed gameplay into defects. Do not assert complete compatibility where source pins reject a supplied host.

Deliver this adapted prompt, a numbered findings file and a linked coverage/contract appendix. Answer each lens explicitly, including unavailable evidence rather than silently substituting a stub. Do not fix findings during this audit.
