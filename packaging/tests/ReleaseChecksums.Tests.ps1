[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$helperPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'write-release-checksums.ps1'
if (-not (Test-Path -LiteralPath $helperPath -PathType Leaf)) {
	throw 'The release checksum writer is missing.'
}

$scratchRoot = Join-Path ([IO.Path]::GetTempPath()) ('release-checksums-test-' + [Guid]::NewGuid().ToString('N'))
if (Test-Path -LiteralPath $scratchRoot) { throw 'The unique release checksum test path already exists.' }
New-Item -ItemType Directory -Path $scratchRoot | Out-Null
try {
	$validRoot = Join-Path $scratchRoot 'valid'
	New-Item -ItemType Directory -Path $validRoot | Out-Null
	[IO.File]::WriteAllBytes((Join-Path $validRoot 'hello.txt'), [Text.Encoding]::ASCII.GetBytes('hello'))
	[IO.File]::WriteAllBytes((Join-Path $validRoot 'world.bin'), [Text.Encoding]::ASCII.GetBytes('world'))
	& $helperPath -ReleaseDirectory $validRoot -AssetNames @('hello.txt', 'world.bin')

	$expected = [Text.Encoding]::ASCII.GetBytes("2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824  hello.txt`n486ea46224d1bb4fb680f34f7c9ad96a8f24ec88be73ea8e5a6c65260e9cb8a7  world.bin`n")
	$actual = [IO.File]::ReadAllBytes((Join-Path $validRoot 'SHA256SUMS.txt'))
	if ([Convert]::ToHexString($actual) -cne [Convert]::ToHexString($expected)) {
		throw 'The manifest must contain the expected lowercase hashes as ASCII LF lines with no BOM.'
	}
	& $helperPath -ReleaseDirectory $validRoot -AssetNames @('hello.txt', 'world.bin')
	if ([Convert]::ToHexString([IO.File]::ReadAllBytes((Join-Path $validRoot 'SHA256SUMS.txt'))) -cne [Convert]::ToHexString($expected)) {
		throw 'An identical existing manifest must be accepted unchanged.'
	}
	foreach ($unsafeName in @("hello.txt`n", "hello.txt`r", "hello.txt`r`n")) {
		$failedAsUnsafe = $false
		try { & $helperPath -ReleaseDirectory $validRoot -AssetNames @($unsafeName) } catch {
			$failedAsUnsafe = $_.Exception.Message -like 'Unsafe release asset name:*'
		}
		if (-not $failedAsUnsafe) { throw 'Trailing LF, CR, or CRLF in an asset name must be rejected as unsafe.' }
	}
	$failedAsDuplicate = $false
	try { & $helperPath -ReleaseDirectory $validRoot -AssetNames @('hello.txt', 'HELLO.TXT') } catch {
		$failedAsDuplicate = $_.Exception.Message -like 'Duplicate release asset name:*'
	}
	if (-not $failedAsDuplicate) { throw 'Case-insensitive duplicate asset names must be rejected before file checks.' }

	$extraRoot = Join-Path $scratchRoot 'extra'
	New-Item -ItemType Directory -Path $extraRoot | Out-Null
	[IO.File]::WriteAllText((Join-Path $extraRoot 'expected.txt'), 'asset', [Text.Encoding]::ASCII)
	[IO.File]::WriteAllText((Join-Path $extraRoot 'unexpected.txt'), 'extra', [Text.Encoding]::ASCII)
	$failed = $false
	try { & $helperPath -ReleaseDirectory $extraRoot -AssetNames @('expected.txt') } catch { $failed = $true }
	if (-not $failed -or (Test-Path -LiteralPath (Join-Path $extraRoot 'SHA256SUMS.txt'))) {
		throw 'An unexpected output must be rejected before a manifest is written.'
	}

	$unsafeRoot = Join-Path $scratchRoot 'unsafe'
	New-Item -ItemType Directory -Path $unsafeRoot | Out-Null
	$failed = $false
	try { & $helperPath -ReleaseDirectory $unsafeRoot -AssetNames @('../escape.txt') } catch { $failed = $true }
	if (-not $failed -or (Test-Path -LiteralPath (Join-Path $scratchRoot 'escape.txt'))) {
		throw 'An unsafe asset name must be rejected before any file is written.'
	}

	$existingRoot = Join-Path $scratchRoot 'existing'
	New-Item -ItemType Directory -Path $existingRoot | Out-Null
	[IO.File]::WriteAllText((Join-Path $existingRoot 'expected.txt'), 'asset', [Text.Encoding]::ASCII)
	$manifestPath = Join-Path $existingRoot 'SHA256SUMS.txt'
	$priorManifest = [Text.Encoding]::ASCII.GetBytes("prior manifest`n")
	[IO.File]::WriteAllBytes($manifestPath, $priorManifest)
	$failed = $false
	try { & $helperPath -ReleaseDirectory $existingRoot -AssetNames @('expected.txt') } catch { $failed = $true }
	if (-not $failed -or [Convert]::ToHexString([IO.File]::ReadAllBytes($manifestPath)) -cne [Convert]::ToHexString($priorManifest)) {
		throw 'A different existing manifest must be preserved and refused.'
	}

	if ($env:SHA256SUM_EXE) {
		if (-not (Test-Path -LiteralPath $env:SHA256SUM_EXE -PathType Leaf)) {
			throw 'SHA256SUM_EXE must name an existing sha256sum executable.'
		}
		Push-Location $validRoot
		try {
			& $env:SHA256SUM_EXE -c SHA256SUMS.txt
			if ($LASTEXITCODE -ne 0) { throw 'GNU sha256sum -c rejected the generated manifest.' }
		} finally {
			Pop-Location
		}
	} else {
		Write-Output 'SKIP: GNU sha256sum -c (set SHA256SUM_EXE to an explicit executable path).'
	}
} finally {
	if (Test-Path -LiteralPath $scratchRoot -PathType Container) {
		$separators = [char[]]@([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
		$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd($separators)
		$resolvedScratchRoot = [IO.Path]::GetFullPath($scratchRoot)
		$scratchParent = [IO.Path]::GetDirectoryName($resolvedScratchRoot).TrimEnd($separators)
		if (-not [string]::Equals($scratchParent, $tempRoot, [StringComparison]::OrdinalIgnoreCase) -or
			[IO.Path]::GetFileName($resolvedScratchRoot) -notmatch '^release-checksums-test-[0-9a-f]{32}$') {
			throw 'Refusing to remove a scratch path outside the exact unique test child of the system temp directory.'
		}
		$tempItem = Get-Item -LiteralPath $tempRoot -Force
		$scratchItem = Get-Item -LiteralPath $resolvedScratchRoot -Force
		if (($tempItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
			($scratchItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
			throw 'Refusing to remove test scratch through a reparse point.'
		}
		$reparseItems = @(Get-ChildItem -LiteralPath $resolvedScratchRoot -Recurse -Force |
			Where-Object { ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 })
		if ($reparseItems.Count -gt 0) { throw 'Refusing to remove test scratch containing reparse points.' }
		Remove-Item -LiteralPath $resolvedScratchRoot -Recurse -Force
	}
}

Write-Output 'PASS: release checksums are deterministic LF ASCII and reject unsafe or unexpected staged files.'
