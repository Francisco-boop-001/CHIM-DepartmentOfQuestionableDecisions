# SHARMAT automatic connection repair

The installed Department emits a reserved connection payload under the neutral physics_raw stock event. SHARMAT handles that type first and rewrites the payload as malformed physics. The Department therefore never sees its confirmation event. The observed direct HTTP/bootstrap path succeeds, but pairing stays pending.

Use the existing CHIM globals hook before preprocessing to recognize and consume only the reserved native connection packet, keeping its exact authentication/expiry/revocation guards and ordinary physics untouched. The native packet, DLL, SHARMAT and CHIM core remain unchanged. A renamed native event would require a new DLL and a host fast-command contract; accepting SHARMAT's rewritten blocked type would retain ordering dependence and malformed-event logging. Early reserved-packet handling is the smallest Department-only correction, subject to source review and proof.

Implementation owner: LUNA/max ingress worker owns server/globals.php, existing connection helpers and focused onboarding tests. Package owner: LUNA/max package worker owns prealpha.21 packaging/version/docs changes. Verification worker owns isolated artifact harnesses. Root owns review, independent bug hunt, compatibility and publication.

Success: old ingress fails with actual frozen SHARMAT; new ingress completes begin/native-event/status and real authenticated+unauthenticated bootstrap over isolated HTTP. Exact shipped DLL validator accepts five-key server envelopes. Browser markers, malformed packets, expiry/revocation/unknown claims still refuse. Normal SHARMAT physics and other owner contracts are unchanged. Package audits and actual archive consumer pass; public bytes match clean tag build.

No live/game/provider operation or native build. Keep old releases immutable and state unperformed Skyrim acceptance clearly.

Root review checkpoint: isolated actual main.php/SHARMAT connection passed with zero warning/error logs, then three actual HTTP bootstrap bodies passed the unchanged DLL export. Focused ingress64 and packaging10 passed; exact five-owner source contracts and 75 protected files are unchanged. Complete owners are absent from the HTTP fixture, so its capabilities are false and no owner-operation proof is claimed. The fixture uses minimal synthetic database rows and PHP test mode. Final package/publication closure remains pending at the source freeze.
