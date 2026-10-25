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
| `k6/checkout.js` | Checkout with cash on delivery: a new guest per iteration reads the cart nonce, adds five products, (FlexaCommerce) reads the checkout token, then places the order. |
| `prep-checkout.php` | Sets up both stores for the checkout scenario (cash on delivery, one US flat rate, deferred emails, five products with stock of 1,000,000) and writes the IDs and rate to `out/checkout-<store>.json`. |
| `e2e-prep.php` | Sets up `e2e.bench` for the browser tests in `tests/e2e`: cash on delivery, one US flat rate, a terms page, a page with the filter block, a customer `shopper`, and writes the URLs and products the journeys use to `out/e2e.json`. |
| `mu-plugins/e2e.php` | On `e2e.bench` only: the theme comes from the `X-FXC-E2E-Theme` header (`twentytwentyfive`, `astra`, `twentytwentyone`), the mini cart block opens the body, and the per-IP order-page limit is off. |
| `tax-golden.php` | Prices the tax golden cases of `tests/fixtures/tax-golden.json` with a real WooCommerce cart and order on `tax.bench`, and writes the expected amounts back (doc 02 §7.12.9). |
| `summarize.php` | p50/p95/p99 per run, median of runs, spread check (≤ 10 %), JSON and markdown. |
| `bench.sh` | Drives the rest. |
| `docker/` | nginx 1.27.3, PHP-FPM (8.2 by default), MySQL 8.0.40, k6 0.55.0. Pinned images. |

## Hosts

| Host | Database | Active plugins |
|---|---|---|
| `core.bench` | `bench_fxc` | none (the mu-plugin empties `active_plugins`) |
| `fxc.bench` | `bench_fxc` | FlexaCommerce, read-only mount of this checkout |
| `wc.bench` | `bench_wc` | WooCommerce, latest at setup time (the version is recorded) |
| `tax.bench` | `bench_tax` | WooCommerce, used only by `tax-golden` (recreated on every run) |
| `e2e.bench` | `bench_e2e` | FlexaCommerce with dataset S, used only by the browser tests; Astra and Twenty Twenty-One are installed for it |

## Usage

```sh
./bench.sh up            # build and start
./bench.sh setup S       # install WordPress 7.1.3, both stores, load dataset S, parity check, dumps
./bench.sh export M      # export both stores again (parity, URLs, cart IDs) without reloading
./bench.sh run           # blog-post and home, 3 runs, stores rotated per run
./bench.sh run home shop simple variable cart-add cart-view
./bench.sh prep-checkout # once after setup: checkout dumps for both stores
./bench.sh run checkout
./bench.sh summary out/runs/<stamp>
./bench.sh tax-golden    # reprice the tax golden fixture with WooCommerce
./bench.sh e2e-setup     # once: the browser-test store, its dump in out/dumps/bench_e2e.sql.gz
./bench.sh e2e           # restore it and run tests/e2e (pnpm install there first)
./bench.sh e2e --project=astra specs/checkout.spec.ts
./bench.sh down
```

Settings come from the environment: `PHP_VERSION` (8.2), `FPM_WORKERS` (4), `VUS` (1), `N` (500), `WARMUP` (50), `RUNS` (3). `setup` drops and recreates the two bench databases.

Each store block in a run starts with a PHP-FPM restart (empty OPcache), then the warm-up requests, then the measured requests. Logs, FPM worker RSS before and after, MySQL `Handler_read%` and `Innodb_rows_read`, and the rendered page land in `out/runs/<stamp>/<scenario>.<store>.<run>/`. A set is valid only when every store has a spread of 10 % or less and no errors. Copy a valid run into `docs/benchmarks/<date>/` to publish it.

## Notes

- The browser tests run Chromium on the host with `--host-resolver-rules=MAP e2e.bench 127.0.0.1:8089`, one worker, against the same store for all three theme projects; `bench.sh e2e` restores the dump first, so every run starts from the same stock and orders. OPcache on the stack does not check file timestamps, so `bench.sh e2e` reloads PHP-FPM before the run to test the code on disk. Results land in `tests/e2e/results/` (ignored); theme axe violations are attachments named `axe-theme-<page>` in `results/report.json`.
- `wp eval-file` runs scripts through `eval()`, so the loaders and the export have no `declare(strict_types=1)`.
- The Debian MySQL client in the PHP image is MariaDB's, which refuses MySQL 8's self-signed certificate; `bench.sh` passes `--skip-ssl`.
- A new WooCommerce store starts in coming-soon mode, which serves a placeholder page to visitors. Setup turns it off, because a store in that mode is not serving its shop.
- Scenarios: `blog-post`, `home`, `shop` (product archive, page 1), `simple`, `variable` and `cart-add`, `cart-view`, `checkout`. `core` has no catalog, so it runs `blog-post` and `home` only.
- GET scenarios do not change data, so runs restart PHP-FPM but do not restore the dumps. Each cart scenario restores the store's database from `out/dumps/` first (the size comes from `out/setup.txt`), because every `cart-add` iteration creates a session.
- `prep-checkout` restores each store from its setup dump, turns on HPOS for WooCommerce (`wp wc hpos enable`, so orders land in `wp_wc_orders`), runs `prep-checkout.php` and writes `out/dumps/bench_<store>-<size>-checkout.sql.gz`. Every `checkout` run restores that dump first.
- A checkout run records the order count and the stock of the five products before and after (`checkout-before.txt`, `checkout-after.txt`). The summary flags a stock error when the stock drop differs from the number of new orders, or when stock goes below 0; a run with a stock error is not valid. Both stores defer order emails to a later request (WooCommerce through `woocommerce_defer_transactional_emails` to Action Scheduler, FlexaCommerce through its `defer_emails` setting). Every k6 request comes from one IP, so the mu-plugin turns off FlexaCommerce's per-IP checkout limit (`flexacommerce.rate_limit.checkout_ip`, 30 per 10 minutes); WooCommerce's Store API rate limiting is off by default. With the limit on, every request after the 30th answers 429.
- Req/s comes from the nginx `$msec` timestamps of the counted requests: (count − 1) divided by the time from the first to the last. For checkout, the latency, queries, memory and CPU describe the order request alone. Rows/req and req/s cover the whole flow: MySQL counters include the prep requests, and the prep requests run between the counted ones, so req/s is the rate of whole checkouts.
- In `cart.js` and `checkout.js`, the requests that only prepare a cart (the nonce read, the setup adds) carry `X-Bench-Run: <label>-prep`, so the summary counts only the request under test. FlexaCommerce sends `X-FXC-Nonce` and an `Origin` header; WooCommerce sends `Nonce` to its Store API. A successful add answers 201, which the summary counts as success.
