Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Import-Module (Join-Path $PSScriptRoot '..\PexMetadata.psm1') -Force

function Add-TestPexString {
	param(
		[Parameter(Mandatory)][System.Collections.Generic.List[byte]] $Buffer,
		[Parameter(Mandatory)][string] $Value
	)
	$encoded = [System.Text.UTF8Encoding]::new($false, $true).GetBytes($Value)
	$Buffer.Add([byte](($encoded.Length -shr 8) -band 0xFF))
	$Buffer.Add([byte]($encoded.Length -band 0xFF))
	$Buffer.AddRange($encoded)
}

$bytes = [System.Collections.Generic.List[byte]]::new()
$fixedHeader = [byte[]]::new(16)
$fixedValues = [byte[]]@(0xFA, 0x57, 0xC0, 0xDE, 3, 2, 0, 1)
for ($i = 0; $i -lt $fixedValues.Length; $i++) { $fixedHeader[$i] = $fixedValues[$i] }
$bytes.AddRange($fixedHeader)
Add-TestPexString $bytes 'K:\private\WorldOfDramaLlamaNative.psc'
Add-TestPexString $bytes 'Local User'
Add-TestPexString $bytes 'Workstation'
$suffix = [byte[]]@(0x00, 0x12, 0x34, 0xFF, 0x00, 0x5A, 0xA5)
$bytes.AddRange($suffix)
$raw = $bytes.ToArray()

$neutral = ConvertTo-NeutralPexMetadata -Bytes $raw -SourceFileName 'WorldOfDramaLlamaNative.psc'
$parsed = Read-PexMetadata -Bytes $neutral
if ($parsed.SourceFileName -cne 'WorldOfDramaLlamaNative.psc' -or $parsed.UserName -cne '' -or $parsed.ComputerName -cne '') {
	throw 'Sanitized metadata did not contain the requested neutral values.'
}
for ($i = 0; $i -lt 16; $i++) {
	if ($raw[$i] -ne $neutral[$i]) { throw 'The fixed PEX header changed.' }
}
for ($i = 0; $i -lt $suffix.Length; $i++) {
	if ($suffix[$i] -ne $neutral[$parsed.SuffixOffset + $i]) { throw 'The PEX payload suffix changed.' }
}

$rawMetadata = Read-PexMetadata -Bytes $raw
$truncated = [byte[]]$raw[0..($rawMetadata.SuffixOffset - 2)]
$truncatedRejected = $false
try {
	$null = ConvertTo-NeutralPexMetadata -Bytes $truncated -SourceFileName 'WorldOfDramaLlamaNative.psc'
} catch {
	$truncatedRejected = $true
}
if (-not $truncatedRejected) { throw 'A truncated metadata field was not rejected.' }

$mismatchedSourceRejected = $false
try {
	$null = ConvertTo-NeutralPexMetadata -Bytes $raw -SourceFileName 'WorldOfDramaLlamaMCM.psc'
} catch {
	$mismatchedSourceRejected = $true
}
if (-not $mismatchedSourceRejected) { throw 'A mismatched source identity was not rejected.' }

Write-Output 'PexMetadata.Tests.ps1 passed: metadata neutralization, fixed-header/suffix identity, truncation refusal, source identity refusal.'
