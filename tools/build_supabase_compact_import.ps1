param(
    [string]$SchemaPath = "database/supabase_schema.sql",
    [string]$DataPath = "database/supabase_data.sql",
    [string]$OutputPath = "database/supabase_full_import_compact.sql",
    [int]$BatchSize = 500
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Utf8NoBom {
    param(
        [string]$Path,
        [string]$Content
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText((Resolve-Path -LiteralPath (Split-Path $Path -Parent)).Path + "\" + (Split-Path $Path -Leaf), $Content, $encoding)
}

function Compress-Schema {
    param([string]$Sql)

    $statements = New-Object System.Collections.Generic.List[string]
    $current = New-Object System.Text.StringBuilder

    foreach ($rawLine in ($Sql -split "`r?`n")) {
        $line = $rawLine.Trim()
        if ($line.Length -eq 0 -or $line.StartsWith("--")) {
            continue
        }

        [void]$current.Append($line).Append(" ")
        if ($line.EndsWith(";")) {
            $statements.Add(($current.ToString() -replace "\s+", " ").Trim())
            [void]$current.Clear()
        }
    }

    if ($current.Length -gt 0) {
        $statements.Add(($current.ToString() -replace "\s+", " ").Trim())
    }

    return $statements
}

function Flush-Batch {
    param(
        [System.Collections.Generic.List[string]]$Output,
        [string]$Prefix,
        [System.Collections.Generic.List[string]]$Values
    )

    if ($Prefix -and $Values.Count -gt 0) {
        $Output.Add($Prefix + " values " + ($Values -join ", ") + ";")
        $Values.Clear()
    }
}

$schemaSql = Get-Content -LiteralPath $SchemaPath -Raw
$dataLines = Get-Content -LiteralPath $DataPath

$output = New-Object System.Collections.Generic.List[string]
$output.Add("-- Havenzen compact Supabase import: schema then data.")
$output.Add("-- This file contains live data. Do not commit it.")
foreach ($statement in (Compress-Schema -Sql $schemaSql)) {
    $output.Add($statement)
}

$currentPrefix = $null
$values = New-Object System.Collections.Generic.List[string]

foreach ($line in $dataLines) {
    $trimmed = $line.Trim()
    if ($trimmed.Length -eq 0 -or $trimmed.StartsWith("--")) {
        continue
    }

    $match = [regex]::Match($trimmed, '^(insert into ".+?" \(.+?\)) values (.+);$')
    if ($match.Success) {
        $prefix = $match.Groups[1].Value
        $value = $match.Groups[2].Value

        if ($currentPrefix -ne $prefix -or $values.Count -ge $BatchSize) {
            Flush-Batch -Output $output -Prefix $currentPrefix -Values $values
            $currentPrefix = $prefix
        }

        $values.Add($value)
        continue
    }

    Flush-Batch -Output $output -Prefix $currentPrefix -Values $values
    $currentPrefix = $null
    $output.Add($trimmed)
}

Flush-Batch -Output $output -Prefix $currentPrefix -Values $values

$content = ($output -join "`r`n") + "`r`n"
Write-Utf8NoBom -Path $OutputPath -Content $content

$lineCount = ($content -split "`r?`n").Count - 1
Write-Host "Wrote $OutputPath"
Write-Host "Lines: $lineCount"
