# 06. Security, Performance, Testing and Compatibility

Status: **Approved 2026-10-24**. All performance numbers in this document are **targets**. No benchmark has been run. Results will be published only after the protocol in §4 has been executed.

## 1. Threat model (summary)

| Asset | Threats | Primary controls |
|---|---|---|
| Order totals and prices | Client-side price tampering, replayed carts, race conditions | Server-side recalculation inside the checkout transaction (doc 02 §7.5), no prices in cart or forms |
| Stock | Oversell under concurrency, double restore | Conditional atomic UPDATE, `stock_reduced_qty` idempotency (doc 02 §7.4) |
| Payment state | Forged webhooks, replays, out-of-order events, forged return URLs | Signature verification, `UNIQUE(gateway,event_id)`, terminal states, returns are hints (doc 02 §7.8) |
| Customer personal data | Order enumeration (IDOR), data leaks through caches, logs and REST | Order key + ownership checks, `no-store` headers, redaction, privacy exporter/eraser |
| Admin functions | CSRF, privilege escalation, missing capability checks | REST nonces, per-route capabilities, `Support\Capabilities` |
| Gateway secrets | Leaks via REST, logs, exports | Masked settings, redaction, never exported |
| Site availability | Checkout flooding, card testing (once online gateways exist), expensive queries | Rate limits, bounded queries, indexed filters |

## 2. Security controls

### 2.1 Capabilities

| Capability | Grants | Default roles |
|---|---|---|
| `manage_flexacommerce` | settings, gateways, shipping, migration, status/logs, uninstall data option | administrator |
| `view_fxc_orders` | order list and detail, customers list | administrator, fxc_store_manager |
| `edit_fxc_orders` | transitions, notes, addresses, record payment, cancel | administrator, fxc_store_manager |
| `refund_fxc_orders` | refunds (separate because it moves money) | administrator, fxc_store_manager |
| `fxc_product` CPT caps (`edit_fxc_products`, ...) | catalog | administrator, fxc_store_manager |

Every check goes through `Support\Capabilities` with filters `flexacommerce.capabilities.{name}` (house rule). Multisite super admins pass through core `map_meta_cap` behavior.

### 2.2 Admin REST (`flexacommerce/v1/admin/*`)

- Cookie auth + `X-WP-Nonce` (`wp_rest`), or application passwords for integrations.
- Every route has a real `permission_callback` naming a capability. A unit test (`RoutesHavePermissionsTest`) iterates `rest_get_server()->get_routes()` for our namespace and fails if any callback is `__return_true` outside the store and webhook families.
- Arguments declared with `type`, `enum`, `minimum`/`maximum`, `sanitize_callback`, `validate_callback`. Unknown arguments are ignored.

### 2.3 Store API (`flexacommerce/v1/store/*`) and storefront forms

WordPress nonces are tied to the user id and session token. For logged-out visitors both are empty, so a core nonce is the same for every guest and gives no CSRF protection. The store API therefore uses its own token:

- `X-FXC-Nonce` = `base64url( HMAC-SHA256( wp_salt('nonce'), session_token_hash | 'store' | tick ) )`, valid for two 12-hour ticks. It is returned by `GET store/cart` and in every store response, never embedded in cacheable HTML.
- Mutating store requests (`POST/PUT/DELETE`) require:
  1. `Content-Type: application/json` (cross-origin forms cannot send it without a CORS preflight; we send no `Access-Control-Allow-*` headers), and
  2. an `Origin` header (or `Referer` when Origin is absent) matching `home_url()` host, and
  3. when a session exists, a valid `X-FXC-Nonce`.
- The first add-to-cart (no session yet) is allowed with checks 1 and 2 only. The worst case of a forged first request is an item placed in a visitor's cart, which is accepted as low impact.
- Checkout additionally requires the `checkout_token` issued with the form and bound to the session, which works as a CSRF token and an idempotency key.
- No-JS form posts (`admin-post.php`) use a hidden HMAC token with the same construction, plus the Origin/Referer check.
- Cookie `fxc_session`: `HttpOnly`, `Secure` when the site is HTTPS, `SameSite=Lax`, path `COOKIEPATH`.

### 2.4 Input validation and output escaping

- All input is validated at the boundary (REST schema, `CheckoutValidator`, admin forms) into typed DTOs. Services never read `$_POST` / `$_GET`.
- Escaping at output with `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` (product description), `wp_json_encode` for Interactivity state. Email templates escape the same way.
- Addresses and names are plain text: stripped of tags, length-limited to the column sizes in doc 03.
- Phone and postcode validated per country with permissive rules (format hints, no rejection of unusual but valid formats).

### 2.5 SQL

- All SQL lives in repositories and uses `$wpdb->prepare()` with `%i` for identifiers (WP 6.2+). No string interpolation of values.
- `IN (...)` lists are built with `implode( ',', array_fill( 0, n, '%d' ) )` and prepared.
- `ORDER BY` columns come from a whitelist.
- phpcs `WordPress.DB` rules are disabled only at class level in repository classes, with a justification comment (house rule).
- Static check: a CI grep rejects `$wpdb->` outside `*Repository.php`, `Database/`, `Support/Transaction.php` and migration adapters.

### 2.6 Payments and webhooks

- Covered in doc 02 §7.7, §7.8 and doc 04 §3.4.
- Webhook endpoint: body size limit 1 MB, JSON only, no authentication cookies honored (requests run as user 0), gateway resolved from the URL, signature verified before any DB write.
- Amount and currency of every payment event are compared with the transaction; mismatch sets `needs_attention`.

### 2.7 Guest order access

Order key (32 random bytes) + `hash_equals()`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer` on key-accessed pages. Order ids are never accepted alone on public routes. Failed key attempts are rate limited (§2.9).

### 2.8 Privacy

- Exporters and erasers for orders (by billing email and user), addresses, sessions, saved user addresses.
- Erasure anonymizes (`anonymized_at`), keeps amounts for accounting, blanks IP, names, emails, phones, addresses, customer-visible note text.
- Logs never contain full addresses, emails (hashed with a site salt for correlation), card data, or secrets. The logger has a redaction pass for keys matching sensitive patterns.
- Retention settings: webhook events 90 days, sessions on expiry, logs 30 days, optional order anonymization after N months.

### 2.9 Rate limiting and abuse

| Action | Limit (default) | Key | Storage |
|---|---|---|---|
| Checkout attempts | 10 per 10 min | session, then IP | session row + transient per IP (only written on checkout) |
| Add to cart | 60 per min | session | session row |
| Order key failures | 10 per hour | IP | transient |
| Order lookup failures (number + email, ADR-057) | 10 per hour | IP | transient |
| Customer cancel | 5 per hour | order | order history count |

Limits are filterable. IPs come from `REMOTE_ADDR` unless the admin configures a trusted proxy header (no automatic trust of `X-Forwarded-For`). The site names the header with the filter `flexacommerce.client_ip_header` (for example `HTTP_X_FORWARDED_FOR`; there is no settings field for it in 1.0). The header is read from the right: each proxy appends the address it saw, so the left entries are whatever the client sent. The client is the right-most public address in the list; private and reserved hops (internal proxies) are skipped, and a list of only internal hops yields the nearest one.

### 2.10 Secrets and settings

Gateway secrets are stored in non-autoloaded options, shown masked, excluded from REST responses, system report and migration exports. Settings endpoints reject masked placeholder values on save so a masked value cannot overwrite the secret.

### 2.11 Supply chain and packaging

No runtime composer or npm dependencies in the shipped plugin. Built JS/CSS ship with source (`client/`) for WordPress.org review (Guideline 4). `.distignore` excludes `docs/`, tests, tooling and every `*.md` file. The only readme in the zip is `readme.txt`.

### 2.12 Security review gate

Before M1 and before 1.0: a checklist review (OWASP ASVS L1 subset relevant to WordPress plugins), `RoutesHavePermissionsTest`, the Plugin Check plugin with zero errors, manual review of every `permission_callback`, nonce check and escaping site, and a dependency-free build audit.

## 3. Performance targets

Doc 10 (performance architecture) refines these targets: per-scenario budgets, the test tier that enforces each one, and regression thresholds. Where the two differ, doc 10 §4 wins. The 2026-10-24 audit (doc 10 §1) found P1 failing by one query.

| Id | Target | Measured how (§4) |
|---|---|---|
| P1 | Non-commerce front-end request (blog post, no FlexaCommerce block): **0** extra DB queries, ≤ **2 ms** added PHP time, ≤ **0.5 MB** added memory, **0** bytes of FlexaCommerce CSS/JS | plugin active vs inactive, same request, each in a fresh PHP process (doc 10 §5.2; an in-process test cannot see boot cost) |
| P2 | Shop archive (24 products): ≤ **2** queries beyond core's `WP_Query` and term queries, independent of product count on the page (variant prime = 1 query) | `SAVEQUERIES` count, two page sizes |
| P3 | Single product page (30 variants): ≤ **3** extra queries | `SAVEQUERIES` |
| P4 | `POST store/cart/items` p95 ≤ **150 ms** server time | load test, dataset M |
| P5 | `GET store/cart` p95 ≤ **100 ms** | load test |
| P6 | Checkout (COD, 5 lines) p95 ≤ **500 ms** excluding gateway calls | load test, 10 concurrent |
| P7 | Admin order list (100,000 orders, filter by status, page 1 and page 200) ≤ **300 ms** server time | timer, `EXPLAIN` shows index use |
| P8 | Storefront assets on product pages ≤ **15 KB** JS gzip, ≤ **10 KB** CSS gzip | build report |
| P9 | Migration batch peak memory < **128 MB** | `memory_get_peak_usage()` per batch |
| P10 | Autoloaded options: 1 (`flexacommerce_settings`), ≤ **10 KB** | option size check |
| P11 | Catalog pages set no cookie and send no `Cache-Control: no-cache` from FlexaCommerce | header check |

A target missed at release is either fixed or documented as a known limitation with the measured value. It is never silently dropped.

## 4. Benchmark protocol

The full methodology (test tiers, stack, dataset parity, WooCommerce configurations, scenarios, reported metrics) is in doc 10 §5 and §6. This section keeps the summary.

- **Environment:** document CPU, RAM, OS, PHP version with OPcache enabled, MySQL/MariaDB version and `innodb_buffer_pool_size`, web server. Primary runs with **no persistent object cache**; a second run with a persistent object cache drop-in is reported separately (test environment only, not a dependency).
- **Datasets**, deterministic: `tools/bench/generate-dataset.php --size=S|M --seed=42` writes a neutral spec, and one loader per store writes it natively (doc 10 §6.2). Dev tooling, excluded from the release zip. Size L is added in phase 7.

| Size | Products | Variants per variable product | Orders | Customers |
|---|---|---|---|---|
| S | 1,000 (30 % variable) | 6 | 10,000 | 2,000 |
| M | 10,000 | 12 | 100,000 | 20,000 |
| L | 50,000 | 12 | 1,000,000 | 200,000 |

- **Comparison baseline:** WooCommerce (latest stable) with the same dataset, loaded natively from a neutral dataset spec and checked for parity until migration exists, then also imported through our own migration (doc 10 §6.2), same theme (Twenty Twenty-Five), same server, only one commerce plugin active per run.
- **Method:** 50 warm-up requests, then 500 measured requests per scenario, 3 runs, report median of runs for p50/p95/p99, plus query count and peak memory from a measurement mu-plugin (`SAVEQUERIES`, `timer_stop()`, `memory_get_peak_usage()`). Load tests with k6 (developer tool, not shipped) at 1, 10 and 25 virtual users.
- **Scenarios:** P1 to P7 above, plus "browse 5 pages, add 2 items, checkout" journey.
- **Publication:** raw results, scripts, environment description in `docs/benchmarks/{date}.md`. Marketing statements only quote published results (doc 01 §2).

## 5. Testing strategy

### 5.1 Layers

| Layer | Tool | Scope | Runs |
|---|---|---|---|
| Static | phpstan level 6 (level 8 for `Contracts/` and `Money`), PHPCS (WordPress standard, house exceptions), `php -l` on min PHP, ESLint + TypeScript strict | all code | every push |
| Architecture | `tests/unit/Architecture/DependencyRulesTest.php` (parses `use` statements and enforces doc 02 §3.2) | module boundaries | every push |
| Unit | PHPUnit, no WordPress loaded | Money, pricing, VariantMatcher, combination normalization, OrderStateMachine (every pair in the transition table), transaction state rules, canonical mappers, validators, session token, HMAC nonce | every push |
| Integration | PHPUnit + WordPress test suite on a real MySQL/MariaDB | repositories, schema install/upgrade, services with DB, REST routes, permissions, privacy exporter/eraser, cron handlers, ImportGuard | every push (matrix nightly) |
| Concurrency | multi-process PHP harness (§5.4) | stock, checkout idempotency, webhook duplicates | nightly + before release |
| Migration | golden fixtures, round trip, crash/resume (doc 05 §11) | all storage modes | nightly + before release |
| E2E | Playwright against `wp-env` | storefront journeys, admin order flows, block and classic theme | nightly + before release |
| Accessibility | axe-core in Playwright + manual keyboard/screen reader pass for checkout | storefront, admin screens | before release |
| Performance | §4 protocol, plus CI query-count assertions for P1, P2, P3 | | P1 to P3 every push, full protocol before release |

### 5.2 Critical unit test examples

- `Money::from_decimal_string( '19.995', 'USD' )` → 2000 with precision warning; `'0.1' + '0.2'` sums exactly; JPY and KWD exponents.
- Allocation of a 10.00 refund across 3 lines sums to exactly 1000.
- Variant matching: exact beats wildcard; tie-break by menu_order then id.
- State machine: every allowed transition in doc 02 §7.6 passes, every other pair fails.

### 5.3 Critical integration tests

- SKU unique across simple base row, variable base row and variations; empty SKU stored as NULL many times.
- Checkout: totals changed between form and submit; disabled gateway; unavailable shipping rate; out of stock; replayed `checkout_token` returns the same order; order key access; non-owner access denied.
- Refund: partial with restock restores exactly the chosen quantities once; second refund cannot exceed paid.
- Uninstall: data kept by default; removed only with the option.
- Query counts: archive page with 12 and 48 products issues the same number of FlexaCommerce queries.

### 5.4 Concurrency tests

Harness: the test spawns N PHP worker processes (`proc_open` running a WP-CLI `eval-file` script), all waiting on a barrier file, then calling the service at the same moment.

| Test | Setup | Assertion |
|---|---|---|
| Last unit | stock = 1, 20 workers place an order for 1 unit | exactly 1 order, stock = 0, 19 `InsufficientStock` |
| Last k units | stock = 5, 20 workers × 1 | exactly 5 orders, stock never negative |
| Multi-line deadlock | 10 workers ordering variants {A,B} and {B,A} | no deadlock surfaces to the user (retry works), stock correct |
| Duplicate checkout | 5 workers with the same `checkout_token` | 1 order |
| Webhook storm | same event delivered 10 times concurrently | 1 state change, 1 history row |
| Cancel race | cron expiry and customer cancel on the same order | stock restored once |
| Cart version conflict | two writers on one session | both mutations applied or one reported, never lost silently |

### 5.5 CI matrix

Push: PHP 8.2 and 8.5, latest WP, MySQL 8.0. Nightly: full matrix of §6 (PHP × WP × DB, with and without WooCommerce, HPOS on/off/sync). Multisite run nightly.

## 6. Compatibility matrix

| Component | Minimum | Tested | Notes |
|---|---|---|---|
| PHP | 8.2 | 8.2, 8.3, 8.4, 8.5 | 8.2 security support ends 31 Dec 2026, so the minimum may move to 8.3 before 1.0 (Q5) |
| WordPress | 6.9 | 6.9, 7.0, 7.1 | Needs APIs from 6.5 to 6.8 (doc 02 §6.3); 6.9 aligns with WooCommerce 11 for migration sites |
| MySQL | 5.7 | 8.0, 8.4 | InnoDB required |
| MariaDB | 10.4 | 10.6, 10.11, 11.4 | |
| WooCommerce (migration only) | 9.0 (Q6) | latest 9.x, latest 10.x, 11.0 | legacy, HPOS, HPOS + sync |
| Themes | | Twenty Twenty-Five, Twenty Twenty-Four (block), one classic theme (Q9) | |
| Multisite | supported | per-site activation and network activation | |
| Object cache | none required | none, persistent drop-in | |
| Browsers | | last 2 versions of Chrome, Firefox, Safari, Edge; iOS Safari; Android Chrome | no-JS checkout path tested in one browser |
| Page cache | | one popular page cache plugin, documented exclusions | P11 |

Recommended production stack (readme): PHP 8.3+, MySQL 8.0+ or MariaDB 10.11+, HTTPS, real system cron.

## 7. Release gates

| Gate | M1 | 1.0 |
|---|---|---|
| phpstan L6 clean, L8 for Contracts | ✔ | ✔ |
| PHPCS reconciliation pass (house rule) | | ✔ |
| All unit, integration, architecture tests green on matrix | ✔ | ✔ |
| Concurrency suite green | ✔ | ✔ |
| Migration suite green in all storage modes | | ✔ |
| E2E + axe (no serious or critical issues) | ✔ | ✔ |
| Security review gate (§2.12) | ✔ | ✔ |
| Plugin Check: zero errors | ✔ | ✔ |
| P1 to P3 query assertions | ✔ | ✔ |
| Full benchmark published | | ✔ |
| Doctor: zero orphans after full suite | ✔ | ✔ |
| Extension API v1 docs and two core implementations per contract | | ✔ |
| `.pot` regenerated, readme, release zip built with `release.sh` and checked for stray files | ✔ | ✔ |
