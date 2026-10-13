$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Install Docker Desktop with its WSL 2 backend, start Docker, then run this script again.'
}
docker info --format '{{.ServerVersion}}'
if ($LASTEXITCODE -ne 0) { throw 'Docker is not running.' }
if (-not (Test-Path -LiteralPath '.env')) {
    $mapaRandom = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $mapaDbBytes = New-Object byte[] 24
    $mapaKeyBytes = New-Object byte[] 32
    $mapaTokenBytes = New-Object byte[] 32
    $mapaRandom.GetBytes($mapaDbBytes)
    $mapaRandom.GetBytes($mapaKeyBytes)
    $mapaRandom.GetBytes($mapaTokenBytes)
    $mapaDbPassword = [BitConverter]::ToString($mapaDbBytes).Replace('-', '').ToLowerInvariant()
    $mapaApiToken = [BitConverter]::ToString($mapaTokenBytes).Replace('-', '').ToLowerInvariant()
    $mapaAppKey = [Convert]::ToBase64String($mapaKeyBytes)
    @("MYSQL_ROOT_PASSWORD=$mapaDbPassword", "APP_KEY=base64:$mapaAppKey", "MAPA_ADAPTER_TOKEN=$mapaApiToken", 'MAPA_COMPANY_UUID=') | Set-Content -LiteralPath '.env' -Encoding ascii
    $mapaRandom.Dispose()
}
docker compose --env-file .env -f compose.yml up -d --build
if ($LASTEXITCODE -ne 0) { throw 'MAPA containers could not start.' }
docker compose --env-file .env -f compose.yml exec -T application sh ./deploy.sh
if ($LASTEXITCODE -ne 0) { throw 'MAPA initialization failed. Inspect the container logs before retrying.' }
Write-Host 'MAPA is ready for onboarding at http://localhost:4200. See README.md to configure the operating company.'
