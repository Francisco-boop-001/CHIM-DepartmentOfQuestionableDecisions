# PCV25 offline package candidate

`packaging/build-pcv25-offline-candidate.py` assembles a local Department prealpha.27 package candidate with the PCV 0.1.25 owner overlay and support companion prealpha.13.

The builder reads the exact published prealpha.26 archive at:

`artifacts/department-sf30-20261028/public-download/WorldOfDramaLlama-0.1.0-prealpha.26.zip`

It requires SHA-256 `f402cfb4e8db58c956dfae1e771c1f6773960a3cdbe5909a31942890cf3729ff`, verifies the seven owner BASE and POSTIMAGE pins against independent constants, checks that the adapter, companion loader and overlay installer agree on the same owner closure, and rejects unrelated component or archive changes.

The build replaces only the versioned Department and support-companion packages in the local assembled ZIP. The Department package manifest receives a local `0.1.0-prealpha.27` identity override; the repository `server/manifest.json` remains at the published prealpha.26 identity. The support package advances from prealpha.12 to prealpha.13. Existing native, UI, MMP and other archive members remain byte-identical.

Inside the Department DWPkg, the fixed 30-member layout is preserved. The seven PCV25 owner files and source manifest map to the existing `server/setup/pcv-overlay/` stage. The preprocessing member is stored as `preprocessing.php.template`, so the overlay stage contains no active `preprocessing.php` hook. Historical PCV24 owner files remain in their source tree and are not copied as a second runtime stage.

Run the focused package guards with:

```powershell
python -B -m unittest discover -s packaging/tests -p 'test_pcv25_offline_candidate.py' -v
```

Run the assembler with:

```powershell
python -B packaging/build-pcv25-offline-candidate.py
```

The candidate ZIP and SHA-bound audit are written under `artifacts/department-pcv25-offline-20261029/`. Existing outputs are never overwritten.

The result is offline source/package evidence only. It does not establish installation, synchronization, native compilation, server/DB/HTTP/provider behavior, installed-game behavior, live acceptance, release approval, or publication.
