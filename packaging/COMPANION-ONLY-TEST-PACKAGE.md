# Department install-and-use guide — prealpha.19

Prealpha.19 supports exact Sealed Fates dev.26 through the existing correspondence adapter, retaining earlier supported versions. Compared with prealpha.18, only the Department DWPkg changes; its two manifests, correspondence adapter and checksums are the only changed files. The other21 installable payloads, including UI, PCV companion and DLL, remain byte-identical. See the [dev.26 compatibility note](../docs/DEV26-COMPATIBILITY.md) and [verification record](../docs/VERIFICATION.md).

Use a disposable MO2 profile, save and separate CHIM server/database. This remains a TEST PRE-ALPHA; package compatibility is not Skyrim acceptance. Other supported owners remain PCV0.1.23, Mind Poisoning0.1.30, Sworn & Scorned0.1.12 and Malpractice Makes Perfect dev.3.

## Install the mod

1. Quit Skyrim, then install [WorldOfDramaLlama-0.1.0-prealpha.19.zip](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.19/WorldOfDramaLlama-0.1.0-prealpha.19.zip) in MO2 using **Replace**. Disable retained older Department installations. Keep only one visible Department and PCV companion package version. The archive root is already Skyrim's Data layout; do not add another Data directory.
2. Meet the requirements: Skyrim SE/AE, matching SKSE64 and Address Library, PRISMA UI with its V1 API, SkyUI, CHIM and the supported owner plugins. PCV is optional; VR is unsupported. The masterless ESL-flagged ESP uses one light slot and no full-plugin slot.
3. Load a disposable save with CHIM enabled. Let normal CHIM synchronization install the three bundled server packages, then confirm Department `0.1.0-prealpha.19` in Plugin Manager. Players do not unpack server packages manually. The complete Sealed Fates dev.26 owner is installed separately.

Install only the regular ZIP. The [source ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.19/WorldOfDramaLlama-0.1.0-prealpha.19-source.zip), [compatibility-proof ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.19/WorldOfDramaLlama-0.1.0-prealpha.19-compatibility-proof.zip) and [checksums](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.19/SHA256SUMS.txt) are review materials, not MO2 installation files.

The installable supplies the companion DLL/view, owned ESP/SEQ/Papyrus assets, license notices and three server packages. It contains no AIAgent.dll, CHIM core or complete PCV owner plugin. No DLL recompilation is needed.

## Connect to CHIM

After CHIM has synchronized the bundled server packages, open the Department UI in Skyrim. The local bridge initializes first; CHIM connection setup then starts automatically and waits for confirmation in the background. There is no browser approval step. If the UI reports **Companion bridge did not start**, reopen the game interface and check `WorldOfDramaLlama.log`; it records timestamps and plugin version and rotates at 1 MiB per file with three backups. If the bridge starts but CHIM setup cannot be confirmed, choose **Retry connection** after confirming CHIM is running and has synchronized its plugins.

No Linux command, pairing-token copy, or manual INI entry is part of this flow. The shipped WorldOfDramaLlama.ini leaves Server.BaseUrl and Server.PairToken blank. The native mod creates a per-endpoint credential and stores it in Windows Credential Manager; the credential is reused across mod updates. It is never shown in the UI or approval URL.

The native mod derives its destination from CHIM's startup configuration. If CHIM's AIAgent.ini is present and valid, its configured destination must match. If that file is absent, the mod makes CHIM's pinned local discovery request once at startup and follows the same source-defined default when discovery is unavailable. A malformed or incompatible present INI is refused; it does not trigger the absent-file fallback. CHIM exposes no public getter for its already-cached destination, so absent-INI discovery is a source-equivalent startup snapshot. Restart Skyrim after changing CHIM's endpoint.

The fixed connection and companion routes use plain HTTP only when it matches the exact native-derived CHIM destination. HTTP does not protect traffic from other systems on that network; use a trusted network. Pairing is initiated through CHIM's stock game-event route and completed by the Department server; the bearer credential is stored in Windows Credential Manager, never shown in the UI or sent through a browser URL.

If the mod reports that CHIM setup is required, ask the CHIM maintainer to check the endpoint configuration rather than editing the shipped Department INI or replacing CHIM files.

## Optional Private Conversation integration

Private Conversation integration is optional. Install the complete supported Private Conversation 0.1.23 owner plugin separately. On authenticated Department bootstrap, the Department checks the seven supported owner files against exact version pins, backs them up in CHIM's private Department data, and applies only the seven-file overlay. If the owner plugin is missing or any file differs, setup refuses without changing those files; the other Department features remain available. No manual PCV setup action is required.

The Department DWPkg carries those staged setup files under its own extension. It does not package under the private_conversation owner name and does not replace that owner's complete plugin. The original Private Conversation input path remains available when the Department is disabled.

## First-use checks

The supported current owners are Private Conversation 0.1.23, Mind Poisoning 0.1.30, Sworn & Scorned 0.1.12, Sealed Fates dev.25 and Malpractice dev.3. Sealed Fates dev.24 and dev.23 remain supported by their retained maps. Malpractice is bundled; install the other complete owners and their required game components separately. Keep the original Private Conversation input fallback.

Use a disposable save and confirm:

1. The Department opens from its assigned key and shows Private Conversation, Mind Poisoning, Sworn & Scorned, Sealed Fates, and Malpractice Makes Perfect. The initial local status should say that the game interface is starting while it waits for the companion bridge.
2. After ordinary CHIM plugin synchronization, opening the Department starts connection setup after local initialization; confirm the tabs load current data after authenticated bootstrap.
3. If using Private Conversation, install its complete 0.1.23 owner plugin. Confirm the Department reports PCV ready after bootstrap. Disable the Department and confirm the owner's original input method still works.
4. Send or mutate once. If the result becomes uncertain, use the relevant status or reconciliation control. Do not resend an uncertain operation or remove its state to clear a lock.
5. Confirm final NPC routing, audio, item transfer, and save/load behavior directly in Skyrim. A consumed Private Conversation text token confirms acceptance at the PCV route only; it does not prove audible delivery.

Native, synthetic browser, source-pairing and package checks are separate from server installation and Skyrim acceptance. The .18 PostgreSQL checks cover bounded profile reads and the PCV adapter/owner-reader seam; the dev.23 PostgreSQL result remains historical, not dev.24 or dev.25 proof. No new dev.26 database, poll-lock, HTTP/provider, installed CHIM-server or Skyrim result is claimed. See the [verification record](../docs/VERIFICATION.md), [state recovery policy](../docs/STATE-RECOVERY-POLICY.md), and [pairing record](../docs/PAIRING-CHECK.md) for exact evidence and remaining limits.

## Updates and removal

The CHIM updater replaces each extension folder, so mutable_paths is empty and Department operation state lives in CHIM's stable private data area. Keep that state when updating; never clear unresolved IDs or receipts to work around an error. The pairing credential remains in Windows Credential Manager across ordinary mod updates.

For a prealpha.18 update, choose Replace for the existing MO2 mod, not Merge. If retaining an older mod for rollback, disable it while the newer one is enabled; confirm only one version-named Department DWPkg and one PCV companion DWPkg are visible to CHIM, which selects by modification time rather than semantic version. Before replacing the Department DLL during an MO2 update, fully quit Skyrim. Start Skyrim again after installing the update; reopening the in-game menu does not reload the DLL.

Disabling or removing the MO2 mod removes the local game files only. It does not remove server-installed packages, revoke the pairing, or undo the optional Private Conversation overlay. Ask the CHIM maintainer to remove the two Department packages and restore the saved PCV owner files before removing server setup. Do not delete CHIM core files or the complete owner plugin.

## Included notices

The MO2 archive includes notices for CommonLibSSE-NG 3.7.0, nlohmann/json 3.11.3, PrismaUI, fmt 10.2.1, rapidcsv 8.84, spdlog 1.13.0, and the pinned CHIM Plugin/PrismaUI API MIT license.
