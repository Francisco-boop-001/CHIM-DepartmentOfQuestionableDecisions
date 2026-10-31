# Sealed Fates dev.31 compatibility

Prealpha.30 adds compatibility for the actual public Sealed Fates dev.31 DWPkg. The owner release source commit is e393bd5f9a1cd31a8d8d006a4356faddc9eb3212; its public DWPkg SHA-256 is 7d17ec6cefd21540d01194f719bd69c9961f6d48b5a1bb35d9f86a9ae76f0777. The reviewed public owner handoff SHA-256 is 742a13eac8348b2a86e81f6a690cdce500522bc959d26d219e3fffa213f9970f, with source-closure SHA-256 4fa413b91e4ee12ab8c8c272b14afd2a8b4b0e04229ae61b3773519861daf9c7. The build uses the public DWPkg itself and records the handoff hashes as provenance references.

The Department adapter preserves all 16 existing supported owner maps and appends the 21 source pins for dev.31, including preprocessing.php. The builder checks the archive's checksum closure and compares each mapped path and digest to the corresponding server/ member in that public DWPkg. Only the correspondence adapter source changes in the Department runtime package. It adds dev.31 to the four existing capability lists and routes pending discovery/resume through a strict gate that accepts only dev.30 and dev.31. No API operation or native wire shape changes.

The UI's executor_unavailable hint now distinguishes the dev.31 activity path: leave Skyrim unpaused so normal CHIM polling can refresh activity, keep CHIM Interaction On where available, then resume the same saved letter. Earlier supported versions still require a recent managed-NPC report. Prealpha.29 PCV receipt polling remains intact. The package reuses native DLL 0.1.18.0 byte-for-byte.

The prealpha.30 source/package check verifies the public owner package pins and the exact payload delta. It does not establish Department PostgreSQL integration, HTTP/provider behavior, installation, or Skyrim behavior. The actual Department database gate remains separate from this packaging proof; mouse control remains unresolved, and no Skyrim result is claimed.

See the [prealpha.30 build contract](BUILD.md).
