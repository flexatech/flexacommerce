# 07. Implementation Roadmap

Status: **Approved 2026-10-24**, phase 1 complete (exit criteria verified locally and in CI), phase 2 complete (its browser pass was done in phase 3), phase 2.5 and phase 3 complete except the latency rerun on a reference machine, phase 4 complete except C1 and that rerun. Phase 4.5 (taxes) added on 2026-10-25 when Q2 changed (ADR-020) and complete except T3 P6t and the cart tax display. Effort figures are rough estimates for one experienced WordPress/PHP developer with part-time frontend help, given only to compare phases; they are not commitments.

Priorities: **P0** blocks the milestone, **P1** expected in the milestone, **P2** may slip to the next milestone, **P3** nice to have.

## Phase overview

| Phase | Goal | Depends on | Milestone | Rough size |
|---|---|---|---|---|
| 0 | Repository audit and planning (this document set) | | | done on approval |
| 1 | Foundation and schema | 0 | M1 | 2 to 3 weeks |
| 2 | Catalog and variants (+ mapping spike) | 1 | M1 | 3 to 4 weeks |
| 2.5 | Performance harness and baseline (doc 10) | 2 | M1 | 1 to 1.5 weeks |
| 3 | Pricing, stock, cart, minimal storefront | 2.5 | M1 | 3 to 4 weeks |
| 4 | Checkout, orders, shipping, COD/bank, emails | 3 | M1 | 4 to 5 weeks |
| 4.5 | Taxes: WooCommerce-equivalent options, US preset (ADR-020) | 4 | M1 | about 4 weeks |
| 5 | Full storefront (blocks, templates, account) | 3 (parallel with 4 after cart API is stable) | M1 | 3 to 4 weeks |
| 6 | Migration (WC→FXC, then FXC→WC) | 2, 4 | M2 | 5 to 7 weeks |
| 7 | Hardening, benchmark, release | all | M2 (1.0) | 2 to 3 weeks |

Order rationale: the riskiest correctness work (variants, stock, checkout) comes first, so the data model is proven by real code before migration is built on top of it. Migration waits until the order model is stable because it is the largest consumer of the schema. A spike in phase 2 checks the canonical product mapping early, so a schema problem found by migration does not arrive in phase 6.

## Phase 1: Foundation and schema

| Pri | Task |
|---|---|
| P0 | Bootstrap file (header with `Requires at least: 6.9`, `Requires PHP: 8.2`, define block, fallback autoloader, PHP guard), `Plugin::boot()` with the registrar list from doc 02 §4.1 |
| P0 | `Support\Services`, `Transaction` (deadlock retry), `AfterCommit`, `Clock`, `Money`, `Currency` table, `Settings` (schema, defaults, sanitize, partial merge) |
| P0 | `Database\Schema` with all tables from doc 03, `DB_VERSION`, upgrade-step runner, InnoDB check |
| P0 | `Setup\Activator` (requirements, schema, settings seed, caps/role, cron), deactivation, `uninstall.php` + `Maintenance\Uninstaller` (opt-in) |
| P0 | `Api\Router`, `Api\Endpoint` (permission helpers), `Support\Capabilities` |
| P0 | Tooling: composer dev deps (phpstan, phpcs, phpunit, wp-phpunit), `phpstan.neon`, `phpcs.xml.dist`, CI pipeline, `DependencyRulesTest`, `RoutesHavePermissionsTest`, `.gitignore`, `.distignore`, `release.sh` |
| P1 | Admin shell: top-level menu, React app scaffold (`client/admin`, Flexa UI system), Status screen with DB version and environment |
| P1 | Logger with redaction, Status > Logs |
| P2 | `wp flexacommerce doctor` (orphan checks start empty, grow per phase) |

Exit criteria: activation on single site and multisite creates all tables with correct indexes on MySQL 8.0 and MariaDB 10.6; schema re-run is a no-op; P1 query assertion (0 queries on a blog post) passes; CI green.

Implementation notes (phase 1, 2026-10-24):

- **Extensions facade is phased.** `Contracts\Extensions` (API 1.0) exposes only `money()`, `settings()` and `logger()` for now. Orders, cart and catalog services join the interface in the phases that build them, so the contract never promises a service that does not exist yet.
- **Client layout.** The admin app lives in `client/admin/` (entry `admin/main.tsx`, which is also the Vite manifest key that `Admin\Enqueue` resolves). Code shared with the later storefront goes in `client/shared/` (UI primitives, styles, API client, i18n). The build writes to `assets/dist/`, which stays in git.
- **PHPUnit 9.6, not 10+.** `wp-phpunit` 7.1 still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10 removed. Both suites use 9.6 and `@dataProvider` annotations until wp-phpunit catches up.
- **Multisite runs from its own config.** `tests/phpunit-multisite.xml.dist` sets `WP_TESTS_MULTISITE`; run it with `vendor/bin/phpunit -c tests/phpunit-multisite.xml.dist --testsuite integration`. CI runs single site and multisite on PHP 8.2 and 8.5 against MySQL 8.0 and MariaDB 10.6.
- **Site deletion drops store tables.** `Database\Schema::site_tables_to_drop()` hooks `wpmu_drop_tables`, so deleting a site from the network removes its `fxc_` tables along with core's.
- **`fxc_logs`** was added to the schema for the plugin log (doc 03 §4.12), bringing the count to 14 tables.

## Phase 2: Catalog and variants

| Pri | Task |
|---|---|
| P0 | `fxc_product` CPT, `fxc_product_cat`, `fxc_product_tag`, `fxc_attribute` taxonomies, capabilities, rewrite bases (`shop-item`/`shop` when WooCommerce active) |
| P0 | `VariantRepository`, `ProductRepository::prime()`, value objects, combination normalization, `VariantMatcher` |
| P0 | `ProductService`: save rules, SKU uniqueness mapping (1062 → `DuplicateSku`), type change rules, variant generation, delete handling, denormalized meta |
| P0 | Admin product data metabox (React): type, prices, stock, attributes (global + local), variant generation and bulk edit, weight/dimensions |
| P0 | **Spike:** map 10 real WooCommerce products (simple, variable, wildcard, parent stock) to canonical records and into `fxc_variants` by hand-written code. Outcome: confirm or adjust doc 03 §3 before phase 3 |
| P1 | Attributes admin screen (global attributes and values, ordering) |
| P1 | Admin REST `admin/products/{id}`, `admin/products/{id}/variants` |
| P2 | Sale schedule cron (`_fxc_next_price_change`) |
| P3 | Product duplicate action |

Exit criteria: unit tests for matcher and normalization; integration tests for SKU rules; product with 100 variants saves in < 2 s in admin (target); spike report written into doc 03 if anything changed.

Implementation notes (phase 2, 2026-10-24):

- **Exit criteria.** `VariantMatcher` and `Combination` unit tests; SKU integration tests (`SkuRulesTest`: case-insensitive duplicates within a save and across products, 1062 mapped to `DuplicateSku`); a 100-variant save takes 0.034 s locally against the 2 s target; spike report in doc 03 §3.9.
- **Spike outcome.** No schema change. Code change: `AttributeRepository::create_attribute()` / `create_value()` take an optional slug so an importer can keep two attributes with the same label. Doc 05 conflict rules changed for duplicate combinations, unoffered values and invalid sale prices. The spike is kept as `WooCommerceMappingSpikeTest` with its fixture, so phase 6 starts from known cases.
- **Stale variations.** Removing an attribute value, a whole axis or a store attribute turns the affected variations stale instead of failing the save: deleted when they hold no stock, disabled otherwise (doc 03 §3.2). The editor only sends a variation's combination when the merchant changed it, so an explicit combination that uses a removed value is still an error.
- **Editor saves with the post.** The product data metabox (`client/admin/product-editor.tsx`, own Vite entry and scoped CSS under `.flexacommerce-product-ui` with no global preflight, so the block editor keeps its styles) saves through `admin/products/{id}` when the post is saved: in the block editor on a non-autosave save, in the classic editor by holding the form submit until the request finishes. It also has its own "Save product data" button. Variation generation saves pending edits first.
- **Attribute usage** on the Attributes screen counts products, not value assignments.
- **Test bootstrap drops plugin tables.** The WordPress test installer recreates core tables, so post ids restart at 1 and stale `fxc_variants` rows from an earlier run matched new posts (an intermittent failure). `tests/bootstrap-integration.php` now calls `Schema::drop_all()` before activation.
- **Browser pass (done in phase 3, 2026-10-25).** The product editor metabox in the block editor (loads, saves through "Save product data" with a 200) and the Attributes screen were checked in Chromium on the bench stack. The classic editor path was not clicked through.

## Phase 2.5: Performance harness and baseline

Added 2026-10-24 after the performance audit (doc 10 §1). It comes before phase 3 so that every phase 3 renderer and route is written against budgets that already run in CI.

| Pri | Task |
|---|---|
| P0 | Remove the per-request `flexacommerce_flush_rewrite` read (doc 10 F1, O1) |
| P0 | Build the extension facade only when `flexacommerce.loaded` has listeners (F2, O2) |
| P0 | T1 harness `tests/Integration/Performance/`: fresh-process probes, plugin on vs core only, `budgets.php`, `baseline.json`, class allowlist; replaces the in-process `FrontendQueryBudgetTest` (F3, O3) |
| P0 | Dataset seeder: `tools/bench/generate-dataset.php --size=S\|M --seed=42` writes a neutral spec, `tools/bench/load-flexacommerce.php` loads it (dev-only, excluded from the zip) |
| P1 | WooCommerce loader for the same spec and the parity script (doc 10 §6.2) |
| P1 | T3 stack: Docker Compose (nginx, PHP-FPM, MySQL 8.0), measurement mu-plugin, k6 scripts for blog post and home; first report in `docs/benchmarks/` |
| P2 | Build REST endpoint objects only for our namespace or the route index (F6, O6) |

Exit criteria: T1 runs in CI on every push and fails on any budget breach; P1 passes in a fresh process (0 extra queries, ≤ 256 KB, allowlisted classes only); baseline report published with environment, raw data and spread.

Implementation notes (phase 2.5, 2026-10-24):

- **Exit criteria.** The harness is in the integration suite that CI runs on every push (single site, PHP 8.2 and 8.5); the Linux memory baselines from the first CI run are recorded in `baseline.json`. P1 passes in a fresh process: 0 extra queries, +82 KB on PHP 8.2 and +111 to 135 KB on 8.5 (macOS), 9 allowlisted symbols, +24 callbacks, no assets. The baseline report is `docs/benchmarks/2026-10-24.md` with raw data and spread.
- **Open item.** The report's latency is invalid by the doc 10 spread rule (22 to 69 %, limit 10 %) because it ran on a busy developer laptop. Its counters are valid. A rerun on a quiet reference machine (Q18) at dataset M is needed before phase 3 uses latency budgets.
- **Seeder shape changed.** The plan said `wp flexacommerce dev seed`. The seeder is a plain spec generator plus one loader per store under `tools/bench/` instead, so the plugin carries no dev command and the WooCommerce loader reads the same file. Customers and orders in the spec stay empty until phase 4.
- **O1 shape changed.** No flag and no settings-save flush: the front end checks the shop rule in the autoloaded `rewrite_rules` and flushes once when it is missing (doc 10 §3.4).
- **Vendor autoload removed** from the bootstrap and `uninstall.php` (doc 10 F8): a dev checkout loaded Composer's dev files on every request.
- **Datasets loaded on the bench stack** (Apple M2 laptop): S in 10 s (FlexaCommerce) and 72 s (WooCommerce 11.2.1), M in 117 s and 1,380 s. Parity OK for both stores at both sizes. Loader times are tooling cost, not a product comparison.

## Phase 3: Pricing, stock, cart, minimal storefront

| Pri | Task |
|---|---|
| P0 | `PricingService` (active price, `flexacommerce.price.unit` filter, line and order totals, allocation) |
| P0 | `StockService` (available, deduct, release, adjust) with the conditional UPDATE and history rows |
| P0 | `fxc_sessions`, `SessionStore` (lazy cookie, hashed token, versioned writes, merge on login, purge cron) |
| P0 | `CartService` + store REST `store/cart`, `store/cart/items`, X-FXC-Nonce, Origin check |
| P0 | Minimal storefront: product price and add-to-cart renderers (block + shortcode), cart block/shortcode, no-JS form handlers |
| P1 | Interactivity API variant selector with inlined matrix, mini-cart block with REST count |
| P1 | Concurrency harness (doc 06 §5.4) with stock tests |
| P2 | Low stock notifications |

Exit criteria: last-unit concurrency test green; cart survives login merge; catalog pages set no cookie (P11); P2 and P3 query assertions pass.

Performance exit (doc 10 §4, §8): T1 budgets P2, P3a, P3b, P4q, P5q and P11 binding, each list budget asserted at two sizes with identical query counts; provisional memory budgets replaced by measured value + 10 %; T3 P4 and P5 measured on the reference stack; first FlexaCommerce versus WooCommerce run for listing and product pages, published as indicative.

Implementation notes (phase 3, 2026-10-25):

- **Exit criteria.** Last unit: `StockServiceTest::test_the_last_unit_is_sold_once` and `ConcurrencyTest` (8 separate PHP processes released at one start time; with 1 and 3 units in stock exactly that many buyers win and the rest get `InsufficientStock`; 8 parallel adds to one cart lose no update). Login merge: `CartServiceTest` (guest cart survives login and merges once, becomes the user cart when the user has none, quantities capped by stock). P11 and the P2/P3 query assertions pass in `StorefrontBudgetTest`.
- **Performance exit.** T1 binding (doc 10 §7.2): P2 +2 queries at 12 and 48 products; P3a and P3b −2 against core's post template, equal at 6 and 250 variants; P4q 4 queries at 1 and 10 lines; P5q 6 (budget 7); P11 holds. Memory budgets are measured + 10 %: P2 1,411,292 B, P3 1,153,733 B, P3b inlined state 40,933 B. The T3 run (`docs/benchmarks/2026-10-25.md`, dataset M) is indicative: P4 p95 15 ms and P5 p95 16 ms against 150 and 100 ms, but most rows fail the 10 % spread rule on the laptop, so "measured on the reference stack" is still open (Q18). FlexaCommerce against WooCommerce 11.2.1: cart-add 16 against 69 queries, product pages 21 to 22 against 111 to 125.
- **Probe memory.** The T1 probe now runs with an OPcache file cache. Without it, compiling the plugin on a cold OPcache counted as request memory. The P1 baseline was re-recorded for macOS PHP 8.5 only; Linux (CI) and macOS PHP 8.2 need `FXC_PERF_RECORD=1`. The P2 and P3 budgets also come from macOS and may need a Linux check on the first CI run.
- **Test salts.** `tests/wp-tests-config.php` defines distinct salts. With the default duplicates, `wp_salt()` falls back to salts stored as options, which adds a query the budgets would count and a real site does not have.
- **Mini-cart uses the count cookie** (Q19 changed, doc 10 O8). The REST read would cost about 14 queries and 10 ms per page view. `fxc_cart_count` is written by the same token store as `fxc_session`, so the store API and the no-JS form both keep it current. The block renders a bare link; placed in post content it does not follow the content width (fine in a header). Wrapping it with `get_block_wrapper_attributes()` is a phase 5 item.
- **Block registration** happens on the first top-level block whose tree holds one of ours (`pre_render_block`), in admin, and on REST requests outside our namespace. Our own REST routes skip it, because registering the editor script builds `WP_Scripts` and cost two queries on every cart request.
- **No page installer yet.** There is no Cart page created on activation. `cart_page_id` empty means cart links point to the shop; the merchant places the cart block or `[flexacommerce_cart]` on a page and sets it. Page setup comes with checkout in phase 4.
- **Road for a standalone React product editor** (asked for in this phase, ADR-019): `admin/products` has list (search, status, type, terms, `X-WP-Total`), create, read, update and delete, and its payload carries the post fields too. The editor is split into `useProductDraft`, a mount-agnostic `ProductDataPanel` and the metabox host. `flexacommerce.admin.product_editor` returns `post` (default) or `app`; `app` removes the metabox and its script.
- **Low stock notifications.** `StockService` fires `flexacommerce.stock.low` and the out-of-stock event and mails the site admin email when `notify_low_stock` is on (the default); `flexacommerce.stock.notify` can stop a mail per variant.
- **Browser pass** (Chromium on the bench stack, 27 checks): catalog pages send no cookie and no `no-cache`; JS add sets an httpOnly `fxc_session` and `fxc_cart_count`; a variable product shows the variant price after a full selection; quantity update and remove on the cart page; mini-cart count after add and after reload; the no-JS add redirects back with a notice. It found two client issues, both fixed: typing a quantity and then removing that line raced a PUT against the DELETE and showed "This item is no longer in your cart." (`assets/frontend/cart.js` now ignores `unknown_line` for a line being removed), and the theme's paragraph margin pushed the unit price down in the cart table.
- **Open items.** Latency rerun on a reference machine (Q18) for P1t, P4 and P5; P1 baselines for Linux and macOS PHP 8.2; classic editor pass of the product editor; products per page on the shop archive (10 from the theme against WooCommerce's 16, phase 5).

## Phase 4: Checkout, orders, shipping, payments, emails

| Pri | Task |
|---|---|
| P0 | Order tables repositories, `OrderStateMachine`, `OrderService` (create, transition, cancel, notes, expire_pending) |
| P0 | `fxc_sequences` order numbers, order key access |
| P0 | `ShippingService`, zones UI, `flat_rate`, `free_shipping`, `local_pickup` |
| P0 | `CheckoutService` + validator + field schema per country, `store/checkout`, checkout renderer (block + shortcode), order confirmation |
| P0 | `PaymentService`, `fxc_transactions`, gateway registry, `cod` and `bacs`, record manual payment |
| P0 | `fxc_webhook_events` + webhook endpoint pipeline (tested with a fake signed gateway in tests) |
| P0 | Refunds (manual and via gateway contract), restock choices |
| P0 | Admin orders list and detail (React), transitions, notes, refunds, needs-attention filter |
| P0 | Notifications (8 emails from doc 02 §7.9), templates, override path, history rows |
| P0 | Privacy exporter and eraser |
| P1 | Customers screen (users with orders + guest emails) |
| P1 | Rate limits (doc 06 §2.9) |
| P2 | Deferred email sending setting |
| P2 | `TaxCalculator` seam hardening (moved to phase 4.5 when Q2 changed) |

Exit criteria: full concurrency suite green; state machine transition table fully covered; security review gate (doc 06 §2.12) passes for checkout, store and admin order routes.

Performance exit: P6q (fixed part + exactly 1 query per line, checked at 1 and 10 lines) and P6m binding; gateway and shipping classes absent from catalog requests (T1 allowlist, O7); T3 P6 and C1 within budget with 0 oversells; worker RSS (C2) reported.

Implementation notes (phase 4, 2026-10-25):

- **Exit criteria.** Concurrency (`tests/Integration/Concurrency/ConcurrencyTest.php`, separate PHP processes with their own connections): parallel checkouts never oversell with 1 and with 5 units in stock; two carts holding the same lines in opposite order do not deadlock; the same checkout submitted five times at once makes one order; a webhook delivered ten times at once pays once; cron expiry and a customer cancel at the same time restore stock once; parallel adds to one cart lose no update. State machine: `OrderStateMachineTest` checks every (from, to) pair of statuses against the table of doc 02 §7.4, plus terminal statuses and the rule that `refunded` never moves stock. Security review (doc 06 §2.12): see the next item.
- **Security review.** Every route in our namespace has a real permission callback, and every `/admin/` handler, every method, refuses anonymous users and subscribers (`RoutesHavePermissionsTest`). Store writes need a JSON body, a same-origin `Origin` and `X-FXC-Nonce` bound to the session; webhooks are checked by the gateway's signature; order pages and `retry_payment` take the order key with `hash_equals`, rate limited per IP, then the owner or staff check. Order notes go through `wp_kses_post`; checkout, confirmation and email templates escape every value. One fix came out of it: with a proxy header configured, `ClientIp` took the first address of the list, which the client controls, so a forged entry got around the per-IP limits. It now takes the right-most public address (doc 06 §2.9).
- **Duplicate submits.** The insert of a second order with the same checkout token fails on the unique key, but under REPEATABLE READ a plain read cannot see the row the other transaction just committed. `OrderRepository::find_by_checkout_token()` has a `$lock` flag, and `OrderService::create()` re-reads with `FOR UPDATE` in that case; callers read the order again after commit, because its items can still be hidden by the snapshot.
- **P6q work.** The first measurement was 73 queries for one line (fixed part 72). What brought it to 55: `Database\TableCharset` answers wpdb's charset questions for our tables, which skips one `SHOW FULL COLUMNS` per table and request; addresses are written with one `REPLACE` (`save_addresses()`, replaces `save_address()`); `set_stock_reduced()` updates every line in one statement; `Logger::batch()` collects log rows and writes them with one multi-row insert (`LogRepository::insert_many()`); `GatewaySettings::prime()` and `EmailSettings::prime()` load the options with one query; `PaymentService::initiate()` takes the order the caller already holds (`?Order $order`); `OrderService::create()` seeds the read model cache so the deferred events do not read the order again. The budget was raised once, from the provisional 30 to measured 54 + 10 % = 60, with exactly 1 query per line (doc 10 §4.1). P6m: 953,600 B at 10 lines, budget 1,048,960 B.
- **Deferred emails** (`defer_emails`, default off). The message is built during the request and kept in a transient `fxc_mail_<id>` for a day; the single event `flexacommerce.cron.send_email` sends it. If the transient or the event cannot be stored, the email goes out inline. The cron handler is added only under cron and WP-CLI (P1 budget). `NotificationServices::mailer()` is the shared mailer.
- **O7.** `StorefrontBudgetTest` now asserts that the archive, simple and variable product pages load no class from `Payments`, `Shipping`, `Checkout`, `Orders` or `Notifications`.
- **T3.** Checkout (COD, 5 lines, deferred email) and the catalog pages at 1, 10 and 25 VU on dataset M, report `docs/benchmarks/2026-10-25-phase4.md`. Order request: 65 queries against WooCommerce's 913, fxc p95 under 500 ms up to 25 VU, no oversell in 18 runs. Latency is indicative (laptop, one valid row of 26), and C1 is not met there: core home alone rises 8.2 × from 1 to 25 VU with 25 workers on 8 CPUs, so C1 waits for the reference machine (Q18). The bench turns off the per-IP checkout limit, because all k6 traffic shares one address. WooCommerce runs checkout with HPOS on; the phase 3 report said HPOS was on, but it was off for those scenarios.
- **Not done.** `TaxCalculator` hardening (P2) moved to phase 4.5 (taxes, ADR-020); the seam stays as in phase 3 until then. C1 waits for Q18.

## Phase 4.5: Taxes (ADR-020, US first)

Added 2026-10-25 when Q2 changed. Specification: doc 02 §7.12; schema: doc 03 §4.13; contracts: doc 04 §7.1; budgets: doc 10 §4.1 (PT-*).

| Pri | Task |
|---|---|
| P0 | Schema `DB_VERSION` 2: `fxc_tax_rates`, `fxc_tax_rate_locations`, `fxc_variants.tax_status`, upgrade step that sets variation rows to `tax_class = parent` (with its fixture test, doc 03 §6) |
| P0 | Tax settings (doc 02 §7.12.1) in `Support\Settings`, tax classes, store base address fields |
| P0 | `Contracts\Tax` (`TaxLocation`, `TaxRate`, `TaxRequest`, `TaxResult`, `TaxRateProvider`, `TaxCalculator`), replacing the phase 3 seam; `Support\IntMath::mul_div_half_up()` |
| P0 | `TaxRateRepository` with the one-query match (postcode exact, prefix, range; city), `TableRateProvider`, `fxc_tax` cache group with a version key |
| P0 | `TaxEngine`: priority, compound, inclusive and exclusive prices, per-line and subtotal rounding, shipping tax class rule, `tax_status`, exemption filter |
| P0 | `TaxService`: location from `tax_based_on`, default location, markets, in-state origin sourcing; guard and fallback for add-on providers and calculators |
| P0 | Cart, checkout `Quote`, order lines: per-line per-rate tax, one `tax` line per rate, totals invariant; confirmation, account, emails and admin order detail show the tax rows (single or itemized) |
| P0 | Refunds split tax per rate (`OrderItem::refund_split()`) |
| P0 | Admin Taxes screen (React): options, classes, rate table per class with search and pagination, CSV import (batches of 500) and export in WooCommerce's format |
| P0 | US preset `resources/tax-presets/us.json` (state base rates, per-state shipping taxability, origin states, `as_of` and sources) and the apply dialog with nexus states and preview |
| P0 | Product editor: tax status and class on the General tab, class per variation with "Same as parent" |
| P0 | Golden parity tests against WooCommerce on the bench stack (`tests/fixtures/tax-golden.json`, doc 02 §7.12.9) |
| P1 | Catalog display incl/excl with the compiled base rates, price suffix placeholders, variable price ranges |
| P1 | Market overrides UI (one market per row, countries and overridden keys) |
| P1 | `flexacommerce.tax.presets` filter and a second preset skeleton in tests (proves the format is not US-only) |
| P2 | Tax-exempt flag on the Customers screen (feeds `flexacommerce.tax.exempt`) |
| P2 | Base-location adjustment for inclusive prices (`flexacommerce.tax.adjust_non_base_prices`) |

Exit criteria: golden parity tests pass to the minor unit or every difference is listed as intended in doc 02 §7.12.9; a US order with state, county, city and district rates for one ZIP stores four `tax` lines whose sum equals `tax_total`; shipping is taxed only by rates with the shipping flag; a full refund returns exactly the tax charged; with taxes off the full test suite and every phase 4 budget give the same numbers as before phase 4.5.

Performance exit: PT-off, PT-cat, PT-opt, PT-cart, PT-q, PT-m, PT-rows binding in T1; T3 P6t reported against WooCommerce with the same rate table.

Implementation notes (phase 4.5, 2026-10-25):

- **Exit criteria.** Golden parity: 20 cases priced by WooCommerce 11.2.1 (`tools/bench/bench.sh tax-golden`, `tests/Integration/Tax/TaxGoldenTest.php`); 19 match to the minor unit and the ZIP+4 case is an intended divergence (doc 02 §7.12.9). The golden run found two bugs, both fixed: the base-location adjustment rounded the net before taxing it (1 cent off on some inclusive lines), and exempt orders stored a 0 `tax` line per rate. A Cupertino order with state, county, city and district rates stores four `tax` lines that add up to `tax_total`, the district rate (shipping flag off) does not tax shipping, and a full refund returns exactly the tax charged (`TaxCheckoutTest`). With taxes off, the full suite and every phase 4 budget give the same numbers as before.
- **Taxes off costs nothing.** `PricingService` reads `tax_enabled` before it calls the tax closure, so catalog pages load no `Tax` class. `CheckoutService::quote()` returns early without a `TaxRequest`, and `Quote::taxes` is `null` while taxes are off; cart and checkout load only `TaxService` and `TaxServices` (PT-off).
- **Budgets** (doc 10 §4.1): PT-cat 0, PT-opt 5,019 B, PT-cart 0, PT-q +1 at 1 and 10 lines, PT-m 84 KB at 10 lines (re-recorded once from the provisional 64 KB, budget 96 KB), PT-rows 174 on a 50,050-location fixture.
- **Cart destination keeps the city.** The cart stored country, state and postcode only, so the form total missed a city rate and checkout answered `totals_changed`. `Cart` and `CartService` now keep `{country, state, postcode, city}` (doc 03 §4.9). Before an address is known the destination defaults to the store address (`tax_default_location = base`).
- **Exempt orders** get meta `_fxc_tax_exempt = 1` and no `tax` lines.
- **Rate matching.** Ties after priority and specificity break on the rate id, as WooCommerce does; rate order is only the screen and CSV order. Postcode ranges must be two numbers, low to high; anything else is refused on save. The range branch of the match query reads every range row with `range_from <=` the postcode in that country and state, which is fine at US scale (PT-rows) but would grow with tens of thousands of ranges in one state.
- **Refunds.** The split lives on the order item, `OrderItem::refund_split()`, since it needs only the stored line; the task table used to name `TaxService::split_refund()`.
- **CSV import batches are sent by the admin screen**, 500 rows each, validate pass first; the server refuses a larger batch. There is no server-side batch runner for it.
- **Tax UI** is the Tax tab of Settings (options, classes, rates per class, CSV, presets, markets), not a separate screen.
- **US preset.** `resources/tax-presets/us.json` has `as_of` 2025-01-01 and a source per state. The state rates, shipping taxability and origin-state list still need the check against each revenue department that doc 02 §7.12.8 asks for before release.
- **Not done.** The cart page and `store/cart` showed no tax (done in phase 5, see its notes). T3 P6t against WooCommerce with the same rate table is not run. The tax-exempt flag on the Customers screen (P2) is not built; the filter is the only way in. With `tax_based_on = billing` the checkout form total follows the shipping address until the order is placed. In the admin order view, an inclusive-price line shows unit × quantity, which differs from its stored net total.

## Phase 5: Full storefront

| Pri | Task |
|---|---|
| P0 | Block templates (`single`, `archive`, taxonomy) via `register_block_template()`, Query Loop variation for `fxc_product` |
| P0 | Classic templates with theme override path and `@version` headers, outdated override check |
| P0 | Remaining blocks: gallery, SKU, stock, attributes, breadcrumbs, account |
| P0 | Account area: orders, order detail, addresses, account details (rewrite endpoints) |
| P0 | Accessibility pass (keyboard, labels, error summaries, focus management in checkout) |
| P1 | Shop sorting (price, newest, popularity placeholder), category filters via `tax_query`, attribute filters |
| P1 | Page cache guidance and header checks |
| P2 | Product search improvements (SKU search) |
| P3 | Related products block |

Exit criteria: E2E journeys green on Twenty Twenty-Five and the chosen classic theme (Q9); axe clean; P8 asset budget met; P1h binding; full doc 10 §6.4 scenario set at dataset M against WooCommerce default and tuned configurations, with parity notes. **M1 reached** when phases 1 to 5 exit criteria and the M1 release gates (doc 06 §7) pass.

Implementation notes (phase 5, 2026-10-25):

- **Block templates.** `Storefront\Templates` builds the single, archive and taxonomy templates from the theme's `header` and `footer` parts, core blocks and ours, so the Site Editor can replace any piece. The archive grid is a `core/query` with `namespace: flexacommerce/product-query`; the editor's Query Loop variation (`assets/frontend/blocks-editor.js`) inserts the same inner blocks, and `Templates::product_query()` is the PHP copy.
- **Classic templates.** `TemplateLoader` serves `templates/single-product.php` and `templates/archive-product.php` on classic themes. A theme overrides them from `flexacommerce/` in its folder. Every template, the email ones included, carries `@version x.y.z`; `TemplateLoader::outdated()` lists theme copies with a lower or missing version. `get_file_data()` cannot read `@version 1.0.0` (it wants a colon), so the plugin reads the first 8 KB with its own pattern. Filters: `flexacommerce.classic_templates`, `flexacommerce.template`, `flexacommerce.template_wrapper`. A fixture classic theme lives in `tests/fixtures/themes/fxc-classic` for the probe.
- **Blocks.** New: gallery, SKU, stock, attributes, breadcrumbs (`flexacommerce.breadcrumbs`), account, catalog sorting, product filters, related products.
- **Account area.** Rewrite endpoints `orders`, `view-order`, `edit-address`, `edit-account` under the account page (`PostTypes::ACCOUNT_ENDPOINTS`). Forms post to the page itself and redirect after save.
- **Sorting and filters.** `ShopQuery` reads `?orderby=` (`menu_order`, `popularity`, `date`, `price`, `price-desc`), `?filter_cat=` and `?filter_{attribute}=`. Price sorts use `_fxc_price_min` and `_fxc_price_max` and keep products without a price, last when ascending. `popularity` sorts newest first until sales counts are stored. Page size: the `products_per_page` setting, filter `flexacommerce.shop.per_page`.
- **SKU search.** An exact SKU of a product or of one of its options adds that product to the main search query.
- **Accessibility (checkout).** Group fields (shipping rates, payment methods) point to their errors with `aria-describedby`; gateway errors have stable ids and no `role="alert"` of their own (the summary has it); the summary links focus the field, or the checked radio of a group; a failed post without scripts focuses the summary; the ship-to-another-address toggle has `aria-controls`; the terms link says it opens a new tab.
- **Page cache.** Catalog pages stay cacheable (P11, now also on sorted, filtered, category, search and classic pages). Cart, checkout and account call `nocache_headers()` and define `DONOTCACHEPAGE`. The readme FAQ says what to exclude when a cache ignores both, and not to bypass the cache on the `fxc_session` cookie.
- **Budgets** (doc 10 §7.4): P1h +2 queries at 12 and 48 products (1 own); P8 3,355 B JS and 2,361 B CSS gzip (2,368 B after the E2E pass); P3 memory re-recorded once. Related products read the product's terms with `wp_get_post_terms()`, 2 extra queries per product page; they now use `get_the_terms()`, which the term cache answers.
- **E2E and axe (2026-10-25).** `tests/e2e` (Playwright 1.61, `@axe-core/playwright`) runs 12 journeys on each of Twenty Twenty-Five, Astra 4.14.1 and Twenty Twenty-One 2.9: shop sorting, the filter block, a category page, the simple product (mini cart and cookies), the variable product (variant price, and the browser's required-option message when nothing is chosen), cart quantity and removal, guest checkout with cash on delivery to the confirmation, the error summary (focus, role, links that focus the field), a customer who signs in, orders and finds the order and the address form in the account, a full add and checkout with scripts off, and the server-rendered error summary when the script files fail to load. All 36 pass. They run on the bench host `e2e.bench` (`tools/bench/bench.sh e2e-setup`, then `bench.sh e2e`), where a mu-plugin picks the theme from a request header and puts the mini cart block at the top of the body, since none of the three themes has it in its header. axe (WCAG 2.0 and 2.1, A and AA) fails a test only for a violation inside FlexaCommerce markup; violations in the theme are attached to the report. The pass found two bugs, both fixed: text opacity in `storefront.css` (sale price `del`, SKU label, cart notes, field help, checkout quantity, order overview) fell under 4.5:1 contrast on Astra and Twenty Twenty-One, and the classic templates called `get_sidebar()` on a theme without `sidebar.php`, which prints core's deprecated theme-compat sidebar (invalid list markup, and a deprecation notice); they now call it only when the theme has the file (`TemplateLoaderTest`). Theme violations left: Twenty Twenty-Five's page-list navigation (`list`), Astra's footer credit link (`link-in-text-block`). axe cannot run with scripts off, so the scripts-off journey checks behavior only.
- **T3 (2026-10-25).** The doc 10 §6.4 set ran at dataset M, 1 and 10 VU, against WooCommerce 11.2.1 default and tuned (`tools/bench/tune-woocommerce.php`, the list is in the report). New bench pieces: `prep-catalog.php` (12 products per page and a `/collection/` page with each store's collection block, so both stores show the same catalog pages), the `cart-page` mode of `k6/cart.js`, `k6/journey.js`, and the host `wct.bench`. Report: `docs/benchmarks/2026-10-25-phase5.md`, summary in doc 10 §7.4. Queries, peak memory, CPU time and worker RSS are lower for FlexaCommerce on every scenario; rows read are higher on the listing and product pages. Latency is indicative on the laptop.
- **Visibility filter (found by T3).** The shop loop, the Query Loop variation and related products hid catalog-hidden products with a `meta_query`, which joined `wp_postmeta` twice and grouped and sorted every published product: about 150,700 rows read per listing page on dataset M, which has no hidden product. `ShopQuery::hide_in()` now sets the `fxc_visibility` query var, and a `posts_where` callback, added on the first query that needs it so P1 keeps its callback count, appends a `NOT EXISTS` subquery. Rows per listing page dropped to about 20,700 and the fxc shop page went from slower than WooCommerce to faster (p50 80 against 130 ms at 1 VU). `ShopQueryTest` checks that hidden products stay out and that the query has no join and no `GROUP BY`.
- **Not done.** L-home and L-list are not met (doc 10 §7.4): the listing pages still read about 20,700 rows against WooCommerce's 11,900 to 14,400, and they are measured against core's blog home, which runs no product query. P5 is over 100 ms at 10 VU on the laptop with unchanged counters. Every latency budget, C1 and the Redis set still need the reference machine (Q18).

## Phase 6: Migration

| Pri | Task |
|---|---|
| P0 | Migration tables repositories, `MigrationService`, `Runner` (lease, budgets, cursors), job state machine, ImportGuard |
| P0 | Canonical DTOs, validator, checksum |
| P0 | `WooSource` + `FxcTarget`: attributes, terms, products, customers, orders with items, transactions, refunds, notes |
| P0 | Tax classes, rates with locations, tax settings and product tax fields in both directions (doc 05 §5, ADR-020) |
| P0 | Preflight, dry run, verification report, admin migration screen, WP-CLI `migrate` commands |
| P0 | Cutover wizard, permalink adoption, redirect map |
| P0 | Golden fixtures in legacy, HPOS and HPOS+sync; crash/resume tests; ImportGuard tests |
| P0 | `FxcSource` + `WooTarget` (FXC→WC), round-trip test |
| P1 | Purge imported data command |
| P1 | Action Scheduler driver |
| P2 | Shipping zones migration |
| P3 | JSONL file transport (cross-site) |

Exit criteria: all migration tests green in all storage modes; 100,000-order synthetic job completes within the memory target, throughput recorded; P9 runs nightly; benchmark dataset also imported through migration and compared with the native load (doc 10 §6.2).

Implementation notes (phase 6, 2026-10-26):

- **Tests.** `tests/WooCommerce` runs against WooCommerce 11.2.1 (`tools/fetch-woocommerce.sh`); `composer test:woocommerce` runs it once per storage mode (legacy, HPOS, HPOS with sync). `MigrationService` takes final repositories, so its flows are tested there (`MigrationTest`, `CutoverTest`, `RoundTripTest`); `tests/Unit/Migration` covers the pure pieces (job state table, options, row decoding, decimals, dates, checksum).
- **Admin screen.** Migration is a submenu after Settings, with two tabs. Jobs: the list; a new-job dialog (direction, order dates, conflict policies, dry run) that creates the job and runs the preflight; the job page with the preflight report, backup confirmation, start, pause, resume and cancel, a choice between running batches from the tab and the background driver, progress per stage, the verification report with its differences and a download, purge, and a paged log filtered by level. The page polls every 5 s while the job is active. Cutover: one step at a time in the doc 05 §9.2 order; verify offers only finished WC→FXC runs and asks for an acknowledgement when the run has warnings; the deactivate step links to the Plugins screen and checks again; rollback is offered once any step after the freeze is done.
- **CutoverEndpoint** registers one route for every step and does not load `Cutover` at registration: all admin endpoints load on store REST requests, and the extra class pushed P6m over its budget.
- **Order numbers to WooCommerce.** The FlexaCommerce number goes to `_order_number` and `_fxc_order_number` (doc 05 §10); the reader treats both as mapped, so a round trip adds no extra meta.
- **P9.** `Runner::run_batch()` resets the peak at the start of each batch and returns the batch's own peak (`memory_get_peak_usage()`), which `wp flexacommerce migrate run` prints. A process-wide peak is the wrong number: it includes the preflight, and WP-CLI sets `memory_limit` to -1, so `php -d memory_limit=128M` proves nothing. `MigrationTest` checks every batch against 128 MB. Most of a batch's peak is the loaded site: WordPress with WooCommerce 11.2.1 is about 110 MB in WP-CLI on the bench stack (51 MB without WooCommerce; FlexaCommerce adds under 2 MB), and a batch adds 4 to 12 MB. The batch budget used to be an absolute 128 MB of `memory_get_usage()`, which would have given a site already over 128 MB one entity per batch; it now counts growth from the batch start (`memory_budget`, 64 MB by default), with a stop at 80 % of `memory_limit` so growth cannot reach the fatal error (doc 05 §4.4). `MigrationTest` covers both.
- **Cross-check** (doc 10 §6.2): the WooCommerce store of dataset M, migrated into FlexaCommerce on the same site and exported after WooCommerce is deactivated, passes the parity check against the spec (10,000 products, 36,828 variants). 10,329 entities in 540 s with highest batch peak 122.8 MB, measured while another job loaded the database, so the time is indicative.
- **Not done.** P9 does not run nightly: the CI workflow is off, so the check lives in the WooCommerce suite until CI is back. The 100,000-order job (doc 05 §11) has no recorded result: `tools/bench/synthetic-orders.php` generates the stores (`mig-hpos.bench`, `mig-legacy.bench`), but the first runs hit a full disk on the bench host and were discarded. A database error during a write is currently logged as a rejected entity, so such a run still ends "completed with warnings"; failing the job instead is open.

## Phase 7: Hardening and release

| Pri | Task |
|---|---|
| P0 | Full benchmark (doc 10 §6, dataset L), published report, fixes for missed targets or documented exceptions; regression thresholds (doc 10 §5.4) in force for release candidates |
| P1 | Invite a third party to rerun the published benchmark before 1.0 |
| P0 | Extension API docs (`docs/extending/`), API version constant, stability tags |
| P0 | PHPCS reconciliation, Plugin Check, `.pot`, readme.txt, screenshots |
| P0 | Release gates (doc 06 §7) |
| P1 | Example extension (a test gateway plugin) in a separate repository |
| P2 | Beta program with 3 to 5 stores (at least one migrating) |

Exit: **1.0 (M2)**.

## Post-1.0 candidates (ordered by expected demand, to be validated)

1. Online gateway add-on (Q4).
2. Tax presets for more markets (EU VAT, UK, Canada, Australia), VAT ID validation and tax reports, as add-ons or presets.
3. Coupons.
4. Shipping zones migration and cross-site transport.
5. Product lookup table if the revisit condition in doc 03 §5 triggers.
6. Public product-type API.

## Roadmap risks

| Risk | Impact | Mitigation |
|---|---|---|
| Tax decision (Q2) lands late | Checkout and emails rework | Decided 2026-10-25 after phase 4 (ADR-020). Phase 4.5 reworks the checkout quote, order lines, emails and refunds; the schema already had the tax columns, so no order data changes. |
| US rate data goes stale or is wrong in the preset | Stores charge the wrong tax | Preset carries `as_of` and sources, checked at each release; the screen states the owner's responsibility; local rates come from CSV or a provider add-on |
| Performance budgets found late | Rework of renderers and routes after they ship | Phase 2.5 puts the T1 gate in CI before phase 3 code exists (doc 10) |
| Variant admin UI larger than estimated | Phase 2 slips | Ship bulk edit as P1, generation and per-row edit as P0 |
| WooCommerce data diversity (plugins writing odd meta) | Migration rejects or warnings explode | Spike in phase 2, beta stores in phase 7, preserve-unknown policy |
| HPOS behavior changes in a WooCommerce release | Migration breakage | CRUD-only access, nightly test against latest WooCommerce |
| Single developer bandwidth | Timeline | Phases 4 and 5 can run in parallel with a second developer once cart API is frozen |
