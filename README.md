# Laravel 12 Benchmark: FPM vs Swoole vs FrankenPHP

Benchmark the same Laravel 12 app on three PHP runtimes, using [k6](https://k6.io/) for load testing and Docker Compose to run everything.

**Live demo & detailed results:** https://mochavin.github.io/fpm-vs-swoole-vs-franken/

## Latest Results (2026-11-07)

Run on a 4 vCPU Azure VM with the default k6 scenarios ([details below](#test-machine)).
Raw k6 summaries are in [`benchmarks/2026-11-07_azure-d4as-v6/`](benchmarks/2026-11-07_azure-d4as-v6/).

### Overall

| Metric | FPM | Swoole | FrankenPHP |
|---|---:|---:|---:|
| Total requests | 58,660 | 112,513 | **179,319** |
| Throughput (req/s) | 255 | 521 | **780** |
| Failed requests | 0.31% | 9.89% | **0.00%** |
| Avg latency | 7,517 ms | **484 ms**\* | 2,078 ms |
| p95 latency | 16,015 ms | **906 ms**\* | 3,153 ms |

### Per scenario: avg / p95 latency (ms)

| Scenario | VUs | FPM | Swoole\* | FrankenPHP |
|---|---:|---:|---:|---:|
| Health check (no DB) | 5000 | 4,467 / 5,490 | **471 / 986** | 2,701 / 3,409 |
| List 20 posts (DB read) | 1000 ramp | 5,815 / 7,841 | **76 / 376** | 955 / 1,677 |
| Single post (DB read) | 3000 | 12,870 / 15,692 | **381 / 627** | 1,806 / 2,137 |
| Create post (DB write) | 2000 | 11,992 / 19,012 | **6 / 12** | 1,281 / 1,500 |
| Heavy compute (CPU) | 2000 | 33,072 / 57,106 | **4,429 / 20,125** | 30,012 / 56,988 |

\* **Read the Swoole latency numbers with care.** About 10% of Swoole requests failed with `dial: i/o timeout`: the listen queue overflowed when thousands of VUs connected at once. k6 records those failed requests as ~0 ms, which pulls Swoole's averages down. For example, Swoole's median heavy-compute latency is 0 ms.

### Takeaways

- **FrankenPHP** handled the most traffic (3× FPM, 1.5× Swoole) and was the only runtime with zero failed requests.
- **Swoole** had the lowest latency on requests that got through, but it dropped about 1 in 10 connections during the bursts of new connections.
- **PHP-FPM** was the slowest on every scenario. With one process per request and `pm.max_children = 100`, thousands of concurrent users queue behind the workers.
- **Heavy compute saturates the CPU on every runtime.** All three share 4 vCPUs with k6 and Postgres, so this scenario measures the machine more than the runtime.

### Test machine

| | |
|---|---|
| Host | Azure `Standard_D4as_v6` |
| CPU | AMD EPYC 9V74, 4 vCPU (2 cores × 2 threads) |
| RAM | 15.6 GiB, no swap |
| OS | Ubuntu 24.04.5 LTS, kernel 7.0.0-1017-azure |
| Docker | Engine 29.1.3, Compose 2.40.3 |
| PHP | 8.3.36 (OPcache + JIT on all runtimes) |
| Database | PostgreSQL 16 (alpine) |
| Load generator | k6 v2.3.0, **on the same machine** as the servers |

Because k6, Postgres and the PHP runtimes all compete for the same 4 vCPUs, absolute numbers are lower than on a dedicated setup. The comparison is still fair because every runtime ran under the same conditions, one at a time.

## Runtimes

| Runtime | Host port | Stack | Workers |
|---|---|---|---|
| **FPM** | 8001 | Nginx → PHP-FPM 8.3 | `pm = static`, 100 children |
| **Swoole** | 8002 | Laravel Octane + Swoole | `--workers=auto --task-workers=auto` |
| **FrankenPHP** | 8003 | Laravel Octane + FrankenPHP | `--workers=auto` |

All runtimes share one PostgreSQL database and use the same OPcache/JIT settings.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) with Docker Compose v2
- ~4 GB free RAM for all containers (16 GB recommended for the full benchmark)
- PHP on the host is **optional**. The runner generates `APP_KEY` with `openssl` and builds the report inside a container when PHP isn't installed.

On a fresh Ubuntu 24.04 machine:

```bash
sudo apt-get update
sudo apt-get install -y docker.io docker-compose-v2 docker-buildx
sudo usermod -aG docker $USER   # log out and back in, or prefix commands with sudo
```

## Quick Start

### 1. Clone & set the app key

```bash
git clone https://github.com/mochavin/fpm-vs-swoole-vs-franken
cd fpm-vs-swoole-vs-franken

echo "APP_KEY=base64:$(openssl rand -base64 32)" > .env
```

### 2. Build & start all runtimes

```bash
docker compose build --parallel
docker compose up -d
```

Each app container runs migrations and seeds the database on startup.

### 3. Check that the services respond

```bash
curl http://localhost:8001/api/health   # FPM
curl http://localhost:8002/api/health   # Swoole
curl http://localhost:8003/api/health   # FrankenPHP
```

Each should return:

```json
{"status":"ok","runtime":"fpm|swoole|frankenphp","timestamp":"..."}
```

### 4. Run the benchmark

**Automated (recommended):** builds, starts, smoke-tests, then benchmarks each runtime in turn and generates `benchmark-report.html`. It takes about 12 minutes.

```bash
bash run-bench.sh          # Linux/macOS
.\run-bench.ps1            # Windows (PowerShell)
```

**Manual (one runtime):**

```bash
docker compose --profile bench run --rm \
  -e BASE_URL=http://nginx-fpm -e RUNTIME=fpm \
  k6 run /scripts/benchmark.js

# Swoole:     -e BASE_URL=http://app-swoole:8000  -e RUNTIME=swoole
# FrankenPHP: -e BASE_URL=http://app-franken:8000 -e RUNTIME=franken
```

k6 runs inside the Compose network, so it uses service names, not `localhost`.

### 5. Build the report

```bash
php generate-report.php
# or, without PHP on the host:
docker compose run --rm --no-deps -v "$PWD:/work" -w /work --entrypoint php app-swoole generate-report.php
```

The report uses the newest `results/<runtime>_*.json` summary for each runtime.

## Benchmark Endpoints

| Endpoint | Method | Description | Purpose |
|---|---|---|---|
| `/api/health` | GET | Returns `{"status":"ok"}` | Pure throughput (no DB) |
| `/api/posts` | GET | Fetch 20 latest posts | ORM + DB read |
| `/api/posts/{id}` | GET | Fetch single post | Single record read |
| `/api/posts` | POST | Create a post | Write + validation |
| `/api/heavy` | GET | Fibonacci(30) + JSON loops | CPU-bound work |

## k6 Test Scenarios

| Scenario | VUs | Duration | Start time |
|---|---|---|---|
| `health_check` | 5000 constant | 30s | 0s |
| `read_posts` | 1000 → 500 → 1000 → 0 | 60s | 35s |
| `single_post` | 3000 constant | 30s | 100s |
| `write_posts` | 2000 constant | 30s | 135s |
| `heavy_compute` | 2000 constant | 30s | 170s |

These loads are far beyond what a 4 vCPU machine can serve, so every runtime breaks at least one latency threshold. To compare runtimes below saturation, lower the VUs in `k6/benchmark.js`.

## Results Layout

```
results/                          # gitignored
├── fpm_<iso-timestamp>.json      # k6 summary per runtime (used by the report)
├── swoole_<iso-timestamp>.json
├── franken_<iso-timestamp>.json
└── <YYYYMMDD_HHMMSS>/            # one folder per run-bench.sh run
    └── <runtime>_output.txt      # full k6 console output
benchmarks/<date>_<machine>/      # committed summaries of published runs
```

To also save k6's raw per-request stream, run `K6_RAW=1 bash run-bench.sh`. The file is large, and writing it uses CPU the servers need.

## Fairness Notes

- **The FPM Nginx is tuned for high concurrency** (`docker/fpm/nginx-main.conf`: `worker_connections 16384`, `worker_rlimit_nofile 65535`, fd ulimit 65535). With the stock `nginx:alpine` config, Nginx ran out of file descriptors and dropped **75% of requests**. That made FPM look far worse than it is. The run is kept for reference in `benchmarks/2026-11-07_azure-d4as-v6/fpm_default-nginx.json`.
- **Swoole's connection drops** come from its listen backlog. Raising Octane's Swoole `backlog` option (publish `config/octane.php` with `php artisan vendor:publish --tag=octane-config` and set `swoole.options.backlog`) together with the kernel's `net.core.somaxconn` (4096 on the test machine) should reduce them.
- **Runtimes run one at a time**, with a 10s cooldown between them, but all containers stay up during every run.

## Project Structure

```
├── app/
│   ├── Http/Controllers/PostController.php
│   └── Models/Post.php
├── docker/
│   ├── fpm/        Dockerfile, entrypoint.sh, www.conf, nginx.conf, nginx-main.conf
│   ├── swoole/     Dockerfile, entrypoint.sh
│   └── franken/    Dockerfile, entrypoint.sh
├── k6/
│   ├── benchmark.js     # full benchmark
│   └── smoke.js         # quick check that a runtime works
├── benchmarks/          # committed result summaries
├── docker-compose.yml
├── run-bench.sh / run-bench.ps1
└── generate-report.php  # builds benchmark-report.html
```

## Configuration

Set in `docker-compose.yml`:

| Variable | Default | Description |
|---|---|---|
| `APP_KEY` | from `.env` | Laravel encryption key |
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` | `db` | PostgreSQL host |
| `DB_DATABASE` | `laravel_bench` | Database name |
| `APP_RUNTIME` | varies | Runtime name returned by `/api/health` |
| `K6_OUT` | empty | Optional k6 raw output, e.g. `json=/results/raw.json` |

OPcache settings (all runtimes):

```ini
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.jit=1255
opcache.jit_buffer_size=128M
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `could not open '/results/...json': permission denied` | `results/` is owned by root (e.g. created via `sudo`). The k6 container runs as non-root. Run `chmod -R a+rwX results`; `run-bench.sh` now does this automatically. |
| Smoke tests always fail | They must use service names (`http://nginx-fpm`), not `localhost`. Fixed in both runner scripts. |
| `php: command not found` at the report step | Fixed: the runner falls back to running the report inside a container. |
| `No file descriptors available` in nginx logs | Use the bundled `nginx-main.conf` (see Fairness Notes). |

## Cleanup

```bash
docker compose down -v            # stop and remove containers + volumes
docker compose down -v --rmi all  # also remove images
```
