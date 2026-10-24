# Benchmark tooling

T3 load tests and the WooCommerce comparison from doc 10 §5.3 and §6. This folder is not shipped (`.distignore`), and nothing in it touches a real site: the stack runs as the docker project `fxc-bench` with its own volumes, and port 8089 is bound to 127.0.0.1 only.

## Files

| File | What it does |
|---|---|
| `generate-dataset.php` | Writes the neutral dataset spec (`--size=S\|M --seed=42`) to `out/`. Same seed, same file, on any PHP version. |
| `load-flexacommerce.php` | Loads a spec through FlexaCommerce services (`wp eval-file`). |
| `load-woocommerce.php` | Loads the same spec through WooCommerce CRUD classes. |
| `parity-export.php` | Exports a store in the normalized parity shape, plus the URLs and cart product IDs the load tests use. |
| `parity-compare.php` | Compares exports with the spec. Any difference exits 1 and stops the benchmark. |
| `mu-plugins/bench-measure.php` | Per-request queries, peak memory and CPU time, joined to the nginx log by request id. |
| `k6/page.js` | One GET scenario, shared iterations, no redirects. |
| `k6/cart.js` | Cart scenarios through each store's own REST API: `add` (a new guest per iteration adds one product) and `view` (one guest cart with three products, read on every iteration). |
| `summarize.php` | p50/p95/p99 per run, median of runs, spread check (≤ 10 %), JSON and markdown. |
| `bench.sh` | Drives the rest. |
| `docker/` | nginx 1.27.3, PHP-FPM (8.2 by default), MySQL 8.0.40, k6 0.55.0. Pinned images. |

## Hosts

| Host | Database | Active plugins |
|---|---|---|
| `core.bench` | `bench_fxc` | none (the mu-plugin empties `active_plugins`) |
| `fxc.bench` | `bench_fxc` | FlexaCommerce, read-only mount of this checkout |
| `wc.bench` | `bench_wc` | WooCommerce, latest at setup time (the version is recorded) |

## Usage

```sh
./bench.sh up            # build and start
./bench.sh setup S       # install WordPress 7.1.3, both stores, load dataset S, parity check, dumps
./bench.sh export M      # export both stores again (parity, URLs, cart IDs) without reloading
./bench.sh run           # blog-post and home, 3 runs, stores rotated per run
./bench.sh run home shop simple variable cart-add cart-view
./bench.sh summary out/runs/<stamp>
./bench.sh down
```

Settings come from the environment: `PHP_VERSION` (8.2), `FPM_WORKERS` (4), `VUS` (1), `N` (500), `WARMUP` (50), `RUNS` (3). `setup` drops and recreates the two bench databases.

Each store block in a run starts with a PHP-FPM restart (empty OPcache), then the warm-up requests, then the measured requests. Logs, FPM worker RSS before and after, MySQL `Handler_read%` and `Innodb_rows_read`, and the rendered page land in `out/runs/<stamp>/<scenario>.<store>.<run>/`. A set is valid only when every store has a spread of 10 % or less and no errors. Copy a valid run into `docs/benchmarks/<date>/` to publish it.

## Notes

- `wp eval-file` runs scripts through `eval()`, so the loaders and the export have no `declare(strict_types=1)`.
- The Debian MySQL client in the PHP image is MariaDB's, which refuses MySQL 8's self-signed certificate; `bench.sh` passes `--skip-ssl`.
- A new WooCommerce store starts in coming-soon mode, which serves a placeholder page to visitors. Setup turns it off, because a store in that mode is not serving its shop.
- Scenarios: `blog-post`, `home`, `shop` (product archive, page 1), `simple`, `variable` and `cart-add`, `cart-view`. `core` has no catalog, so it runs `blog-post` and `home` only.
- GET scenarios do not change data, so runs restart PHP-FPM but do not restore the dumps. Each cart scenario restores the store's database from `out/dumps/` first (the size comes from `out/setup.txt`), because every `cart-add` iteration creates a session.
- In `cart.js`, the requests that only prepare a cart (the nonce read, the setup adds) carry `X-Bench-Run: <label>-prep`, so the summary counts only the request under test. FlexaCommerce sends `X-FXC-Nonce` and an `Origin` header; WooCommerce sends `Nonce` to its Store API. A successful add answers 201, which the summary counts as success.
