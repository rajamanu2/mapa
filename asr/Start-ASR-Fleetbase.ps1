$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Install Docker Desktop with its WSL 2 backend, start Docker, then run this script again.'
}
docker info --format '{{.ServerVersion}}'
if ($LASTEXITCODE -ne 0) { throw 'Docker is not running.' }
if (-not (Test-Path -LiteralPath '.env')) {
    $asrRandom = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $asrDbBytes = New-Object byte[] 24
    $asrKeyBytes = New-Object byte[] 32
    $asrTokenBytes = New-Object byte[] 32
    $asrRandom.GetBytes($asrDbBytes)
    $asrRandom.GetBytes($asrKeyBytes)
    $asrRandom.GetBytes($asrTokenBytes)
    $asrDbPassword = [BitConverter]::ToString($asrDbBytes).Replace('-', '').ToLowerInvariant()
    $asrApiToken = [BitConverter]::ToString($asrTokenBytes).Replace('-', '').ToLowerInvariant()
    $asrAppKey = [Convert]::ToBase64String($asrKeyBytes)
    @("MYSQL_ROOT_PASSWORD=$asrDbPassword", "APP_KEY=base64:$asrAppKey", "ASR_ADAPTER_TOKEN=$asrApiToken", 'ASR_COMPANY_UUID=') | Set-Content -LiteralPath '.env' -Encoding ascii
    $asrRandom.Dispose()
}
docker compose --env-file .env -f compose.yml up -d --build
if ($LASTEXITCODE -ne 0) { throw 'Fleetbase containers could not start.' }
docker compose --env-file .env -f compose.yml exec -T application sh ./deploy.sh
if ($LASTEXITCODE -ne 0) { throw 'Fleetbase initialization failed. Inspect the container logs before retrying.' }
Write-Host 'Fleetbase is ready for onboarding at http://localhost:4200. See README.md to link the ASR company.'
