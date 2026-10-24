# Automatic CHIM setup: prealpha.9

User authorization: implement with GPT-6-LUNA/max workers, independently review and verify, then publish. TEST only. CHIM core, AIAgent.dll, live, installed game/MO2 files and published prior releases remain untouched.

## Acceptance

A normal user installs the MO2 ZIP, lets normal CHIM plugin sync complete, loads Skyrim and opens the Department. Setup runs automatically; no browser approval, token copying, INI provisioning or separate PCV setup action. A bounded connection failure provides an understandable retry. Existing pair identity, revocation, restore fencing, unknown operation history and the original PCV input fallback survive.

## Frozen boundary

- Native retains the per-endpoint Credential Manager bearer. Only its SHA-256 hash and a bounded claim UUID pass to the existing fixed JSON begin endpoint; begin does not authorize anything.
- After pending begin, the companion calls the stock global AIAgentFunctions.logMessage with message `dql_connect_v1:<lowercase UUID>:<lowercase SHA-256>` and type `physics_raw`. The exact message has no raw pipe or bearer. This type already exits stock dialogue processing even when the new extension is absent.
- The owned Department preprocessing hook accepts only the exact four-field packet and matching unexpired claim/hash, requires the stock decimal X-CHIM-Generation header, and refuses browser Origin/Sec-Fetch headers. Stock playthrough preflight remains authoritative. Recognized refused packets stay physics_raw and cannot become dialogue.
- This uses the existing CHIM game-ingress/network trust boundary. It does not cryptographically identify the Skyrim binary or create an authenticated administrator account. The logged hash cannot be used as the bearer. Ordinary CHIM logMessage also emits surroundings telemetry and stock activity handling; no zero-DB-activity claim.
- Approved status reuses an existing nonrevoked pair identity. Expired/mismatched/revoked claims do not grant access. Browser approval is disabled. Native VM queue acceptance is pending, never proof of pairing; server status and fresh authenticated bootstrap are authoritative.
- UI begin/status results are exactly `{state: pending|approved|expired}`. Start once per live view after unauthenticated bootstrap, poll at one-second intervals up to 12 times, then stop and offer retry. Close/reinitialize cancels timers and stale results. Approved triggers one fresh bootstrap; it cannot create an endless pairing loop.
- Authenticated bootstrap with PCV scope idempotently applies the existing exact-pinned PCV overlay before owner loading, covering existing .8 pairs too. Missing/drifted optional PCV sources do not disable other tabs. Preserve backup, rollback and replay rules.

## Ownership and checks

1. Luna/max native owner: native/**; producer/parser, VM dispatch and endpoint/lifecycle guards; focused host checks. Root builds final companion DLL from frozen sources using cached pinned dependencies.
2. Luna/max server owner: server/** and pcv-companion/** except manifest; hook and claim validation, browser removal, automatic pinned overlay, focused producer-format/revocation/replacement checks.
3. Luna/max UI/package owner: ui/**, packaging/**, pcv-companion/manifest.json and README.md; automatic lifecycle, focused browser checks, .9 builder using existing strict archive logic, normal install guide.
4. Root: review each boundary and actual caller, run relevant checks and inspect screenshots, exercise actual pinned CHIM package installer in a fresh TEST temporary layout, compare archive bytes to frozen sources and receipts, then commit/push/tag/publish .9 and verify public downloads. Keep old tags/assets immutable. No broad owner suites, provider/database starts, live access or installed modifications.

Skyrim execution remains user acceptance: native VM behavior, actual game focus/input/audio, save/load and physical letter delivery cannot be certified by the synthetic checks.
