# Private operation state and recovery policy

Send once. A missing receipt, elapsed time, server restart or empty current scene does not prove a previous operation did nothing.

| State/evidence | Permitted recovery |
| --- | --- |
| Native response explicitly proves the matching operation was never submitted | Release that local operation; refresh current access/inventory and review again. Lost authorization still erases private text. |
| Prepared PCV token, never claimed | Explicit cancellation or its prepared-token expiry can close it. |
| PCV claimed/unknown | Refresh the same operation's status. Do not resend, delete its record or treat token expiry as a safe unlock. |
| Letter queued/dispatched/unknown | Reconcile that same operation against the authoritative Sealed Fates document and receipt. Queued is not delivered. |
| Durable version2 reservation says commit never entered and its operation record is missing | Only the verified reservation-recovery code may reclaim that no-write reservation. |
| Present pending record, legacy orphan, entered commit marker, corrupt/contradictory state | Refuse replay. Preserve complete state and investigate authoritative owner evidence. Never infer no-write from age or missing UI data. |

For Sealed Fates dev.14–17, the companion marks entry before the first potentially mutating owner-store method. For exact dev.18, entry occurs before the owner lifecycle initialization, including ensureSchema, because that initialization can itself write schema, restore state or files. A failure after that entry remains uncertain even if no letter is visible; it is not permission to resend. Read-only inspection never initializes owner schema or restore state. That is deliberately conservative: a failure inside that method before its first SQL write may still remain uncertain. A note renderer can also create a file before a database transaction rolls back. A database rollback alone therefore does not prove absence of all side effects.

Reservation recovery requires a Linux filesystem and PHP build that can sync directory handles. The Department mutation journal syncs its parent on acquisition and its directory after atomic replacement or deletion; unsupported sync operations refuse the mutation. The isolated test distribution verified this PHP capability. This change covers that journal only: it does not certify physical power-loss recovery or change PCV, CHIM or database persistence. Preserve and reconcile uncertain operations after a machine crash or snapshot restore.

Concurrent unresolved work is admitted within fixed limits. Successful lifetime operations must not exhaust an unresolved-work limit. Prepared-token count/text bounds and expiry are separate from irreversible uncertainty.

Anti-replay tombstones remain durable. UUID operation history cannot support indefinite replay refusal while also having finite lifetime storage without a reviewed retirement protocol. Terminal detail is compacted, but file count and private disk use still grow; some scans grow with that history. Monitor free disk and stop the isolated worker before exhaustion. This PRE-ALPHA has no automatic general cleanup or operator command that can prove every unknown outcome safe.

To retire a disposable test, revoke its pairing, stop its own worker, preserve the complete state/receipts/journal and record the test identity. A new disposable test uses fresh server/database/state paths and pairing. This creates another test namespace; it does not repair, settle or authorize replay of unresolved operations in the retired one. Never apply that procedure to the live server as an experimental recovery method.
