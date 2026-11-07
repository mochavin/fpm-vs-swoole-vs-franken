# Laravel 12 Benchmark: FPM vs Swoole vs FrankenPHP

Benchmark the same Laravel 12 app on three PHP runtimes, using [k6](https://k6.io/) for load testing and Docker Compose to run everything.

**Live demo & detailed results:** https://mochavin.github.io/fpm-vs-swoole-vs-franken/

## Latest Results (2026-11-07)

Run on a 4 vCPU Azure VM with the default k6 scenarios ([details below](#test-machine)).
Raw k6 summaries are in [`benchmarks/2026-11-07_azure-d4as-v6/`](benchmarks/2026-11-07_azure-d4as-v6/).

### Overall

| Metric | FPM | Swoole | FrankenPHP |
|---|---:|---:|---:|
| Total requests | 57,793 | 143,961 | **177,275** |
| Throughput (req/s) | 251 | 626 | **771** |
| Failed requests | 0.28% | **0.00%** | **0.00%** |
| Avg latency | 7,537 ms | 2,748 ms | **2,126 ms** |
| p95 latency | 15,711 ms | 4,686 ms | **2,438 ms** |

Latency counts successful responses only (`http_req_duration{expected_response:true}`).

### Per scenario: avg / p95 latency (ms)

| Scenario | VUs | FPM | Swoole | FrankenPHP |
|---|---:|---:|---:|---:|
| Health check (no DB) | 5000 | 4,521 / 5,542 | 2,456 / 2,919 | **2,131 / 2,431** |
| List 20 posts (DB read) | 1000 ramp | 6,445 / 9,856 | 5,036 / 6,717 | **1,948 / 2,792** |
| Single post (DB read) | 3000 | 12,741 / 15,542 | 1,950 / 2,318 | **1,805 / 2,120** |
| Create post (DB write) | 2000 | 11,722 / 18,709 | 1,938 / 2,420 | **1,286 / 1,532** |
| Heavy compute (CPU) | 2000 | 32,940 / 57,142 | **29,800 / 54,491** | 30,065 / 56,951 |

### Takeaways

- **FrankenPHP** won every I/O scenario: about 3× FPM's throughput and 1.2× Swoole's, with zero failed requests.
- **Swoole** came close behind on most scenarios, but was noticeably slower on the list-posts read (5.0 s vs 1.9 s average).
- **PHP-FPM** was the slowest everywhere. With one process per request and `pm.max_children = 100`, thousands of concurrent users queue behind the workers.
- **Heavy compute is a tie.** It saturates the 4 vCPUs on every runtime, so this scenario measures the machine more than the runtime.

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

The first runs on this machine gave misleading results. Each problem below came from container defaults rather than the runtimes, and each is now fixed in this repo:

| Problem | Effect | Fix |
|---|---|---|
| Stock `nginx:alpine` config (1024 `worker_connections`, low fd limit) | Nginx in front of FPM dropped **75%** of requests | `docker/fpm/nginx-main.conf` + `ulimits` on `nginx-fpm` |
| Container open-file limit of 1024 | Swoole caps its connections at this limit, so it could hold only ~1000 of 2000–5000 connections; **10%** of requests hung for 30 s and failed. FrankenPHP (Go) raises the limit itself, so it was unaffected. | `ulimits: nofile: 65535` on every app container |
| Listen backlog: Swoole 512, nginx 511, FrankenPHP 4096 | Connection bursts overflowed the smaller queues | `backlog` 4096 everywhere (`docker/swoole/octane.php`, `listen 80 backlog=4096`) |
| k6 recorded failed connections as ~0 ms | Swoole's latency looked better than FrankenPHP's while it was failing | `k6/benchmark.js` and the report only count successful responses |

The earlier runs are kept for reference in `benchmarks/2026-11-07_azure-d4as-v6/`:
- `fpm_default-nginx.json`: FPM behind the stock nginx config
- `swoole_fd-limit-1024.json`: Swoole with the 1024 open-file limit

The published Swoole run was repeated after the open-file fix. The FPM and FrankenPHP runs are from the run just before, with the backlog fix applied. The open-file fix doesn't change them: FrankenPHP already raises the limit itself, and FPM workers each hold one connection.

Runtimes are benchmarked one at a time with a 10 s cooldown, but all containers stay up during every run.

## Project Structure

```
├── app/
│   ├── Http/Controllers/PostController.php
│   └── Models/Post.php
├── docker/
│   ├── fpm/        Dockerfile, entrypoint.sh, www.conf, nginx.conf, nginx-main.conf
│   ├── swoole/     Dockerfile, entrypoint.sh, octane.php (Swoole server options)
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
| `dial: i/o timeout` on new connections | The container's open-file limit is too low (default 1024). Check with `grep 'open files' /proc/<pid>/limits` and set `ulimits` (see Fairness Notes). |
| Smoke tests always fail | They must use service names (`http://nginx-fpm`), not `localhost`. Fixed in both runner scripts. |
| `php: command not found` at the report step | Fixed: the runner falls back to running the report inside a container. |
| `No file descriptors available` in nginx logs | Use the bundled `nginx-main.conf` (see Fairness Notes). |

## Cleanup

```bash
docker compose down -v            # stop and remove containers + volumes
docker compose down -v --rmi all  # also remove images
```
