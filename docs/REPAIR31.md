# Prealpha.31 repair notes

Prealpha.31 contains two bounded repairs: preserve an uncertain letter operation when the waiting-letter scan does not list it, and report an unavailable actor separately from a stale server authorization context.

## Uncertain letter operation

When a compose request may have reached CHIM but its result is unknown, the Department keeps the current operation locked while the player checks waiting letters. A completed scan that omits the local operation ID is not evidence that the operation was delivered, rejected, or never created. The UI now retains the current operation, draft lock, and related send reservations when a scan omits it. Reconcile that same operation ID: a confirmed delivery receipt or a rejection resolves the operation, while unknown results remain locked. A separate `dispatched: false` response can release it because that response confirms the request was not sent. The UI does not issue another compose request as a substitute for reconciliation.

The focused browser fixtures cover complete empty scans, paginated omissions, limited/stale reads, retained duplicate and transfer guards, and same-ID terminal reconciliation. These fixtures cover the UI request/state contract, not the live CHIM HTTP path, PostgreSQL behavior, or actual delivery.

## Actor unavailable

If the selected managed actor is no longer eligible or cannot be matched after the fresh roster check, the native companion now returns `actor_unavailable` with `dispatched: false`. The UI can ask the player to refresh the cast without treating that actor-specific refusal as `stale_context`, which invalidates server access. Authorization failures and genuinely stale server context keep their existing handling.

The native boundary fixture checks the refusal code, non-dispatch result, and omission of private letter content. The build receipt and PE verification bind the resulting 0.1.19.0 binary to the reviewed source closure and required exports.

## Limits

This repair package does not verify Skyrim focus or mouse/keyboard/controller behavior. It does not prove that a letter reached an NPC, an item or gold transfer happened in-game, the installed mod was synchronized, or the live server/provider accepted a request. Those runtime and physical-delivery checks remain unperformed.
