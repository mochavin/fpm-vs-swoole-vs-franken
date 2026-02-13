#!/bin/bash
set -e

# ───────────────────────────────────────────────────────────
# Laravel Benchmark Runner
# Benchmarks FPM, Swoole, and FrankenPHP runtimes using k6
# ───────────────────────────────────────────────────────────

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RESULTS_DIR="./results/${TIMESTAMP}"
RUNTIMES=("fpm" "swoole" "franken")
PORTS=("8001" "8002" "8003")
INTERNAL_URLS=("http://nginx-fpm" "http://app-swoole:8000" "http://app-franken:8000")

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}"
echo "╔═══════════════════════════════════════════════════════╗"
echo "║   Laravel 12 Benchmark: FPM vs Swoole vs FrankenPHP  ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Create results directory
mkdir -p "$RESULTS_DIR"

# ─── Step 1: Generate APP_KEY if not set ──────────────────
if [ ! -f .env ] || ! grep -q "APP_KEY=base64:" .env 2>/dev/null; then
    echo -e "${YELLOW}[Setup] Generating APP_KEY...${NC}"
    APP_KEY=$(php artisan key:generate --show 2>/dev/null || echo "base64:$(openssl rand -base64 32)")
    echo "APP_KEY=${APP_KEY}" > .env
else
    source .env
fi
export APP_KEY

# ─── Step 2: Build and start services ────────────────────
echo -e "${BLUE}[1/5] Building Docker images...${NC}"
docker compose build --parallel

echo -e "${BLUE}[2/5] Starting services...${NC}"
docker compose up -d db app-fpm nginx-fpm app-swoole app-franken

# ─── Step 3: Wait for services to be healthy ─────────────
echo -e "${BLUE}[3/5] Waiting for services to be ready...${NC}"

wait_for_service() {
    local name=$1
    local port=$2
    local max_attempts=60
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:${port}/api/health" 2>/dev/null | grep -q "200"; then
            echo -e "  ${GREEN}✓ ${name} is ready (port ${port})${NC}"
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    echo -e "  ${RED}✗ ${name} failed to start (port ${port})${NC}"
    return 1
}

for i in "${!RUNTIMES[@]}"; do
    wait_for_service "${RUNTIMES[$i]}" "${PORTS[$i]}"
done

# ─── Step 4: Run smoke tests ─────────────────────────────
echo -e "${BLUE}[4/5] Running smoke tests...${NC}"

for i in "${!RUNTIMES[@]}"; do
    echo -e "  ${YELLOW}Smoke testing ${RUNTIMES[$i]}...${NC}"
    docker compose --profile bench run --rm \
        -e BASE_URL="http://localhost:${PORTS[$i]}" \
        k6 run /scripts/smoke.js 2>/dev/null && \
        echo -e "  ${GREEN}✓ ${RUNTIMES[$i]} smoke test passed${NC}" || \
        echo -e "  ${RED}✗ ${RUNTIMES[$i]} smoke test failed${NC}"
done

# ─── Step 5: Run benchmarks ──────────────────────────────
echo -e "${BLUE}[5/5] Running benchmarks...${NC}"
echo ""

for i in "${!RUNTIMES[@]}"; do
    runtime="${RUNTIMES[$i]}"
    url="${INTERNAL_URLS[$i]}"

    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${CYAN}  Benchmarking: ${runtime^^}${NC}"
    echo -e "${CYAN}  URL: ${url}${NC}"
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    docker compose --profile bench run --rm \
        -e BASE_URL="${url}" \
        -e RUNTIME="${runtime}" \
        -e K6_OUT="json=/results/${runtime}_results.json" \
        k6 run /scripts/benchmark.js \
        2>&1 | tee "${RESULTS_DIR}/${runtime}_output.txt"

    echo ""
    echo -e "${GREEN}✓ ${runtime^^} benchmark complete${NC}"
    echo ""

    # Small pause between benchmarks to let things settle
    if [ $i -lt $((${#RUNTIMES[@]} - 1)) ]; then
        echo -e "${YELLOW}  Cooling down for 10 seconds...${NC}"
        sleep 10
    fi
done

# ─── Summary ─────────────────────────────────────────────
echo ""
echo -e "${CYAN}╔═══════════════════════════════════════════════════════╗"
echo -e "║                  Benchmark Complete!                  ║"
echo -e "╚═══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Results saved to: ${GREEN}${RESULTS_DIR}/${NC}"
echo ""
echo -e "Files:"
ls -la "$RESULTS_DIR/" 2>/dev/null || true

echo ""
echo -e "${YELLOW}[Report] Generating HTML summary...${NC}"
php generate-report.php

echo ""
echo -e "${YELLOW}To view results:${NC}"
echo "  Open benchmark-report.html in your browser"
echo ""
echo -e "${YELLOW}To clean up:${NC}"
echo "  docker compose down -v"
