# Department install-and-use guide — prealpha.15

This PRE-ALPHA candidate adds a normal in-game connection flow. Use a disposable MO2 profile, save, and CHIM server until you have completed your own acceptance checks.

## Install the mod

1. Download and install [WorldOfDramaLlama-0.1.0-prealpha.15.zip](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.15/WorldOfDramaLlama-0.1.0-prealpha.15.zip) in MO2 as an ordinary mod and enable it. Its archive root is the Skyrim Data layout: SKSE, PrismaUI, Scripts, SEQ, the Department ESP, notices, and CHIM server packages. Do not add another Data or Skyrim directory. The published prealpha.14 archive does not include the dev.24 pin.
2. Meet the requirements: Skyrim SE/AE, matching SKSE64 and Address Library, PRISMA UI with its V1 API, SkyUI, CHIM, and the owner-plugin versions supported by the tabs you plan to use. Private Conversation is optional. VR is not supported. The masterless ESL-flagged ESP uses one light-plugin slot and no full-plugin slot.
3. Load a save with CHIM enabled. CHIM's normal mod/plugin synchronization detects the three bundled schema-4 packages under CHIM/server-plugins and installs them on the server. Players do not unpack or copy those packages manually.

The [source ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.15/WorldOfDramaLlama-0.1.0-prealpha.15-source.zip), [compatibility-proof ZIP](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.15/WorldOfDramaLlama-0.1.0-prealpha.15-compatibility-proof.zip), and [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/download/v0.1.0-prealpha.15/SHA256SUMS.txt) are separate developer materials. Only the MO2 ZIP is installed.

The archive contains the Department DLL and view, its owned ESP/SEQ/Papyrus assets, seven license notices, and three server packages (Department, PCV companion and Malpractice) for CHIM's normal plugin synchronization. It does not contain AIAgent.dll, CHIM core files, or a complete Private Conversation plugin. Compared with prealpha.14, only the Department DWPkg is replaced; all other 21 installable members are byte-identical. Within the Department DWPkg, only the version manifests, correspondence adapter and checksum file change. The four-file candidate set is the MO2 installable, source ZIP, compatibility-proof ZIP and `SHA256SUMS.txt`; install only the MO2 ZIP.

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

The supported current owners are Private Conversation 0.1.23, Mind Poisoning 0.1.30, Sworn & Scorned 0.1.12, Sealed Fates dev.24 and Malpractice dev.3. Sealed Fates dev.23 remains supported by its retained map. Malpractice is bundled; install the other complete owners and their required game components separately. Keep the original Private Conversation input fallback.

Use a disposable save and confirm:

1. The Department opens from its assigned key and shows Private Conversation, Mind Poisoning, Sworn & Scorned, Sealed Fates, and Malpractice Makes Perfect. The initial local status should say that the game interface is starting while it waits for the companion bridge.
2. After ordinary CHIM plugin synchronization, opening the Department starts connection setup after local initialization; confirm the tabs load current data after authenticated bootstrap.
3. If using Private Conversation, install its complete 0.1.23 owner plugin. Confirm the Department reports PCV ready after bootstrap. Disable the Department and confirm the owner's original input method still works.
4. Send or mutate once. If the result becomes uncertain, use the relevant status or reconciliation control. Do not resend an uncertain operation or remove its state to clear a lock.
5. Confirm final NPC routing, audio, item transfer, and save/load behavior directly in Skyrim. A consumed Private Conversation text token confirms acceptance at the PCV route only; it does not prove audible delivery.

Native, synthetic browser, source-pairing and package checks are separate from server installation and Skyrim acceptance. PHP source/projection checks use isolated fixtures; the dev.23 PostgreSQL evidence remains historical and is not dev.24 proof. No dev.24 database, HTTP/provider, actual CHIM-server or Skyrim result is claimed. See the [verification record](../docs/VERIFICATION.md), [state recovery policy](../docs/STATE-RECOVERY-POLICY.md), and [pairing record](../docs/PAIRING-CHECK.md) for exact evidence and remaining limits.

## Updates and removal

The CHIM updater replaces each extension folder, so mutable_paths is empty and Department operation state lives in CHIM's stable private data area. Keep that state when updating; never clear unresolved IDs or receipts to work around an error. The pairing credential remains in Windows Credential Manager across ordinary mod updates.

Before replacing the Department DLL during an MO2 update, fully quit Skyrim. Start Skyrim again after installing the update; reopening the in-game menu does not reload the DLL.

Disabling or removing the MO2 mod removes the local game files only. It does not remove server-installed packages, revoke the pairing, or undo the optional Private Conversation overlay. Ask the CHIM maintainer to remove the two Department packages and restore the saved PCV owner files before removing server setup. Do not delete CHIM core files or the complete owner plugin.

## Included notices

The MO2 archive includes notices for CommonLibSSE-NG 3.7.0, nlohmann/json 3.11.3, PrismaUI, fmt 10.2.1, rapidcsv 8.84, spdlog 1.13.0, and the pinned CHIM Plugin/PrismaUI API MIT license.
