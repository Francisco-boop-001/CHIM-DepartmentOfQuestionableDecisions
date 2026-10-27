# Sealed Fates dev.28 compatibility

The Department's correspondence adapter pairs with Sealed Fates `correspondence-v0.1.0-dev.28`, frozen at source commit `e2064d34ec8607ac5b833fdfac64635aef28f4ae`. Its owner manifest SHA-256 is `4dad1a9ce578d75fe3b85c72c39608cd4e50a89564258fd2c76a73e7a6ecddb6`.

The exact owner DWPkg SHA-256 is `496d48acfb23fef3d2d7798130f0ecca248efbe9d5973aca774831be756df51f`; the full owner MO2 ZIP SHA-256 is `0bae46838d43fc9e23d5bc5af1f699af1a239f690d8149d824d320d3fda0ac41`.

The runtime closure remains the exact 19-file closure used for dev.27. Only the owner `manifest.json` pin changes; the other 18 runtime pins retain the dev.27 values. The dev.28 publication changes only that manifest inside the 19-file closure. The owner `index.php` referrer-policy update is outside the runtime closure. The Department adapter keeps the historical dev.27 mapping and adds the exact dev.28 map.

The prealpha.24 builder and focused source-contract test verify the dev.28 owner identity, all 19 closure hashes, retained dev.27 pins, and drift refusal. A dev.27 manifest-only map remains valid for its historical version; loading dev.28 does not make that old identity a runtime match. These checks establish source pairing and readiness only. They do not test Correspondence service calls, HTTP behavior, provider operations, live CHIM, installed-game state, or Skyrim.

The versioned prealpha.24 package includes this adapter pairing. Release asset and publication evidence is recorded separately from this owner/source contract.
