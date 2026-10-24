# 08. Architecture Decision Records

Status of every ADR: **Accepted** (plan approved 2026-10-24). Format: context, decision, alternatives, consequences, revisit when.

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

**Consequences.** One extra request (`GET store/cart`) before the first mutation on cached pages; the first add-to-cart relies on content type and Origin only.

**Revisit when.** WordPress core adds session-bound nonces for guests.
