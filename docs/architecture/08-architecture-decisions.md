# 08. Architecture Decision Records

Status of every ADR: **Accepted** (plan approved 2026-10-24) unless it says otherwise; ADR-015 is superseded by ADR-020. Format: context, decision, alternatives, consequences, revisit when.

---

## ADR-001: One `fxc_variants` table for all purchasable rows, including simple products

**Context.** The initial proposal stored simple products in postmeta and variations in a custom table. WooCommerce stores variations as child posts and needs lookup tables to make them queryable.

**Decision.** Every product has exactly one base row in `fxc_variants`. Simple products are purchasable through their base row; variable products buy through variation rows, and their base row holds shared stock and defaults (doc 03 §3).

**Alternatives.** Variations as child posts; variants as JSON meta; split storage (initial proposal). Compared in doc 03 §3.8.

**Consequences.** One SKU index, one stock code path, one query to prime a page. Variants have no revisions or post meta API; extensions use the `meta` JSON column. The admin needs a custom variant editor.

**Revisit when.** A product type needs per-variant content that only posts provide (for example per-variant pages for SEO).

---

## ADR-002: Module-first source layout

**Context.** House plugins group code by technical layer (`Api/`, `Domain/`). A commerce plugin has many domains that change together.

**Decision.** `src/{Module}/` per domain (Catalog, Pricing, Inventory, Cart, Shipping, Checkout, Payments, Orders, Migration, ...) with shared `Support/`, `Database/`, `Api/`, `Contracts/`. Allowed dependencies are listed in doc 02 §3.2 and enforced by `DependencyRulesTest`.

**Alternatives.** Layer-first (house default). Rejected: a change to orders would touch five sibling folders and the dependency rules could not be expressed per module.

**Consequences.** Departure from house layout, documented. Contributors must read the dependency table.

**Revisit when.** Never for 1.x; the test makes boundary drift visible.

---

## ADR-003: Hand-written composition root

**Decision.** `Support\Services` with one explicit lazy factory per service. `HasInstance` only for stateless registrars and REST endpoints (doc 02 §4.2).

**Alternatives.** `HasInstance` everywhere (hidden dependencies, no fakes in unit tests); a DI container library (runtime dependency for about 30 factories).

**Consequences.** A little boilerplate per service; dependencies are visible in one file; no reflection cost.

**Revisit when.** The factory count passes about 80 or factories start to duplicate wiring logic.

---

## ADR-004: Stock is owned by exactly one row, never inherited implicitly

**Decision.** A variation either manages its own stock or explicitly uses the base row's stock (`stock_from_base = 1`). `StockService` resolves the owner row before locking. Only weight and dimensions fall back to the base row (doc 03 §3.4, §3.6).

**Alternatives.** WooCommerce-style implicit fallback chains for stock, price and status. Rejected: implicit rules make the stock row to lock ambiguous and are a known source of oversell bugs.

**Consequences.** Migration maps WooCommerce `manage_stock = 'parent'` explicitly. Admin UI must show where stock lives.

**Revisit when.** Not expected.

---

## ADR-005: The order is the reservation

**Context.** The brief proposed a stock reservations table.

**Decision.** Stock is deducted at order placement inside the checkout transaction with a conditional atomic UPDATE in ascending variant id order. Unpaid `pending` orders hold stock for `hold_minutes` (default 60) and are then cancelled by cron. `stock_reduced_qty` per item makes restore idempotent (doc 02 §7.4).

**Alternatives.** Cart-level timed reservations (cleanup system, more races, stock locked by browsing carts); deduct on payment (oversell between order and payment for online gateways).

**Consequences.** No reservations table. Abandoned online payments hold stock up to `hold_minutes`. Customers can still see "in stock" and fail at checkout when the last unit goes, which is reported per line.

**Revisit when.** Online gateways show long payment windows causing complaints, or flash-sale use cases appear.

---

## ADR-006: Global attributes as one hierarchical taxonomy

**Decision.** `fxc_attribute` taxonomy: attributes are top-level terms, values are child terms. Products are assigned the value terms they use (doc 03 §3.2).

**Alternatives.** One taxonomy per attribute registered at runtime (WooCommerce `pa_*`): needs a definitions table and dynamic registration on every request. Attributes in a custom table: loses `tax_query` filtering.

**Consequences.** Filters use `tax_query` with child term ids. Term slugs are unique across all attributes (`red` under Color and under Pattern need distinct slugs, handled by core suffixing; ids are used internally).

**Revisit when.** A store needs more than about 50,000 attribute values.

---

## ADR-007: Money as integer minor units

**Decision.** `bigint` minor units + `currency` + snapshotted exponent; decimal strings at boundaries; half-up rounding; no floats (doc 03 §2.1).

**Alternatives.** `decimal(19,4)` (house schema style, WooCommerce strings): exact in SQL but PHP reads strings and arithmetic tends to drift into floats.

**Consequences.** Every boundary converts. Display formatting goes through `Money::format()`. Currencies with non-ISO exponents need the filter.

**Revisit when.** Multi-currency or crypto with large exponents is planned.

---

## ADR-008: Server-side cart sessions with a lazy cookie

**Decision.** `fxc_sessions` table, random token in cookie `fxc_session`, SHA-256 hash stored, cookie set on the first cart write only, versioned writes (doc 02 §7.3.1).

**Alternatives.** Cart in cookie or localStorage (tampering, size limits, prices must still be recomputed); WooCommerce-style session on every visit (breaks page caching).

**Consequences.** One write per cart mutation; catalog pages stay cacheable; a purge cron is needed.

**Revisit when.** Session table growth becomes a problem on high-traffic stores (move purge to real cron, shorten guest lifetime).

---

## ADR-009: Shared renderers for blocks, templates and shortcodes

**Decision.** `Storefront\Render\*` produces the HTML; blocks, classic templates and shortcodes are thin adapters (doc 02 §6.3).

**Alternatives.** Blocks only (classic themes unsupported); separate implementations per entry point (behavior drift).

**Consequences.** Renderers must accept context from three sources. One test suite covers all entry points.

**Revisit when.** Classic theme support is dropped.

---

## ADR-010: Custom order tables from day one

**Decision.** Orders live in `fxc_orders` and child tables, not posts (doc 03 §4).

**Alternatives.** `fxc_order` CPT: free admin list and meta API, but the scalability problems WooCommerce spent years leaving behind with HPOS.

**Consequences.** Custom admin list, custom privacy hooks, custom search. Easy mapping to HPOS during migration.

**Revisit when.** Not expected.

---

## ADR-011: Migration through a canonical format, WooCommerce CRUD only, same-site transport in MVP

**Decision.** Four adapters around one canonical record format; WooCommerce is read and written only through CRUD (raw SQL only for id cursors); MVP transport is in-process on one site; the format is ready for JSONL files later (doc 05).

**Alternatives.** Direct SQL copy (breaks on HPOS sync, plugin hooks, future schema changes); WooCommerce REST API (slow, auth setup, still needs mapping); per-direction custom code (four times the mapping bugs).

**Consequences.** Slower than raw SQL; `woocommerce_new_order` listeners see FXC→WC imports; behavior follows WooCommerce's own rules in all storage modes.

**Revisit when.** Cross-site migration becomes a priority (add file transport, no core change).

**Revisited 2026-10-26.** File transport added in phase 6 (doc 05 §4.6) as planned: a JSONL file read through a fourth source, `FileSource`, with no change to the canonical format, stages or targets. The only additions outside `Transport\` are an id map scope per source site and a `Relocation` hook in `Mapping` for user and attachment ids.

---

## ADR-012: WP-Cron and a transport-agnostic runner, no queue library

**Decision.** Bounded idempotent WP-Cron jobs for maintenance; migration batches through `Runner::run_batch()` driven by admin polling, WP-CLI or Action Scheduler when WooCommerce provides it (doc 02 §6.6).

**Alternatives.** Bundle Action Scheduler (dependency and tables for a store that may never migrate); custom queue tables.

**Consequences.** Low-traffic sites with WP-Cron depend on visits; docs recommend system cron. Expiry of pending orders may run late on such sites, which only delays stock release.

**Revisit when.** Features need reliable delayed jobs at scale (subscriptions).

---

## ADR-013: No infrastructure dependencies

**Decision.** Runs on standard WordPress hosting: no Redis, Elasticsearch, Node at runtime, queue servers or frontend framework. Persistent object caches are used when present, never required. No runtime composer or npm packages.

**Consequences.** Search uses core search plus SKU lookup; heavy catalogs rely on indexed columns and the revisit condition in doc 03 §5.

**Revisit when.** Catalogs well beyond assumption A1 become a target market.

---

## ADR-014: No foreign key constraints

**Decision.** Integrity enforced in repositories and transactions, checked by `Doctor` (doc 03 §2.8).

**Alternatives.** InnoDB FKs: `dbDelta` does not manage them, WordPress core tables cannot be referenced reliably, and host migrations/backups often break on them.

**Consequences.** Orphans are possible after manual DB edits; Doctor finds them.

**Revisit when.** Not expected.

---

## ADR-015: Tax as a seam in MVP pending Q2

**Status.** Superseded by ADR-020 (2026-10-25). Kept for history.

**Decision.** Schema stores tax per line and per order. `TaxCalculator` interface with `NullTaxCalculator` default; prices are final as entered. A tax engine is a separate scope decision (Q2).

**Consequences.** Stores that must show tax lines cannot launch on M1 unless Q2 changes the scope. Migrated orders keep their historic tax amounts.

**Revisit when.** Q2 is answered.

---

## ADR-016: One transactions table for payments and refunds

**Decision.** `fxc_transactions` with `type = payment | refund`, shared state model, idempotency key and gateway reference (doc 03 §4.7).

**Alternatives.** Separate payments and refunds tables (house precedent in stay-suite).

**Consequences.** One query for paid/refunded sums; refund lines live in JSON.

**Revisit when.** Partial captures or multi-tender payments need their own structures.

---

## ADR-017: Narrow extension facade, no WooCommerce hook emulation

**Decision.** Extensions receive `Contracts\Extensions`, not the service container. Public contracts are versioned separately from the plugin; `woocommerce_*` hooks are never fired (doc 04).

**Consequences.** WooCommerce extensions do not work on FlexaCommerce (a stated non-goal). The API can stay small and stable.

**Revisit when.** A compatibility layer is requested by enough users to justify a separate plugin.

---

## ADR-018: Own CSRF token for the store API

**Decision.** `X-FXC-Nonce` HMAC bound to the cart session, JSON content type, Origin check, `checkout_token` (doc 06 §2.3).

**Alternatives.** Core `wp_rest` nonce: identical for all guests and embedded in HTML that page caches share.

**Consequences.** Cached catalog pages print the guest nonce, which is the same for every visitor without a cart session; a guest who has a session still passes with it, so a cached page works without a retry. For guests the real defence is the JSON content type, the same-site Origin check and the SameSite=Lax cookie; for users the nonce is bound to the login session. A stale nonce is answered with 403 and a fresh nonce in `X-FXC-Nonce`, and the script retries once.

**Revisit when.** WordPress core adds session-bound nonces for guests.

---

## ADR-019: Product editor stays in the post editor, built to move out

**Decision.** In MVP, product data is a React metabox in the block editor. The editor is split into a draft hook (`useProductDraft`), a mount-agnostic panel (`ProductDataPanel`) and a metabox host (`ProductEditor`). The admin REST API takes the whole product, post fields included (`admin/products` list, create, read, update, delete). `flexacommerce.admin.product_editor` (`post` or `app`) turns the metabox off for a future standalone editor.

**Alternatives.** A standalone React editor now: more work before the storefront exists, and it would have to rebuild title, content and media editing that the block editor already gives. Keeping the editor as one metabox component: a later app would need a rewrite of the save and error logic.

**Consequences.** A standalone editor needs no new endpoint and no change to the panel; it adds a screen, post field inputs and a host that saves the whole payload in one request. Until then the `app` value only removes the metabox, so nothing ships that is switched off. There is no setting until the screen exists.

**Revisit when.** The standalone editor ships: then decide whether `app` becomes the default and whether the post editor redirects to it.

---

## ADR-020: Tax engine with WooCommerce-equivalent options, built per market, US first

**Context.** Q2 was answered on 2026-10-25: taxes ship in M1 with the options a WooCommerce store owner knows, they must be easy to adapt to each market, and the US market comes first. US sales tax is the hardest common case for a rate table: state, county, city and special district rates stack, the rate depends on the ZIP code, a store collects only in states where it has nexus, shipping is taxable in some states and not in others, and some states source in-state sales at the seller's address. ADR-015 left a single-total `TaxCalculator` seam, which cannot produce per-rate order lines or refund tax correctly.

**Decision.**
1. **Core engine, table based** (`Tax\TaxEngine`), integer-only and deterministic: rates in a table (`fxc_tax_rates`, `fxc_tax_rate_locations`, doc 03 §4.13) matched by country, state, postcode (exact, `*` wildcard, numeric range) and city, one rate per priority, compound rates after simple ones, per-line half-up rounding or rounding at subtotal. The options mirror WooCommerce's (doc 02 §7.12): enable taxes, prices entered with or without tax, calculate on shipping, billing or store base address, shipping tax class, rounding, shop and cart display, price suffix, tax totals as one line or itemized, tax classes, product tax status. Off by default, and with taxes off nothing in this ADR runs.
2. **Per-market layers on top.** (a) **Presets**: JSON files that create the rate rows and recommended settings for one market; the US preset ships first, with state base rates for the states the admin selects as nexus states. (b) **Market overrides**: a market is a list of countries that can override display, suffix, rounding, address basis and in-state sourcing. (c) **`TaxRateProvider`** interface: the table is the default provider; an add-on can supply rates from a service (TaxJar, Avalara) without replacing the math. (d) **`TaxCalculator`** interface for an add-on that must replace the whole calculation (product tax codes). (e) Filters for exemptions and rate changes (doc 04 §7).
3. **Order storage per rate.** Every line keeps its tax per rate; the order gets one `tax` line per rate (doc 03 §4.3). Refunds split tax per rate pro rata.
4. **The experimental `TaxCalculator::tax(): Money` is replaced** by `calculate( TaxRequest ): TaxResult` (doc 04 §7.1). It was tagged experimental and no release has shipped, so no deprecation period applies.

**Alternatives.** Keep the seam and leave taxes to add-ons: a US store could not take orders on M1 without a paid service, and the stores migrating from WooCommerce lose the rate table they already have. Ship a single rate per country (the old Q2 fallback): wrong for every US store, where the rate changes by ZIP code. Bundle a full US rate database (about 13,000 jurisdictions, rates change monthly): FlexaCommerce would then be responsible for keeping it current, which a free plugin cannot promise; this stays with CSV imports and provider add-ons.

**Consequences.** Phase 4.5 (about 4 weeks) is added before phase 5 (doc 07). Phase 6 can migrate WooCommerce tax rates and product tax settings. The preset files carry an `as_of` date and the admin screen says the store owner is responsible for the rates; FlexaCommerce never changes rates on its own. Cost: 0 extra queries with taxes off; with taxes on, catalog pages still read no tax table (the base-location rates are compiled into the settings option), cart and checkout read at most 1 extra query per distinct address per request (doc 10 §4.1). VAT ID validation, tax reports and filing stay out of core.

**Revisit when.** A second market preset is requested (EU VAT is the expected next one: prices inclusive, base-location adjustment), a provider add-on needs a contract change, or a store's US table grows past 100,000 location rows (§4.13 index review).

## ADR-021: Coupons with fixed rules in their own tables, applied in sequence

**Context.** After phase 6 the user picked coupons as the next feature (2026-10-26). Stores coming from WooCommerce expect percent and fixed codes with expiry, spend limits, usage limits and product or category rules. doc 03 §8 had left coupon tables post-MVP on the grounds that `discount` order lines cover history; that holds for past orders but not for checking a code in the cart. Coupons touch cart pricing, taxes and the order transaction, so the rules for when a code applies, how codes combine and when a use is counted have to be fixed before any code is written.

**Decision.**
1. **Own tables** (doc 03 §4.14): `fxc_coupons` with one row per code and the list rules in a JSON column, and `fxc_coupon_uses` with one row per order and coupon. No custom post type: the limits need a counter that checkout can lock, and a post meta counter cannot be updated under a row lock with the limit in the same statement.
2. **Three types** matching WooCommerce: `percent`, `fixed_cart`, `fixed_product`. Rules: products, categories (with their parents), sale items, spend bounds, start and end, total and per-customer limits, allowed emails, individual use. No hooks change the amount in 1.0 (doc 04 §7.0).
3. **Sequential application.** Codes apply in the order they were added, each to what the earlier ones left of every line (doc 02 §7.13.2). WooCommerce has a setting for this; FlexaCommerce has only the sequential mode, which gives the same result whatever the code types and never discounts below zero.
4. **Taxes follow the discounted price.** Exclusive prices are taxed after the discount; with inclusive prices the discount comes off the gross price (doc 02 §7.13.4).
5. **Uses are counted in the order transaction.** One conditional `UPDATE` per code takes the use and locks the row; the per-customer count is read after it; a failure rolls the order back. Cancelling an order gives the uses back in the cancel transaction. Refunds do not.
6. **A code that stops applying stays visible.** It stays in the cart with its reason, adds no discount, and checkout refuses until the shopper removes it, so the total never changes without a message.
7. **No cost without codes.** A cart without codes does not build the coupon service and runs no coupon query; the public cart has no coupon fields.

**Alternatives.** Store coupons as a `shop_coupon`-style post type: familiar for WooCommerce add-ons, but a racy usage counter (WooCommerce itself needs a separate "held" mechanism for it) and a meta query per rule. Apply all codes to the full price: two 60 % codes would discount 120 %, which needs a cap and a rule for which code loses; sequential has neither problem. Count uses when the order is paid instead of placed: a shopper could place many unpaid orders with a single-use code; placing is when the stock is held too (ADR-005), and cancellation of an unpaid order releases both.

**Consequences.** DB_VERSION 4 adds two tables. Cart, Checkout and Orders get optional coupon closures, so code that builds them without coupons keeps working. Order lines carry the discount per product line and one `discount` line per code, so reports and refunds read the order alone. WooCommerce coupon migration and the discount lines in the order export reader followed (doc 05 §5.8). Free-shipping coupons followed with DB_VERSION 5 (doc 02 §7.13.8): a `free_shipping` flag on the coupon and a `requires` setting on the free shipping method, as in WooCommerce. The admin screen then got a product and category picker (`admin/coupons/targets`), and variation rules moved to their own `variant_ids` lists so a variant id is never read as a product id. The rules live in the JSON column, so this needed no DB_VERSION. DB_VERSION 6 adds `item_limit` (WooCommerce's "Limit usage to X items"), so those coupons import active instead of disabled. Buy X get Y coupons followed as a fourth type with their fields in the rules JSON; WooCommerce has no such type, so they stay in FlexaCommerce when migrating back.

**Revisit when.** A store needs buy-X-get-Y rules, an add-on needs to change a discount amount (a filter would have to run in the cart, the quote and the order with the same input), or a store has more than about 10,000 codes (the admin search uses `LIKE`).
