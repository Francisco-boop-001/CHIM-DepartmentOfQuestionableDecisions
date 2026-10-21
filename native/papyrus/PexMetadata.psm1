Set-StrictMode -Version Latest

$script:PexUtf8 = [System.Text.UTF8Encoding]::new($false, $true)

function Read-PexField {
	param(
		[Parameter(Mandatory)][byte[]] $Bytes,
		[Parameter(Mandatory)][int] $Offset
	)
	if ($Offset -lt 0 -or $Bytes.Length - $Offset -lt 2) {
		throw 'PEX metadata length is truncated.'
	}
	$length = ([int]$Bytes[$Offset] -shl 8) -bor [int]$Bytes[$Offset + 1]
	$start = $Offset + 2
	if ($length -gt $Bytes.Length - $start) {
		throw 'PEX metadata value is truncated.'
	}
	try {
		$value = $script:PexUtf8.GetString($Bytes, $start, $length)
	} catch [System.Text.DecoderFallbackException] {
		throw 'PEX metadata is not valid UTF-8.'
	}
	return [pscustomobject]@{
		Value = $value
		NextOffset = $start + $length
	}
}

function Read-PexMetadata {
	[CmdletBinding()]
	param([Parameter(Mandatory)][byte[]] $Bytes)

	if ($Bytes.Length -lt 16) { throw 'PEX fixed header is truncated.' }
	if ($Bytes[0] -ne 0xFA -or $Bytes[1] -ne 0x57 -or $Bytes[2] -ne 0xC0 -or $Bytes[3] -ne 0xDE) {
		throw 'PEX magic is invalid.'
	}
	if ($Bytes[4] -ne 3 -or $Bytes[5] -ne 2 -or $Bytes[6] -ne 0 -or $Bytes[7] -ne 1) {
		throw 'PEX version or game identifier is not Skyrim 3.2.'
	}

	$source = Read-PexField -Bytes $Bytes -Offset 16
	$user = Read-PexField -Bytes $Bytes -Offset $source.NextOffset
	$computer = Read-PexField -Bytes $Bytes -Offset $user.NextOffset
	return [pscustomobject]@{
		SourceFileName = $source.Value
		UserName = $user.Value
		ComputerName = $computer.Value
		SuffixOffset = $computer.NextOffset
	}
}

function ConvertTo-NeutralPexMetadata {
	[CmdletBinding()]
	param(
		[Parameter(Mandatory)][byte[]] $Bytes,
		[Parameter(Mandatory)][string] $SourceFileName
	)

	if ([string]::IsNullOrWhiteSpace($SourceFileName) -or [System.IO.Path]::GetFileName($SourceFileName) -cne $SourceFileName) {
		throw 'The neutral PEX source identity must be a file name without a path.'
	}
	$metadata = Read-PexMetadata -Bytes $Bytes
	$inputSource = $metadata.SourceFileName.Replace('/', '\')
	if ([System.IO.Path]::GetFileName($inputSource) -cne $SourceFileName) {
		throw 'The PEX source metadata does not match the requested script file.'
	}
	$sourceBytes = $script:PexUtf8.GetBytes($SourceFileName)
	if ($sourceBytes.Length -gt [uint16]::MaxValue) { throw 'The neutral source file name exceeds the PEX field limit.' }

	$stream = [System.IO.MemoryStream]::new()
	try {
		$stream.Write($Bytes, 0, 16)
		$fields = [System.Collections.Generic.List[byte[]]]::new()
		$fields.Add($sourceBytes)
		$fields.Add([byte[]]::new(0))
		$fields.Add([byte[]]::new(0))
		foreach ($fieldBytes in $fields) {
			$stream.WriteByte([byte](($fieldBytes.Length -shr 8) -band 0xFF))
			$stream.WriteByte([byte]($fieldBytes.Length -band 0xFF))
			if ($fieldBytes.Length -gt 0) { $stream.Write($fieldBytes, 0, $fieldBytes.Length) }
		}
		$stream.Write($Bytes, $metadata.SuffixOffset, $Bytes.Length - $metadata.SuffixOffset)
		return ,$stream.ToArray()
	} finally {
		$stream.Dispose()
	}
}

Export-ModuleMember -Function Read-PexMetadata, ConvertTo-NeutralPexMetadata
