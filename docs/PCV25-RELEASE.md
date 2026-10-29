# PCV25 prealpha.27 release build

The installable prealpha.27 archive is the already reviewed offline candidate emitted under its ordinary release name. It contains the PCV 0.1.25 owner overlay and support companion prealpha.13. The native DLL, UI, MMP, and other unchanged archive members retain their reviewed bytes.

## Inputs and source binding

Build from a clean checkout whose `HEAD` is the full commit passed to the builder. Download the published prealpha.26 archive and the prealpha.27 offline-proof ZIP. Extract exactly `reviewed-candidate-audit.json` and `reviewed-offline-checks.json` from the proof ZIP into a sibling `../release-inputs` directory, alongside the prealpha.26 archive. The builder checks the baseline archive hash, both receipt hashes, all 25 reviewed PCV25 source hashes, and the source allowlist transition. It permits exactly two guarded source changes: `server/manifest.json` changes only its version from prealpha.26 to prealpha.27, and the source allowlist preserves the prior byte prefix before appending the four release paths.

For the focused release tests, place the ordinary reviewed installable at `../release-inputs/WorldOfDramaLlama-0.1.0-prealpha.27.zip`. It is the fourth test input, pinned to SHA-256 `abb2fe3fb85859b26a26c251929a464e27c36d13179380e6ff491d15af2eb978`; the build and audit commands use only the baseline ZIP and two receipts.

The historical PCV25 assembler requires the prealpha.26 server manifest while it creates the Department package's local prealpha.27 identity. The wrapper supplies that pinned prealpha.26 manifest only to that assembler. The source ZIP and release provenance use the actual selected commit, including its prealpha.27 manifest.

## Build and audit

Run from the repository root in PowerShell. Paths are relative to the worktree; the new output directory stays outside the source checkout.

```powershell
$sourceCommit = (git rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0) { throw "Could not resolve HEAD" }
if (git status --porcelain=v1 --untracked-files=all) { throw "Use a clean checkout" }

python -B packaging/build-installable-prealpha27.py build `
  --prealpha26-zip ..\release-inputs\WorldOfDramaLlama-0.1.0-prealpha.26.zip `
  --candidate-audit ..\release-inputs\reviewed-candidate-audit.json `
  --offline-checks ..\release-inputs\reviewed-offline-checks.json `
  --source-commit $sourceCommit `
  --output-dir ..\release-output

python -B packaging/build-installable-prealpha27.py audit `
  --prealpha26-zip ..\release-inputs\WorldOfDramaLlama-0.1.0-prealpha.26.zip `
  --candidate-audit ..\release-inputs\reviewed-candidate-audit.json `
  --offline-checks ..\release-inputs\reviewed-offline-checks.json `
  --source-commit $sourceCommit `
  --output-dir ..\release-output
```

The output directory contains exactly four files:

- `WorldOfDramaLlama-0.1.0-prealpha.27.zip`
- `WorldOfDramaLlama-0.1.0-prealpha.27-source.zip`
- `WorldOfDramaLlama-0.1.0-prealpha.27-offline-proof.zip`
- `SHA256SUMS.txt`

The installable ZIP must be byte-identical to the reviewed PCV25 candidate, SHA-256 `abb2fe3fb85859b26a26c251929a464e27c36d13179380e6ff491d15af2eb978`. The source ZIP is constructed from the selected commit's complete public-source allowlist. The offline proof ZIP contains the exact accepted candidate audit and Windows offline-check receipt, plus a compact provenance summary bound to the selected commit and source archive. It contains no raw command logs, private fixtures, or machine-specific paths.

## Verification scope

The reviewed checks establish Windows source, control-flow, and package behavior for the recorded cases. The reported input freeze is unchanged. Logger persistence, POSIX filesystem safety, and tagged native packet ordering remain unverified. HTTP, database, provider, native compilation, Skyrim, installed-game, and live behavior are outside this evidence. The release builder does not install or publish the package.
