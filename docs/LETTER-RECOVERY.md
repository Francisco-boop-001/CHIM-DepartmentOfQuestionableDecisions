# Recover a queued manual letter

Prealpha.28 adds an explicit recovery action for a manual letter that is still queued because the native executor was unavailable. The pending list helps you find that letter after the required Department DLL update and a game restart. Refreshing the list only reads status; it never sends mail.

## Before you resume

1. Exit Skyrim completely.
2. Update the Department installation to prealpha.28 using your normal mod-manager workflow. The native plugin must be version 0.1.18.0 for the recovery protocol.
3. Start Skyrim again and open the Department correspondence screen.

Do not replace the DLL while Skyrim is running. This recovery flow does not require browser approval or a Linux setup.

## Resume the saved letter

1. Choose **Refresh waiting letters** to discover pending letters. If there are multiple pages, use **Continue checking waiting letters** until the screen reports that discovery is complete.
2. Select the saved recipient and the queued letter you intend to resume.
3. Check the displayed waiting reason. Choose **Resume delivery** only when the letter is still eligible and the screen offers that action.
4. Resume submits the stored original letter, including its saved body and attachments. It does not use the current empty compose form, make a new letter, or change the recipient.
5. Read the result shown for that same letter. A **queued** result means it is waiting; it is not confirmation of delivery. Refreshing status remains safe and read-only.

Do not open a new composition and press **Send** to recover the same letter. Do not treat a transport timeout, an unknown result, or an already-dispatched operation as permission to send again. The server checks the saved request and document again at the moment Resume is accepted; stale presence, changed restore state, closed letters, companion-owned letters, and letters with an existing operation are refused.

If Resume remains unavailable after updating and restarting, follow the exact waiting reason displayed and leave the saved letter unchanged. The recovery protocol does not automatically retry queued letters.

## What this update proves

The prealpha.28 release evidence covers the selected source commit, package assembly, native build and host-test receipt, PE architecture/version/exports, focused UI/native/PostgreSQL acceptance, and owner review. It does not show that a physical letter or gold transfer occurred. Live-server use, provider behavior, installed-game behavior, Skyrim gameplay, and the separate mouse investigation remain outside the release evidence.
