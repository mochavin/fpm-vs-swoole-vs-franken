# ---------------------------------------------------------------
# Laravel Benchmark Runner (PowerShell)
# Benchmarks FPM, Swoole, and FrankenPHP runtimes using k6
# ---------------------------------------------------------------

$ErrorActionPreference = "Continue"

$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$ResultsDir = "./results/$Timestamp"
$Runtimes = @(
    @{ Name = "fpm";     Port = "8001"; InternalUrl = "http://nginx-fpm" },
    @{ Name = "swoole";  Port = "8002"; InternalUrl = "http://app-swoole:8000" },
    @{ Name = "franken"; Port = "8003"; InternalUrl = "http://app-franken:8000" }
)

Write-Host ""
Write-Host "=== Laravel 12 Benchmark: FPM vs Swoole vs FrankenPHP ===" -ForegroundColor Cyan
Write-Host ""

# Create results directory
New-Item -ItemType Directory -Force -Path $ResultsDir | Out-Null

# --- Step 1: Check APP_KEY ---
if (-not $env:APP_KEY) {
    if (Test-Path .env) {
        $envContent = Get-Content .env -Raw
        $match = [regex]::Match($envContent, 'APP_KEY=(.+)')
        if ($match.Success) {
            $env:APP_KEY = $match.Groups[1].Value.Trim()
        }
    }

    if (-not $env:APP_KEY -or $env:APP_KEY -eq "") {
        Write-Host "[Setup] Generating APP_KEY..." -ForegroundColor Yellow
        $bytes = New-Object byte[] 32
        $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
        $rng.GetBytes($bytes)
        $env:APP_KEY = "base64:$([Convert]::ToBase64String($bytes))"
        "APP_KEY=$($env:APP_KEY)" | Set-Content .env
    }
}
Write-Host "[Setup] APP_KEY is set" -ForegroundColor Green

# --- Step 2: Build and start services ---
Write-Host "[1/5] Building Docker images..." -ForegroundColor Blue
docker compose build --parallel 2>&1 | Out-Host

Write-Host "[2/5] Starting services..." -ForegroundColor Blue
docker compose up -d db app-fpm nginx-fpm app-swoole app-franken 2>&1 | Out-Host

# --- Step 3: Wait for services ---
Write-Host "[3/5] Waiting for services to be ready..." -ForegroundColor Blue

function Wait-ForService {
    param(
        [string]$Name,
        [string]$Port,
        [int]$MaxAttempts = 60
    )

    for ($attempt = 0; $attempt -lt $MaxAttempts; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri "http://localhost:$Port/api/health" -TimeoutSec 3 -UseBasicParsing -ErrorAction SilentlyContinue
            if ($response.StatusCode -eq 200) {
                Write-Host "  [OK] $Name is ready (port $Port)" -ForegroundColor Green
                return $true
            }
        }
        catch {
            # Not ready yet
        }
        Start-Sleep -Seconds 2
    }

    Write-Host "  [FAIL] $Name failed to start (port $Port)" -ForegroundColor Red
    return $false
}

foreach ($rt in $Runtimes) {
    Wait-ForService -Name $rt.Name -Port $rt.Port
}

# --- Step 4: Smoke tests ---
Write-Host "[4/5] Running smoke tests..." -ForegroundColor Blue

foreach ($rt in $Runtimes) {
    Write-Host "  Smoke testing $($rt.Name)..." -ForegroundColor Yellow
    docker compose --profile bench run --rm -e "BASE_URL=http://localhost:$($rt.Port)" k6 run /scripts/smoke.js 2>&1 | Out-Host

    if ($LASTEXITCODE -eq 0) {
        Write-Host "  [OK] $($rt.Name) smoke test passed" -ForegroundColor Green
    }
    else {
        Write-Host "  [FAIL] $($rt.Name) smoke test failed" -ForegroundColor Red
    }
}

# --- Step 5: Run benchmarks ---
Write-Host "[5/5] Running benchmarks..." -ForegroundColor Blue
Write-Host ""

for ($i = 0; $i -lt $Runtimes.Count; $i++) {
    $rt = $Runtimes[$i]

    Write-Host "---------------------------------------------------" -ForegroundColor Cyan
    Write-Host "  Benchmarking: $($rt.Name.ToUpper())" -ForegroundColor Cyan
    Write-Host "  URL: $($rt.InternalUrl)" -ForegroundColor Cyan
    Write-Host "---------------------------------------------------" -ForegroundColor Cyan
    Write-Host ""

    docker compose --profile bench run --rm -e "BASE_URL=$($rt.InternalUrl)" -e "RUNTIME=$($rt.Name)" k6 run /scripts/benchmark.js 2>&1 | Tee-Object -FilePath "$ResultsDir\$($rt.Name)_output.txt"

    Write-Host ""
    Write-Host "[OK] $($rt.Name.ToUpper()) benchmark complete" -ForegroundColor Green
    Write-Host ""

    # Cooldown between benchmarks
    if ($i -lt ($Runtimes.Count - 1)) {
        Write-Host "  Cooling down for 10 seconds..." -ForegroundColor Yellow
        Start-Sleep -Seconds 10
    }
}

# --- Summary ---
Write-Host ""
Write-Host "=== Benchmark Complete! ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Results saved to: $ResultsDir" -ForegroundColor Green
Write-Host ""
if (Test-Path $ResultsDir) {
    Get-ChildItem $ResultsDir | Format-Table Name, Length, LastWriteTime -AutoSize
}

Write-Host ""
Write-Host "[Report] Generating HTML summary..." -ForegroundColor Yellow
php generate-report.php

Write-Host ""
Write-Host "To view results:" -ForegroundColor Yellow
Write-Host "  Open benchmark-report.html in your browser"
Write-Host ""
Write-Host "To clean up:" -ForegroundColor Yellow
Write-Host "  docker compose down -v"
