# 07. Implementation Roadmap

Status: **Approved 2026-10-24**, phase 1 complete (exit criteria verified locally and in CI), phase 2 complete (its browser pass was done in phase 3), phase 2.5 and phase 3 complete except the latency rerun on a reference machine. Effort figures are rough estimates for one experienced WordPress/PHP developer with part-time frontend help, given only to compare phases; they are not commitments.

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
| P2 | `TaxCalculator` seam hardening (depends on Q2) |

Exit criteria: full concurrency suite green; state machine transition table fully covered; security review gate (doc 06 §2.12) passes for checkout, store and admin order routes.

Performance exit: P6q (fixed part + exactly 1 query per line, checked at 1 and 10 lines) and P6m binding; gateway and shipping classes absent from catalog requests (T1 allowlist, O7); T3 P6 and C1 within budget with 0 oversells; worker RSS (C2) reported.

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

## Phase 6: Migration

| Pri | Task |
|---|---|
| P0 | Migration tables repositories, `MigrationService`, `Runner` (lease, budgets, cursors), job state machine, ImportGuard |
| P0 | Canonical DTOs, validator, checksum |
| P0 | `WooSource` + `FxcTarget`: attributes, terms, products, customers, orders with items, transactions, refunds, notes |
| P0 | Preflight, dry run, verification report, admin migration screen, WP-CLI `migrate` commands |
| P0 | Cutover wizard, permalink adoption, redirect map |
| P0 | Golden fixtures in legacy, HPOS and HPOS+sync; crash/resume tests; ImportGuard tests |
| P0 | `FxcSource` + `WooTarget` (FXC→WC), round-trip test |
| P1 | Purge imported data command |
| P1 | Action Scheduler driver |
| P2 | Shipping zones migration |
| P3 | JSONL file transport (cross-site) |

Exit criteria: all migration tests green in all storage modes; 100,000-order synthetic job completes within the memory target, throughput recorded; P9 runs nightly; benchmark dataset also imported through migration and compared with the native load (doc 10 §6.2).

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
2. Tax engine (if Q2 deferred it).
3. Coupons.
4. Shipping zones migration and cross-site transport.
5. Product lookup table if the revisit condition in doc 03 §5 triggers.
6. Public product-type API.

## Roadmap risks

| Risk | Impact | Mitigation |
|---|---|---|
| Tax decision (Q2) lands late | Checkout and emails rework | Decide before phase 4 starts. Schema already carries tax columns. |
| Performance budgets found late | Rework of renderers and routes after they ship | Phase 2.5 puts the T1 gate in CI before phase 3 code exists (doc 10) |
| Variant admin UI larger than estimated | Phase 2 slips | Ship bulk edit as P1, generation and per-row edit as P0 |
| WooCommerce data diversity (plugins writing odd meta) | Migration rejects or warnings explode | Spike in phase 2, beta stores in phase 7, preserve-unknown policy |
| HPOS behavior changes in a WooCommerce release | Migration breakage | CRUD-only access, nightly test against latest WooCommerce |
| Single developer bandwidth | Timeline | Phases 4 and 5 can run in parallel with a second developer once cart API is frozen |
