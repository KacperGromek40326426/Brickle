$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$tokenConfigPath = Join-Path $projectRoot 'includes\setup-token.php'
if (Test-Path -LiteralPath $tokenConfigPath) {
    throw "A setup-token.php already exists. Remove it before generating a new token."
}

$random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$bytes = New-Object byte[] 32
$random.GetBytes($bytes)
$token = [System.BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
$sha256 = [System.Security.Cryptography.SHA256]::Create()
$tokenHash = [System.BitConverter]::ToString(
    $sha256.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($token))
).Replace('-', '').ToLowerInvariant()

$phpConfig = "<?php`ndeclare(strict_types=1);`n`nreturn '$tokenHash';`n"
$utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($tokenConfigPath, $phpConfig, $utf8WithoutBom)

Write-Output 'One-time setup token (save it now; it cannot be shown again):'
Write-Output $token
Write-Output ''
Write-Output 'Upload includes\setup-token.php with your site files. Do not upload or share the token itself.'
