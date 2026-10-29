# PCV 0.1.25 source provenance

This local offline candidate stages the seven-file Private Conversation 0.1.25 owner closure under `ext/private_conversation/` using the separate Department support companion `0.1.0-prealpha.13`. The pinned owner is `private_conversation-v0.1.25` at commit `886a54967db7c6c08725c8bec8854ea3919ea532`. The complete source and BASE-to-POSTIMAGE pins are recorded in `pcv-0.1.25-source-manifest.json`.

| Owner file | PCV 0.1.25 BASE SHA-256 | Shipped POSTIMAGE SHA-256 |
| --- | --- | --- |
| `installation_guard.php` | `082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d` | `082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d` |
| `log.php` | `5ce07e9b834dba51ea5552d923c3a83f97dd3fa5dce7131db773d16813612a8c` | `5ce07e9b834dba51ea5552d923c3a83f97dd3fa5dce7131db773d16813612a8c` |
| `manifest.json` | `198a242e4e85cd418aca588fd7583e1643437ffe069c3eaa6070b53d2eff4a3c` | `198a242e4e85cd418aca588fd7583e1643437ffe069c3eaa6070b53d2eff4a3c` |
| `preprocessing.php` | `5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c` | `01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950` |
| `reflection.php` | `d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540` | `d33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540` |
| `scope.php` | `75a2d61d9f223bae2d5bef32d1d2bbd88cf15dc4764f8759f8d763c236c1af2f` | `6eaa8d09d423a7364dfd26934b56794fa2cb51cacd26950e753f499989181e33` |
| `state.php` | `49c2b53466572015e77117653739966377267b5b50ec4514155e53256bca7361` | `b64277236a520b5464a26becc9c7d15dce233fdf2e3f4c99201c568da22e16d6` |

The Department installer maps owner `preprocessing.php` to the inert `server/setup/pcv-overlay/preprocessing.php.template` member. It does not ship an active `server/setup/pcv-overlay/preprocessing.php` hook. The adapter, companion owner loader, and overlay installer each carry the same independently checked PCV 0.1.25 identity and seven-file hashes.

The historical PCV 0.1.24 source stage and its records remain in the repository. The local candidate replaces the installed overlay stage from the exact published Department prealpha.26 package and creates support companion prealpha.13; the published prealpha.26 archive and prealpha.12 companion remain unchanged.

The frozen upstream snapshot has no standalone license declaration. This provenance notice is not a license grant. Existing third-party notices and rights remain applicable.
