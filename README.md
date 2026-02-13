# Laravel 12 Benchmark: FPM vs Swoole vs FrankenPHP

![Benchmark Result](https://github.com/user-attachments/assets/32ca3839-8837-4ad9-83bc-638a23202919)
This page presents a performance comparison between **PHP-FPM**, **Swoole**, and **FrankenPHP** under identical benchmarking scenarios.

**Live demo & detailed results:**  https://mochavin.github.io/fpm-vs-swoole-vs-franken/


Benchmark Laravel 12 across three PHP runtimes using [k6](https://k6.io/) load testing, orchestrated with Docker Compose.

## Runtimes

| Runtime    | Port  | Stack                          |
|-----------|-------|--------------------------------|
| **FPM**   | 8001  | Nginx → PHP-FPM 8.3            |
| **Swoole**| 8002  | Laravel Octane + Swoole         |
| **FrankenPHP** | 8003 | Laravel Octane + FrankenPHP |

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) & Docker Compose v2
- [PHP 8.2+](https://www.php.net/) & [Composer](https://getcomposer.org/) (for local key generation)
- ~4GB free RAM (for all containers)

## Quick Start

### 1. Clone & setup

```bash
git clone <repo-url> laravel-bench
cd laravel-bench

# Generate APP_KEY
php artisan key:generate
```

### 2. Build & run all runtimes

```bash
docker compose build --parallel
docker compose up -d
```

### 3. Verify services are healthy

```bash
# FPM (Nginx)
curl http://localhost:8001/api/health

# Swoole
curl http://localhost:8002/api/health

# FrankenPHP
curl http://localhost:8003/api/health
```

All should return:
```json
{"status":"ok","runtime":"fpm|swoole|frankenphp","timestamp":"..."}
```

### 4. Run benchmarks

**Automated (recommended):**

```powershell
# Windows (PowerShell)
.\run-bench.ps1

# Linux/macOS (Bash)
bash run-bench.sh
```

**Manual (per runtime):**

```bash
# Benchmark FPM
docker compose run --rm --profile bench \
  -e BASE_URL=http://nginx-fpm/api \
  -e RUNTIME=fpm \
  k6 run /scripts/benchmark.js

# Benchmark Swoole
docker compose run --rm --profile bench \
  -e BASE_URL=http://app-swoole:8000/api \
  -e RUNTIME=swoole \
  k6 run /scripts/benchmark.js

# Benchmark FrankenPHP
docker compose run --rm --profile bench \
  -e BASE_URL=http://app-franken:8000/api \
  -e RUNTIME=franken \
  k6 run /scripts/benchmark.js
```

## Benchmark Endpoints

| Endpoint | Method | Description | Purpose |
|----------|--------|-------------|---------|
| `/api/health` | GET | Returns `{"status":"ok"}` | Pure throughput (no DB) |
| `/api/posts` | GET | Fetch 20 latest posts | ORM + DB read |
| `/api/posts/{id}` | GET | Fetch single post | Single record read |
| `/api/posts` | POST | Create a post | Write + validation |
| `/api/heavy` | GET | Fibonacci(30) + JSON loops | CPU-bound work |

## k6 Test Scenarios

| Scenario | VUs | Duration | Start Time |
|----------|-----|----------|------------|
| `health_check` | 5000 constant | 30s | 0s |
| `read_posts` | 1000→0 ramp | 60s | 35s |
| `single_post` | 3000 constant | 30s | 100s |
| `write_posts` | 2000 constant | 30s | 135s |
| `heavy_compute` | 2000 constant | 30s | 170s |

## Project Structure

```
laravel-bench/
├── app/                          # Laravel application
│   ├── Http/Controllers/
│   │   └── PostController.php    # API controller
│   └── Models/
│       └── Post.php              # Post model
├── docker/
│   ├── fpm/
│   │   ├── Dockerfile            # PHP-FPM image
│   │   ├── nginx.conf            # Nginx configuration
│   │   └── entrypoint.sh         # Startup script
│   ├── swoole/
│   │   ├── Dockerfile            # Swoole Octane image
│   │   └── entrypoint.sh         # Startup script
│   └── franken/
│       ├── Dockerfile            # FrankenPHP Octane image
│       └── entrypoint.sh         # Startup script
├── k6/
│   ├── benchmark.js              # Full benchmark script
│   └── smoke.js                  # Quick smoke test
├── results/                      # Benchmark results (gitignored)
├── docker-compose.yml            # All services
├── run-bench.sh                  # Bash benchmark runner
├── run-bench.ps1                 # PowerShell benchmark runner
└── README.md
```

## Configuration

All Docker services share the same PostgreSQL database. OPcache + JIT are enabled on all runtimes for fair comparison.

### Environment Variables

Set in `docker-compose.yml`:

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_KEY` | from `.env` | Laravel encryption key |
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` | `db` | PostgreSQL host |
| `DB_DATABASE` | `laravel_bench` | Database name |
| `APP_RUNTIME` | varies | Runtime identifier |

### OPcache Settings (all runtimes)

```ini
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.jit=1255
opcache.jit_buffer_size=128M
```

## Cleanup

```bash
# Stop and remove containers + volumes
docker compose down -v

# Remove images
docker compose down -v --rmi all
```

## Tips

- **Run benchmarks on a quiet machine** — close other apps for consistent results
- **10s cooldown** between runtimes is built into the runner scripts
- **Results are saved** to `results/<timestamp>/` with full k6 output per runtime
- **Smoke test first** — the runner scripts do this automatically
- **Adjust k6 scenarios** in `k6/benchmark.js` to match your use case
