$ErrorActionPreference = 'Stop'
$environmentPath = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
if (Test-Path -LiteralPath $environmentPath) {
    throw 'Ya existe .env en la raiz; se conserva sin cambios.'
}
$generator = [Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $keyBytes = New-Object byte[] 32
    $secretBytes = New-Object byte[] 32
    $generator.GetBytes($keyBytes)
    $generator.GetBytes($secretBytes)
    $lines = @(
        ('APP_KEY=base64:' + [Convert]::ToBase64String($keyBytes))
        'REVERB_APP_ID=dinostar'
        'REVERB_APP_KEY=dinostar-local'
        ('REVERB_APP_SECRET=' + [BitConverter]::ToString($secretBytes).Replace('-', ''))
    )
    [IO.File]::WriteAllLines($environmentPath, $lines)
    Write-Output 'Archivo .env privado creado. No subirlo a Git.'
} finally {
    $generator.Dispose()
}
