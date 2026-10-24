# 10. Performance Architecture

Status: **Approved 2026-10-24.** Phase 2.5 (§8) is implemented: O1, O2, O3, O4 and O6 are done, the T1 harness is part of the CI integration job, and the first T3 baseline is in `docs/benchmarks/2026-10-24.md` (§7.1). Phase 3 (2026-10-25): O5 and O8 done, T1 budgets P2, P3, P4q, P5q and P11 binding, first commerce T3 run in `docs/benchmarks/2026-10-25.md` (§7.2). §1 keeps the audit as it was measured before those changes. It refines doc 06 §3 (targets) and §4 (benchmark protocol); where the two differ, this document wins and doc 06 points here.

Rule for every number below: it is either **measured** (with the command, environment and date that produced it) or a **budget** (a limit a named test enforces). A budget that no test enforces yet is marked *provisional*, and it becomes binding when its first measurement is recorded. No performance claim reaches the readme or marketing unless a published benchmark report backs it (doc 01 §2).

## 1. Audit of the current code (phase 2, commit `de966f5`)

### 1.1 Method

- **Probe:** a PHP script that defines a front-end request (`REQUEST_URI`, `HTTP_HOST`), loads `wp-blog-header.php` in a fresh process and prints, at shutdown: wall time, `memory_get_peak_usage()`, `$wpdb->num_queries`, classes declared after start, files included, total hook callbacks, HTML size, status, autoloaded bytes. Plugins and theme are forced per run through pre-seeded `$GLOBALS['wp_filter']` entries (`option_active_plugins`, `pre_option_template`/`stylesheet` = Twenty Twenty-Five, `wp_redirect` returns false), so on/off runs differ only by the plugin. With `SAVEQUERIES` set, the probe also dumps every query with its caller.
- **Runs:** 9 per scenario, the first 2 dropped as warm-up, median reported. OPcache on (`opcache.enable_cli=1`, file cache, `validate_timestamps=0`).
- **Environment:** Apple M2, 16 GB, macOS (Darwin 25.6), PHP 8.5.2 CLI (NTS), MySQL 8.0.35 (`innodb_buffer_pool_size` 32 MB), WordPress 7.1.3, no persistent object cache, no page cache. FlexaCommerce site: 1 published product, 0 mu-plugins.
- **Limits:** CLI, not PHP-FPM behind a web server; no concurrency; tiny dataset. These numbers are an audit, not a benchmark. They locate costs and give the first baselines for the counters in §5.2.

### 1.2 FlexaCommerce today

| Scenario (Twenty Twenty-Five) | Plugin | Peak memory | Queries | Classes loaded | Files | Hook callbacks |
|---|---|---|---|---|---|---|
| Blog post `/?p=1` | off | 6,853 KB | 29 | 254 | 496 | 893 |
| Blog post `/?p=1` | on | 6,946 KB | 30 | 270 | 520 | 917 |
| | **delta** | **+93 KB** | **+1** | **+16** | **+24** | **+24** |
| Home `/` | off / on | 6,673 / 6,740 KB | 25 / 26 | 254 / 270 | 494 / 518 | 891 / 915 |
| Product archive `/?post_type=fxc_product` | on | 6,905 KB | 24 | 270 | 517 | 914 |
| Single product `/?post_type=fxc_product&p=5` | on | 7,007 KB | 32 (1 ours) | 270 | 520 | 915 |

Archive and product pages have no "off" counterpart (the URL does not resolve without the plugin), so there is no delta for them. They render through the theme's default templates: the storefront does not exist yet, so the variant prime and blocks are not exercised. Autoloaded options: 54 KB site-wide, of which `flexacommerce_settings` is 575 bytes (the only autoloaded option the plugin owns, P10 holds).

Wall time is left out of the table on purpose. The same blog post request, with the plugin off, measured 74.9 ms in one session and 70.4 ms in another; with it on, 79.7 ms and 104.3 ms. A ±30 % swing in single-process CLI timing is larger than any effect this plugin should have, so CLI wall time is never used as a gate (§5.1).

### 1.3 Findings

| Id | Finding | Evidence | Budget affected |
|---|---|---|---|
| F1 | Every front-end request runs `SELECT option_value FROM wp_options WHERE option_name = 'flexacommerce_flush_rewrite'`. `PostTypes::maybe_flush()` (`init`, priority 99) calls `get_option()` on an option that normally does not exist and is not autoloaded. Without a persistent object cache, a missing option costs one query per request. | Query dump, caller `PostTypes->maybe_flush, get_option` | P1 (0 extra queries): **fails** |
| F2 | `Plugin::announce()` (`plugins_loaded:20`) always builds `Support\Services`, `Clock` and `ExtensionsFacade` to pass the facade to `flexacommerce.loaded`, even when no extension listens. Doc 02 §4.1 says no service objects are created on a request that never touches commerce code. | Loaded classes on a blog post: `Plugin`, `PostTypes`, `Cron`, `Router`, `Requirements`, `Services`, `Clock`, `ExtensionsFacade`, `Settings`, `Currency`, `Capabilities`, traits `HasInstance`, `Extensions` | Boot contract (doc 02 §4.1) |
| F3 | The P1 test (`tests/Integration/FrontendQueryBudgetTest`) passes while F1 is real. It runs in the test process: `init` already ran during bootstrap, and the in-memory cache already holds the `notoptions` entry, so the query never happens inside the measured window. An in-process test cannot see boot cost. | Test source; probe shows the query in a fresh process | All boot budgets |
| F4 | `Settings` and `Currency` load on every request because `PostTypes::register_types()` reads the permalink bases from settings at `init`. This is needed (the post type must exist on every request) and cheap (the option is autoloaded, 0 queries). Listed so it is a known, accepted cost. | Class list | none |
| F5 | Already good, keep as rules: admin assets and the Vite manifest read happen only on FlexaCommerce screens; REST endpoints are only built on `rest_api_init`; `Cron::self_heal()` only runs in admin or cron; `Requirements` only adds an `admin_notices` hook; non-settings options are stored with `autoload = false`. | `src/Admin/Enqueue.php`, `src/Api/Router.php`, `src/Maintenance/Cron.php` | P1, P10 |
| F6 | `Api\Router::register_routes()` builds all 5 admin endpoint objects on every REST request, including requests for other plugins' routes. Small cost, but it grows with every endpoint added in phases 3 and 4. | `src/Api/Router.php` | REST budget (§4, R-REST) |
| F7 | There are no benchmark tools yet: no dataset seeder, no WooCommerce loader, no load-test scripts, no `docs/benchmarks/`. The doc 06 §4 protocol cannot run today. | Repository | All T3 budgets |
| F8 | Found while building the T1 harness. In a dev checkout the bootstrap loaded `vendor/autoload.php`, which pulls Composer's dev `autoload_files` (PHPUnit assertion functions, polyfills, the phpstan bootstrap) into every request. The release zip has no `vendor/`, so a dev checkout measured about 70 KB more than what ships. The plugin has no runtime Composer packages, so it now always uses its own autoloader. | T1 P1 memory on PHP 8.2 fell from about 154 KB to 82 KB with no other change | P1 memory, every T1 memory baseline |

### 1.4 Indicative WooCommerce reference (not a benchmark)

Same probe, on the local WooCommerce site (WooCommerce 11.0.1, 11 products, 28 variations; only WooCommerce active in the "on" run, the same six license-stub mu-plugins load in both runs; theme Twenty Twenty-Five; 260 KB autoloaded options). This site has different content and options from the FlexaCommerce site, so the two sites must not be compared with each other. Only the on/off delta on the same site means something.

| Scenario | WooCommerce | Peak memory | Queries | Classes | Files | Hook callbacks |
|---|---|---|---|---|---|---|
| Blog post `/?p=330` | off | 7,248 KB | 32 | 254 | 502 | 902 |
| Blog post `/?p=330` | on | 17,057 KB | 63 | 1,146 | 1,430 | 2,522 |
| | **delta** | **+9,809 KB** | **+31** | **+892** | **+928** | **+1,620** |
| Shop page | on | 17,832 KB | 71 | 1,161 | 1,452 | 2,511 |
| Simple product | on | 17,722 KB | 91 | 1,163 | 1,461 | 2,515 |
| Variable product | on | 17,798 KB | 97 | 1,163 | 1,465 | 2,515 |
| Cart page (empty cart) | on | 17,797 KB | 75 | 1,162 | 1,449 | 2,523 |

The home page and the checkout with an empty cart redirect on that site, so they were excluded. What this table supports: WooCommerce's fixed per-request cost on a non-commerce page is about 10 MB and 31 queries on this setup. What it does not support: any claim that FlexaCommerce is faster or lighter than WooCommerce. FlexaCommerce has no storefront, cart or checkout yet, so it does far less work. A comparison only counts when both plugins serve the same pages from the same data (§6).

## 2. Risks and trade-offs

Listed before the design, because several of them decide the design.

| Id | Risk | Consequence if ignored | Response |
|---|---|---|---|
| R1 | **Benchmark bias.** We write the dataset loaders, pick the WooCommerce settings and write the scripts. | Results nobody outside can trust; claims that do not survive a third-party rerun. | Neutral dataset spec with a parity check (§6.2), WooCommerce measured in two configurations (default and tuned, §6.3), all scripts and raw data published, a third party invited to rerun before 1.0. |
| R2 | **Measurement noise.** Observed ±30 % on single CLI runs (§1.2). | Gates that fail at random, or real regressions hidden in noise. | Gate only deterministic counters on every push (§5.1 T1). Timing is gated only from load tests with a measured spread; a run whose spread exceeds 10 % is discarded and repeated. |
| R3 | **In-process tests miss boot cost** (F3). | Green CI while budgets fail in production. | T1 counters run each request in a fresh PHP process (§5.2). |
| R4 | **Object cache changes the cost model.** With a persistent cache, missing options and primes are free after the first hit; without it, every lookup is a query. Most small stores run without one. | Budgets met only on the developer's Redis setup. | Primary budgets assume no persistent object cache. Persistent-cache runs are reported separately and never relax a budget. |
| R5 | **Lazy loading and extensions.** Extensions expect a ready facade at `flexacommerce.loaded`; lazy containers make bugs show up later and stack traces longer. | Extension breakage, harder debugging. | Keep the hook and its timing; build the facade only when the hook has listeners (O2). Container factories stay one line each so laziness adds no logic. |
| R6 | **Page cache hides PHP cost and cookies bypass it.** Cart cookies or `no-cache` headers on catalog pages make every visitor uncached. | Real-world load far above the benchmark. | Benchmarks measure PHP with page cache off. P11 (no cookie, no `no-cache` on catalog pages) is a T1 assertion. |
| R7 | **Speed against correctness.** Caching stock or prices can sell stock that does not exist or charge stale prices. | Oversells, wrong totals: worse than slowness. | Stock checks and checkout totals are never served from cache (doc 02 §7.4, §7.5). Caching is allowed only for display data, with explicit invalidation (§3.6). |
| R8 | **Small datasets hide O(N) code.** One product cannot reveal an N+1. | Pages that slow down as catalogs grow. | Every list budget is asserted at two sizes: the query count must be identical for N and 4N (§4). |
| R9 | **Unequal features.** At M1 FlexaCommerce lacks many WooCommerce features (coupons, reviews, analytics, marketing hooks). | "Faster because it does less" passed off as better engineering. | Each published scenario carries a feature-parity note; features WooCommerce runs that we lack are listed next to the numbers. |
| R10 | **PHP peak memory is not process memory.** `memory_get_peak_usage()` excludes the PHP binary, OPcache shared memory and allocator overhead. | Capacity planning off by a large factor. | Report PHP peak (gate), `memory_get_peak_usage(true)` and PHP-FPM worker RSS (capacity) separately (§5.3). |
| R11 | **Millisecond targets depend on hardware.** | Unreproducible targets. | Absolute ms budgets apply only to the documented reference machine (§6.1, Q18). Elsewhere, compare against the core-only baseline measured on the same machine. |
| R12 | **More custom tables, less ecosystem caching.** SEO, feed and cache plugins read postmeta; they cannot see variant rows. | Third-party plugins add their own queries to fill the gap. | Out of scope for budgets (we measure FlexaCommerce alone), noted in compatibility docs. |

Trade-offs this design accepts, each with what it costs:

| Choice | Gain | Cost |
|---|---|---|
| One autoloaded option, everything else `autoload = false` | No query for settings on any request | Every non-autoloaded option read is a query, so hot paths may only read the autoloaded one (O1) |
| Inline up to 250 variants on the product page (doc 02 §7.1) | Variant switching without requests | Larger HTML: must be measured and budgeted (P3b) |
| Conditional stock `UPDATE` per order line inside the checkout transaction | No oversell under concurrency | Checkout queries grow by 1 per line; budget is "constant + 1 per line", not constant |
| Lazy containers and per-context module loading | Near-zero cost when commerce code is not used | One extra indirection per service call; contexts must be classified correctly or a hook is missing |
| No queue library, emails after commit | Fewer moving parts and dependencies | Email sending stays on the checkout request unless deferral is enabled (P6 excludes it only when deferred) |

## 3. Performance-first architecture

### 3.1 Principles

1. **Pay only for what the request uses.** Loading a module is a decision taken per request context, not at file load.
2. **Registration is cheap, construction is lazy.** Boot only calls `add_action`/`add_filter`; objects are built on first use (doc 02 §4.1, made enforceable in §5.2).
3. **One query per entity type per page**, regardless of how many entities the page shows.
4. **No hidden queries**: no non-autoloaded option, transient or meta read on paths that run every request.
5. **Correctness first for money and stock** (R7).
6. **Every rule has a counter**: if it cannot be measured by §5, it is a guideline, not a budget.

### 3.2 Request contexts and module loading

The context is decided once in `Plugin::boot()` from cheap signals: `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`, `defined( 'WP_CLI' )`, and for REST a prefix check of the request path against `rest_get_url_prefix()` (core defines `REST_REQUEST` only at `parse_request`, too late for boot). A wrong guess must be safe: a module that needs a later signal registers one cheap hook that loads it then.

| Module | Front: catalog page | Front: cart / checkout page | Store REST | Admin REST | wp-admin | Cron | WP-CLI |
|---|---|---|---|---|---|---|---|
| Catalog (post types, taxonomies) | hooks | hooks | hooks | hooks | hooks | hooks | hooks |
| Storefront renderers and blocks | on render | on render | | | | | |
| Pricing | on render | yes | yes | on use | on use | | on use |
| Stock | | on checkout | on cart write | on use | on use | on use | on use |
| Session and cart | **no** (unless cookie present) | yes | yes | | | purge | |
| Checkout, orders | | yes | yes | yes | on use | expiry | on use |
| Payment gateways | | checkout only | checkout, webhooks | settings | settings | | |
| Shipping methods | | when the cart needs shipping | rates route | settings | settings | | |
| Notifications | | after commit | after commit | after commit | after commit | after commit | |
| Admin UI | | | | | our screens only | | |
| Migration | | | | admin only, WC active | admin only, WC active | runner | yes |

"hooks" means registrars only; "on render" and "on use" mean the classes are built when a block renders or a service is first called. Gateways and shipping methods are registered as **descriptors** (id, label, class name, supported features). A gateway class is only autoloaded when checkout lists or uses it, so ten installed gateways cost nothing on a catalog page. The `flexacommerce.payment.gateways` and shipping filters therefore return descriptors or factories, never constructed objects (to be reflected in doc 04 when those contracts are written).

### 3.3 Boot contract

- File load: constants, autoloader, two lifecycle hooks, one `plugins_loaded` callback. No I/O.
- `boot()`: per-context registrars only. A registrar may read the autoloaded settings option and nothing else.
- `flexacommerce.loaded`: fired as today, but the facade is built only when `has_action( 'flexacommerce.loaded' )` is true (O2).
- Hook budget: the number of callbacks FlexaCommerce adds is counted per context (§4). New hooks need a budget change in the same pull request.
- Class budget: the list of classes allowed to load on a non-commerce front-end request is an allowlist kept in the test (today: the 11 classes and 2 traits listed in F2, minus the three removed by O2).

### 3.4 Options and autoload

- `flexacommerce_settings` stays the only autoloaded option, ≤ 10 KB (P10).
- Hot paths read no other option. Flags that must be checked every request (F1) move into the autoloaded settings, or are replaced by acting in the request that changes the state. For the rewrite flush, O1 removed the flag: `PostTypes::maybe_flush()` checks that the shop rule is present in `rewrite_rules`, which is autoloaded, so the check costs no query. A missing rule (after activation or a base change) is restored by one flush on the next front-end request. If the flush cannot restore it because another plugin claims the same URL, the base is written to `flexacommerce_rewrite_guard` (autoloaded) so the flush is not repeated on every request; that option exists only in this conflict case.
- Transients are not used on front-end hot paths: without a persistent cache they are option rows, so a missing or expired transient is a query.

### 3.5 Database access

- All SQL stays in repositories (existing rule). Repositories expose batch loaders (`prime( list<int> )`, `find_many( list<int> )`) and the renderer calls them once per page before the loop.
- No commerce data in postmeta beyond what core needs. Variant rows, prices and stock live in custom tables with the indexes doc 03 defines; each new list query ships with an `EXPLAIN` check in its integration test (index used, no filesort on the default sort).
- No `SELECT *` on wide tables in list paths; columns are named.
- Writes: multi-row `INSERT` for order items and history rows; one conditional `UPDATE` per stock line (R7).
- Checkout keeps the transaction short: no remote calls (gateways, emails, webhooks) inside it (doc 02 §4.3, §4.4).

### 3.6 Caching

| Layer | What | Invalidation | Without a persistent cache |
|---|---|---|---|
| Request memo | Repository results within a request | end of request | works |
| Object cache group `flexacommerce_variants` | variant rows by product id | delete on product save (single owner: `ProductService`) | request-local only, still saves repeated reads |
| Object cache, `last_changed` per group | list results (e.g. attribute terms with values) | bump `last_changed` on any write to the group | request-local only |
| Not cached | stock availability at add to cart and checkout, totals at checkout, order state | | |

The rule is "never slower without a persistent cache, faster with one": caching must not add queries when the cache is empty.

### 3.7 Session, cart and checkout

- Catalog pages never read or create a session (doc 02 §7.3.1). Assertion: with no `fxc_session` cookie, the session table is not queried and no `Set-Cookie` header is sent (P11, T1).
- With a cookie: one primary-key read of the session row by hashed token per request that needs the cart.
- Cart read: session row + one prime of products + one prime of variants. Constant in the number of lines.
- Mini-cart count: doc 02 fetched it via REST so catalog pages stay cacheable. That costs one extra HTTP request on each page view for visitors with a cart. Phase 3 uses the alternative instead (Q19): a non-sensitive `fxc_cart_count` cookie, written with the session cookie and read by the block script. The server markup is the same for every visitor, so catalog pages stay cacheable and add no request. The script asks `store/cart` only for a logged-in shopper whose browser has no count yet (a cart merged at login). A count can be stale across tabs until the next cart action; the cart page and every cart response correct it.
- Checkout: queries = fixed part + 1 conditional stock `UPDATE` per line; emails after commit; optional deferral (doc 02 §6.6).

### 3.8 Assets

- Zero CSS and JS on pages without a FlexaCommerce block, shortcode or template (P1).
- Blocks use `viewScriptModule` (Interactivity API) and per-block styles, enqueued only when the block renders.
- Budgets P8 (≤ 15 KB JS, ≤ 10 KB CSS gzip on a product page) measured from the built files and from the rendered page's actual asset list.

### 3.9 Admin and REST

- Admin assets and manifest stay limited to our screens (F5).
- REST: endpoint objects are built only when the request targets `flexacommerce/v1`, or when the full route index is requested (`/wp-json/`), which needs all routes (F6, O6).
- Admin list screens: server-side pagination and the `EXPLAIN` rule; P7 stays as in doc 06.

## 4. Budgets

Columns: **Budget** is the limit. **Tier** says which test enforces it (§5.1). **Now** is today's measured value, or "n/a" when the feature does not exist. Queries and memory are counted against a **core baseline**: the same theme rendering an equivalent core page (same template structure, plugin inactive) on the same machine, measured in the same run. Absolute times apply only to the reference machine (§6.1).

### 4.1 Per-request cost

| Id | Scenario | Metric | Budget | Tier | Now |
|---|---|---|---|---|---|
| P1 | Blog post, no FlexaCommerce content | extra queries | 0 | T1 | +0 (was +1, F1, fixed by O1) |
| P1 | | extra peak memory | ≤ 256 KB | T1 | +70 KB on PHP 8.5 with a warm OPcache file cache (CLI, macOS, phase 3; §5.2); +63 KB under PHP-FPM 8.2 (T3) |
| P1 | | FlexaCommerce classes loaded | allowlist only (≤ 10 after O2) | T1 | 9 (8 classes, 1 trait; was 13) |
| P1 | | callbacks added | ≤ 30 | T1 | +24 |
| P1 | | FlexaCommerce asset bytes | 0 | T1 | 0 |
| P1t | | added server time, p50 at 1 VU | ≤ 2 ms | T3 | not resolved yet: run p50s moved up to 9 ms on the first T3 run (§7.1) |
| P1h | Home page with a product collection (12 products) | extra queries | ≤ 3, same for 12 and 48 products | T1 | n/a |
| P2 | Archive, 12 and 48 products per page | extra queries vs core archive of 12/48 posts | ≤ 2, identical for both sizes | T1 | 2 at 12 and 48 (1 own: the variant prime) |
| P2 | | extra peak memory | ≤ 1,411,292 B (measured 1,282,992 B at 48 per page + 10 %) | T1 | 1,154,768 B at 12, 1,282,992 B at 48 (macOS, PHP 8.5) |
| P3a | Simple product | extra queries vs core single post | ≤ 3 | T1 | −2 against core's post template; 1 own query (variant prime) |
| P3b | Variable product, 6 and 250 variants | extra queries | ≤ 3, identical for both | T1 | −2 at 6 and 250; 1 own query |
| P3b | | inlined variant state | ≤ 40,933 B at 250 variants (measured 37,211 B + 10 %) | T1 | 794 B at 6, 37,211 B at 250 |
| P3 | Simple and variable product | extra peak memory | ≤ 1,153,733 B (measured 1,048,848 B at 250 variants + 10 %) | T1 | 215,136 B simple; 234,648 B at 6 variants, 1,048,848 B at 250 (macOS, PHP 8.5) |
| P4q | `GET store/cart`, 1 and 10 lines | queries | ≤ 4, identical for both | T1 | 4 at 1 and 10 lines |
| P5q | `POST store/cart/items` | queries | ≤ 7 (measured 6 + 10 %, rounded up) | T1 | 6 |
| P6q | Checkout (COD), 1 and 10 lines | queries | fixed part ≤ 30 *provisional*, plus exactly 1 per line | T1 | n/a |
| P6m | Checkout | extra peak memory | ≤ 3 MB *provisional* | T1 | n/a |
| R-REST | REST request for another plugin's route | FlexaCommerce endpoint objects built | 0 | T1 | 0 (was 5, F6, fixed by O6) |
| P10 | Any request | autoloaded options owned | 1, ≤ 10 KB | T1 | 1, 575 B (plus `flexacommerce_rewrite_guard`, which exists only while another plugin claims the shop URL, §3.4) |
| P11 | Catalog pages | `Set-Cookie` or `no-cache` from FlexaCommerce | none | T1 | holds on archive, simple and variable product: no sessions query, no `nocache_headers`, no `Set-Cookie` in `wp_headers` (§5.2) |
| P8 | Product page | JS / CSS gzip | ≤ 15 KB / ≤ 10 KB | T1 (build) | n/a |
| P9 | Migration batch | peak memory | < 128 MB | T2 | n/a |

P1 memory is tightened from doc 06's 0.5 MB to 256 KB because the measured cost is 93 KB; the 2 ms time limit moves to T3 because CLI timing cannot resolve it. Whether load-test timing (nginx upstream time over 500 requests) can is checked on the first T3 run: if the run-to-run spread of the p50 exceeds 2 ms, P1t is reported instead of gated until the stack is quieter. The first run (§7.1) was on a busy developer laptop and the p50 moved by up to 9 ms, so P1t is reported, not gated, until a run on the reference machine (Q18).

Provisional values come from the design, not from data. Each is confirmed or corrected when the feature first runs under §5, and the measured value plus a margin of 10 % then becomes the binding budget. A provisional budget may be raised once at that point, with the measurement recorded in the pull request; after that, raising it follows §5.4.

### 4.2 Latency and concurrency (reference machine, dataset M, no page cache, no persistent object cache)

| Id | Scenario | 1 VU | 10 VU | 25 VU | Tier |
|---|---|---|---|---|---|
| L-home | Home with product collection | p95 ≤ core home p95 + 15 % | same | error rate 0 % | T3 |
| L-list | Archive page 1 and page 20 | p95 ≤ core archive p95 + 20 % | same | error rate 0 % | T3 |
| L-simple | Simple product | p95 ≤ core single p95 + 20 % | same | error rate 0 % | T3 |
| L-var | Variable product, 12 variants | p95 ≤ core single p95 + 25 % | same | error rate 0 % | T3 |
| P4 | `POST store/cart/items` | p95 ≤ 150 ms | p95 ≤ 150 ms | report | T3 |
| P5 | `GET store/cart` | p95 ≤ 100 ms | p95 ≤ 100 ms | report | T3 |
| P6 | Checkout COD, 5 lines, deferred email | p95 ≤ 500 ms | p95 ≤ 500 ms | report, 0 oversells | T3 |
| C1 | All catalog scenarios | | | p95 at 25 VU ≤ 2 × p95 at 1 VU, with FPM workers ≥ VU | T3 |
| C2 | Capacity | report requests/s at which p95 first exceeds its budget, and FPM worker RSS (p50, max) | | | T3 |

Catalog latency is budgeted relative to core on the same machine, because the absolute value is mostly WordPress, theme and hardware (R11). Cart and checkout keep the absolute values from doc 06 because core has no equivalent page. "Report" means the number is published and tracked for regressions (§5.4) without a fixed limit until there is data to set one. C2 is how "minimal RAM" becomes measurable: on a fixed RAM size, the number of PHP workers a store can run is RAM divided by worker RSS, and C2 publishes both.

## 5. Measurement methodology

### 5.1 Three tiers

| Tier | What | Where | When | Gates on |
|---|---|---|---|---|
| T1 | Deterministic counters: queries (and their text), classes loaded, callbacks, autoload bytes, asset bytes, `Set-Cookie`, peak memory | CI integration job, MySQL 8.0, PHP 8.2 and 8.5 | every push | exact counts; memory budget and baseline + 5 % |
| T2 | Local microbenchmarks: hrtime over 200 fresh-process runs, migration batch memory | developer machine, CI nightly for P9 | on demand, nightly | P9 only; timings are informational |
| T3 | Load tests: PHP-FPM + nginx, k6, datasets S/M (L at release) | reference machine (§6.1) | each phase exit, every release candidate | §4.2 budgets, regression rule §5.4 |

### 5.2 T1 harness (built in phase 2.5)

- `tests/Integration/Performance/` (part of the integration suite, so it runs wherever that suite runs) starts each scenario in a **fresh PHP process**: `Probe` runs `probe-request.php` through `proc_open`, once with FlexaCommerce active and once with only core, against the integration test database, and compares. A fresh process is what makes boot cost visible (F3). Each mode gets one warm-up request first, because the first request after a change writes cron and transient rows; memory is the lower of two measured runs.
- The probe forces plugins and theme through pre-seeded `$GLOBALS['wp_filter']` entries, as the audit probe did, and uses the newest bundled `twenty*` theme because the test database's default theme does not exist.
- Scenario fixtures are seeded before the probes run (two catalog sizes for every list scenario from phase 3 on, R8). Today: one published blog post and the home page, plus two REST requests for R-REST (another namespace builds no endpoint, ours builds them).
- Budgets live in one file (`budgets.php`) with the class allowlist, next to the recorded memory baselines (`baseline.json`). Baselines are keyed by OS family and PHP minor version (`Darwin-8.2`, `Linux-8.5`), because peak memory differs between builds. `FXC_PERF_RECORD=1` records them; a missing key prints the measured value to record and only the budget applies.
- On failure the test prints the extra queries with their callers and the unexpected classes, so the cause shows up directly in the CI log. This was checked by putting a `get_option()` back into `maybe_flush()`: the test failed with the SQL and the caller chain.
- Memory counters use `memory_get_peak_usage()` (not `true`), which is stable to about ±1 % between runs of the same code; budgets leave a 5 % band for PHP patch versions. The memory assertion is skipped when the CLI has no OPcache, because without it compiled code counts toward peak memory. The harness skips on multisite; the single-site run covers the boot path.
- The plugin never loads `vendor/autoload.php` (F8), so a dev checkout measures the same as the release zip.
- **OPcache file cache (phase 3).** Each probe is a new process, so without a file cache every file was compiled again and the compiler's memory counted as request memory: adding any one line to `Plugin.php` moved P1 by the same 13.5 KB. The probe now runs with `opcache.file_cache` in the temp directory, and the warm-up run fills it, as a warm OPcache would be in production. P1 dropped from about 138 KB to 66 to 72 KB on PHP 8.5 (macOS) and `baseline.json` was recorded again for `Darwin-8.5`. The other keys were recorded before this change and are looser than the current measurement; record them again with `FXC_PERF_RECORD=1` on each platform.
- **Headers in the CLI.** PHP's CLI keeps no sent headers (`headers_list()` is empty), so P11 checks the causes instead: no query on the sessions table, `nocache_headers` not called, no `no-` in the final `Cache-Control`, and no `Set-Cookie` in the `wp_headers` filter result. T3 and the browser pass check the real headers.
- **Storefront fixtures** (`StorefrontBudgetTest`): 48 simple products, a simple product with managed stock, variable products with 6 and 250 variants, and guest carts with 1 and 10 lines. Query budgets compare plugin on against off, and also count the plugin's own queries, because the theme's template for a bare post type runs more queries than the product template (P3a and P3b read −2).

### 5.3 T3 load tests

- Stack: Docker Compose with pinned images: nginx, PHP-FPM (8.2 and the newest supported), MySQL 8.0. OPcache on (`validate_timestamps=0`, `memory_consumption=256`, `max_accelerated_files=20000`), JIT off, `pm=static` with a documented worker count, `innodb_buffer_pool_size` documented and large enough to hold dataset M.
- Server time comes from nginx `$upstream_response_time` logs, not from k6 client timings, so network and client noise stay out of the numbers.
- Per scenario: 50 warm-up requests, 500 measured, 3 runs, median of the runs for p50/p95/p99; spread (max minus min of the run p95s, divided by the median) must stay ≤ 10 %, otherwise the set is discarded and rerun. Error rate must be 0.
- Collected per request by a measurement mu-plugin (benchmark stack only): queries, `memory_get_peak_usage()`, `memory_get_peak_usage(true)`, `getrusage()` CPU time. Collected per run: FPM worker RSS from `ps` before and after, MySQL `Handler_read_*` and `Innodb_rows_read` deltas.
- Cart and checkout scripts keep cookies per virtual user and follow each plugin's own flow (§6.4).
- Mail is replaced by a null transport (`pre_wp_mail` returns true) in both plugins, so mail servers do not enter the numbers; the email rendering cost stays in.

### 5.4 Regression thresholds

| Counter | Fails when | Escape |
|---|---|---|
| T1 queries, classes, callbacks, cookies, autoload | any increase over the budget file | change `budgets.php` in the same PR with the reason; reviewer approval required |
| T1 memory | above budget, or more than 5 % above the recorded baseline | same, with before/after numbers |
| T3 p95 | more than 10 % above the previous release's published p95 on the same machine and dataset | blocks a release; a documented exception needs the measured value in the release notes (doc 06 §3) |
| T3 capacity (C2) | worker RSS more than 10 % above the previous release | same |

## 6. WooCommerce comparison methodology

### 6.1 Environment

- One reference machine with fixed specs (Q18), CPU frequency scaling and turbo settings documented, nothing else running. Both plugins run on the same machine, same container images, same PHP and MySQL settings, in alternating order (FXC, WC, FXC, WC) so drift over time affects both.
- Each run starts from a restored database dump and a restarted stack, then runs the warm-up.
- Versions recorded in the report: WordPress, WooCommerce, FlexaCommerce commit, PHP, MySQL, images.

### 6.2 Identical datasets

- A neutral dataset spec (JSON, generated with a fixed seed) describes products, attributes, variants, prices, stock, categories, images (shared files), customers and orders for sizes S/M/L (doc 06 §4).
- Two loaders write it natively: one through FlexaCommerce services, one through WooCommerce CRUD classes. Until migration exists (phase 6), this is the only way to get equal data. After phase 6, the WooCommerce dataset is also migrated into FlexaCommerce and compared with the native load as a cross-check.
- A parity script compares both stores before every benchmark: per SKU price, sale price, stock, status, attribute values, category; product, variant, order and customer counts. Any difference stops the benchmark.

### 6.3 Configuration

- Only the commerce plugin and the measurement mu-plugin are active. Theme Twenty Twenty-Five, the same page structure (header, footer, 12 products per page, same sorting), mini-cart in the header for both or for neither.
- WooCommerce runs in two configurations, both reported: **default** (as installed, HPOS on) and **tuned** (features that a careful store turns off and that have no FlexaCommerce equivalent, such as order attribution, remote logging and the coming-soon mode; the exact list is published with the run). This answers both "what a store gets" and "what a careful store gets" (R1).
- Each scenario carries a parity note listing features one plugin runs and the other does not (R9).
- Object cache: primary runs without; a second set with Redis for both plugins. Page cache off.

### 6.4 Scenarios

| Scenario | FlexaCommerce | WooCommerce |
|---|---|---|
| Homepage | front page with a product collection block, 12 products | same with the WooCommerce product collection block |
| Product listing | product archive pages 1 and 20 | shop page, pages 1 and 20 |
| Simple product | single product page | single product page |
| Variable product | 12-variant product page | same product |
| Add to cart | `POST flexacommerce/v1/store/cart/items` | `POST wc/store/v1/cart/add-item` |
| Cart | `GET store/cart` and the cart page | `GET wc/store/v1/cart` and the cart page |
| Checkout | `POST store/checkout`, COD, 5 lines | `POST wc/store/v1/checkout`, COD, 5 lines |
| Journey | browse 5 pages, add 2 items, checkout | same |

### 6.5 Reported metrics

Per scenario and configuration: p50/p95/p99 server time, throughput, error rate, queries, PHP peak memory, FPM worker RSS, CPU time, rows read, HTML bytes, JS/CSS bytes and request count on page load. Raw data, scripts, dataset spec, parity output and environment go to `docs/benchmarks/{date}/` with a summary `docs/benchmarks/{date}.md`. Claims quote these reports only, name the configuration (default or tuned) and include the parity note.

## 7. Optimization priorities

Ordered by evidence and cost. Status as of 2026-10-25: O1, O2, O3, O4 and O6 done in phase 2.5, O5 and O8 in phase 3; the rest are open.

| # | Item | Evidence | Expected effect (verified by) |
|---|---|---|---|
| O1 | Remove the per-request flush-flag read (§3.4) | F1 | P1 extra queries 1 → 0 (T1) |
| O2 | Build the extension facade only when `flexacommerce.loaded` has listeners | F2 | 3 fewer classes on non-commerce requests (T1 allowlist) |
| O3 | Replace the in-process P1 test with the fresh-process T1 harness, and record baselines | F3 | Boot costs become visible in CI |
| O4 | Dataset seeder (S/M) and WooCommerce loader with parity check | F7 | T3 can run |
| O5 | Batch loaders and prime calls designed into the phase 3 renderers from the start | doc 02 §7.1 | P2/P3 query counts flat at N and 4N (T1). Done in phase 3: one variant prime per page at 12 and 48 products, 6 and 250 variants |
| O6 | Build REST endpoint objects only for our namespace or the route index | F6 | R-REST 5 → 0 (T1) |
| O7 | Gateway and shipping descriptors with lazy classes | §3.2 | Gateway classes absent from catalog requests (T1 allowlist) |
| O8 | Mini-cart count: REST call or count cookie | §3.7 | Decided by T3 numbers (Q19). Done in phase 3: count cookie `fxc_cart_count`, because the REST read costs about 14 queries and 10 ms of server time per page view (§7.2) |
| O9 | Static classmap for the release build instead of path-computing autoload | files and autoload calls per request | Measured with T2 before adopting; dropped if the gain is under 1 % of request time |

### 7.1 Phase 2.5 results (2026-10-24)

| Item | Result | Verified by |
|---|---|---|
| O1 | P1 extra queries 1 → 0. Rewrite self-healing covered by `RewriteRulesTest` (rule present: no flush; rule missing: one flush; conflict: one flush, then the guard) | T1 `FrontendBudgetTest`, `RewriteRulesTest` |
| O2 | Loaded FlexaCommerce symbols on a blog post 13 → 9 (`Services`, `Clock`, `ExtensionsFacade`, `Extensions` no longer load) | T1 allowlist |
| O3 | Fresh-process harness in `tests/Integration/Performance/` replaces `FrontendQueryBudgetTest` (§5.2); memory baselines recorded for macOS and Linux, PHP 8.2 and 8.5 | T1 |
| O4 | `tools/bench/`: dataset spec generator (S and M, identical output on PHP 8.2 and 8.5), FlexaCommerce and WooCommerce loaders, parity export and compare | parity OK for both stores on S (1,000 products, 1,824 variants) and M (10,000 products, 36,828 variants). Load time on the bench stack: S 10 s FlexaCommerce, 72 s WooCommerce; M 117 s and 1,380 s |
| O6 | R-REST 5 → 0: endpoint objects are built only for our namespace or the route index | T1 REST tests |
| T3 | Stack, mu-plugin, k6 script, driver and summarizer in `tools/bench/`. First baseline: `docs/benchmarks/2026-10-24.md` | report |

The first T3 run confirms P1 under PHP-FPM: 0 extra queries, 0 extra rows read, +63 KB peak memory and +320 KB worker RSS on the blog post. Its latency is not valid (run p95 spread 22 to 69 %, limit 10 %) because it ran on a busy developer laptop. That is the open item for this phase: a quiet reference machine (Q18) and a rerun there, at dataset M, before latency budgets are used in phase 3.

### 7.2 Phase 3 results (2026-10-25)

| Item | Result | Verified by |
|---|---|---|
| O5 | One variant prime per page: archive queries equal at 12 and 48 products (+2 against core, 1 own), product page queries equal at 6 and 250 variants (−2 against core's post template, 1 own) | T1 `StorefrontBudgetTest` |
| Memory budgets | Provisional values replaced by measured + 10 % (macOS, PHP 8.5, warm OPcache file cache): P2 1,411,292 B, P3 1,153,733 B, P3b inlined state 40,933 B | T1 |
| P4q, P5q | `GET store/cart` 4 queries at 1 and 10 lines; `POST store/cart/items` 6 queries for a new guest (budget 7) | T1 |
| P11 | Archive, simple and variable product pages: no sessions query, no `nocache_headers()`, no `Set-Cookie` | T1, and a browser pass on the bench stack |
| O8 | Count cookie chosen (Q19). The mini-cart reads `fxc_cart_count`, which the store API and the no-JS form handler set with the session cookie | browser pass |
| T3 | Scenarios shop, simple, variable, cart-add, cart-view on dataset M (`tools/bench/k6/cart.js` for the cart). Report: `docs/benchmarks/2026-10-25.md` | report |

The T3 counters, FlexaCommerce against WooCommerce 11.2.1 default: cart-add 16 against 69 queries, cart-view 14 against 74, product pages 21 to 22 against 111 to 125, worker RSS about 47 MB against 82 MB. Latency is indicative: P4 p95 15 ms and P5 p95 16 ms (budgets 150 and 100 ms), but 11 of 13 rows fail the 10 % spread rule on the developer laptop. P4 and P5 stay reported, not gated, until the reference machine run (Q18). The shop rows are not like for like: the FlexaCommerce archive shows 10 products per page (theme `posts_per_page`) and WooCommerce 16.

Open items: memory baselines for P2 and P3 come from macOS only, and the first CI run on Linux may need them recorded there; the P1 baselines for Linux and for macOS PHP 8.2 must be re-recorded with `FXC_PERF_RECORD=1` because the probe now uses an OPcache file cache (§5.2).

## 8. Roadmap integration

Doc 07 carries the tasks. Summary of what this document adds:

- **Phase 2.5 (before phase 3 feature work, done 2026-10-24 except the reference-machine rerun, §7.1):** O1, O2, O3, O4 (S and M), the T3 Docker stack and k6 scripts for the scenarios that exist (blog post, home), first `docs/benchmarks/` report as a baseline.
- **Phase 3 (done 2026-10-25 except the reference-machine rerun, §7.2):** O5; T1 budgets P2, P3a, P3b, P4q, P5q, P11 become binding; T3 cart scenarios P4 and P5; first FXC versus WooCommerce run for listing and product pages, marked indicative because the storefront is minimal.
- **Phase 4:** O6, O7; P6q, P6m binding; T3 checkout P6 and concurrency C1; worker RSS (C2) reported.
- **Phase 5:** P1h, P8 binding; full scenario set in §6.4 at dataset M, both WooCommerce configurations.
- **Phase 6:** P9 nightly; migration-based dataset cross-check (§6.2).
- **Phase 7:** dataset L, final published report, third-party rerun invitation, release thresholds §5.4 in force.
