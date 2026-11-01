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

## ADR-022: Swatches as radio groups, set in the Attributes screen

**Context.** The user asked for the features of the flexa-extra plugin inside FlexaCommerce (2026-10-26), starting with variation swatches. flexa-extra keeps a hidden `<select>` for WooCommerce and draws swatches next to it with JavaScript, with the type stored per taxonomy in an option and the colour or image in term meta on `pa_*` terms. FlexaCommerce has no WooCommerce form to stay compatible with, and its global attributes are child terms of one `fxc_attribute` taxonomy (ADR-006).

**Decision.**
1. **Term meta, not an option.** The swatch type lives on the attribute term and the colour or image on the value term (doc 03 §3.2). Deleting a term removes its look, and an import that keeps term ids keeps it too.
2. **Radio inputs, not a hidden select.** A swatch attribute renders as a fieldset of radios posting `attributes[{key}]`. Without JavaScript the form still posts; the Interactivity store treats a radio change like a select change. Nothing has to keep a hidden control in sync.
3. **Edited where the values are.** The Attributes screen has a "shown as" select per attribute and a swatch button on each value chip. A colour opens a small dialog with a picker and a hex field; an image opens the media library directly, and the dialog only changes or removes it, because the media modal cannot open above our dialog.
4. **Display options in Settings > Storefront** (doc 02 §7.2.1), with a preview.

**Alternatives.** Keep flexa-extra's option map per taxonomy: one option for all attributes, but it outlives deleted attributes and needs its own cleanup. A hidden select with swatches drawn by JavaScript: two controls to keep in step, and no swatches at all without JavaScript.

**Consequences.** No DB_VERSION. `PurchaseOptions` attributes gain `swatch` and each option `color` and `image`. Swatches in product lists, per-swatch stock counts and a "+N more" limit are left for later. Gallery and badges (the rest of flexa-extra's storefront features) come next, then pricing rules and product options.

**Revisit when.** A store needs a swatch type per product rather than per attribute, or custom attributes need colours (they would need per-product meta, since they have no terms).

## ADR-023: Badges as one ordered option, gallery options in Settings

**Context.** Step 3 of the flexa-extra port (2026-10-26): product badges and the product gallery. flexa-extra stores each badge as a post of a private type and draws them with JavaScript after the page loads; its gallery is a slider library. FlexaCommerce renders the product page and the archive on the server and keeps the storefront free of scripts it does not need (doc 10).

**Decision.**
1. **One option, `flexacommerce_badges`, autoloaded.** It holds an ordered list of at most 50 badges, each a small row (text, colours, corner, shape, condition, products or categories, excluded products, places, start and end). The list is read on every product image, so it must cost no query; a post type would cost one per page. Order in the list is display order inside a corner. Ids are the largest kept id plus one. The option starts with `flexacommerce_`, so uninstall erases it.
2. **Server-rendered overlay.** `BadgeOverlay` puts a `div.fxc-badges` inside the gallery figure, the archive card image, related products and the core featured-image block when it shows a product. The filter for that block is added at the first product image of a page, so other pages carry no extra callback. Conditions only read the product (sale price, post date, featured flag, stock, categories) and never the shopper, so pages stay cacheable. `{percent}` in the text becomes the largest variant discount, rounded down.
3. **Gallery options are settings, not a library.** `gallery_thumbs` (bottom, left, dots), `gallery_aspect`, `gallery_arrows`, `gallery_zoom` and `gallery_lightbox` are keys of `flexacommerce_settings` (Settings > Storefront, with a preview). Arrows, swipe, hover zoom and the full-screen view are handlers in the existing `flexacommerce/cart` store; the full-screen view is a native `<dialog>`. Without scripts the main image and thumbnails still link to the full images.
4. **Edited under Promotions > Badges**, next to coupons, because both are about selling. A badge is created from a preset (sale, new, featured, low stock, out of stock, custom) and the dialog shows a live preview.

**Alternatives.** A badge post type with meta: free revisions and per-badge capabilities, but a query on every page and a screen the merchant has to leave the Promotions page for. A JavaScript slider: more effects, but a dependency, layout shift and no gallery before the script runs.

**Consequences.** No DB_VERSION. A single image is now interactive by default because the full-screen view is on; turning it and zoom off gives the old script-free markup. Customer or role conditions are left out on purpose; adding one would need `Vary` handling or client-side rendering.

**Revisit when.** A store needs more than 50 badges, badges per customer group, or video in the gallery.


## ADR-024: Pricing rules as one ordered option, applied inside the unit price

**Context.** Step 4 of the flexa-extra port (2026-10-26): automatic price changes with no coupon code. flexa-extra keeps its rules in a post type and rewrites WooCommerce prices through price filters, so cart, product page and list prices can drift apart. FlexaCommerce already has one place that decides a unit price: `PricingService::unit_price()`, which the cart, the product page and the REST API all call.

**Decision.**
1. **One option, `flexacommerce_price_rules`, autoloaded**, with at most 100 rules. A rule is a small row: a type (`adjust`, the same change for any quantity, or `bulk`, tiers by quantity), a change (percent off in hundredths, an amount off each unit, or a set unit price, in minor units), the products (all, chosen products, or categories including their child categories), excluded products, the customers (everyone, logged in, or chosen roles), what to do with products on sale (apply to the sale price, or skip them) and optional start and end dates in UTC. A store with no rules reads the option and loads no rule class, so the P1 budgets hold.
2. **Rules only lower prices, and the lowest wins.** `PriceRules::apply()` runs on the active price (sale price when there is one) before the `flexacommerce.price.unit` filter. Each matching rule gives a candidate price; the result is the lowest of them and of the active price. Rules never add up and never raise a price, so a merchant can reason about one rule at a time.
3. **Bulk counts the whole product or each variation.** With `count: product`, the cart passes the sum of all lines of the product, so 2 red and 3 blue shirts reach the 5-unit tier. With `count: variant`, only the line quantity counts. The largest tier whose minimum is reached applies.
4. **The product page shows the tiers.** `QuantityTiers` renders a table under the quantity field from the first live bulk rule for the product and shopper. A simple product shows the real unit price at each tier; a variable product shows the change ("12.5% off").
5. **Edited under Promotions > Pricing rules**, between coupons and badges. A rule starts from a preset (category sale, bulk discount, wholesale price, custom), and the dialog shows what a product at 100 would cost.

**Alternatives.** Price filters at display time, as flexa-extra does: easy to add, but every reader of a price would need the same filters, and the cart total could differ from the product page. Rules as coupons applied automatically: reuses the coupon tables, but coupons work on the cart, so the product page could not show the lower price.

**Consequences.** No DB_VERSION. Activation and `maybe_upgrade()` create the rule list and the badge list empty and autoloaded: a missing option is a query on every page without a persistent object cache. P10 now counts three autoloaded options. Price min/max meta used for sorting and the price filter does not reflect rules. Coupons with `exclude_sale_items` treat a rule-reduced item as on sale, because they check `is_reduced()`. Guest prices stay cacheable; role and logged-in prices depend on the shopper, so a page cache must vary on login (the default for WordPress page caches). Rules on the cart total, and graduated tiers (each tier priced separately), are left out. With several bulk rules for one product, the tier table shows the first in list order.

**Revisit when.** A store needs cart-level rules (spend 100, get 10% off), graduated tiers, or price sorting that follows rules.


## ADR-025: Product options as one ordered option, charged on the cart line

**Context.** Step 5 of the flexa-extra port (2026-10-26): extra fields on the product page, such as an engraving text, a gift message or a gift wrap choice that costs more. flexa-extra stores its field groups in a post type and adds the cost through cart item data filters. FlexaCommerce cart lines already carry a unit price from `PricingService`, so the extra can be computed in one place and saved with the line.

**Decision.**
1. **One option, `flexacommerce_product_options`, autoloaded**, with at most 30 sets of up to 20 fields each. A set has a name, an on/off switch, the products it applies to (all, chosen products, or categories including their child categories) and excluded products. A field is text, long text, number, dropdown, radio buttons, checkboxes, or a heading with no input. Each field has a label, help text, a required flag, limits (length, or min, max and step) and either its own price or a price per choice. When several sets fit a product, their fields show in list order.
2. **A price is a fixed amount in minor units or a percent of the unit price.** The percent is taken of the unit price after pricing rules (ADR-024), so a 10% engraving on a discounted item costs 10% of what the shopper pays. The extra is added to both the regular and the active unit price, so the saving a rule shows does not change. Option prices show as entered, with no tax adjustment.
3. **Conditional fields.** A field can show or hide itself when all or any of its rules match; a rule looks at a field above it in the same set (is, is not, empty, not empty). `cart.js` applies the same rule as `ProductOptions::visible()` to hide fields and disable their inputs while the shopper picks. On the server, fields hidden by their logic are dropped before validation, so a hidden required field never blocks the cart and a hidden priced field never charges.
4. **Validated again on add to cart.** The form posts `options[<set>-<field>]` (checkboxes post a list of choice ids). `ProductOptions` checks the values against the current sets and refuses the add with a message when a required field is empty, a value is too long or out of range, or a choice does not exist. The cart line keeps the chosen values and their labels, so the cart, checkout and order show what was picked. The extra is worked out again from the current fields each time the cart is priced, the same way the unit price is.
5. **Edited on its own screen**, Product options, right after Attributes. A set starts from a preset (gift wrap, engraving, size, custom); fields are collapsible cards with move up, move down and remove.

**Alternatives.** Option sets as a post type: revisions for free, but a query on every product page, and a list screen the merchant would rarely need. Options per product in post meta: simple for one product, but the same gift wrap field would have to be copied to each product.

**Consequences.** No DB_VERSION. Activation and `maybe_upgrade()` create the list empty and autoloaded; P10 now counts four autoloaded options. A deleted field adds nothing to lines already in a cart, although the line keeps its label. A set that is turned off still charges items already in a cart, since the shopper saw that price. File uploads, date pickers, color pickers and per-variation option sets are left out (date and color fields came with ADR-028).

**Revisit when.** A store needs file uploads, more than 30 sets, or option prices that follow tax display settings.


## ADR-026: Automatic discounts as coupons without a code

**Context.** Item 1 after the flexa-extra port (2026-10-27): a discount the shopper does not have to type, such as 10% off orders over $100, and a line in the cart that says how much more to spend to get it. ADR-024 left cart-level rules out of pricing rules. Coupons already have everything such a discount needs: a minimum spend, product and category limits, dates, usage limits, free shipping, buy X get Y, the order discount lines, the usage count and the tax split.

**Decision.**
1. **A coupon with `automatic = 1`.** Its `code` is the name shoppers see in the cart ("Discount: Spring sale"). The editor asks for a name instead of a code, and forces the allowed emails empty and the per-customer limit to 0, because the cart has no billing email to check them against before checkout.
2. **Applied after the shopper's codes.** `CouponService::discounts()` reads the active automatic coupons in id order and applies each one that passes the usual checks, on what the codes left. One that does not fit drops out without a notice. An individual-use code keeps every automatic coupon out. An individual-use automatic coupon applies only when nothing else did, and then stops the list.
3. **Its name is not a code.** Typing it in the coupon field gives the unknown-code message. A code already in a cart whose coupon later turned automatic is skipped in the code pass and applied once by the automatic pass.
4. **The nudge.** Among the automatic coupons that only miss their minimum spend (checked by running the rules again with the subtotal at that minimum), the one closest to applying goes to the cart response as `next_discount`: its name, the amount missing and the share reached, in percent, capped at 99. The cart shows a sentence ("Spend $30.00 more for this discount: Big basket.") and a bar; the bar is `aria-hidden` and the sentence is in a polite live region.
5. **No cost for stores without one.** `flexacommerce_automatic_coupons` is an autoloaded flag, 1 while an active automatic coupon exists, written after every coupon insert, update and delete. With the flag off, a cart without codes never builds the coupon service, as before. With it on, the cart reads the automatic coupons in one indexed query (`automatic_status`) per request.

**Alternatives.** A cart rule in pricing rules (ADR-024): it would need its own order lines, usage counts, tax split and checkout checks, all of which coupons already have. Applying the automatic coupons before the codes: a percent code would then shrink with each automatic discount, which reads oddly in the cart. A plain query on every cart without the flag: one query per cart request in every store, most of which have no automatic discount.

**Consequences.** `DB_VERSION` 7 adds the `automatic` column and the `automatic_status` key (dbDelta, no upgrade step). P10 counts five autoloaded options. A store with automatic discounts pays one query on cart requests (P4q is measured without them). The flag counts disabled coupons as off but expired and used-up ones as on, which only costs that query. WooCommerce has no automatic coupons, so migration back to WooCommerce refuses them (`unsupported_coupon`), as it refuses buy X get Y. Shoppers cannot remove an automatic discount from the cart.

**Revisit when.** A store needs an automatic discount limited by customer email or account, more than a few dozen active ones, or a choice between stacking and best-of.


## ADR-027: Automatic buy X get Y with a count-down nudge

**Context.** Item 2 after the flexa-extra port (2026-10-27): offers like "buy 2, get 1 free" that apply without a code, as flexa-extra's BOGO rules do. Coupons already have buy X get Y (ADR-021) and ADR-026 made any coupon automatic, so the offer already applied. What was missing: the cart said nothing until the first set was complete, and the admin had to work out the numbers for common offers.

**Decision.**
1. **No new rule type.** An automatic discount of type `buy_x_get_y` is the offer. It applies through the automatic pass like any other coupon.
2. **An item nudge.** When an automatic buy X get Y coupon passes every rule except having a set (`check()` with `$sets = false`), `CouponService::short()` counts the units still missing for the first set. Units on lines that count only as bought or only as discounted fill their own side; units on lines that count for both fill the discounted side first, then the bought side. With no get rules every line counts for both, so the count is X + Y minus the units in the cart. The cart needs at least one unit that counts as bought: a cart with only the free item gets no nudge.
3. **One nudge.** A missing minimum spend (ADR-026) still wins. Without one, the buy X get Y coupon with the fewest missing units is the nudge. `Discounts::$next` carries `items` beside `remaining`.
4. **The sentence comes from the server.** `next_discount` gains `message` (the translated sentence, "Add 1 more item for this discount: Buy 2, get 1 free.", plural forms through `_n`) and `items`; `remaining` is null for an item nudge. The percent is the share of X + Y already in the cart, capped at 99. `cart.js` shows `message`, so it no longer needs the spend sentence in its strings.
5. **Quick fill in the form.** The buy X get Y section of the coupon form has three buttons: Buy 1, get 1 free; Buy 2, get 1 half off; Buy 2, get 1 free. Each sets X, Y and the percentage, and names an automatic discount after the offer when the name is still empty.

**Alternatives.** Porting flexa-extra's pricing rule pass: it would discount inside the unit price (ADR-024), with no order line, usage count or nudge. Counting the missing units in the browser: `cart.js` would need the coupon's rules and the category tree.

**Consequences.** No DB_VERSION, no new option. The nudge costs nothing beyond the automatic pass: `short()` works on lines already resolved. With get rules the count can say "1 more item" when the item missing is the discounted one; the offer's name is what tells the shopper which. Migration back to WooCommerce refuses these offers already (automatic, ADR-026).

**Revisit when.** Shoppers need to see which product is missing, or a store wants both nudges at once.


## ADR-028: Date, color, swatch and button option fields

**Context.** Item 3 after the flexa-extra port (2026-10-27): flexa-extra's option fields include a date picker, a color picker, color swatches and button choices. ADR-025 left the first two out and had no visual choice types.

**Decision.**
1. **Four field types in the same list.** `date` and `color` are typed fields with their own price, like text. `swatch` and `button` are choice fields with a single pick, so they post one choice id and validate like radio buttons. Nothing about the storage changes; each choice gains a `color` key, empty except on swatches.
2. **A date range in days from today.** `min` and `max` hold whole days (0 to 3650) counted from today in the site time zone, so "from tomorrow up to 60 days ahead" stays true without editing. The browser gets them as `min` and `max` on `<input type="date">`; `ProductOptions` checks the posted `Y-m-d` value is a real day inside the range. The cart line keeps `Y-m-d` and shows it in the site date format.
3. **A color always has a value.** `<input type="color">` cannot be empty, so the editor turns the required flag off and the field uses the placeholder slot for its starting color (black when empty). The server takes `#rrggbb`, stores it lowercased and shows it uppercased in the cart. A priced color field therefore always charges when it is shown.
4. **Swatches reuse the attribute swatch look** (ADR-022): each choice renders as `.fxc-swatch` with a radio inside, the color in `--fxc-swatch-color`, and the name and price in the title and in screen-reader text. Buttons render the name inside the face. A swatch choice without a valid `#rrggbb` color is refused in the editor.
5. **Editor.** The type list adds Date, Color picker, Color swatches and Buttons. A date field asks for the earliest and latest day; a color field asks for the starting color; swatch choices get a color column. Switching type clears limits and the starting color that mean something else in the new type. Two presets: a color choice and a delivery date.

**Alternatives.** Absolute dates for the range: a merchant would have to move them forward by hand. A text field with a pattern for colors: no picker on the product page.

**Consequences.** No DB_VERSION, no new option. Sets saved before this keep working: a choice without `color` reads as empty. The date range uses today when the page renders, so a page cached past midnight can offer one day the server then refuses, with a message. A color field cannot be optional; a store that needs "no color" should use swatches with a "None" choice. File uploads and per-variation option sets are still left out.

**Revisit when.** A store needs blocked weekdays or holidays on the date field, time slots, or several swatches picked at once.


## ADR-029: WooCommerce Product Add-ons into option sets, with a once-per-line price

**Context.** Item 4 after the flexa-extra port (2026-10-27): stores leaving WooCommerce often use the official Product Add-ons extension, and their add-ons were left in the `_fxc_import` bucket. The extension has a flat fee charged once per cart line, which option sets could not express, and stores with add-ons on many products would pass the limit of 30 sets.

**Decision.**
1. **A new `once` price mode.** A field or a choice priced `once` adds its amount to the line total, not to the unit price, so it stays the same at any quantity. `ProductOptions::fee()` sums it; `CartItem::$fee` carries it and `CartService` adds it after `line_total`. Coupons and taxes work on the line total, so the fee is discounted and taxed with the line. The order item keeps `subtotal = unit price × quantity + fee` and records the fee as `meta.fee`. The form shows "(+$5.00 once)" and `cart.js` adds it to the live total like a fixed price.
2. **An `options` migration stage**, WC→FXC only, right after products (doc 05 §5.9). The reader keeps the add-ons as the extension stores them and the writer maps them, so the report can name each field it changed or left out. Products with identical add-on lists share one set. When the stage ends the imported sets are sorted in WooCommerce's display order.
3. **Convenience over completeness.** Fields FlexaCommerce lacks are left out with a warning naming the set and the field; images become buttons; conditional logic and character rules are dropped while the field stays. A group that targets nothing is imported turned off.
4. **Up to 200 sets**, with a search box on the Product options screen once there are 8. The arrows to reorder wait while a search is on, since the order is that of the whole list.

**Alternatives.** Reading the add-ons through the extension's classes: the extension is often inactive by the time a store migrates. One set per product: hundreds of identical sets. A FXC→WC writer: the extension's format changes between versions and is not part of WooCommerce.

**Consequences.** No DB_VERSION. The order of imported sets waits in `flexacommerce_migration_option_order` (not autoloaded) during the stage, keyed by job. A price that charges per entered unit (the quantity add-on) has no FlexaCommerce equivalent and is imported without its charge. The option set list is still one autoloaded option; at 200 large sets it can pass 1 MB, which a store with that many would notice on every page.

**Revisit when.** A store needs file uploads, a charge per entered unit, or more sets than one option can hold.


## ADR-030: A countdown when a price is about to go up, and a fuller tier table

**Context.** Item 5 after the flexa-extra port (2026-10-27): flexa-extra shows a countdown for pricing rules with an end date and a quantity discount table with a discount column. FlexaCommerce already listed bulk tiers (ADR-024), but only as "5 or more" rows, with no price for fewer units and no link to the quantity the shopper picked.

**Decision.**
1. **The countdown follows the price.** `PricingService::price_ends()` collects the end dates of the live rules for the product and shopper and the next sale schedule change of each row, soonest first. The first one at which one unit of some row costs more than now is the end; a rule that ends while a bigger rule stays, or a sale that starts, shows nothing. The price is worked out with `unit_price()` at that moment, so the `flexacommerce.price.unit` filter counts too.
2. **Only when it is close.** `countdown_days` (1 to 365, default 7; 0 turns it off) is the window. Further away, a countdown would make a long sale look urgent. It is one key rather than a switch and a number: a 65th settings key doubles the hash table of the settings arrays, about 9 KB on every page (P1).
3. **Readable without JavaScript.** The form starts with "This price ends on {date}." in the site date and time format, inside a `<time>`. `callbacks.countdown` (a `data-wp-init` in the existing `flexacommerce/cart` store) hides the sentence visually, shows four boxes (days, hours, minutes, seconds) marked `aria-hidden`, and at zero says the offer has ended. The boxes carry no live region, so screen readers are not told every second.
4. **The tier table starts at 1.** Rows are ranges ("1 to 4", "5 to 9", "10 or more"). A product with one price shows the unit price of each range and a "You save" column, the share saved against the price of one, rounded to a whole percent; a variable product shows "Full price" and the change of each tier. The row for the quantity in the form has `is-current`; `actions.setQuantity` moves it. A bulk rule that ends inside the window gets its own countdown under the table, unless the offer countdown already shows that end.
5. **Two settings on the Storefront screen.** `tier_table` hides the table and `tier_table_title` replaces "Buy more, pay less". The Offers card previews both, and the pricing rule dialog says a countdown will show once an end date is set.

**Alternatives.** flexa-extra's soonest-ending matching rule: simpler, but it counts down to moments when nothing changes for the shopper. A countdown in the price block: it renders in product lists too, where a ticking clock per card is noise. A live region on the clock: screen readers would read it every second.

**Consequences.** No DB_VERSION, no new option. A store without rules or sale dates pays nothing; with them, the product page works out a few more prices, memoised per request. On a variable product the countdown is for the soonest change among its variations, before the shopper picks one. The page is cacheable: the end is a fixed moment and the browser counts. After the end the page still shows the old price until it is reloaded, which the ended message says. Countdowns for coupons and automatic discounts are left out.

**Revisit when.** Shoppers ask for a countdown per variation, or for automatic discounts that end soon.

## ADR-031: A checkout builder for the fields, stored as one layout option

**Context.** After the flexa-extra port (2026-10-27) the user asked for a drag and drop checkout builder. Three settings (`checkout_company`, `checkout_phone`, `checkout_address_2`) and the `flexacommerce.checkout.fields` filter were the only ways to change the form, so a store wanting a gift message or a delivery slot needed code. The full design is doc 11; this record covers part 1, the fields.

**Decision.**
1. **One option, only the differences.** `flexacommerce_checkout_layout` (autoload off) holds the three sections (billing, shipping, additional) with per-field overrides: order, label, placeholder, help, width, answer. `Checkout\CheckoutLayout` normalises it once per request; a store without it renders the form of before. A damaged option falls back to the default instead of breaking the checkout.
2. **The schema follows the layout, extensions still have the last word.** `FieldSchema` builds the address and additional schemas from the layout, then runs `flexacommerce.checkout.fields` and the new `flexacommerce.checkout.additional_fields`. `CheckoutValidator` uses the same schema, so what the builder makes required is checked on the server.
3. **Some fields are locked.** Email and country stay shown and required; state and postcode are shown or hidden, and the country decides whether they are required. The server refuses a layout that breaks this, with the path of the field at fault, and the builder points at that field.
4. **Custom fields of nine types,** at most 30 per section and 30 choices per field. The id comes from the first label and never changes; a choice keeps its value when its label changes. Answers are order meta `_{section}_{id}`, and `_fxc_fields` keeps the label, value and email switch of every answer as placed, so the order screen, confirmation, account, emails and privacy export show history as it was.
5. **The three settings move into the layout.** `V8CheckoutLayout` (DB_VERSION 8) copies them over and drops the keys; when they were the defaults it writes no layout. `Settings` goes from 64 to 61 keys.
6. **The builder is its own admin chunk.** FlexaCommerce > Checkout, with `@dnd-kit/core` and `@dnd-kit/sortable`, lazy loaded (about 24 KB gzip). Every move works from the keyboard with spoken announcements; the inspector also has Move up and Move down. REST is `GET`, `PUT` and `DELETE /admin/checkout-layout`.

**Alternatives.** Fields as rows of a table: the layout is read whole on every checkout and is small, so one option is one read. A label snapshot per answer in separate `_label` meta keys: twice the meta rows and a second read per field; one JSON list is read once per order. Rendering the builder canvas with the live theme: theme styles vary too much, so the canvas is a sketch and the real page is the preview (part 2).

**Consequences.** No new storefront JavaScript; `storefront.css` gains the half-width rules (about 4.5 KB gzip in total, cap 10 KB). The checkout page reads one more option, cached by the object cache. Custom answers are not saved to the customer's address book. Section moves, one column, content items and Preview on site are part 2.

**Revisit when.** Merchants ask for fields shown only for some countries or products, or for importing the WooCommerce Checkout Field Editor options.

## ADR-032: Sections, columns, content and preview in the checkout layout

**Context.** Part 1 of the checkout builder (ADR-031) arranged the fields; the sections of the page stayed where the template put them. Part 2 of doc 11 lets the merchant move them, use one column, fold the summary on phones, add headings and paragraphs, and see the result before saving.

**Decision.**
1. **The page lives in the same option.** `flexacommerce_checkout_layout` gains `page` next to `fields`: the column mode, the summary position on phones, and the ordered `main` and `side` lists of sections and content items. One read per checkout, as before.
2. **Strict on save, lenient on read.** A PUT must keep all six sections, once each, with Payment last in its column; a stored layout that breaks a rule is repaired on read (missing sections appended to their default column, Payment moved to the end), so a damaged option never breaks the checkout.
3. **Content items are blocks of a column,** limited to 20, sanitised on save (heading as text, paragraph through `wp_kses` with links, bold and italic).
4. **The folded summary is HTML first.** A `<details open>` that CSS moves to the top on phones and `checkout.js` closes there. No JavaScript means an open summary, never a hidden one.
5. **Preview is a per-user transient plus a nonce.** The draft is checked like a save, kept for one hour, and shown only to its author holding the manage capability and the nonce. Place order is disabled on the preview and an empty cart gets a sample line.

**Alternatives.** Content inside sections: it ties text to a section the merchant may move, and the server would need a second kind of entry in each section; between blocks is simpler and covers the notes merchants write. A hide switch per section: it would let a merchant hide Payment or Billing; hiding fields one by one covers Additional information. Preview as a saved revision: it would need a revision store and a way to discard it; a transient expires by itself.

**Consequences.** `storefront.css` gains the one-column, content and fold rules (still under its 10 KB gzip cap); `checkout.js` gains a few lines for the fold. The admin chunk grows with the page canvas. REST requotes during a preview run with the saved layout, which only matters to a merchant testing shipping with the preview.

**Revisit when.** Merchants ask for a steps layout, for content shown only to some carts, or for richer text than links, bold and italic.

## ADR-033: Conditions on the merchant's checkout fields

**Context.** Part 3 of doc 11 starts with fields that show only for some countries (a VAT number for the EU), for carts that ship or do not (a door code, a pickup time), or for carts with some products or categories (an engraving text). flexa-extra and the Checkout Field Editor offer this, and stores moving over expect it.

**Decision.**
1. **Conditions belong to custom fields only.** Core address fields keep Required, Optional and Hidden; the address formats already follow the country.
2. **One `when` object per custom entry,** in the layout option: countries, a shipping choice, products and categories. Empty parts mean always, so old layouts need no upgrade.
3. **AND between the parts, OR between products and categories.** It covers "this product or anything in Jewellery" without a rule editor. Parent categories count.
4. **Cart conditions are evaluated on the server.** The cart is fixed on the checkout page, so a field the cart does not meet is left out of the schema and the markup. Category terms are read only when a field names a category.
5. **Country conditions are evaluated on the page and on the server.** The field is rendered hidden and disabled for other countries and `checkout.js` toggles it; the validator skips it and stores nothing for it.

**Alternatives.** A general rule builder (any field, any operator): more than merchants asked for and hard to explain in an inspector. Evaluating everything in the browser: a shopper without JavaScript would be asked for fields that do not apply, and the server would still need the rules. Conditions on core fields: hiding the postcode for one country would fight the address formats.

**Consequences.** `checkout.js` gains the country toggle (still under its budget); the layout option grows by a small object per custom field. Extensions using the field filters see `CheckoutField::$countries` and must call `shown_in()` before treating a field as asked.

**Revisit when.** Merchants ask for conditions on the answer of another field, on the cart total, or on the customer's role.

## ADR-034: A steps layout for the checkout

**Context.** Part 3 of doc 11 asks for a checkout in steps (Information, Shipping, Payment), as flexa-extra and hosted checkouts offer. Long forms on phones read better one part at a time, and a recap of what was entered helps the shopper check it before paying.

**Decision.**
1. **One switch, `page.flow`, with fixed steps.** Each section has its step; the merchant does not assign steps by hand. Headings and paragraphs follow the section below them, the summary shows in every step.
2. **The server renders one page; the script makes it steps.** The nav ships hidden and no block is hidden, so without JavaScript the form posts as one page and the server checks it as before. Nothing in the order flow changes.
3. **Checking a step uses the browser's validation** (`checkValidity`, `reportValidity`) on the step's enabled controls. The server stays the judge at Place order; its errors open the step of the first field.
4. **Each step is a history entry,** so the browser's Back button moves between steps instead of leaving the checkout.
5. **The steps run down the main column.** Blocks of the side column that belong to a step move to the end of the main column; the side keeps the summary.

**Alternatives.** One server request per step: slower, more state to keep, and a step could go stale against the cart. Steps the merchant builds freely: more to explain and to check, for little gain over the three steps shoppers know. Hiding the steps with CSS before the script loads: a shopper without JavaScript would see only the first step.

**Consequences.** `checkout.js` grows to about 7.4 KB gzip and `storefront.css` to about 4.9 KB, both under budget. The layout option gains one key. Themes that style `[hidden]` away still see the one-page form until the script runs.

**Revisit when.** Merchants ask for their own steps, for an express payment button above the first step, or for saving the steps the shopper finished across visits.

## ADR-035: A coupon box on the checkout

**Context.** Part 3 of doc 11 asks for a coupon field on the checkout. Until now codes were added on the cart page only, so a shopper who went straight to the checkout had to go back to use a code, and could not remove one without leaving.

**Decision.**
1. **The box belongs to the order summary,** under its table, and is on by default (`page.coupons`). It moves, folds and shows in every step with the summary, so it needs no place of its own on the page.
2. **The checkout uses the cart's coupon paths.** The REST routes and the no-JS form handler are the cart's; the checkout adds no new endpoint and no second way to apply a code. The cart keeps the rules (limits, individual use, the reasons a code is refused).
3. **A separate form after the order form,** joined by the `form` attribute, since forms cannot nest. The code stays out of the order post, and Enter in the code field applies the code instead of placing the order.
4. **A requote after each change.** The checkout totals depend on the address (shipping, tax display), so the script asks the checkout for them again instead of reading the cart's totals. The expected total follows, so the order goes through at the new price.

**Alternatives.** Applying the code with the order post: the shopper would see the discount only after placing the order, and a refused code would block it. A coupon section the merchant places freely: one more block to explain, and a box far from the totals it changes. A field inside the order form named so the server skips it: Enter would place the order, and a second submit path through the order form is easier to get wrong.

**Consequences.** `checkout.js` grows to about 8.0 KB gzip and `storefront.css` to about 5.0 KB (budget 10 KB). The layout option gains one key. Stores that never open the builder get the box; a merchant who does not want it turns it off on the summary.

**Revisit when.** Merchants ask for the box above the summary or open by default, for gift cards in the same field, or for a list of codes the shopper can pick from.

## ADR-036: Importing the Checkout Field Editor into the checkout layout

**Context.** The last item of part 3 in doc 11. Many WooCommerce stores set up their checkout with the Checkout Field Editor by ThemeHigh. A merchant who moves to FlexaCommerce should not have to rebuild those fields by hand.

**Decision.**
1. **A Migration stage, `checkout`, WC→FXC only,** with one record that holds the extension's three options. It runs last, refers to nothing and is skipped when the source has not changed. The options are read even when the extension is off.
2. **A saved section replaces that section; everything else stays.** The page settings, unsaved sections and fields made in the builder are kept. Core fields the extension removed come back hidden, so the layout stays complete.
3. **WooCommerce's own wording is not imported.** A label or placeholder equal to it (in English or translated) is cleared, so it keeps following the site language.
4. **What has no match is left out with a log line,** never forced into something close: multiselect and checkbox groups, hidden fields, headings and paragraphs, default values and the Pro conditions. A field that showed on conditions becomes optional, so it never blocks an order.
5. **The writer saves through `CheckoutLayout`.** Migration may use that one class of Checkout (doc 02 §3.2, enforced by `DependencyRulesTest`), so the import passes the same checks and limits as the builder.

**Alternatives.** Reading the fields through the extension's PHP classes: they exist only while it is active, and the stored options are simpler to read. A separate import button in the builder: a second import path without dry run, preflight or job log. Converting checkbox groups to several checkboxes: one question would become several, each with its own answer on the order.

**Consequences.** One new stage and one record type; no table or option is added. The dependency rule gains one named exception. A second run after the merchant edited the builder replaces the imported sections again.

**Revisit when.** Merchants ask for other checkout field plugins (Flexible Checkout Fields, Checkout Field Manager), for multi choice fields, or for the import to keep builder changes made after the first run.

## ADR-037: Changing a cart line from the product page

**Context.** A shopper who picked the wrong size or misspelled an engraving had to remove the line and add the product again, filling in every option a second time. flexa-extra let them edit a line; the port needs the same.

**Decision.**
1. **The product page is the editor.** An "Edit" link on each cart line of a variable product, or of a product with option fields, opens the product page with `?fxc_edit=<line key>`. The add-to-cart form starts from the line: variation picked, option fields filled, quantity set, the button reads "Update cart" and a Cancel link goes back to the cart. The key is looked up in the shopper's own cart, so showing the page needs no nonce; a key from another cart or another product leaves the form as it is.
2. **One write replaces the line.** The form posts what it always posts plus `replace=<key>`, on the same REST route and no-JS form. `CartService::replace()` checks the choices, quantity, stock and `validate_add` as `add()` does, counting stock without the old line. The new line takes the old one's place and its `added_at`; when another line already has the same choices, the two are merged.
3. **Back to the cart afterwards,** with "Cart updated.", in both paths.
4. **A variation no longer sold can still be changed.** The form opens in edit mode with nothing picked, so the shopper chooses another one instead of removing the line.

**Alternatives.** Editing in the cart table: every option field type and the variation matrix would need a second renderer in the cart, and the cart script would grow. A drawer with the form on the cart page: the same form loaded twice, and one more script path. A remove plus add from the browser: two writes, so a failure between them loses the line.

**Consequences.** No storage change: lines keep their key scheme. `store/cart/items` takes one more optional field and answers 200 for a replace. `cart.js` grows by a few lines.

**Revisit when.** Shoppers ask to change options without leaving the cart, or a product type needs a form the product page does not have.

## ADR-038: Swatches in product lists

**Context.** On the shop and category pages a variable product only had a "Choose options" button, so a shopper could not see which colours exist without opening each product. flexa-extra printed the swatches of the first attribute under each card, as links to the product.

**Decision.**
1. **Links, not a form.** Each value is an `<a>` to the product page with `?fxc_pick[{key}]={value}`. The list needs no JavaScript, no matrix in the page state and no form fields, and every link works with the keyboard. `rel="nofollow"` keeps crawlers on the product URL.
2. **The product page reads the pick.** `AddToCartForm` checks each value against the product's own and marks it picked. When every attribute is named, the matching variation shows its price at once, as when a cart line is edited (ADR-037).
3. **Stock per value.** A value that no variation in stock carries is crossed out and its name says "(out of stock)", or it is left out, following `swatch_unavailable`, so the list and the product page agree.
4. **One attribute, a limit.** Only the first attribute drawn as swatches is shown, at three quarters of `swatch_size`. `swatch_list` (default 5, 0 for off) caps the values; a "+N" link opens the product for the rest.

**Alternatives.** Adding to the cart from the list: one value rarely names a variation, so the shopper would still pick the rest somewhere. Swapping the card image on hover: needs the cart script in every list and an image per value, for little gain over the product page, which already shows the variation image.

5. **A block knows it is in a list from its product.** The Post Template passes its inner blocks only `postId` and `postType`, so the add-to-cart block never saw `queryId` and drew the full product form in block theme lists. It is now in list mode when it takes its product from the context and that product is not the page being viewed; a block with its own `productId` stays a full form.

**Consequences.** One setting (61 keys). A list with variable products builds `PurchaseOptions` for each of them; the term meta it uses is already primed (ADR-022), and `swatch_list = 0` skips the work. `storefront.css` grows by six rules.

**Revisit when.** Merchants ask to pick the attribute shown per product, to swap the card image, or to add a variation to the cart straight from the list.

## ADR-039: Formula prices for options

**Context.** A fixed amount or a percent cannot price a sign by its area, an engraving by its letters, or a setup fee shared by the line. flexa-extra let a field carry a formula over the other fields of its set; merchants selling made-to-measure goods rely on it.

**Decision.**
1. **A fourth price mode.** A field or a choice can use `formula` and keep a `formula` string (price 0). The result is the extra charge per item in the currency's main unit (dollars, not cents), rounded to minor units. A result at or below 0, or one that cannot be worked out, charges nothing.
2. **A small grammar, read by a parser of our own.** Numbers, `+ - * /`, brackets, `base` (unit price after pricing rules), `qty` (line quantity), `{n}` (field n of the same set) and round(x, digits), ceil, floor, min and max. No `eval()`, no other names. Division by zero gives 0. At most 300 characters. `Options\Formula` reads it on the server; `cart.js` has the same parser for the price shown while the shopper types, and the two are kept in step by the same test cases.
3. **Every field reads as a number.** A number field gives its value; text gives its characters without spaces; a picked choice gives 1; checkboxes give how many are ticked; a date, a colour, an empty or a hidden field gives 0. A formula field the shopper left empty is not charged.
4. **Checked on save.** A formula that cannot be read, or that names a field the set does not have (or a heading), is refused with the formula in the message. The editor offers buttons that insert each field, `base` and `qty` at the cursor, and a "Price by area" preset.
5. **The server decides.** The cart works the formula out again each time it is priced, from the stored values; the price in the form is a preview.

**Alternatives.** A general expression library: far more than four operators need, and a larger surface to keep safe. Per-unit fields ("price per cm") instead of formulas: covers area only in two steps and not letters or shared fees.

**Consequences.** No new setting (61 keys). `cart.js` grows by the parser. A formula that reads `base` makes the form carry the unit price, as a percent does.

**Revisit when.** Merchants ask for conditions inside a formula (if/else), for formulas over fields of another set, or for a formula on the whole line instead of each item.

## ADR-040: Stock for option choices

**Context.** A shop that sells a ring in gold or silver, or a print on three papers, often runs out of one choice while the product itself is still in stock. flexa-extra kept a count per choice and took from it at checkout. Without it, merchants mark the choice by hand or oversell.

**Decision.**
1. **A table, not the option.** `fxc_option_stock (set_id, field_id, choice_id, quantity)` holds one row per choice that tracks stock (doc 03 §4.15). The choice itself only says `track_stock`. The option sets stay one autoloaded option that changes when the merchant edits; the counts change on every order, so they live where a conditional `UPDATE` can take them. `DB_VERSION` 9.
2. **Empty means unlimited.** The choice row of the editor gets a Stock box. A number turns tracking on; clearing it turns tracking off and drops the row. The listing sends each choice's count, and the save sends it back with `stock_was`, the count loaded. The server writes a count only when it differs from `stock_was` (or the row is new), so a save does not undo what orders took while the editor was open.
3. **The cart checks, the order takes.** Adding to the cart, changing a line or raising its quantity checks every tracked choice of the line against what the other lines of the cart already use, and says so: "Only 2 of Finish: Gold are left." The order item keeps the tracked choices in `meta.option_stock` and how many units it holds in `meta.option_held`. `OrderService` takes them with the variant stock, all or nothing: the choices go first and are put back if the variants are short. Cancelling puts back what a line holds; a refund with restock puts back the refunded units.
4. **The form shows it.** A sold-out choice is shown disabled with "(out of stock)", is never picked at the start, and stays disabled when logic shows its field. The swatch style already strikes out a disabled swatch.

**Alternatives.** Counts inside the option set: every order would rewrite an autoloaded option read on every product page, and two orders at once would overwrite each other. Stock reserved while in the cart: the store does not reserve variant stock either (ADR-008), and a cart left open would hide stock from paying customers.

**Consequences.** No new setting (61 keys). A product page whose sets track stock runs one indexed query for the sold-out choices; pages without tracked choices run none. The order screen does not list option stock yet; the history line for stock covers the variants only.

**Revisit when.** Merchants ask for low-stock emails per choice, for stock shared by a choice across sets, or to see option stock on the order screen.


## ADR-041: Conditions for pricing rules and coupons

**Context.** flexa-extra let a discount depend on more than the products in the cart: how many items or how much weight, the country, the shipping or payment method, another coupon, and what the customer bought before. A merchant who wants "10 % off with cash on delivery", "free gift from 5 kg" or "first order only" had no way to say it with dates, roles and a minimum spend alone.

**Decision.**
1. **One block, two users.** A pricing rule and a coupon each keep `conditions`: `{match: "all"|"any", items: [{type, op, value}]}`, at most 10 items. Types: `cart_quantity`, `cart_weight` (grams), `lifetime_spend` (minor units) and `order_count` with `gte`/`lte` and a whole number; `country`, `payment_method`, `shipping_method` and `coupon` with `in`/`not_in` and a list; `purchased` with `in`/`not_in` and product ids. `Support\Conditions::clean()` checks the block on save and names the bad item; an empty block means no condition. The rule keeps it in its option entry, the coupon in its `rules` JSON, so neither needed a `DB_VERSION`.
2. **Three answers.** `Conditions::check()` returns true, false, or null when a fact is not known yet (no address, no payment method chosen, no shipping rate). With `final` set, an unknown fact counts as false. `all` is false as soon as one item is false; `any` is true as soon as one is true.
3. **Facts come from the cart, then from checkout.** `ConditionFacts` holds the cart's items, quantity and weight, the coupon codes, and the country, shipping rate and payment method once known. The cart stores the destination, the rate and now the payment method. Checkout prices with the shopper's live choices (`CheckoutService::quoted()`), and the shipping requote sends the chosen payment method, so switching to cash on delivery updates the total at once. Placing the order checks again with the submitted choices and `final` set.
4. **Shipping methods** match a rate id exactly (`flat_rate:3`, one method in one zone) or by its type (`flat_rate`, that kind in any zone).
5. **Customer history** counts paid orders only (processing and completed), and spend is paid minus refunded in the store currency (`Contracts\Orders\CustomerHistory`, from `CustomerRepository`). It is read only when a condition needs it, once per request. Guests have no orders and have spent 0, so "first order only" works for a guest and a returning-customer condition tells the guest to log in.
6. **Pricing rules apply only on true.** The product page knows only the shopper (account and past orders), so a rule that needs a cart or checkout fact shows its price in the cart, not on the product page.
7. **Coupons.** False refuses the code with a message that names the condition ("Coupon "COD" is not available with this payment method."). Null keeps the code in the cart in `Discounts::waiting` with "applies once you choose a payment method": no discount yet, and checkout does not refuse it. An automatic coupon applies only on true.

**Alternatives.** A separate rule engine with its own table and screen: two places to set one discount. Treating an unknown fact as false in the cart: the shopper would see "not available" for a coupon that will work as soon as they pick cash on delivery.

**Consequences.** No new setting (61 keys). A cart or rule without conditions runs no extra code and no query. `checkout.js` sends the payment method with the requote. The admin reads the countries, gateways and shipping methods from `admin/conditions/choices`.

**Revisit when.** Merchants ask for conditions on the day or time, the customer's city or postcode, or on product attributes in the cart.

## ADR-042: Graduated quantity tiers

**Context.** A bulk rule (ADR-024) prices every unit of a line at the tier the quantity reaches: at 10 units, all 10 get the 10-unit price. Wholesale and print shops often price the other way, as flexa-extra's graduated mode did: units 1 to 4 at the full price, units 5 to 9 at the next price, units from 10 at the lowest. Merchants used it to keep the step at 10 from giving away the first nine units.

**Decision.**
1. **A third rule type, `graduated`.** It keeps the same tiers and the same count setting as `bulk` (all variations together or each one alone). Each unit pays the price of the tier its position reaches; positions below the first tier pay the active price. `PriceRules::graduated()` adds up the bands that the line covers.
2. **Positions across lines.** Counting each variation alone starts every line at unit 1. Counting the product together goes on from the units of that product in the cart lines above, so 2 small and 3 medium shirts with a tier from 3 price the medium line at the tier.
3. **Exact line totals.** `PriceRules::apply()` now returns the unit price and, for a graduated line, the exact line total; the lowest line total wins among the matching rules. `PriceResolution` gains `line`, and `PricingService::line_total()` uses it before unit times quantity. The unit price shown is the average rounded half up. A `flexacommerce.price.unit` filter that changes the unit price drops the exact total, so every unit is then priced alike.
4. **Orders.** The product item's subtotal is the line total, and the order check allows the rounded average: the subtotal less the once-per-line fee may differ from unit price times quantity by at most half a minor unit per unit.
5. **The tier table** shows each range with its band price and adds a footer line: "Each price applies only to the units in its range." The admin preview lists the ranges and the total for a sample quantity.

**Alternatives.** Splitting a line into one line per band: the cart, coupons and the order would show three lines for one product. Keeping only the average unit price: rounding would make the line total drift from the bands by up to a minor unit per unit.

**Consequences.** No new setting (61 keys) and no `DB_VERSION`. Coupons that limit or give away units by price (`item_limit`, BOGO) see the average unit price of a graduated line. The order screen shows the average unit price times the quantity next to the exact subtotal.

**Revisit when.** Merchants ask for the bands on the cart line, for graduated tiers that count the whole cart across products, or for a band price shown per unit on the order screen.

## ADR-043: Checkout fees

**Context.** flexa-extra had a `checkout_fee` rule: a charge added to the cart for small orders, a payment method such as cash on delivery, or products that need extra handling. FlexaCommerce had the `fee` order item type and `fee_total` on the order, and the order screen, the emails and the totals check already read them, but nothing in the cart could add one.

**Decision.**
1. **A fourth pricing rule type, `fee`.** It lives in `flexacommerce_price_rules` with the other rules, so it keeps their scope (all products, chosen products or categories, less excluded products), customers, dates, order and conditions. No new option and no new setting. The rule's name is the label shoppers see.
2. **Amount.** `mode` is `fixed` (minor units) or `percent` (hundredths); `price` is refused. A percent fee takes its percent of the targeted lines after coupons, as prices are entered, rounded half up, and `cap` (minor units, 0 for no limit) is its largest amount. A fixed fee has no cap. `sale_items` is always `apply`.
3. **When it applies.** The cart needs at least one targeted product, and the conditions must pass. An unknown fact (no payment method chosen yet) means no fee until the shopper chooses; placing the order counts unknowns as false, as for other rules. Fees add up in list order, and price rules (`PriceRules::apply()`, the countdown) skip them.
4. **The cart subtotal as a condition.** ADR-041 gains `cart_subtotal`: the items after pricing rules and coupons, before shipping and tax, in minor units. Only a fee may use it (`Conditions::clean( $value, true )`); a price rule or a coupon would change the subtotal it reads. Before the cart is priced the fact is unknown.
5. **Totals.** `CartService::resolve()` asks `PricingService::fees()` for the fees after the discounts, and `CartState` keeps them as `Pricing\Rules\Fee` (`rule_id`, `name`, `amount`). Fees carry no tax and are added to the total in both tax branches. The cart, the checkout summary and the confirmation show one row per fee above the tax.
6. **Orders.** Each fee becomes a `fee` order item with meta `rule_id`, and the items add up to `fee_total`; `NewOrder::assert_totals()` now checks that too.

**Alternatives.** A separate list of fees under its own tab: a second editor for the same scope, customers, dates and conditions. Taxable fees: the tax engine would need a fee line with a tax class, and the common cases (payment and small order fees) are usually untaxed in the US, where the tax engine starts.

**Consequences.** No `DB_VERSION` and still 61 settings. The cart response gains `fees` and `totals.fees`; the checkout quote gains `fees` and a `fees` total before `tax`. Gateways that decide availability from the total see the total with fees.

**Revisit when.** Merchants ask for taxable fees, a fee per item or per unit, a negative fee (a surcharge discount), or a fee on shipping.

## ADR-044: Slider, time and date and time option fields

**Context.** flexa-extra's option fields include a range slider, a time picker and a date and time picker. ADR-028 brought the date and color fields; shops that take bookings or pickups, or sell by length with a slider, still had to use a number or a text field.

**Decision.**
1. **Three typed field types.** `range`, `time` and `datetime` join `OptionField::TYPES` after `number` and `date`. They price like the other typed fields (fixed, percent, once or formula) and can show or hide on the fields above.
2. **Slider.** `min`, `max` and `step` are always stored; an empty one becomes 0, 100 and 1, `max` must be above `min` and `step` above 0. `placeholder` is the number it starts at (empty: `min`), and must be one the slider can reach. A slider always posts a number, so it cannot be required and its charge always applies, like a color. The server checks the bounds and the step, and a formula reads its number. The form shows the number in an `<output>` next to it, which `cart.js` updates as it moves.
3. **Time.** Posts H:i (seconds dropped). `min`, `max` and `step` are minutes from midnight; the editor shows time inputs and the API also takes H:i. `step` is 1 to 720 minutes, counted from `min`, so 9:00 to 17:00 by 30 offers the half hours. The cart and the order show the site's time format.
4. **Date and time.** Posts Y-m-d\TH:i. `min` and `max` are days from today, like a date field; the time of day is free. The cart and the order show the site's date and time formats. A field that needs opening hours pairs a date field with a time field (the "Pickup slot" preset).
5. **Starting values count.** `OptionField::start()` gives the value a color or slider posts before the shopper touches it, and the form uses it to decide which fields show at first, so a field that shows once a slider is set is not hidden until the script runs.

**Alternatives.** Absolute bounds for the date and time field, as flexa-extra kept them: a product sold all year would need its dates edited. A time window on the date and time field: the browser's `datetime-local` cannot limit the hours of each day, so the shopper would learn the rule only from an error.

**Consequences.** No `DB_VERSION` and still 61 settings. A WooCommerce Product Add-ons import is unchanged, since that plugin has none of these types. Logic rules on these fields use `empty` and `not_empty`.

**Revisit when.** Merchants ask for opening hours per weekday, blocked dates, a time zone per product, or a slider that shows a unit or a price next to its number.

## ADR-045: Bundle coupons

**Context.** flexa-extra has a "Product bundle" pricing rule: a set of products sold together for a percentage off, an amount off or a flat price, applied as a negative cart fee. FlexaCommerce price rules (ADR-024) change one line's unit price and do not combine lines, so a set of different products has no place there.

**Decision.**
1. **A coupon type.** `bundle` joins `Coupon::TYPES`. As a coupon it gets codes, automatic discounts (ADR-026), conditions (ADR-041), dates, usage limits, the cart's discount rows, the tax handling of coupon discounts and the order's DISCOUNT lines with no new code paths.
2. **The set.** `product_ids` and `variant_ids` name the set, one unit of each, 2 to 20 entries. A product covers any of its variations; a variation only itself. `repeat_limit` caps the sets per order, as for buy X get Y.
3. **Pricing.** `bundle_mode` (in `rules`) says what `amount` is: `percent` (hundredths of a percent off the set), `amount` (off each set, at most the set's value) or `price` (what a set costs; a set already cheaper gets nothing). The default is `percent`.
4. **Forming sets.** Greedy: variations are filled before whole products so a product that covers a named variation does not use up its only unit, and each takes the dearest unit left, which gives the shopper the larger discount. The discount of all sets is split over the lines by what is left of the units in the sets, so a line's later coupons and refunds see their share.
5. **Nudge.** An automatic bundle without a set counts the products it lacks for the next-discount line (ADR-027), its bar measured against the size of the set.

**Alternatives.** A price rule type: price rules are per line and do not stack, so the bundle would need its own cart pass. A cart fee as in flexa-extra: the discount would then sit outside the coupon totals, taxes and order lines. An optimal matching of units to the set: greedy with variations first covers the carts merchants build, and is easy to explain.

**Consequences.** No `DB_VERSION` and still 61 settings. The FlexaCommerce file export and import carry `bundle_mode`; WooCommerce has no bundle coupon, so a copy to WooCommerce rejects it like a buy X get Y one. A bundle has no category rules and no item limit.

**Revisit when.** Merchants ask for more than one unit of a product per set, a choice between products in one slot ("any 3 from this category"), or a bundle shown on the product page with a single add-to-cart button.


## ADR-046: Rule simulator

**Context.** flexa-extra has a rule simulator: the merchant picks products and a customer and sees which rules apply. With pricing rules (ADR-024), graduated tiers (ADR-042), conditions (ADR-041), fees (ADR-043) and automatic coupons (ADR-026, ADR-045) a merchant can no longer tell from the rule list why a price came out as it did, or why a discount did not apply.

**Decision.**
1. **A tab.** Promotions > Simulator holds a test cart: products or variations with quantities (up to 20 lines), a guest or any account (`admin/simulator/customers` searches every account with its roles, ordered before or not), a country, state and postcode, a shipping rate and payment method (or what checkout would pick), and coupon codes. The result updates as the inputs change.
2. **The real code paths.** `POST admin/simulator` builds a `Cart` in memory and runs it through `CartService::preview()` and `CheckoutService::preview()`, which call the same resolve and requote steps as a shopper's cart and checkout. Nothing is stored: no session, no cart token, no coupon use.
3. **One test per question.** `PriceRules::explain()` and `explain_fees()` share `why_not()` and the line pricing with `apply()` and `fees()`, so the reason shown is the one the cart used. Each rule gets a status: `applied`, `beaten` (another rule gives a lower price), `no_change`, `no_tier`, `off`, `scheduled`, `ended`, `customer`, `not_targeted`, `on_sale`, `unmet` or `waiting`, with the condition behind the last two.
4. **Coupons.** Each code entered is applied, rejected or waiting, with the reason the cart would give. Each automatic coupon that did not apply says why (`CouponService::skipped()`): a code in the cart that stands alone, its own conditions, its minimum spend, or an earlier automatic discount that stands alone.
5. **The rest of checkout.** The response also carries the shipping rates, the chosen rate and method, the totals with tax, the next-discount line and the problems (a product that cannot be added says so instead of failing the whole run).

**Alternatives.** A copy of the rule logic in JavaScript: fast, but it would drift from the server. A real cart under a test session: it would leave rows behind and count against stock checks and coupon limits.

**Consequences.** No `DB_VERSION` and still 61 settings. Product options are not simulated; a variable product must be added as one of its variations. There is no "as of a date" run: a rule with a future start or a past end shows as `scheduled` or `ended`.

**Revisit when.** Merchants ask to test a cart on a future date, with product options, or to save test carts and rerun them after editing rules.

## ADR-047: Promotion templates

**Context.** flexa-extra ships rule templates: a merchant picks "Bulk discount" or "BOGO" and gets a rule with the values filled in. FlexaCommerce had six presets inside the pricing rule editor and on the empty rule list, and nothing for coupons. A merchant who wants "free shipping over 75" or "a first order discount" had to know that it is a coupon, which type, and which condition.

**Decision.**
1. **One gallery, by goal.** A Templates button on the Coupons and Pricing rules tabs (and a link on the empty rule list) opens a gallery grouped by what the merchant wants to do: run a sale, grow the cart, sell more units, reward customers, charge a fee. Each card says what it creates (pricing rule, checkout fee, automatic discount or coupon code), so the merchant does not need to know where a promotion lives.
2. **Templates fill the editor.** Picking one switches to its tab and opens the pricing rule or coupon editor with its values. Nothing is saved until the merchant saves; targets (products, categories, roles, payment methods) are left for the merchant to pick, and the editor's checks say what is missing.
3. **Money follows the store.** Amounts are written for a currency with 2 decimals and scaled to the store's exponent when the editor opens; the descriptions show them in the store currency.
4. **The list.** Store-wide sale, category sale, clearance, discount code; spend and save, amount off a big order, free shipping over an amount; bulk discount, graduated tiers, buy one get one free, buy 2 get 1 half price, bundle price; wholesale price, first order discount (`order_count` at most 0, once per customer), loyal customer discount (`lifetime_spend` from 500); small order fee, payment fee, handling fee. The pricing rule editor keeps its "Start from" presets.

**Alternatives.** Templates stored on the server and editable by the merchant: more to build and test for a list that changes with releases. Creating the promotion straight from the card: one click, but it would save a rule with no targets or a coupon code the merchant never chose.

**Consequences.** Client only: no route, no `DB_VERSION`, still 61 settings. `TemplatePayloadTest` saves the values of the templates that need no targets, so a merchant who changes nothing gets no error.

**Revisit when.** Merchants ask to save their own templates, or to share them between stores.

## ADR-048: Gallery video and gallery editor

**Context.** flexa-extra adds one video to the product gallery: a YouTube, Vimeo or MP4 link, shown first or last, with the product image as its poster so the page makes no third-party calls. FlexaCommerce stored gallery images (`_fxc_gallery_ids`, ADR-023) but the admin had no way to edit them; only migration and REST could set them.

**Decision.**
1. **A Gallery tab.** The product data panel gets a Gallery tab: add several images from the media library at once, move them earlier or later, remove them. The product image stays in the sidebar, as in core, and the tab says it shows first. REST returns `gallery` (`id`, `thumb`, `alt`) beside `gallery_ids`; attachments that are gone are left out and dropped on the next save.
2. **One video per product.** `Catalog\ProductVideo` reads a YouTube link (watch, youtu.be, shorts, live, embed, m. and nocookie hosts), a Vimeo link (with the hash of an unlisted video, as a path segment or `?h=`), a link to an `.mp4`, `.m4v` or `.webm` file, or a media library video. Anything else is refused with field `video.url` (or `video.attachment_id` for a file that is not a video), and the editor flags the link while typing. It is stored as JSON in `_fxc_gallery_video`; a media library file keeps only its id. `ProductInput::video`: absent keeps it, null or an empty link removes it. Saving, duplicating, and FlexaCommerce to FlexaCommerce migration carry it.
3. **Placed by the merchant.** `position` is `second` by default (right after the product image), `first` or `last`. The product page needs no new setting.
4. **Nothing loads before play.** The video is slide `0` in the gallery context, with the product image as its cover. A layer over the main image holds a play button; on play, the iframe's `src` is bound from its `data-src` (youtube-nocookie.com with `rel=0`, Vimeo with `dnt=1`), or the file (`preload="none"`) plays. Leaving the slide (thumbnail, arrow, swipe, a variation's image) removes the iframe address or pauses the file, and coming back shows the play button again. Hover zoom and the full-screen view skip the video. Without scripts the layer stays hidden and the video's thumbnail links to the video.

**Alternatives.** A global setting for the video position (flexa-extra): one less field per product, but a merchant cannot put a demo first on one product and last on another, and Settings is near its 64-key budget. Several videos per product: more editor and context weight for a case few stores need. oEmbed through WordPress: it calls the video host when the page renders and returns markup we cannot keep lazy.

**Consequences.** No `DB_VERSION` and still 61 settings. A product with a video and no image shows no gallery, since the video needs a cover; the editor says so. `cart.js` grows by about 380 bytes gzip (8327 of 10240) and `storefront.css` by about 230 (5349).

**Revisit when.** Merchants ask for several videos, a custom cover image, or autoplay without sound.

## ADR-049: Badge customer conditions

**Context.** flexa-extra can show a badge to logged-in customers, to guests, or to chosen roles: "Member price" for logged-in shoppers, "Sign up for 10% off" for guests, "Trade" for wholesale roles. ADR-023 left this out because a badge drawn on the server ends up in cached pages. ADR-024 later accepted role and logged-in pricing on the basis that page caches keep logged-in pages apart from guest pages, which is how WordPress page caches behave by default (they skip pages for a request with a login cookie).

**Decision.**
1. **Four choices.** A badge gets `customers`: `all` (default), `logged_in`, `guests` or `roles`, with `roles` (a list of role slugs) for the last. These are the pricing rule choices plus `guests`, which a price rule has no use for and a badge does. Roles must exist (`wp_roles()->is_role()`), at least one is needed, duplicates collapse, and they are dropped when the badge no longer uses them. An unknown value is refused with field `customers`.
2. **Checked first, on the server.** `BadgeMatcher::for_product()` takes the user id and checks `Badge::shows_to()` before the product checks, so a badge for another audience costs nothing. Roles are read once per user per request, and only when a badge asks for them. `BadgeOverlay` passes `get_current_user_id()`. The overlay stays server-rendered: no request after load and no flash of a badge.
3. **The editor.** The badge dialog's last section becomes "Where, who and when", with a Customers select and role checkboxes, and help text that says guests never see a logged-in badge. The list line names the audience when it is not everyone. `GET /admin/badges` returns `roles` beside `items`, as the pricing rules route does.

**Alternatives.** Rendering the badges in the browser from a per-user request: correct under any cache, but every product list waits for a request, and badges appear after the images. Sending `Vary: Cookie`: most page caches ignore it, and it would split the guest cache by every cookie.

**Consequences.** No `DB_VERSION`, no new setting (still 61). Stored badges without the keys read as `all`. A page cache set to serve cached pages to logged-in users would show the badges of whoever filled it; that cache would already be wrong for pricing rules (ADR-024) and carts, so it needs no special handling here.

**Revisit when.** Merchants ask for conditions on purchase history (`order_count`, `lifetime_spend`, ADR-041) for badges, or a store runs a page cache that does not split by login.

## ADR-050: Pricing blocks and shortcodes

**Context.** flexa-extra has four pricing blocks with shortcodes: a pricing table, a discount badge, a countdown for one rule, and a cart nudge. FlexaCommerce already draws the same things in fixed places: the tier table and the countdown in the add-to-cart form (ADR-030), the progress to the next automatic discount in the cart (ADR-027). Merchants want them elsewhere too: a tier table in a landing page, a countdown in a banner, the progress bar in the header.

**Decision.**
1. **Four dynamic blocks, four shortcodes, one renderer.** `flexacommerce/quantity-prices`, `discount-badge`, `offer-countdown` and `discount-nudge`, with `[flexacommerce_quantity_prices]`, `[flexacommerce_discount_badge]`, `[flexacommerce_countdown]` and `[flexacommerce_discount_nudge]`. Blocks and shortcodes both end in `Storefront::block_html()` and `Render\PricingBlocks`, which reuses `QuantityTiers`, `Countdown` and `CartPresenter::next_discount()`, so the numbers match the product page and the cart. Product blocks take `productId` (shortcode `id`) and fall back to the current product, like the other product blocks.
2. **A placed block shows.** The Quantity prices block ignores `tier_table`, and a product countdown looks a year ahead instead of `countdown_days`: those settings are about what the product page adds on its own. To avoid two tables on a product page the merchant turns `tier_table` off. A standalone table carries `data-product`, so cart.js marks its row as the quantity in the form changes.
3. **Discount badge.** The largest percent off the regular price over the enabled variations, from `unit_price()` at quantity 1, so sales and pricing rules both count; rounded down, so it never claims more than the shopper saves. "Save N%" when every variation saves the same, "Up to N% off" when not, nothing when nothing is off. Unlike the image badges (ADR-023) it is text in the flow of the page.
4. **Countdown to a rule.** With `ruleId` (shortcode `rule`), `PriceRules::ends_of()` returns the rule's end when the rule changes prices, is live, has an end and is for this customer; its products and conditions are not checked, since the merchant picked the rule for that page. The editor lists the rules with an end date (from `GET /admin/pricing-rules`) and falls back to a number field for users who cannot manage pricing.
5. **Progress stays cacheable.** For a guest the bar is rendered hidden, never from the guest's cart, so a page cache can serve the page to everyone. `callbacks.nudge` reads the cart (`GET store/cart`) once when the count cookie is above zero, then the `noNudge`, `nudgeLabel` and `nudgeWidth` getters follow `state.cart`, which add-to-cart updates. A logged-in customer's page is rendered from their cart, since page caches skip logged-in pages (ADR-024, ADR-049). The server puts the rendered value in `state.nudge`.

**Alternatives.** Reading the setting in the placed blocks too: a merchant who turned the table off under the form would find the block empty with no reason shown. A `[flexacommerce_pricing type="..."]` shortcode: one tag is harder to remember than four named ones and the block names already exist. Rendering the nudge in a REST fragment for everyone: a request on every page view, also for empty carts.

**Consequences.** No `DB_VERSION`, no new setting (still 61). The editor script now depends on `wp-api-fetch`. storefront.css grows by about 60 bytes gzip and cart.js by about 300. Empty blocks show a short placeholder in the editor so they can still be found. The four shortcodes are registered on every request, which on PHP 8.5 macOS takes one more 4 KB allocator page on a page without commerce content (82,704 B over the plugin-off run, was 78,632 B); the Darwin-8.5 memory baseline is recorded again, the Linux ones are not measured here.

**Revisit when.** Merchants ask for the progress bar for free shipping thresholds, or for the discount badge to show amounts instead of percents.

## ADR-051: Pricing rules from WP-CLI

**Context.** flexa-extra has WP-CLI commands to list, turn on, turn off and delete its pricing rules. A store owner or agency uses them to switch a sale on at midnight from a cron job, to turn every fee off during an incident, or to check from a deploy script which rules are live.

**Decision.**
1. **`wp flexacommerce pricing-rule list|enable|disable|delete`**, next to `doctor`, `db` and `migrate`, registered only under WP-CLI. Several ids at once, so `disable $(wp flexacommerce pricing-rule list --type=fee --format=ids)` works.
2. **One row a rule, in the words of the Promotions screen.** Status is `live`, `scheduled`, `ended` or `off`, worked out the same way as the list on the screen. `change` says what the rule does in store money ("-12.5%", "price $8.99", "5+ -10%, 10+ -$1.50", "+3% fee (at most $5.00)"). `products` and `customers` sum up the targets ("1 category, 2 left out", "editor"). Dates show in the site time zone, as on the screen. `--status` and `--type` filter; `--fields` picks columns and refuses unknown ones.
3. **The repository is the only path.** Enable and disable save `enabled` through `PriceRuleRepository::save()`, delete through `delete()`, so the checks and the `flexacommerce.price_rules.saved` action (page cache clearing) are the same as from REST. A rule that no longer passes the checks (a role removed since) is reported and left unchanged. An id that is already in the wanted state is reported, not an error.
4. **Failures exit with 1.** Unknown ids are warnings after the found ones are done, then the command exits with 1, so a script notices. Delete asks first unless `--yes`.

**Alternatives.** Commands to create and edit rules: the dialog has many fields that depend on each other (tiers, targets, conditions), and a JSON payload is already accepted by `POST /admin/pricing-rules` for scripts. `wp option update` on the rules option: skips the checks and the cache action.

**Consequences.** No `DB_VERSION`, no setting, nothing loaded outside WP-CLI. `PriceRuleCommand::rows()` is a plain function of the rules, the time and the money format, which the integration test checks; the commands themselves were run against the bench store.

**Revisit when.** Merchants ask to create rules from a file, or to reorder them from the command line.

## ADR-052: Stripe gateway in the plugin

**Context.** The store had cash on delivery and bank transfer only. The first online gateway was planned as a first-party add-on (Q4), to prove the gateway contract before 1.0. Merchants coming from WooCommerce expect cards at checkout on day one, and most of them used the WooCommerce Stripe extension. A second plugin to install and keep in step with core is one more thing for the merchant to get wrong.

**Decision.**
1. **Built in, through the contract.** `Payments\Gateways\Stripe\StripeGateway` is a core gateway next to `cod` and `bacs`, off by default. It uses only the public contract (`AbstractPaymentGateway`, DTOs, `Signature`), plus the internal `ConnectedGateway` for the settings save, so an add-on gateway can do the same. No Stripe SDK: `StripeClient` calls the API over the WordPress HTTP API with the API version pinned (`2024-06-20`) and the transaction's idempotency key on every POST, so a retry after a timeout never charges twice.
2. **Payment Element, confirmed on the server, as the WooCommerce extension does.** `stripe.js` mounts the Payment Element with `paymentMethodCreation: manual` and the theme's colors and font. On place order it makes a PaymentMethod (through the new `fxc:payment` event), the server creates and confirms a PaymentIntent with it (`automatic_payment_methods`, a debit mandate for SEPA and the other bank debits). `succeeded` pays the order at once, `processing` waits for the webhook, `requires_action` sends the client secret to the browser only, where `handleNextAction()` runs 3-D Secure in a modal (`fxc:payment-action`), then the shopper goes to the return URL.
3. **Hosted page as the fallback.** No payment method in the request (scripts off, Stripe.js blocked, the order-pay page) means a Stripe Checkout Session for the order total, one line "Order N". Its PaymentIntent carries `fxc_session=1` so only the session's events count.
4. **The return is checked with the secret key.** `verify_return()` reads the PaymentIntent or the session: paid gives `succeeded`, a refused 3-D Secure gives `failed`, anything else waits for the webhook. A failed return fails that attempt only; the order stays `pending` and the shopper sees Stripe's reason on the order-pay page (`CheckoutService::last_failure()`). Card declines at checkout work the same way and show next to the payment methods.
5. **Webhooks.** `Stripe-Signature` is checked against the test and the live signing secret (five minutes tolerance, any `v1` may match, as during a secret roll). The store acts on `payment_intent.succeeded`, `canceled`, `payment_failed` (only for methods that fail after the shopper left, like SEPA), the four `checkout.session` events, and `charge.refund.updated` for refunds Stripe settles later. Amounts go through `StripeAmount`, since Stripe counts ISK in hundredths and some currencies differently from ISO 4217.
6. **Settings that check themselves.** Test and live keys side by side with a mode switch. On save the keys must have the right prefix for their field, and a secret key Stripe refuses (401) stops the save with Stripe's message (400 from the admin endpoint). A new key reads the account name and creates the webhook endpoint for this site (removing an older one for the same URL), storing its signing secret. The card shows what happened: "Connected to the Stripe account X in test mode", where updates go, or why the webhook could not be made with the steps and the event list to add it by hand. The account status lives in `flexacommerce_stripe_account`; gateway options do not count against the 64 settings.
7. **Refunds** go by PaymentIntent, by the session's PaymentIntent, or by charge (`ch_`, `py_`) for orders imported from WooCommerce, whose transactions now have `method = import` and can be refunded online. Stripe's 4xx refusals ("already refunded") are shown to the merchant; other errors keep the generic failure.

**Alternatives.** A separate add-on (the old Q4 answer): one more plugin, versioned on its own, for a gateway nearly every store turns on. The Stripe PHP SDK: about 2 MB of code for the dozen calls used here, and its own HTTP layer next to WordPress's. Confirming in the browser (`confirmPayment`) as Stripe's quickstart does: the browser would decide the amount and the order would depend on a redirect; confirming on the server keeps the amount and the idempotency key on our side, as the WooCommerce extension does. Checkout Session only: simpler, but every card payment leaves the site.

**Consequences.** No `DB_VERSION`, no new setting (still 61). `CheckoutFields` gains `data`, `PaymentResult::requires_action()` now leads to the return URL instead of the confirmation, and the gateway option save may throw. checkout.js grows by the two hand-off events (8148 to 8481 bytes gzipped); stripe.js (1822 bytes gzipped) loads only on a checkout with Stripe on. Out of scope: saved cards, Apple Pay and Google Pay buttons outside the Payment Element (Express Checkout, done in ADR-053), the authorize then capture UI, importing the WooCommerce Stripe extension's keys. Tests answer the Stripe API from a table through `pre_http_request`; a contract test runs the same calls against stripe-mock when `FXC_STRIPE_MOCK` is set (its fixtures have no webhook secret and static statuses, so it checks the requests, not the flow). The Payment Element itself needs js.stripe.com and real test keys, so it has no E2E test here; the merchant tries it with Stripe test cards.

**Revisit when.** Merchants ask for saved cards or Express Checkout buttons, or a second online gateway needs the hand-off events to carry more.

## ADR-053: Apple Pay and Google Pay buttons (Express Checkout)

**Context.** ADR-052 left wallet buttons out of scope: the Payment Element shows Apple Pay and Google Pay only as one more method inside the checkout form, after the shopper typed an address. Merchants coming from the WooCommerce Stripe extension expect the buttons on the product page, the cart and the top of the checkout, where a shopper with a wallet pays in two taps and never fills the form.

**Decision.**
1. **Stripe's Express Checkout Element, through an internal interface.** `Payments\ExpressGateway` (core gateways only, like `ConnectedGateway`) gives `express_config( place )` for `product`, `cart` and `checkout`, with the script that draws the buttons. `Storefront\Render\ExpressButtons` renders a hidden box for the first enabled gateway that answers; `stripe-express.js` mounts the element with `paymentMethodCreation: manual` and shows the box only when the device has a wallet. Link, PayPal, Amazon Pay and Klarna stay off, so the buttons mean wallets only.
2. **The wallet sheet shows our numbers.** `POST store/express` quotes the cart (or one product) for the address the wallet shares: total, the subtotal, fee and tax lines, and the shipping rates. It stores nothing, so a shopper who closes the sheet finds the cart and its address as before. The sheet opens on the last quote (Stripe wants it within a second of the click); a change of address or rate asks again, and an address without rates is refused in the sheet.
3. **The order goes through the same checks as the form.** `POST store/express/order` builds a `CheckoutRequest` from the wallet's billing and shipping contacts (the name split in two, a state name mapped to its code by `Countries::state_code()`, a billing address taken from the shipping one when Google Pay sends only part of it) and calls `place_order`, with `expected_total` from the quote. The PaymentMethod the wallet made goes to the same server-side confirm as a card (ADR-052), so 3-D Secure, declines and webhooks behave the same. The order's payment title reads "Card (Apple Pay)" and `_fxc_express` keeps the wallet.
4. **Buy now leaves the cart alone.** On a product page the button buys the chosen variant, quantity and options as a cart of one line, built by `CartService::single()` with the same stock and option checks as add to cart. `CheckoutService::place_single()` prices that cart and places the order without touching the shopper's stored cart, idempotent by a checkout token the browser makes for each attempt. The button checks the add-to-cart form first and asks for a choice when a variant is missing.
5. **Fields a wallet cannot fill.** A required field outside name, address, email and phone (a company, a field the merchant added, the order note) keeps the buttons off the product page and the cart. On the checkout the buttons stay; a click first checks those fields in the form, and their values go with the order.
6. **Settings.** Three checkboxes (product, cart, checkout, all on) and a button color on the Stripe card. Saving the keys registers the site's domain with Stripe (`payment_method_domains`), which Apple Pay needs; the card says whether that worked, or gives Stripe's reason and where to add the domain by hand. A terms line under the buttons links the terms page, since the wallet skips the form's checkbox.

**Alternatives.** The Payment Request Button: older, one wallet per device, and Stripe moves stores to the Express Checkout Element. Adding the product to the cart before the sheet opens (as some plugins do): a closed sheet would leave the product in the cart, and a shopper with a full cart would pay for all of it. Confirming in the browser with `confirmPayment`: the same reasons as ADR-052, the amount and the idempotency stay on the server.

**Consequences.** No `DB_VERSION`, no store setting (still 61); the new keys live in the Stripe option. Catalog pages must not build the gateways (O7), so saving a gateway's settings writes `flexacommerce_wallet_places` (gateway id to the places with buttons, `Support\WalletPlaces`) and the buttons read only that. It is seeded empty and autoloaded like the automatic coupon flag (ADR-026): P10 goes from 5 to 6 autoloaded options (6 bytes), and a store without wallets pays no query on product pages, the cart and the checkout. stripe-express.js is 4142 bytes gzipped and loads only on pages with the buttons; storefront.css grows to 5511 bytes. Both routes are store routes with the usual session nonce; the order route has the checkout rate limits. Tests answer Stripe from the table (ADR-052) and check the quote, buy now with the cart left as it was, the token, the wallet in the title, the domain notes and the rendering rules. The element itself needs a wallet on a real device and HTTPS, so it has no E2E test here; the merchant tries it with Stripe test keys in Safari or Chrome.

**Revisit when.** A second gateway wants wallet buttons (then `ExpressGateway` becomes part of the contract), or merchants ask for the buttons in product lists or the mini cart.

## ADR-054: PayPal gateway and PayPal buttons

**Context.** Product scope had PayPal as an add-on through the gateway contract. Stripe is now built in (ADR-052, ADR-053), and most stores moving from WooCommerce also take PayPal (WooCommerce PayPal Payments). Asking them to find and install a second plugin for the most common wallet in the US and Europe is the opposite of what the merchant wants, so PayPal moves into the plugin with the same two parts as Stripe: a payment method at checkout and buttons on the product page, the cart and the top of the checkout.

**Decision.**
1. **Orders API v2, no SDK on the server.** `Payments\Gateways\PayPal` talks to PayPal over `wp_remote_request`: `PayPalClient` gets an OAuth token from the client ID and secret (kept in `flexacommerce_paypal_token`, not autoloaded, fetched again on a 401), sends `PayPal-Request-Id` on every write, and throws `PayPalError` with PayPal's `issue` for a refusal. Sandbox and live each have a client ID, a secret and a webhook ID.
2. **The checkout method opens PayPal's window on the order the server made.** With PayPal chosen, `paypal.js` puts PayPal's buttons (PayPal, Venmo, Pay Later, card, as PayPal offers them) in place of the place button. A click checks the form through a new `fxc:place` event on checkout.js (`detail.check` only checks; otherwise it submits and `detail.placed` says whether the request went out). The order is placed as usual with `flow=popup`; `process_payment` creates the PayPal order (`custom_id` = the transaction, the address as `SET_PROVIDED_ADDRESS`, retried without it when PayPal refuses the address) and returns `requires_action` with its id, which the window opens. When the window closes, checkout.js goes to the return URL and `verify_return` reads the order with the secret: approved is captured, unapproved fails the attempt so the shopper can pay again. Without scripts, or when PayPal's script does not load, the place button stays and `process_payment` returns `redirect` to PayPal's approval page.
3. **The buttons let PayPal collect the address.** `ExpressGateway` buttons now render one row per gateway, so Stripe's wallets and PayPal share the box. A gateway whose buttons keep a sheet open with its own order implements `Payments\ExpressSession`: `POST store/express/session` quotes the cart (or one product, ADR-053) and opens the PayPal order with the amount breakdown and the shipping rates as PayPal's options, or brings an open one to the new address and option (PATCH). On approval `store/express/order` gets the PayPal order id in `gateway_input.session`; the server reads the payer's name, email and address from PayPal (`express_contact`), never from the browser, checks the approved amount against the store order and captures it. Buy now leaves the cart alone as in ADR-053.
4. **One capture per order.** The return, the webhook and a retry send the same request id (`fxc-capture-{order}`); `ORDER_ALREADY_CAPTURED` reads the order instead. A capture PayPal holds (eCheck, review) leaves the payment pending until `PAYMENT.CAPTURE.COMPLETED`. A declined instrument fails the attempt with PayPal's issue code.
5. **Webhooks are checked by PayPal.** PayPal signs with a certificate chain; the gateway posts the headers and the body, byte for byte, to `verify-webhook-signature` with the stored webhook ID and accepts only `SUCCESS`. `CHECKOUT.ORDER.APPROVED` for a checkout order (one with a `custom_id`) is captured, which covers a shopper who approved and never came back; `PAYMENT.CAPTURE.*` pay or fail it; `PAYMENT.CAPTURE.REFUNDED` settles a pending refund.
6. **Saving the credentials sets everything up.** `connect()` gets a token (a refused ID and secret saves nothing and says which mode they did not match), creates the webhook for the site's URL with the five events, or finds the one an earlier install made and updates its events. When PayPal cannot add it (a local site without public HTTPS) the card says why and how to add it by hand; payments still confirm on the return.
7. **Refunds by capture, imported orders included.** A refund reads the PayPal order for its capture and refunds that. Orders imported from WooCommerce PayPal Payments (`ppcp-gateway`, `ppcp-credit-card-gateway`, mapped to `paypal` by `WooOrderReader`) keep the capture id as their reference, so a 404 on the order refunds the reference as a capture. PayPal refusing a refund tells the merchant its reason.
8. **Settings.** Mode, the credentials per mode, the three button places (all on), a button color and Pay Later (on). Venmo and Pay Later are named on the order like the wallets ("PayPal (Venmo)").

**Alternatives.** PayPal's partner onboarding (a "Connect with PayPal" button): it needs a partner account and a server of ours in the middle; pasting the app's ID and secret works for every merchant today and can sit beside it later. Creating the PayPal order in the browser (`actions.order.create`): the amount would come from the page, and it is deprecated. Authorize then capture: no UI yet, as for Stripe (ADR-052).

**Consequences.** No `DB_VERSION`, no store setting (still 61); the keys live in the PayPal option, the status in `flexacommerce_paypal_account`. Catalog pages still read only `flexacommerce_wallet_places` (ADR-053). paypal.js is 1516 bytes gzipped and loads only on a checkout with PayPal on; paypal-express.js is 2883 bytes and loads only where its buttons are; PayPal's own SDK loads from paypal.com on those pages. checkout.js grows to 8648 bytes for `fxc:place`, stripe-express.js to 4210 bytes for the shared box, storefront.css to 5516 bytes. Tests answer the PayPal API from a table through `pre_http_request` (`FakePayPal`) and cover connect, the popup and redirect flows, the return, declines, webhooks, refunds (an imported order too), the session route and the buttons. PayPal's window needs PayPal's sandbox and a browser, so it has no E2E test here; the merchant tries it with sandbox accounts.

**Revisit when.** PayPal opens partner onboarding to us, merchants ask for saved PayPal accounts (vaulting), or a third gateway wants buttons.

## ADR-055: Shop filters with counts, price and stock

**Context.** The product filters block offered categories and global attributes as plain checkboxes. A shopper could not filter by price or by stock, could not see how many products a choice would list, and had to read the form again to see what was chosen. WooCommerce stores have price, attribute, stock and active-filter blocks; a store moving over expects the same in one place.

**Decision.**
1. **URL parameters stay WooCommerce's.** `min_price` and `max_price` are shown prices in major units (a decimal comma reads as a point, a negative bound is ignored, swapped bounds are put right). `filter_stock_status` takes `instock`, `onbackorder` and `outofstock`, comma-separated like the other `filter_*` parameters. Links shared from a WooCommerce shop keep working.
2. **The price matches the product's range.** A product matches when its stored range (`_fxc_price_min` to `_fxc_price_max`) meets the asked range, so a variable product from 10 to 30 shows for "up to 15". The shown bounds are turned into stored amounts with the standard rate at the store address when the shop shows prices with tax and stores them without (or the other way round), as WooCommerce does; the low bound rounds down and the high one up so a product shown exactly at a bound stays.
3. **Counts per group, among the other filters.** `ShopFacets` counts each value among the products that match every other filter, so picking a second colour adds to the list and the counts say by how much. The product set is the listing the block sits on (the shop, a category or tag with its children, a search). It builds the ids query with `WP_Query` (the same filters and visibility as the main query, stopped by `posts_pre_query` before it runs) and `ShopFacetRepository` counts inside it: one query per attribute, one for categories (rolled up to parents in PHP), one for stock, one for the price bounds. The ids query is read as a derived table (`IN ( SELECT ID FROM ( ... ) AS fxc_ids )`): as a plain `IN` subquery MariaDB 11.8 now and then counted products the subquery leaves out. Results are kept in the object cache until a product or a term changes.
4. **The form.** Chosen filters show first as links that each remove one (the price as one link), with "Clear filters". Values with no products are left out unless chosen. Swatch attributes show their colour or image beside the name. The price group has two number boxes with the listing's lowest and highest shown prices as placeholders. With scripts, a ticked box applies at once and empty price boxes stay out of the address; without, the Apply button submits the same GET form. The sort form keeps the price as it keeps the other filters.
5. **Block settings.** Categories, price, availability and counts can each be turned off; the attributes are picked from a list in the block sidebar (all when none is picked). Counts off falls back to WordPress's own term counts to leave out unused values.

**Alternatives.** A slider for the price: it needs a script to work at all and is harder to use with a keyboard; two boxes work without one. A precomputed facet table: faster on very large catalogs, but it is a second copy of the catalog to keep right; the cached queries are enough until a store shows otherwise. Counts that ignore the other filters (WordPress term counts): cheap, but they promise products that the list then does not show.

**Consequences.** No `DB_VERSION`, no setting (still 61). The price filter reads the stored prices, so it does not see pricing rules (ADR-024) or a customer's group price; a product a rule brings under the bound is not listed. The block stays out of the default shop templates: its count queries would break the P2 and P11 query budgets, so a merchant adds it to a sidebar or widget area where it is wanted. cart.js grows to 8809 bytes gzipped for the form callback, storefront.css to 5638 bytes. Tests cover the price and stock filters, counts that skip their own group, category roll-up, the chips and their links, and the sort form keeping the price.

**Revisit when.** A store with tens of thousands of products finds the counts slow (move them to a facet table), or merchants ask for the price filter to follow pricing rules.

## ADR-056: Product reviews and star ratings

**Context.** Product scope had reviews as post-MVP, with WordPress comments as the likely store. A store moving from WooCommerce has reviews on its products and stars in its lists, and its shoppers expect them on the product page. The migration left them on the WooCommerce post (Q14), where they disappeared at cutover.

**Decision.**
1. **Reviews are WordPress comments, kept as WooCommerce keeps them.** A comment on an `fxc_product` is given the type `review`; the star rating (1 to 5) and the verified-buyer flag are comment meta `rating` and `verified`. A reply has no rating and the same type. Core posts the form, moderates, notifies and lists them in **Comments**; the FlexaCommerce menu gets **Reviews**, which opens that screen filtered to reviews, with each review's stars before its text.
2. **One setting, `reviews`.** Anyone (the default, which follows the Discussion settings such as moderation and logged-in only), customers who bought the product, or nobody. A buyer is a logged-in customer with a processing or completed order holding the product, matched by account or by order email, so a guest order counts once its customer has an account. Every review from a buyer gets "Verified buyer", whatever the mode. With reviews off the form goes and approved reviews still show. A review without a rating is refused; only moderators may reply, and they may always write.
3. **The summary lives in product meta.** `_fxc_rating` (the average, two decimals), `_fxc_review_count` and `_fxc_rating_counts` (per star) are rebuilt from approved top-level reviews when a review is added, edited, approved, held or trashed. The stars in lists and the `rating` sort read only that meta, so a product list runs no comment query.
4. **Two blocks.** `product-rating` (stars and "N reviews", linked to the reviews section; nothing for a product without reviews) sits under the title on the product page and in the product grid. `product-reviews` shows the average with a bar per star, the approved reviews newest first (10 a page, filter `flexacommerce.reviews.per_page`, `?reviews-page=` links), the visitor's own review while it waits, the store's replies and core's comment form. The star field is five radio buttons, so it works without JavaScript and with a keyboard. Both are in the default block templates and the classic `single-product.php` (now `@version 1.1.0`).
5. **Sort by rating.** `?orderby=rating` sorts by the average, highest first. Products without reviews come last. The sort joins the meta key itself (`ShopQuery::order_by_meta`), because a `meta_query` OR of `EXISTS` and `NOT EXISTS` joined every meta row of a product without the key and sorted by whichever came first. The price sorts use the same join, which fixes the same fault for products without a price: they now come last in both directions.
6. **Migration stage `reviews`.** It runs last. Both stores read and write with one `ReviewReader` and one `ReviewWriter`; replies depend on the review they answer. Held reviews stay held, spam and trash stay behind, the customer is kept, and the target rebuilds its summary (`WC_Comments::clear_transients` on WooCommerce). WooCommerce keeps replies as plain comments, FlexaCommerce as reviews. The settings stage maps `woocommerce_enable_reviews` and `woocommerce_review_rating_verification_required` to `reviews` and back.
7. **Hooks only where reviews are.** `Reviews::register()` runs in admin, REST and WP-CLI requests, on `wp-comments-post.php`, and when the storefront renders a review block, so other pages carry no extra callbacks (P1).

**Alternatives.** A reviews table: faster for huge catalogs, but core's moderation, spam checks, notifications and the comments screen would all have to be rebuilt, and both stores already use comments. Photo reviews, review voting and review reminder emails: left for later. A star widget built in JavaScript: radio buttons do the same job with no script.

**Consequences.** No `DB_VERSION`. The store settings grow to 62 keys. storefront.css grows to 6228 bytes gzipped (6216 as P8 measures it). Tests cover the type and summary, held and trashed reviews, the rating and reply rules, buyers mode and a pending order, reviews off, the rendered blocks, the rating sort, the price sort without a price, and the migration stage both ways with replies, a reply whose review was not imported, and the setting.

**Revisit when.** Merchants ask for photos, votes or reminder emails, or a store with a very large number of reviews finds the comment queries slow.

## ADR-057: Guest order lookup

**Context.** Product scope had guest order lookup as post-MVP: the order key link in the emails was enough. A guest who deleted the email, or who opens the store on another device, had no way back to their order, and stores coming from WooCommerce have the `[woocommerce_order_tracking]` page for it.

**Decision.**
1. **Order number and billing email.** The form asks for both; a leading `#` is dropped and the email matches case-insensitively. A match redirects (303) to the order's page on the account page with the order key in the URL (`view-order/{id}/?key=`), or to the order confirmation when the store has no account page. The key then works as it does in the emails (doc 06 §2.7): the order key failure limit applies, and a wrong key looks like a missing order.
2. **Where the form is.** Under the sign-in form on the account page for logged-out visitors, so a store gets it without a new page or setting. The `order-lookup` block and the `[flexacommerce_order_tracking]` shortcode put it on any other page.
3. **No nonce, a failure limit instead.** The form only reads, and a nonce on a cached page goes stale and refuses real customers. The post must come from this site (`SameSite`), and failures (an unknown number or a wrong email, with one message for both) count toward `order_lookup_ip`, 10 per hour per IP, filter `flexacommerce.rate_limit.order_lookup_ip`. Empty or malformed fields are reported per field and do not count. A blocked IP is told to try later, even with the right answer.
4. **The order page for a key holder.** A guest, or a logged-in customer who is not the owner, sees the order (status sentence, pay link, order updates, items, fields, addresses) without the account navigation, with a link back to the lookup. The owner gets the usual account page.
5. **Literal gate.** `Plugin::on_template_redirect()` checks `$_POST['fxc_order_lookup']` literally, so other requests do not load `OrderLookup` (P1). A successful lookup fires `flexacommerce.order.looked_up`.

**Alternatives.** Emailing the order link instead of showing the order: safer against someone who knows a customer's email and order number, but slower for the shopper, and WooCommerce shows the order right away. A separate tracking page created on activation: one more page and setting (the settings are capped at 64 keys) for something the account page already hosts.

**Consequences.** No `DB_VERSION`, no new setting. storefront.css gains two short rules. Tests cover the form on the account page, the block and the shortcode, a match with `#` and upper-case email, a wrong number and a wrong email with one message and the counter, field errors that do not count, the limit (and another IP passing), a foreign origin, the guest and non-owner views by key, the owner view, and a wrong key.

**Revisit when.** Stores report lookups by guessing, or merchants want the lookup to send the link by email.

## ADR-058: Downloadable and virtual products

**Context.** Assumption A5 kept the store to physical goods, and migration imported a WooCommerce downloadable product as a physical one with a warning. Ebooks, templates, music and software licences are a common first product for small stores, and WooCommerce stores that sell them could not move without losing the files and the customers' access.

**Decision.**
1. **Flags and files on the variant.** `DB_VERSION` 10 adds `is_virtual`, `is_downloadable` and `downloads` (JSON) to `fxc_variants`, so a variation can sell its own files, as in WooCommerce. `Catalog\Downloads` holds up to 50 files (`id`, `name`, `file`), `limit` (downloads per unit bought, null for no limit) and `expiry_days` (days after payment, null for never). A file keeps its id across saves, so customers keep their links when the merchant renames it or replaces the file behind it. A virtual line needs no shipping address and no shipping rate.
2. **Where files may live.** A local file must sit under the uploads folder (the filter `flexacommerce.downloads.allowed_paths` adds folders) and have a file type WordPress allows; anything else is refused on save with a field error. Any other URL is remote. Files uploaded from the product editor carry `fxc_download`, which sends them to `uploads/flexacommerce_uploads`, a folder with an `.htaccess` that refuses every direct request and an empty `index.html`.
3. **Permissions table.** `fxc_downloads` has one row per order line and file, with a random 32-character token, the remaining downloads, the expiry, the count and the last download. The token is the credential: `?fxc_download=<token>` works from the email without signing in, and a site can require the buyer's account with `flexacommerce.downloads.require_login`. The limit is multiplied by the quantity, and the expiry counts from the paid date.
4. **When access starts and ends.** Permissions are granted when the order reaches processing or completed, from the order event's view. A link only works while the order is processing or completed, so a refunded or cancelled order loses access (403). Used up gives 410, expired 410, an unknown token 404. An order where every product line is virtual and downloadable completes itself after payment; the filter `flexacommerce.order.needs_processing` keeps such an order at processing for a store that checks orders by hand.
5. **Delivery.** The setting `download_method` (63 keys) picks `force` (PHP streams the file in chunks, with `Range` support), `xsendfile` (X-Accel-Redirect on nginx, X-LiteSpeed-Location on LiteSpeed, X-Sendfile on Apache with mod_xsendfile; any other server falls back to `force`) or `redirect`. Remote files always redirect. A redirect cannot reach the protected folder, so those files fall back to `force`. A range that does not start at byte 0 is the rest of a download already counted and is not counted again. Responses send `nocache_headers()` and `X-Robots-Tag: noindex, nofollow`; `flexacommerce.download.started` fires before the file goes out.
6. **Product edits.** `DownloadService::sync_product()` runs after a save: a file added to a product is granted to the paid orders that bought it, and a removed file loses its permissions.
7. **Where customers see links.** The order confirmation, the customer's order emails (processing and completed), the account `downloads` endpoint and the order page on the account. Each shows the file name, the downloads left and the expiry.
8. **Admin.** The product panel has the Virtual and Downloadable toggles and the files editor; Settings has a Downloads card for the method. The order screen has a Downloads card: grant missing files (`POST admin/orders/{id}/downloads`), edit the downloads left and the expiry date (`PATCH admin/orders/{id}/downloads/{permission}`, a field left out keeps its value), and remove access (`DELETE`). It copies a link instead of opening it, because opening it would use one of the customer's downloads.
9. **Privacy and migration.** The privacy exporter lists the permissions of an email with counts and dates, and the eraser deletes them. Migration moves the flags, files, limit and expiry in both directions, and the order permissions with their counts and dates (doc 05 §5.2, §5.4, §10). A file that does not pass the checks of the target is dropped with `download_dropped`; a permission for a product that was not imported is skipped with `download_skipped`.
10. **Literal gates and query budget.** `Plugin::boot()` checks `$_GET['fxc_download']` and `$_POST['fxc_download']` literally, so other requests do not load the handler (P1). Checkout skips the auto-complete lookup unless the new order's lines are all downloads, and `OrderItemView::downloadable` lets the grant skip the variant lookup for lines that sell no files, which keeps the checkout fixed query count at 58 (P6q, limit 60).

**Alternatives.** Signed, expiring URLs with no table: no per-file counts, and no way to remove one customer's access. Files as attachments only (no URLs): simpler, but stores that host files on a CDN or S3 could not use them, and WooCommerce imports would lose files. Granting at payment instead of on status: a store that holds orders at on-hold for bank transfers would hand out files before the money arrives.

**Consequences.** One table, three columns and one setting. A5 is gone: the store can sell physical goods, files, and services without shipping. The non-goal "digital downloads" in doc 01 now only covers licence keys and file streaming services. Tests cover granting and auto-complete, a mixed order that stays processing, counting, the limit per unit, used-up, expired, refunded and unknown links, the range rule, require_login, the redirect and protected folder rules, sync on product edits, paths outside uploads, the account page, the admin routes and a WooCommerce round trip with counts kept.

**Revisit when.** Merchants ask for licence keys, per-customer watermarking, or files on private cloud storage that need signed URLs.

## ADR-059: Product CSV import and export

**Context.** The migration subsystem moves a whole WooCommerce store, but merchants also keep products in spreadsheets: a supplier price list, a bulk price change, a catalogue built in Excel, or a file exported from another WooCommerce store. Doc 01 listed CSV as post-MVP. WooCommerce's CSV format is the one those files already use, and suppliers and other tools write it.

**Decision.**
1. **WooCommerce's columns.** `Catalog\Csv\CsvColumns` uses WooCommerce's keys and English headers ("Regular price", "Attribute 1 value(s)", "Download 1 URL", "Meta: key"), so a file exported from either store imports into the other. A header also matches when it is a key, a translated label of the import screen, or a weight or dimension with any unit ("Weight (lb)"); a value in another unit is converted to the store unit. WooCommerce columns that have no field here (GTIN, upsells, cross-sells, grouped products, external URL, shipping class, purchase note, sold individually, brands) are recognised, shown as "Not imported" and skipped. Grouped and external products are errors.
2. **Values.** Lists are joined with ", " and a comma inside a value is written `\,`; a newline in a description is written as a literal `\n`. Published is 1 publish, 0 private, -1 draft, 2 pending. Stock is empty when not managed, and "parent" on a variation that takes stock from the product. Prices accept "12.50" and "12,50". Dates accept `Y-m-d`, `Y-m-d H:i` and WooCommerce's `Y-m-d G:i:s`, in the site timezone. Categories are paths ("Clothes > Winter"); missing categories, tags, attributes and values are created. Images are attachment ids or URLs: a URL of this site is looked up, any other is downloaded once and remembered by `_fxc_source_url`, so importing the same file again reuses the copy.
3. **Export.** `ProductCsvExporter` writes 50 products a page, each variable product followed by its variations. The layout (how many attribute and download columns, and which custom fields with "Include custom fields") is read before page 1 and sent back with the later pages, so every page has the same columns. The file starts with a UTF-8 BOM for Excel, and a cell starting with `=`, `+`, `-`, `@`, tab or carriage return gets a leading `'` so a spreadsheet does not run it as a formula. Custom fields whose key starts with `_` are never exported.
4. **Import.** The browser reads the file (UTF-8, or Windows-1252 when it is not valid UTF-8; comma or semicolon), shows each column with its first value and the field it will fill, and sends rows in batches of about 10. A batch grows while the next row is a variation, up to 200 rows, so a variable product and its variations are saved together. A row matches an existing product by SKU, then by ID; the ID only counts when "Update existing products" is on. With update off a matching row is skipped, and so are its variations. Variations of one parent are saved in one `ProductService::save()`; a variation that matches an existing one by SKU or by its attribute values is updated or skipped. A SKU used twice in the file is an error on the second line.
5. **Empty cells.** An empty list cell (categories, tags, images, attributes, downloads) keeps what is stored, because WooCommerce exports leave them empty on variations and in partial files. An empty scalar cell (sale price, dates, stock, weight, description) clears the value, so a file can remove a sale price.
6. **Check first.** "Check the file" runs the same reading and validation with `dry_run` and writes nothing: new rows get pseudo ids so variations find their parent, and nothing is downloaded. The result lists errors, skips and warnings ("The category X will be created") by line, with a CSV report to download. Only after the check does the screen offer "Import products". A run can be stopped between batches.
7. **Context between batches.** `ImportContext` holds the SKUs and ids that earlier batches created, so a variation finds a parent from an earlier batch and a repeated SKU is caught across batches. The REST route keeps it in a transient named by the user and a random session id from the screen, for one day.
8. **Where.** The screen is `admin.php?page=flexacommerce-product-csv`, opened from Import and Export buttons next to "Add new product" on the product list; it has no menu item of its own and highlights Products. Routes `GET admin/products/csv/export`, `POST admin/products/csv/map` and `POST admin/products/csv/import` need the manage capability. `wp flexacommerce products export [<file>] [--meta]` and `wp flexacommerce products import <file> [--update] [--dry-run]` use the same exporter and importer, 50 rows a batch.
9. **Writes go through the catalogue.** Every product is saved with `ProductService::save()` and `wp_insert_post()`/`wp_update_post()`, so the editor's validation, the SKU index, stock rules, `flexacommerce.product.saved` and `flexacommerce.product.price_changed` behave as in the editor. Custom fields starting with `_fxc`, `_wp_`, `_edit_` or `_thumbnail_id` are never written from a file.

**Alternatives.** The migration JSONL format: complete, but nobody edits it in a spreadsheet. Our own column set: shorter, but every supplier file and WooCommerce export would need mapping by hand. Uploading the file and parsing it on the server: a large file would need a stored upload and a queue; the browser already has the file and batches keep each request short. Treating an empty cell as "keep" everywhere: there would be no way to clear a sale price from a file.

**Consequences.** No table, no setting and no autoloaded option; the screen is lazy and loads nothing on other pages. Tests cover the column matching and value rules (unit), an export that imports back with update, a variable product with a category path and a decimal comma, a check that writes nothing, a skipped existing SKU and a duplicate SKU (integration), a real WooCommerce export of the golden store on legacy and HPOS storage (simple, sale dates, a draft, a variable product with an "any" value, a grouped product and a duplicate SKU), and the screen end to end with a semicolon file.

**Revisit when.** Merchants ask for scheduled imports from a URL, column mappings saved between runs, or the fields WooCommerce has and FlexaCommerce lacks (GTIN, upsells, cross-sells).

## ADR-060: PDF invoices and packing slips

**Context.** Doc 01 listed invoices as post-MVP and Q15 asked about gapless invoice numbers. Most stores need an invoice the customer can download and a packing slip for the parcel, and in many countries the invoice needs its own numbering with no gaps from abandoned checkouts. WooCommerce stores get this from a plugin; merchants expect it in the box. The plugin has no runtime dependencies, so a PDF library (dompdf, TCPDF, mPDF) would be the first, and the Settings option has one key left (64 at most).

**Decision.**
1. **Our own PDF writer.** `Invoices\Pdf\PdfDocument` writes PDF 1.7 with text, lines, filled rectangles and images, and compresses every stream. `TrueTypeFont` reads a TrueType file, keeps only the glyphs used (with the parts of composite glyphs) and embeds them as a Type0 font with Identity-H and a ToUnicode map, so text can be searched and copied. Noto Sans Regular and Bold ship in `assets/fonts` (SIL Open Font License) and cover Latin, Greek and Cyrillic; `flexacommerce.invoice.fonts` swaps in other fonts. `PdfImage` takes JPEG as it is and 8-bit PNG with its rows, the alpha channel as a soft mask; other images go through GD first. About 1,000 lines in all, and an invoice is 10 to 20 KB.
2. **Numbers.** An order gets its invoice number the first time an invoice is made for it: the admin opens or assigns it, an email carries it, or the customer downloads it. Numbers come from `fxc_sequences` (`invoice_number`, or `invoice_number_YYYY` with the yearly reset) and are stored as `_fxc_invoice_number` and `_fxc_invoice_date` in `fxc_order_meta`. A `GET_LOCK` per order (10 seconds) makes two requests at once give one number. The format is prefix + number padded to the minimum digits + suffix, and the prefix and suffix may hold `{year}` and `{month}` of the invoice date in site time. "Next number" in the settings raises the counter and refuses a lower value, so a number is never given twice. A packing slip never numbers the order.
3. **Settings.** One option `flexacommerce_invoices`, not autoloaded: prefix, suffix, padding, reset, the emails that attach the invoice, customer access, shop name, address, extra details, logo, footer, paper and show SKUs. Empty shop fields use the site title and the store address from the general settings; the paper is Letter for a store in the US or Canada and A4 elsewhere unless chosen. `EmailSettings::prime()` reads the option with the email options in one query, so an order email adds none. Settings > Invoices edits it and sends only the changed fields.
4. **Layout.** The header has the logo (a medium-size copy when WordPress made one), the shop name, address and details, and on the right the title with invoice number and date, order number and date, and the payment method (the shipping method on a packing slip). Then bill-to and ship-to addresses (swapped on a packing slip), the items with the same attribute lines as the order confirmation and their SKUs, and on the invoice the totals the order confirmation shows: subtotal, coupons, shipping, fees, taxes, total, refunded and amount due. The customer's note and the footer end it, with "Page X of Y" per order. A long table breaks onto new pages and repeats its header. Each order is drawn in its own locale.
5. **Where.** The order page has an Invoice card with the number, "Invoice PDF", "Packing slip", "Assign number" and the customer link. The order list has checkboxes; the bulk bar links to one PDF with an invoice or packing slip per selected order, up to 100. Both go through `admin-post.php?action=fxc_invoice` with the REST nonce. Customers get "Download invoice (PDF)" on the order confirmation and the account order page, a link to `?fxc_invoice=<id>&key=<key>`. `wp flexacommerce invoice <order-id>... [--file=] [--packing-slip]` writes the file.
6. **Emails.** The new filter `flexacommerce.email.attachments` lets the invoice module add the PDF to the emails chosen in the settings (none by default). The file is written to a random temp folder and deleted on the new action `flexacommerce.email.sent`. If the PDF fails, `flexacommerce.invoice.failed` fires and the email goes without it.
7. **Customer access.** "When the order is paid" (the default), "After you make the invoice" or "Never". The link needs the order key or the owner's account, an anonymized order never has one, and every refusal is a 404.

**Alternatives.** dompdf or TCPDF: complete HTML or drawing APIs, but 2 to 10 MB of third-party code with its own security history, autoloaded on every invoice, for a layout that is a header, a table and totals. HTML for the browser to print: no file to attach to an email, and the output depends on the browser. Using the order number as the invoice number: it has gaps from abandoned checkouts. A Settings key per field: there is room for one.

**Consequences.** No table and no new Settings key; one non-autoloaded option, two order meta keys and a sequence row per counter. The storefront loads nothing unless `?fxc_invoice` is in the URL (literal check in `Plugin::boot()`, P1). Only Latin, Greek and Cyrillic render with the bundled fonts; other scripts need the font filter. Tests cover the font subset and PDF structure (unit), numbering, the yearly counter, raise-only next numbers, page breaks, bulk files, the email attachment and its cleanup, customer access by setting, key and status, the admin routes and their capabilities (integration), and the settings tab, the order card, the guest's link and both PDFs end to end.

**Revisit when.** Merchants ask for invoice templates beyond the settings, right-to-left scripts, or migration of invoice numbers from WooCommerce invoice plugins.

## ADR-061: Orders the admin creates and edits

**Context.** An order could only come from the storefront checkout. Merchants take orders by phone, email and chat, send quotes, add a forgotten item before the customer pays, and fix a typo in an address after payment. In WooCommerce they do this on the order screen. Without it a merchant would have to place the order as the customer, or keep a second tool.

**Decision.**
1. **Priced as a cart.** `Checkout\AdminCheckout` turns the editor's draft (`AdminDraft`: product lines with their variation choices and option values, the customer account, billing and shipping addresses, coupon codes, the shipping rate, the payment method and the customer note) into a `Cart`, prices it with `CartService::preview()` for the chosen account and quotes it with `CheckoutService::quote()`. The admin gets the prices, pricing rules, automatic discounts, coupon rules, fee rules, shipping rates and taxes the customer would get at checkout; nothing is typed by hand. `CartService::admin_line()` builds a line with the storefront's checks (variation match, required options, stock), and `CheckoutService::order_items()` writes the order lines checkout writes.
2. **What may be left out.** The address may be incomplete (an order taken over the phone with a name and an email). The draft is quoted at the shipping address, else the billing address, else the store's address. An email is required, from the billing address or the chosen account. The payment method may be empty: the customer picks one on the pay page.
3. **Status.** A new order is pending, on hold for an offline method, or processing when there is nothing to pay, with `created_via` `admin` and the note "Order created by the admin.". Its stock is taken and its coupons counted in one transaction, as at checkout. `OrderRepository::stale_pending()` skips admin orders, so the pending timeout never cancels one the merchant is waiting on.
4. **Editing.** An order can be edited while it is pending, on hold or failed, nothing has been paid or refunded and it is not anonymized (`Order::is_editable()`). `OrderService::edit()` locks the order, gives back its stock and coupon uses, prices the new draft with them free, replaces the lines, addresses and totals (`OrderRepository::replace_contents()`), takes the stock again and writes "Order edited by the admin.", all in one transaction; a refused draft rolls everything back. The order keeps its number, key, status and locale. The action `flexacommerce.order.edited` (`OrderView $order, OrderView $before`) fires after commit.
5. **Choices kept for the next edit.** Product lines now store the raw variation choice (`meta.selection`) and option values (`meta.options`) next to the display labels, so the editor can open an order and price it again. Lines placed before this ADR fall back to the variation's own values; their option values have to be picked again.
6. **Addresses on any order.** `PUT admin/orders/{id}/addresses` changes the billing and shipping addresses whatever the status, paid or not, and changes no line or total. It writes "Addresses changed by the admin." and fires `flexacommerce.order.edited`.
7. **Order details email.** A new customer email `customer_invoice` ("Order details", ten emails in all) has no trigger hook: the admin sends it from the order screen ("Send order details") or when creating the order. While the order is pending and has a balance due it carries the pay link, otherwise a link to the order. It is sent even when switched off in Settings > Emails, where it shows "Sent by hand" instead of a switch, and a successful send writes "Order details sent to the customer.".
8. **Routes.** All need the edit orders capability: `POST admin/orders/quote` (prices a draft, writes nothing), `POST admin/orders` (create, with `send_details`), `GET admin/orders/{id}/draft`, `PUT admin/orders/{id}`, `PUT admin/orders/{id}/addresses`, `POST admin/orders/{id}/details-email`, `GET admin/orders/product-form` (a product's attributes, variations and option sets) and `GET admin/orders/customer-addresses` (an account's saved addresses). An error names the field (`lines`, `coupons`, `billing.email`, `billing.country`, `shipping.country`, `payment_method`).
9. **Screen.** "Create order" on the order list opens `?order=new`; "Edit order" on an editable order opens `&edit=1`. Products are added by name or SKU, with selects for the variation's attributes, inputs for option fields and a quantity. Picking an account fills its saved addresses. The summary is quoted again 400 ms after each change and shows the lines, coupons, shipping rate, payment method, totals and what stops the order. The order screen also gets "Edit addresses" and "Send order details".

**Alternatives.** Free lines with typed prices, as WooCommerce allows: quick, but the order would skip pricing rules, coupon rules and tax, and the totals could not be checked against the catalogue. A cart session switched to the customer ("log in as"): the order would carry the customer's real cart and the admin's session mixed together. Editing paid orders: stock, refunds and the payment amount would need reconciling; refunds and a new order cover that.

**Consequences.** No table, no setting and no `DB_VERSION` change; one email option. Tests cover quoting with valid and refused coupons, creating with stock and coupon uses, the pending timeout, editing with stock and coupons given back and taken again, a paid order refused, a variation line opened again, address changes on a paid order, the details email with the pay link, and the capability check (integration), and the whole screen end to end: create for a guest, edit the quantity, change the addresses, open the editor from its link.

**Revisit when.** Merchants ask for free lines with a typed price, editing paid orders, or quotes that become orders when the customer accepts them.

## ADR-062: Grouped and external products

**Context.** Doc 01 kept grouped and external/affiliate products out of core, and the migration skipped them. Both are common in WooCommerce stores: a grouped product sells a set of products from one page (a starter kit, a lamp with its bulbs), and an external product shows an item the store promotes but sells elsewhere (affiliate links, a partner's shop, a marketplace listing). A store with either could not move without rebuilding those pages by hand, and a CSV from such a store had error rows.

**Decision.**
1. **Two more types.** `Catalog\ProductTypes` gains `grouped` and `external` (`_fxc_type`). `is_sold()` and `has_stock()` are true for simple and variable only; `has_variations()` for variable only. Every product keeps its base row, so SKU, price and tax fields stay where they are, but the cart refuses a product that is not sold (`NotPurchasable`), and RuleTargets search (coupons, pricing rules) does not offer it.
2. **Grouped.** `_fxc_children` holds the listed product ids in their order, unique, at most 100. A listed product must exist, must not be the group itself and must not be grouped; a product listed in a group cannot become grouped. Each child keeps the groups that list it in `_fxc_grouped_in`, so a price or stock change on the child updates its groups without a query. The group's `_fxc_price_min/max` is the range of its published children (sold out ones included) and its `_fxc_stock_status` is in stock while one child is. Deleting a product takes it out of every group; trashing or drafting it hides it from the group page.
3. **Group page.** The single product form becomes a table of the children: a quantity input (0 by default) for a simple product that can be added, "Choose options" linking to a variable product or one with option sets, "Out of stock", or the child's own external button, then the name and the price. One "Add to cart" sends every quantity in one request (`POST store/cart/items` with `product_id` of the group and `quantities` as product id => quantity, or the `add_group` form operation without JavaScript). `CartService::add_group()` adds all of them or, when one fails a stock or purchase check, none. Quantities of products outside the group are ignored; no quantity at all is an error ("Choose the quantity of at least one product."). In lists the group shows "View products" and the range of its children.
4. **External.** `_fxc_product_url` (http or https only, checked on the input since `esc_url_raw()` would add a scheme to a bare word) and `_fxc_button_text` (empty means "Buy product"). The base row is saved with no stock management and in stock. The product page and lists show a link button with `rel="nofollow sponsored noopener"` and `target="_blank"` instead of the form, with the regular or sale price of the base row.
5. **Editor.** The product type select offers four types. Grouped hides Inventory, Shipping, Downloads and prices; its General tab has a product search (by name or SKU, grouped products left out) and a list to reorder or remove. External shows the URL, the button text, prices and tax, and hides Inventory, Shipping and Downloads. `admin/products/{id}` returns `children` (id, title, sku, type, status), `product_url` and `button_text`.
6. **Migration and CSV.** WooCommerce grouped and external products move both ways in the products stage: `get_children()` as references resolved through the mapping (a child that did not move is left out with `child_skipped`), `get_product_url()` (an invalid one becomes empty with `url_skipped`) and `get_button_text()`. Writing to WooCommerce, an external product is saved with no stock management, as WooCommerce requires. The CSV columns "Grouped products" (SKUs, or `id:N` without one), "External URL" and "Button text" are now read and written; grouped rows are saved after the other rows of a batch so their children exist. Other WooCommerce types (bundle, subscription, composite) are still skipped and reported.

**Alternatives.** Grouped as a variable product with one axis: the children are real products with their own pages, stock and SEO, which variations are not. A group with a price of its own (a bundle): that needs stock across products and a discount model, and is a different feature. External products as simple products with a custom link in the description: no price display, no button, and nothing a migration could map.

**Consequences.** No table, no setting and no `DB_VERSION` change; four post meta keys. Tests cover the type helpers (unit); the group's order, price and stock sync, a deleted child, the refused children, all or nothing adds, the REST `quantities` and the `add_group` form, the external link and its refusal by the cart, the admin endpoint fields, and the CSV round trip (integration); both migration directions and a WooCommerce CSV (WooCommerce suite); and in the browser a group adding two products at once and the external button.

**Revisit when.** Merchants ask for bundles with a price of their own, a group discount, or grouped products listing variations.

## ADR-063: Abandoned cart recovery

**Context.** Doc 01 kept abandoned cart recovery out of core. Most carts are left before the order, and the stores moving from WooCommerce often ran a plugin that emailed those shoppers. Carts already live server side in `fxc_sessions` (ADR-008), so the plugin knows what was left; it lacked an address for guests, a record of what was sent, and a way back into the cart from an email.

**Decision.**
1. **Who is followed.** A signed-in shopper's cart, and a guest's cart once they typed an email at checkout. `checkout.js` sends the address once when the field loses focus (`POST store/checkout/email`, session token and nonce as the other store routes); it is saved on the session (`fxc_sessions.email`, `DB_VERSION` 11) only while reminders are on and guests are included. A short note under the field says a reminder may follow. A guest who signs in later counts under the account.
2. **Left carts.** The five-minute cron reads sessions quiet for `abandon_minutes` (15 to 1440) that changed since `recovery_checked`, 50 per run, and keeps one open `fxc_recoveries` row per email with a copy of the cart (lines, codes, priced items). A later change of the same cart refreshes the row; the delays are counted from the last cart activity, so a shopper who comes back and leaves again does not get the next reminder early.
3. **Reminders.** Up to three, each with its own delay, subject, heading, text and an optional code. Due reminders go out 50 per run through the Mailer as the `cart_reminder` email (template `templates/emails/cart-reminder.php`), listing the items and the total and carrying two links.
4. **Codes.** A reminder with a code creates a percent or fixed cart coupon for that shopper with `CouponEditor`: used once, limited to the email, valid for the days set. The code goes into the cart the link restores.
5. **The links.** `?fxc_recover=<id>.<token>` puts the stored lines and codes into the visitor's cart (`CartService::restore()`, which skips products that are gone or sold out), prefills the guest email and redirects to the checkout. `?fxc_recover_stop=` shows a confirm form on GET and stops every reminder to the address on the POST with a nonce, since mail scanners open every link in a message.
6. **Outcome.** An order by the same email or account placed after the cart was left settles the row. It is recovered when the shopper was reached (a reminder was sent or the link used), with the order linked; otherwise the row is deleted, since nothing the plugin did led to the order. A cart emptied after a reminder is lost; emptied before one, the row is deleted. The daily cron marks rows quiet for 7 days lost and deletes settled rows after 90 days; unsubscribed rows are kept so the address stays quiet.
7. **Admin.** **Orders > Abandoned carts** lists the carts with status, progress, codes sent and a link to the order, filtered by status, with the recovered sales, the rate and the open count of the last 30 days. The Settings tab turns reminders on, includes guests or not, sets the wait and the three reminders, and sends a test to any address (the admin's own when the field is empty). Routes: `admin/recovery`, `admin/recovery/{id}` (delete), `admin/recovery/settings`, `admin/recovery/test`; the list needs the right to view orders, a delete the right to edit them, and the settings and test the store manager right.
8. **Settings.** They live in the option `flexacommerce_recovery`, not autoloaded and outside the Settings keys (64 at most), read only by the cron, the checkout of a guest and the admin screen.
9. **Privacy.** The exporter and eraser "FlexaCommerce cart reminders" cover the rows and the session emails of an address; the policy text mentions the reminders.

**Alternatives.** Sending when the session expires: the session lasts days, far too late for a reminder. Saving the email on each keystroke: noisier and it would store addresses the shopper did not finish typing. Counting every order after a left cart as recovered: it inflates the numbers with orders the reminders never touched. A generic code shared by all reminders: it leaks to coupon sites. A stop link that acts on GET: scanners would unsubscribe shoppers on delivery.

**Consequences.** One table, two session columns and an index (`DB_VERSION` 11), one option, one email, five routes and a menu item; the cron does nothing while reminders are off. Recovery depends on Cart, Coupons, Orders (read), Notifications, Support and Database (doc 02 §3.2). Tests cover the settings sanitizer (unit); detection, the reminder with its code, the link, recovered, forgotten and lost carts, a signed-in shopper, the stop form, capture gating, expiry and purge, the admin routes, the test send and privacy (integration); and in the browser an admin turning reminders on, a guest leaving a cart at checkout, the link restoring it in a fresh browser, and the row in the list.

**Revisit when.** Merchants ask for reminders by SMS, per product or per amount rules, more than three steps, or reporting beyond 30 days.

## ADR-064: Credit notes for refunds

**Context.** ADR-060 gave orders an invoice and left credit notes for later. Where an invoice is a legal document, a refund needs its own document that refers to the invoice and has a number from its own series, since an invoice once issued cannot be changed. Merchants coming from WooCommerce invoice plugins had one per refund.

**Decision.**
1. **One per refund.** Each succeeded refund transaction (`fxc_transactions`, type refund) can have one credit note. It is made the first time its PDF is: the admin opens it, the Refunded order email carries it, the customer downloads it, or WP-CLI writes it. Making one first gives the order its invoice, then takes the order's `GET_LOCK`, so two requests at once give one number.
2. **Numbers.** Their own counter in `fxc_sequences` (`credit_note_number`, or `credit_note_number_YYYY` with the yearly reset), their own prefix (`CN-` by default) and suffix with `{year}` and `{month}`; the minimum digits and the reset are shared with invoices. "Next number" raises the counter and refuses a lower value. Each note is an `fxc_order_meta` row `_fxc_credit_note` holding `{refund_id, number, date}`; the first row for a refund wins. The action `flexacommerce.credit_note.created` (`int $order_id, int $refund_id, string $number, string $date`) fires once per note.
3. **Layout.** The invoice header with the title "Credit note" and its number and date, then the invoice number and date, the order number and date and the payment method; the addresses. The table lists the refunded lines (product, quantity or "–" for an amount-only line, amount without tax). The totals are the subtotal, "Other refunded amount" for the part of the refund outside the lines ("Refunded amount" when there are none), the tax per rate from the split stored on each refund line, and "Total credited". A reason given with the refund is printed under "Reason". To keep the split, `Transaction::lines()` now keeps each line's `taxes`.
4. **Settings.** `credit_prefix`, `credit_suffix` and `credit_attach` (off by default) go in `flexacommerce_invoices`, so the Settings option stays at 63 keys. Settings > Invoices gets a Credit notes card with the prefix, suffix, next number and "Attach to the Refunded order email".
5. **Emails.** `EmailMessage` gets a `context` array, which the Mailer passes as the fourth argument of `flexacommerce.email.attachments`. `CustomerRefundedOrder` sets `refund_id`, so the invoice module knows which refund the email is about and attaches that credit note when `credit_attach` is on.
6. **Where.** The order page Invoice card lists each refund with its amount and date, the number or "Not made yet", a "Credit note PDF" link (`admin-post.php?action=fxc_invoice&orders=<id>&document=credit_note&refund=<txn>`) and the customer link. Any order action on the page (a payment, a refund, a status change) refetches the card. `admin/orders/{id}/invoice` returns `credit_notes`; `admin/invoices/settings` takes `next_credit` and returns `next_credit` and `next_credit_formatted`. Customers get "Download credit note CN-0001 (PDF)" on the order confirmation and the account order page, or "Download the credit note for the $5.00 refund (PDF)" before it is made, a link to `?fxc_invoice=<id>&key=<key>&refund=<txn>`. `wp flexacommerce invoice <order-id> --credit-note=<refund-id>` writes the file.
7. **Customer access.** The invoice rules apply. With "After you make the invoice" the customer sees a credit note only once the shop made it.

**Alternatives.** A negative invoice from the invoice series: some countries allow it, but a mixed series is harder to read and to audit, and a separate prefix is what the merchants coming from invoice plugins used. Numbering a credit note when the refund is recorded: refunds through a gateway can still fail, and stores that do not need credit notes would burn numbers. A table for credit notes: one meta row per note is enough, and the order meta already holds the invoice.

**Consequences.** No table, no Settings key and no `DB_VERSION` change; one order meta key, a sequence row per counter and three values in the invoice option. Invoices now depend on Payments for the refund transactions (doc 02 §3.2). Tests cover numbering with the invoice made first, the hook, the own counter with its yearly reset and raise-only next numbers, the drawn totals and reason, the Refunded order email attachment through the context, customer access by setting, the admin routes (integration), and in the browser an admin recording a payment and a refund and opening the credit note PDF from the order card.

**Revisit when.** Merchants ask for credit notes without a refund (a price correction), cancelling an invoice with a full credit note on cancellation, or one series for invoices and credit notes.

## ADR-065: Back-in-stock emails

**Context.** A sold-out product page was a dead end: the shopper saw "Out of stock" and left, and the merchant never learned how many people wanted it. WooCommerce stores usually ran a plugin that kept addresses and emailed them on restock. The plugin already knows when stock moves (`StockService`, ADR-005), sends mail through the Mailer and runs a five-minute cron, so only the signups and the sending were missing.

**Decision.**
1. **Signup.** A product with nothing to buy shows "Email me when it is back in stock" under "Out of stock": a form that posts to `admin-post.php` (FormHandler op `stock_alert`), so it works without scripts, with a notice after the redirect. A variable product with some variations in stock gets a block inside the add-to-cart form instead; the store script shows it while the picked variation is sold out and sends it to `POST store/stock-alerts`. While alerts are on, sold-out swatches and options stay pickable (crossed out) so the shopper can pick one to sign up for it. A signed-in shopper clicks one button and their account address is used; a guest types an address. With guests turned off, a guest sees a sign-in link. Both paths share the `stock_alert_ip` limit (10 per hour per IP).
2. **Rules.** A signup is refused when alerts are off, the address is not valid, the product is not published, not sold, external or unknown, the variant is not one of the product's, or the variant (any variant for a product without one picked) can be bought now. Asking twice for the same product and variant returns the first row. The action `flexacommerce.stock_alert.created` (`StockAlert $alert`) fires once per new row.
3. **Storage.** One table `fxc_stock_alerts` (`DB_VERSION` 12): product, variant (0 for any), user, email, locale, status (`waiting`, `sent`, `cancelled`), a `due` flag, a 32-character token and the dates.
4. **When to look.** `StockService` takes a `restocked` closure from the cart services and calls it after a change that leaves the variant with more stock than before and above zero; the closure marks the product's waiting rows due (`mark_variant`, which covers a stock shared by every variant). A product save marks them too. No hook is added at boot and a sale (a decrement) does nothing, so the P1 callback budget and the per-line query budget are unchanged. The daily cron marks every waiting row due, for stock changed without the service (a direct import or SQL).
5. **Sending.** The five-minute cron returns at once when alerts are off or nothing is due. Otherwise it reads 50 due rows, checks each row's variant (or any variant) with a fresh stock read and the purchasable rules, and sends the `back_in_stock` email (template `templates/emails/back-in-stock.php`) in the shopper's locale: the product name with the variant's values, the image of the variant or the product, a link to the product page with the variant's values picked (`fxc_pick`), and a stop link. A sent row is never sent again. A row still out of stock is unmarked; a failed send is unmarked and logged, and the daily run tries it again. Stock is not held for the shopper, and the text says so.
6. **Stop link.** `?fxc_stock_stop=<id>.<token>` shows a confirm form on GET and, on the POST with a nonce, cancels every waiting row of that address, since mail scanners open every link in a message.
7. **Admin.** **FlexaCommerce > Stock alerts** lists the rows with the product (and variant), email, status and dates, filtered by status and product, with a "Most wanted" panel (the 10 products with the most addresses waiting) and the waiting count and emails sent in the last 30 days. A row can be deleted. The Settings tab turns alerts on (on by default), includes guests or not, edits the subject, heading and text (`{product}`, `{site_title}`; empty uses the translated default) and sends a test to any address for a product, the most wanted one by default. Routes: `admin/stock-alerts`, `admin/stock-alerts/{id}` (delete), `admin/stock-alerts/settings`, `admin/stock-alerts/test`; the list needs the right to view orders, a delete the right to edit them, the settings and test the store manager right.
8. **Settings.** They live in the option `flexacommerce_stock_alerts`, not autoloaded and outside the Settings keys (64 at most), read only by a product page with something sold out, a restock, the cron and the admin screen.
9. **Cleanup and privacy.** The daily cron deletes sent and cancelled rows after 90 days, and rows of deleted products. The exporter and eraser "FlexaCommerce stock alerts" cover the rows of an address.

**Alternatives.** Sending from the stock change itself: an import that restocks 500 products would send thousands of emails in one request; the due flag plus the batched cron spreads them. Listening on `flexacommerce.stock.changed`: one more boot-time callback, and every sale would run an UPDATE; the closure only runs on increases. Holding stock for the first shopper on the list: it needs timed holds, which ADR-005 rejected. Emailing everyone waiting on a product when one variant returns: a shopper who wanted another size gets a useless email; a row waits for its own variant unless it asked for any. A stop link that acts on GET: scanners would unsubscribe shoppers on delivery.

**Consequences.** One table (`DB_VERSION` 12), one option, one email, five routes, a menu item, a FormHandler op and a constructor argument on `StockService` and `CartServices`. StockAlerts depends on Catalog, Inventory, Notifications, Support and Database (doc 02 §3.2). Tests cover a guest restock with one email, the signup rules, a variation waiting for its own variant, the stop link, the daily marking and purge with a deleted product, the store route with its limit, the admin routes, the test send, privacy and the form render (integration); and in the browser a guest signing up on a sold-out product without scripts, a guest signing up on a sold-out variation, the restock sending one email and its stop link, and the admin list and settings, with axe checks.

**Revisit when.** Merchants ask for SMS, a waiting list that holds stock for the first in line, alerts on price drops, or sending in order of signup with a limit per restock.

## ADR-066: Wishlists

**Context.** Shoppers wanted to keep products for later without putting them in the cart, and WooCommerce stores ran a wishlist plugin for it. Most of those plugins print the heart filled or empty from PHP, which makes every product page and list different per visitor and breaks the page cache. Product pages here are built once for every visitor (doc 06 P11), so the state has to come from the browser.

**Decision.**
1. **One list per shopper.** A customer has one list, found by user id. A guest has one list found by the HttpOnly cookie `fxc_wishlist` (a 43-character random token; only its SHA-256 hash is stored), kept for 90 days from the last change. A new guest list counts against `wishlist_ip` (20 per hour per IP); saving to a list that exists does not. A list holds 200 products at most.
2. **Sign-in.** The first request of a signed-in customer that reads the list (the account page, `GET store/wishlist`) moves the guest list in the cookie into the account: it becomes theirs when they have none, or is joined with theirs up to the cap, then the guest token is cleared. No boot-time hook is added for it.
3. **Same markup for everyone.** The heart under the add-to-cart form (component `wishlist_button`) never prints its state. Each change writes the script-readable cookie `fxc_wishlist_ids` ("user:id.id", for example "5:12.34"), and the store script reads it to set `aria-pressed`. A cookie of another user (a shared computer, a stale cookie after logging out on another tab) is ignored and a signed-in customer asks `GET store/wishlist` once. Logging out clears it.
4. **Saving.** With scripts the heart posts to `POST store/wishlist/items` or `DELETE store/wishlist/items/{product_id}` and says the result in a status region. Without scripts it is a form to `admin-post.php` (FormHandler ops `wishlist_add` and `wishlist_remove`) that always saves, with a notice and a "View wishlist" link after the redirect. Only published products can be saved. The action `flexacommerce.wishlist.added` (`int $product_id`, `int $user_id`, 0 for a guest) fires once per new row.
5. **Where it shows.** The setting `wishlist` (`all` by default, `single` for product pages only, `off`) under **Settings > Storefront**. With `off` the button, the account tab and the routes refuse.
6. **The list page.** The account endpoint `wishlist` (`fxc_wishlist`, `?fxc_wishlist=1` with plain permalinks) and the shortcode `[flexacommerce_wishlist]` (component `wishlist`) show a table of image, name, price, the list add-to-cart button and Remove. A guest sees their own list there with a prompt to sign in to keep it on every device. Both pages send `DONOTCACHEPAGE`.
7. **Sharing.** Each list has a random 32-character share key. The owner's page shows the link `account/wishlist/<key>`; it shows the products only, with no name and no Remove buttons, and a bad key says the link does not work any more.
8. **Storage.** Two tables (`DB_VERSION` 13): `fxc_wishlists` (user id or token hash, both unique, the share key, dates) and `fxc_wishlist_items` (list, product, date added; one row per product).
9. **Cleanup and privacy.** The daily cron deletes guest lists unchanged for 90 days, lists of deleted users, and rows of deleted products. The exporter and eraser "FlexaCommerce wishlist" cover the list of the account with that email; a guest list cannot be found by email, and the privacy policy text names both cookies.

**Alternatives.** Printing the state in PHP: simple, but every product page and list would need `DONOTCACHEPAGE` or a cache split per visitor. Keeping a guest list in localStorage only: no list on the account page without scripts, and nothing to move on sign-in. A list per variant: a shopper saving a shirt has not picked a size yet, and the product page lets them pick later. Several named lists per customer: more screens for a feature most shoppers use as one list.

**Consequences.** Two tables, one setting (the Settings schema now has 65 keys, so the settings array crossed a hash-table size: about 7 KB more peak memory on every request; the Darwin PHP 8.5 baseline of FrontendBudgetTest was re-recorded at 90000 B and the next 63 keys cost nothing more), two cookies, one rate limit, three store routes, two FormHandler ops, an account endpoint, a shortcode and two render components. Wishlist depends on Catalog, Support and Database (doc 02 §3.2). Tests cover saving and removing, the setting, unpublished products and the cap, the guest cookie and the move on sign-in, the IP limit, the share link, the daily purge (multisite included), the store routes, the no-JS forms, the button and the account page, and privacy (integration); and in the browser a guest saving and removing without scripts, the heart saving and remembering with the share link opened in another browser, and a guest list moving into the account on sign-in, with axe checks.

**Revisit when.** Merchants ask for a header link with the count, a "most wished for" report, saving a picked variant, emails when a saved product goes on sale or comes back in stock, or several named lists.

## ADR-067: Shipment tracking and the shipped email

**Context.** Merchants who ship physical goods want to give the customer a tracking number when the package leaves. On WooCommerce that takes the Shipment Tracking extension or one of the plugins that copy its meta; without one, merchants paste the number into a customer note. Customers then ask "where is my order", and the answer sits in an email they may not find.

**Decision.**
1. **One row per package.** `fxc_shipments` (`DB_VERSION` 14) holds the carrier key, the carrier name, the tracking number, the link, the lines and the day it left (doc 03 §4.20). An order can have any number of packages. Rows are not edited: a wrong one is removed and added again, and both leave a `shipment` history row.
2. **Carriers.** `Orders\Carriers` lists UPS, USPS, FedEx, DHL Express, DHL eCommerce, Canada Post, Royal Mail and Australia Post with the link that opens a number on their site. The filter `flexacommerce.shipment.carriers` adds or replaces carriers. Any other carrier is typed in by name, with an optional http(s) link where `{number}` stands for the tracking number. The form starts on the carrier used last.
3. **Which lines.** By default a package is the whole order. "Some products" takes a quantity per product line, at most what the line holds after refunds; choosing every line in full is stored as the whole order. Lines are not tracked against each other across packages: a merchant who splits an order knows which box holds what, and blocking a second box with the same product would get in the way of a resend.
4. **When.** Processing, on-hold and completed orders can ship. The day it left defaults to today, cannot be in the future, and a past day is stored as noon in the site time zone so it shows the same day everywhere.
5. **Completing and emailing.** "Mark the order as completed" (shown when the order can move to completed) completes it in the same transaction, with `shipment` in the transition context. "Email the customer the tracking" (on by default) sends the new Shipped order email (`customer_shipped_order`, "Your order is on its way") with only that package's tracking. When a shipment completes the order the Order completed email is not sent, so the customer gets one email. Completing an order by hand still sends Order completed, which now lists all the tracking.
6. **Where the customer sees it.** The order confirmation page and the account order view show a Tracking table (day, carrier, number linked to the carrier's site in a new tab, and the products when it is not the whole order) once the order is past pending.
7. **Hook.** `flexacommerce.order.shipped` (`OrderView`, `Shipment`, `{notify}`) fires after commit, for apps that sync with a fulfilment service.
8. **Other ways in.** `wp flexacommerce shipment add|list|delete|carriers` runs the same service. The admin order search matches an exact tracking number. The migration reads and writes the Shipment Tracking extension's `_wc_shipment_tracking_items` meta (doc 05 §5.4).

**Alternatives.** Order meta with a JSON list, like the WooCommerce extension: no search by number and no integrity check. A shipment status (in transit, delivered) from carrier APIs: each carrier needs an account and its own API, which belongs in an add-on that listens to `flexacommerce.order.shipped`. A per-line shipped quantity on `fxc_order_items`: exact, but it turns a tracking number into fulfilment bookkeeping that most small shops do not want.

**Consequences.** One table, one email, one action, one filter, three admin routes, one CLI command and one history type; no new setting (the schema stays at 65 keys) and no boot-time hook (the email listens through the Notifications registry). Tests cover carriers and links, the filter, typed carriers and bad links, the lines and the whole-order rule, the status and date checks, the time zone of a past day, completing with one email, a second package, the customer page, delete across orders, the tracking number search, the REST routes and permissions, and the CLI row (integration); the Shipment Tracking meta in both directions in every WooCommerce order storage mode; and in the browser the admin adding tracking with the order completed and the customer seeing the link, with axe checks.

**Revisit when.** Merchants ask to edit a shipment in place, to print the tracking on packing slips, for delivery status from carrier APIs, or for a bulk "mark shipped" with tracking from a CSV.

## ADR-068: Shipping classes and table rates

**Context.** Flat rate charges the same for a mug and a sofa. WooCommerce shops handle that with shipping classes and the flat rate's class costs, and the bigger ones buy the Table Rate Shipping extension to price by weight, item count or subtotal. A store that moves to FlexaCommerce with class costs had its flat rate imported turned off (doc 05 §5.7), and there was no way to price by weight at all.

**Decision.**
1. **Classes.** The list lives in the setting `shipping_classes` (`{slug, name}`, sanitized like `tax_classes`; `parent` and `inherit` are reserved), edited in the Shipping classes card at the bottom of Settings > Shipping, which shows how many products use each one. A product picks a class in its Shipping fields; `fxc_variants.shipping_class` (`DB_VERSION` 15) holds the slug. On the base row `''` means no class; on a variation `''` means the product's class, so a variable product needs one choice. A slug that is no longer in the list counts as no class (`CartItem::shipping_class()`), so removing a class never breaks a cart.
2. **Package.** `PackageLine` gains an optional last argument `shipping_class` and `ShippingPackage::hash()` includes the weight and the class of every line, so a cached quote changes with them.
3. **Flat rate class costs.** A `class_costs` field (new `SettingField` type) holds a cost and a cost per item for each class and for "No shipping class". With "Add the cost of every class in the cart" (`per_class`) each class in the cart adds its cost plus its per-item cost times the items of that class; with "Charge only the most expensive class" (`per_order`) only the highest one is added. Both are WooCommerce's `type` choices. The costs apply only while the store has classes.
4. **Table rate.** A new core method `table_rate` with a `rate_table` field: up to 200 rows, checked from the top, the first that matches wins. A row has a class (any, none or one class), a condition (always, weight, item count or subtotal) with an inclusive from/up to range (empty is open), and a price made of a cost, a cost per item, a cost per weight unit and a percent of the subtotal; or "Do not ship when this row matches". No matching row, or a matching no-shipping row, hides the method. "Per order" matches one row for the whole cart; "per class" prices each class group with its own row and needs a row for every group. The method also has the weight unit of the table (kg, g, lb or oz; weights are stored in grams), a handling fee, a minimum and a maximum cost, and the tax status. `Support\RateTables` sanitizes both field types.
5. **CSV and migration.** The product CSV has a "Shipping class" column with the class name; import matches a name or slug and creates a missing class. The migration reads and writes WooCommerce's `product_shipping_class` terms, the class of each product and variation, and the flat rate `class_cost_{term id}`, `no_class_cost` and `type` settings (doc 05 §5.7). A class cost formula that FlexaCommerce cannot reproduce still imports the method turned off.

**Alternatives.** A `fxc_shipping_classes` table: a store has a handful of classes, and the setting keeps them in the Settings cache with no query. Classes as a WordPress taxonomy, as WooCommerce does: a term query on every cart read, and the class belongs to the variant row like the tax class. Woo's cost formulas with `[qty]`, `[cost]` and `[fee]`: the fields cover what the formulas are used for without a parser a merchant has to learn. A matrix of destination by weight in one table rate: zones already split by destination, so one table per zone is enough.

**Consequences.** One setting (66 keys), one column, one method, two field types. Variant rows now have 33 columns, which doubles the size of each keyed PHP array; the variants cache keeps the rows as plain lists with the column names once, which brought the P3 product page memory at 250 variants back to 1,420,752 B (budget 1,448,656 B). Tests cover the flat rate per class and per order, no classes, table rows by weight (inclusive bounds, no match, no-shipping rows, lb), items, subtotal and percent, per-class rows, handling fee and limits and the sanitizers (unit); classes on cart lines with variation inheritance and a removed class, both methods pricing a cart, the admin endpoint counts and the CSV column (integration); and classes, class costs and product classes in both migration directions, plus a products-only import that keeps the class (WooCommerce).

**Revisit when.** Merchants ask to import from the WooCommerce Table Rate Shipping extension (its rows live in its own table and map closely to ours), for dimensional weight, for rates by postcode inside one table, or for live carrier rates per class.

## ADR-069: Gift cards

**Context.** Third of the roadmap agreed on 2026-11-01. Stores sell gift cards for the holidays and hand them out as goodwill after a bad order. WooCommerce needs a paid extension for this, and a coupon is a poor stand-in: it has no balance that goes down as it is spent, and a refund does not put money back on it.

**Decision.**
1. **The product.** "Gift card" in the product's General fields marks a product as sold as cards (`fxc_variants.is_gift_card`, `DB_VERSION` 16). Saving it makes every row virtual with tax status None (tax is due when the card is spent, not when it is sold), and variations follow the product, so a variable product with 25, 50 and 100 as variations sells three amounts. The product page asks for the recipient email and name, a From name, a message and a send date (today up to a year ahead); all are optional. They are kept on the cart line (`CartLine::GIFT_KEYS`) and on the order line as `meta.gift_card`.
2. **Issue.** When an order with gift card lines reaches Processing or Completed, `GiftCardService::issue_for_order()` makes one card per unit, once per line, worth what the unit was paid after coupons (a coupon on the card does not add value). A card goes to the recipient, or to the buyer when no email was given. It is emailed (`customer_gift_card`) at once, or by the hourly cron on the chosen day (`deliver_at`, at most 50 per run). The setting `gift_card_expiry_months` (0 to 120, 0 = never) sets the expiry of new cards.
3. **Codes.** 16 characters from an alphabet without look-alike characters (`234679ACDEFGHJKMNPQRTUVWXYZ`), printed in groups of four, unique by index. Codes are matched after removing spaces and dashes and upper-casing. Outside the admin a card is shown masked (`••••4K7Q`).
4. **Paying with a card.** The shopper types the code in the same box as coupons, in the cart or at checkout. A code that cannot be a card code is tried as a coupon and never looked up; a card-shaped code that matches nothing counts against the `gift_card_ip` limit (10 an hour), and a card that is disabled, expired, used up or in another currency is refused with the reason. Up to 5 cards per cart. The totals gain a Gift cards row per card and "Left to pay" (`Quote::due()`); each card pays what it can of the total in the order entered. At submit, inside the checkout transaction, each card row is locked, debited and given a `redeemed` activity row, and the order gets a succeeded `fxc_gift_card` payment per card with the card id as its reference. A card spent elsewhere since the quote rolls the order back with a message. The browser posts the amount it showed as left to pay as `expected_total`. When nothing is left, no payment method is asked for and the order is paid at once; otherwise the gateway charges only `balance_due()`. Express wallets charge the whole total, so they hide while a card is in the cart.
5. **Refunds and cancels.** `GiftCardGateway` is registered with the new `GatewayRegistry::internal()`: `get()` finds it, but it is never offered or listed in Settings > Payments. Its `refund()` calls `GiftCardService::credit()`, which adds the amount back once per refund transaction. A refund larger than one payment is split over the order's payments, newest first, so the card is refunded last. A cancelled order gets its card payments back; a cancelled or refunded order disables the cards it sold.
6. **Admin.** Orders > Gift cards (view_orders to see, edit_orders to change) lists cards with search by any part of the code, email, name or order number and filters (active, disabled, used up, expired). A merchant can make a card by hand (value, recipient, send date, expiry, private note, email now or not), turn a card off and on, edit the recipient and expiry, send the email again, and add to or take off the balance with a reason. Each card has a history of issued, redeemed, credited and adjusted rows with who did it. Routes: `admin/gift-cards`, `admin/gift-cards/{id}`, `/{id}/adjust`, `/{id}/send`. A Settings tab holds the expiry.
7. **Privacy.** The exporter lists the cards sent to an address with the masked code and the balance. The eraser clears the recipient name, email and message but keeps the card: the balance is the holder's money, and the code alone does not identify anyone.

**Alternatives.** Cards as coupons with a balance meta: coupon rules (spend bounds, products, individual use) do not fit money that belongs to the shopper, and a coupon discounts before tax while a card pays after it. A card as a payment method at checkout with its own field: two code boxes confuse shoppers, and the cart would not show what is left to pay. A wallet balance per customer account: guests buy most gift cards, and the recipient often has no account. Storing the card id on the order instead of a payment row: refunds, the order balance and reports already work on payments.

**Consequences.** Two tables (`fxc_gift_cards`, `fxc_gift_card_activity`), one column, one setting (67 keys), one email, one internal gateway, one cron step and one admin screen. The cart code box and the checkout summary know about cards; a coupon still applies before the cards. No boot-time hooks: the order events reach the service through the existing download handler, and the cart gets a closure. Tests cover a card paying the whole order and part of it, a card spent since the quote, refused codes, a refund back to the card that a retry does not repeat, a cancel that gives the payment back, cards bought with a recipient and a later date, cards for the buyer, a variable gift card, the admin routes and the privacy tools (integration), and the admin card and a part payment at checkout on the storefront (E2E).

**Revisit when.** Merchants ask to import cards from the WooCommerce Gift Cards extension or PW Gift Cards, for printable or PDF cards, for a custom amount the buyer types, for a balance check page, for cards in more than one currency, or for physical cards with codes made elsewhere.

## ADR-070: Order CSV export

**Context.** First of the roadmap agreed on 2026-11-01. Merchants hand orders to an accountant, a warehouse or a spreadsheet every month, and WooCommerce needs a paid extension for a usable order export. The order list already filters by tab, search and customer; it had no date range.

**Decision.**
1. **Dates on the list.** The order list gains "Orders from" and "Orders to" date fields with an "Any date" reset. `OrderQuery` takes `date_from` and `date_to` (YYYY-MM-DD, store-local days, both included; anything else is dropped) and turns them into a UTC `created_at` range from the start of the first day to the start of the day after the last. `admin/orders` takes the same two params.
2. **One button.** "Export CSV" next to "Create order" opens a dialog that exports what the list shows: the same tab, search, dates and customer, every page of it. The dialog says whether the list is narrowed. Two layouts: one row per order (customer, phone, billing and shipping address, payment and shipping method, coupon codes, item count, subtotal, discount, shipping, fees, tax, total, what gift cards paid, paid, refunded, tracking numbers, customer note, checkout field answers, date paid and completed) and one row per product line (SKU, options as "label: value", quantity, unit price, line subtotal, discount, tax and total, refunded quantity and amount). Both start with order number, date, status, email and currency.
3. **Paging that new orders cannot move.** `GET admin/orders/export` (view orders capability) answers one page of 100 orders, keyset by id: the first call returns the total, the highest matching id (`until`), the header and the file name (`orders-Y-m-d.csv` or `order-items-Y-m-d.csv`); the browser asks for the next page with `after` = the last id until a page has fewer than 100 orders, then saves the file with a UTF-8 BOM. An order placed during the export has a higher id than `until` and is left out, and an order that changes status meanwhile cannot appear twice or be skipped. The dialog shows "x of y orders".
4. **Cells a spreadsheet reads.** Amounts are plain decimals in the order's currency (no symbol, no thousands separator), dates are `Y-m-d H:i:s` in the store's time zone, statuses are their labels. A plain number is written as it is; any other cell that starts with `=`, `+`, `-`, `@`, tab or carriage return gets a leading `'`, as the product export and WooCommerce do. The cell code moved from `Catalog\Csv\CsvValues` to `Support\Csv`, which both exports use.
5. **Bulk reads.** `Orders\Csv\OrderCsvRepository` loads a page's orders, items and addresses in three queries; the order layout adds checkout field answers (`_fxc_fields`), the net of gift card payments and refunds, and tracking numbers, one query each. The gift card gateway id is passed in by the endpoint, since Orders may not depend on GiftCards.

**Alternatives.** A server-built file in uploads with a download link: it leaves personal data on disk that someone must clean up, and a long export needs a background job; the browser already builds the product CSV this way. Offset paging: orders placed or moved between tabs during the export shift the pages. A column picker: two layouts cover the requests seen so far and keep the dialog to one choice. Exporting only the current page of the list: merchants want the month, not 20 rows.

**Consequences.** One admin route, two list params, one dialog and no setting, table or hook. `admin/orders` filters by date as well. Tests cover both layouts, totals with a coupon, shipping and gift card payments, tracking and checkout fields, the formula guard, status, search and store-local date filters, paging that ignores later orders and the permission (integration), and both downloads from the list with a date filter (E2E).

**Revisit when.** Merchants ask for chosen or reordered columns, a scheduled export emailed or sent to FTP, XLSX, an export of customers or coupons, or an export of more than about 50,000 orders at once (a background job then).

## ADR-071: Product bundles

**Context.** Second of the roadmap agreed on 2026-11-01. Merchants sell gift sets, starter kits and "buy the set and save" offers. A bundle coupon (ADR-045) discounts products the shopper already put in the cart, but it has no product page, no single add-to-cart button and no stock of its own. WooCommerce needs a paid extension (Product Bundles) for this, and a grouped product (ADR-062) only lists its children.

**Decision.**
1. **A product type.** `bundle` joins `ProductTypes`. Its items are kept as JSON in the post meta `_fxc_bundle` (`{mode, discount, items: [{product_id, variant_id, quantity}]}`), at most 20 items of 1 to 999 units each. An item is a simple product's base row or one variation of a variable product with every value set. `ProductService` refuses an item that does not exist, the bundle itself, another bundle, a grouped or external product, a whole variable product, a variation with an "Any" value, a gift card and the same row twice, each error on its field (`bundle.items.N`). Each part keeps the bundles that hold it in `_fxc_bundled_in`, so a change to a part finds its bundles without a query, and a product in a bundle cannot become a bundle, a grouped or external product, or a gift card.
2. **Price.** The editor offers three modes: the bundle's own price (`fixed`), the sum of its items (`sum`), and that sum less 1 to 99 percent (`discount`, rounded half up). With a computed price the regular price is the sum of the parts' regular prices times their units, and the sale price is their active prices less the discount, kept only when lower. It is written on the bundle's base row when the bundle or a part is saved, and again when a part's scheduled sale starts or ends, so the catalog, filters and price rules read a plain price.
3. **Stock.** A bundle holds no stock. It is in stock while every part can give the units one bundle takes, on backorder when one gives them on backorder, and out of stock otherwise; a part's stock change updates its bundles. The cart counts demand per stock row across all its lines, so a bundle and a loose part in the same cart share the part's stock, and the quantity a shopper can add is what the scarcest part allows. A bundle with a part that is deleted, unpublished, disabled or without a price cannot be sold. A deleted part stays in the item list (shown as removed in the editor) until the merchant edits the bundle, so the bundle never quietly sells less than it says.
4. **Storefront.** The product page lists "This bundle includes:" with each item's units, a link to its page and its variation values; the markup is the same for every visitor (P11). The cart and checkout show "Includes:" with "2 × Pack mug" under the bundle line. A bundle is virtual when all its parts are; for shipping it is sent as its parts, with their weights, sizes and shipping classes and the line total shared out by their regular prices.
5. **Orders.** The bundle line carries the price, tax and discounts and `meta.bundle.key`. Each part follows it as an unpriced product line with `meta.bundled_by` = that key and quantity = bundle units × part units. Stock is taken, held and returned on the part lines, and a downloadable part grants its files. The item count of an order leaves the parts out. Admin order detail, emails, the confirmation page and invoices show the parts indented under the bundle without prices; the order CSV marks them "Part of a bundle". `OrderItemView::$bundle_part` tells extensions which lines these are. Saving an admin-edited order rebuilds the parts from the bundle line.
6. **Refunds.** A part line cannot be refunded on its own ("A bundle is refunded on its own line, with its parts."), and the refund dialog does not list it. Refunding units of the bundle line refunds the same share of each part's units with no money and the bundle line's restock choice.
7. **Admin.** The product editor's type list gains Bundle. Its General tab shows the price mode, the discount, a search over simple products and variations (`GET admin/coupons/targets?kind=part`, via `RuleTargets::search_parts()`) and the item list with units, move up and down, and remove. `admin/products` reads and writes `bundle`, with each item's label, SKU and `missing` on the way out.
8. **CSV and migration.** The product CSV gains "Bundled items" (`2 x SKU`, or `id:N` / `variation:N` without a SKU, comma separated) and "Bundle pricing" (`fixed`, `sum` or `N%`). Bundle and grouped rows are imported after the other rows of a batch so their items exist; an item not found is left out with a warning. WooCommerce core has no bundle type, so a copy to WooCommerce leaves bundles behind and preflight says how many; orders that sold them keep their lines.

**Alternatives.** A bundle as a grouped product with a discount: a group adds its children as separate lines the shopper can change, so the set price would not hold. One order line for the whole bundle: stock, refunds, downloads and packing slips all work per product row, and every one of them would need a bundle case. Stock of its own for the bundle: it drifts from the parts and oversells them. Optional or choosable items ("pick 3 of these"): that is a composite product, and most merchants asked for fixed sets.

**Consequences.** No table, no `DB_VERSION` and no setting (still 67 keys); two post meta keys, one product type, one admin search kind and two CSV columns. The cart, checkout, order, refund, invoice and email code knows about part lines. Tests cover price modes and validation (unit); prices that follow the parts, refused items, shared stock in the cart, order part lines with stock and the refund cascade, the part search, the admin route and the CSV round trip (integration); and the product page, the cart and the editor's item list (E2E).

**Revisit when.** Merchants ask for optional items, a choice of variation on the product page, items picked by the shopper (composite products), a bundle inside a bundle, a per-item discount, or importing bundles from WooCommerce Product Bundles.

## ADR-072: Store credit

**Context.** Item 6 of the post-1.0 list (doc 07), built on 2026-11-01. Merchants want to refund a customer without sending money back, and to give a customer money to spend (a goodwill gesture, a price match) without making a code the customer has to keep. WooCommerce needs a paid extension for this. Gift cards (ADR-069) already hold a balance that pays at checkout, locks on change, takes refunds and keeps a history, so store credit reuses them.

**Decision.**
1. **An account is a gift card with an owner.** `fxc_gift_cards` gains `user_id` (unique, NULL for a normal card) in `DB_VERSION` 17. A customer's credit is that one row, made on the first credit with a zero balance and an `issued` row of 0, in the store currency and with no expiry. The user meta `_fxc_store_credit` (`GiftCardService::CREDIT_META`) keeps the card id, so the cart of a customer who never had credit reads no table. The code is random like any card's but never shown; the cart, checkout and their scripts only see `store-credit`.
2. **It pays by itself.** A signed-in customer with a balance has the credit applied to every cart after any gift card codes (`CartState::$credit`, `GiftCardService::rows()`). The cart and checkout show a "Store credit" row with the amount and a "Don't use" button; turned off, the row shows "$30.00 available" and a "Use" button. The choice is `Cart::$skip_credit` and survives a reload and an emptied cart. Without scripts the buttons post `remove_coupon=store-credit` and `use_credit=1`. Typing the hidden code in the coupon box counts as a wrong code against `gift_card_ip`, and a redeem checks that the card's owner placed the order, so only its owner can spend it. A guest who posts `store-credit` is told to log in.
3. **Refunds to credit.** The refund dialog shows "Refund $X to store credit" on orders of a customer account. `POST admin/orders/{id}/refunds` takes `to_credit`, which calls `GiftCardService::refund_to_credit()`. That goes through the new `PaymentService::refund_as()`, which records a succeeded refund (lines, restock, reason and status as for a manual refund) and, in the same database transaction, runs a closure that credits the account with a `credited` activity row tied to the refund. Either both happen or neither does. Guest orders cannot be refunded to credit. A manual refund now goes through `refund_as()` with no closure.
4. **Admin changes.** Customers gains a Store credit column (guests show "–"). Clicking a balance opens a dialog with the balance, an Add / Take off form with a required reason ("Reason, shown to the customer") and the history. Routes: `GET admin/customers/{id}/credit` (view orders) and `POST` with `amount` (signed minor units) and `note` (edit orders), served by `GiftCardService::adjust_credit()`, which refuses zero and taking off more than the balance. The customer list returns `credit` per account from one `IN` query (`GiftCardRepository::for_users()`). Orders > Gift cards leaves credit accounts out. The history table is shared with the gift card screen (`ActivityTable`).
5. **My Account.** A "Store credit" endpoint (`store-credit`) shows the balance, how it is used and the last 100 changes: "Paid for order #1001", "Refund for order #1001", "Added by the store" or "Taken off by the store" with the reason. The menu shows it once the customer has had credit; the dashboard says "You have $30.00 in store credit." when the balance is above zero. Adding the endpoint flushes the rewrite rules once.
6. **Privacy.** The personal data export adds a Store credit group with the balance, found by the account even after the email changed. Erasing leaves the balance, which stays tied to the account by `user_id`.
7. **Gift card fix.** A card payment's `gateway_reference` was the card id, so a second order paid by the same card hit the unique (gateway, reference) key and failed. It is now `<card id>-<order id>`; `GiftCardGateway` still reads the card id from the start. Refunds to credit use `store-credit-<card id>-<refund id>`.

**Alternatives.** A balance in user meta: no row lock, no history and no payment row on the order, so refunds of credit payments would need their own path. A separate credit table: the same columns and locking as gift cards a second time. Showing the credit as a code the customer types: easy to lose, and a shared code would let others spend it. Asking the shopper to opt in at each checkout: most shoppers with credit want it used, and turning it off is one click.

**Consequences.** One column, `DB_VERSION` 17, one user meta key, one account endpoint, one admin route and one REST argument; no setting (still 67 keys). A cart without credit costs one cached user meta read. Tests cover the toggle round trip (unit); paying with credit, turning it off and on, guests and accounts without credit, the hidden code, refunds to credit and their limit, guest orders, the admin routes, the account page, the privacy export and one card paying two orders (integration).

**Revisit when.** Merchants ask for credit that expires, credit for guests by email, customers turning gift cards into credit, a cap on how much of an order credit may pay, bulk credit for many customers, or a credit email to the customer.

## ADR-073: Subscriptions, part 1 (core and manual renewals)

**Context.** Item 7 of the post-1.0 list (doc 07), part 1 built on 2026-11-01. Merchants selling coffee, boxes or memberships want a product that bills again every week, month or year. WooCommerce needs a paid extension for it, and that extension charges saved cards. FlexaCommerce has Stripe and PayPal (ADR-052, ADR-054) but no saved cards yet, so part 1 renews by hand: the store makes the renewal order and the shopper pays it from an email. Automatic charges come in part 2.

**Decision.**
1. **A plan on the row.** A simple product, or a variable product and each of its variations, can carry a plan in variant meta `subscription` (`SubscriptionPlan`): `period` (day, week, month, year), `interval` (1 to 365), `length` (number of payments up to 999, 0 renews until cancelled), `trial_days` (0 to 730) and `signup_fee` (minor units). A variation without its own plan uses the product's (`Product::plan_for()`). `SubscriptionPlan::after()` adds periods in UTC and keeps the day of the month: a plan started on the 31st bills on Feb 28 and then on Mar 31 (`anchor_day`). `key()` groups lines with the same period, interval, length and trial; the fee is left out. Gift cards, bundles and bundle parts cannot be subscriptions (`ProductService` refuses with a field path), and other product types drop the plan on save. Product CSV still reports WooCommerce subscription rows as unsupported.
2. **Editor.** The General tab of a simple or variable product has "Sell as a subscription" with Bill every (number and period), Number of payments, Free trial (days) and Sign-up fee. A variation has "Own subscription plan" with the same fields and says which plan it inherits. Turning on Gift card clears the plan.
3. **Storefront.** The price reads "$15.00 / month" (or "every 3 months") with the terms under it: "12 payments, 14-day free trial, $5.00 sign-up fee" (`ProductPrice::with_plan()`). The cart and checkout lines show the whole plan in one line under the product name. The first payment is the price plus the sign-up fee; with a trial it is only the fee (`CartService::FIRST`). Coupons, fee rules and option prices charged once per line (ADR-029) apply to the first order only; option prices per unit renew with the line.
4. **Checkout.** Each group of lines with one plan key becomes one subscription, `pending`, created in the order transaction with a `Snapshot` of what each renewal bills: the lines at their price without trial or fee, shipped and taxed as this order, with no coupons, fees or credit (`CartService::RECURRING`). A subscription belongs to an account: a guest gets one made from the billing email with a link to set the password; an email that has an account must log in first, unless this same checkout made it a moment ago (`ACCOUNT_TOKEN` user meta, so a repeated submit does not fail). Order lines sold on a plan carry `meta.subscription`.
5. **Life cycle.** Statuses `pending`, `active`, `on-hold`, `pending-cancel`, `cancelled`, `expired` (`SubscriptionStatus`). A paid first order (processing or completed) starts it: `start_at`, the trial end, and the next payment one period on (or at the trial end). A cancelled or failed first order before any renewal cancels it. The hourly cron (`Cron::run_hourly()`) handles 25 due rows and 25 ending rows per run: on the payment day it makes a renewal order from the snapshot (`created_via` `subscription`, order meta `_fxc_subscription`), moves the next payment one period on and puts the row `on-hold` until that order is paid. A renewal of 0 stays active. No stock for a renewal puts the row on hold with no order and an event that says why. A plan with a length ends `expired` after the last paid period. A renewal paid by an internal gateway (gift card) leaves the payment method empty so the shopper picks one on the pay page.
6. **Paying a renewal.** Email `customer_renewal_invoice` ("Subscription renewal", on by default, editable in Emails) goes out when a renewal order is created pending, with the order's pay link. (Since ADR-074 it goes out through the hook `flexacommerce.subscription.renewal_due`, only for renewals no saved card paid.) The account's subscription page shows "Pay renewal" while one is waiting. Paying it puts the row back to `active`.
7. **Cancelling.** The shopper cancels from My Account: an active row runs to the end of the paid period (`pending-cancel`, "Keep my subscription" undoes it) and the cron ends it then. The merchant can also cancel now, which cancels unpaid renewal orders. Every cancel emails the store (`cancelled_subscription`, to the admin email, with a link that opens the subscription on the admin screen).
8. **Admin.** Orders > Subscriptions (`flexacommerce-subscriptions`, view orders) lists rows with search by email, name, product or number, status tabs with counts and paging. A row opens a dialog with the terms, each renewal's lines, shipping, tax and total, the orders it made, the addresses and the history (`fxc_subscription_events`, with who did it). Actions: make the renewal order now, put on hold, resume, reactivate, cancel at the end of the period, cancel now (with a confirm). "Move the next payment" takes a store-local date after today and keeps the time of day; later payments follow the new day. Routes: `GET admin/subscriptions`, `GET` and `PATCH admin/subscriptions/{id}` (`next_payment_on`), `POST admin/subscriptions/{id}/actions` (edit orders).
9. **My Account.** A Subscriptions endpoint (`subscriptions`, `view-subscription`) lists the rows with status, next payment and price; one row shows its terms, the cancel or keep button, the pay button and the related orders. The menu shows it once the customer has subscribed (user meta `_fxc_has_subscriptions`, so other accounts read no table).
10. **Privacy.** The exporter `flexacommerce-subscriptions` lists each subscription with its status, price, start day and next payment, found by the subscription's email. The eraser keeps running subscriptions (the store still bills them) and clears the email and addresses of cancelled and expired ones.
11. **Schema.** `DB_VERSION` 18 adds `fxc_subscriptions` (one row per subscription with the plan columns, `billed`, `anchor_day`, `total`, the snapshot JSON, addresses and dates), `fxc_subscription_orders` (subscription, order, `parent` or `renewal`) and `fxc_subscription_events`.

**Alternatives.** Charging saved cards now: Stripe and PayPal would need saved payment methods, off-session charges and their failure handling first; manual renewals work with every gateway, cash on delivery included. A subscription as an order type: renewals would lock the order table and order screens would need a second mode. Pricing renewals from the live product: a price change would bill old subscribers without telling them; the snapshot keeps the price they agreed to. One subscription per cart: a monthly coffee and a yearly membership cannot share billing days. Allowing guest subscriptions: the shopper could not see or cancel it.

**Consequences.** Three tables, `DB_VERSION` 18, two emails, one admin screen with three routes, two account endpoints, one privacy exporter and eraser, no setting (still 67 keys). Products without a plan cost nothing more: `plan_for()` reads meta already loaded with the row, and the cron's two queries use the `(status, next_payment_at)` and `(status, end_at)` indexes. Tests cover the plan date math, parsing and wording and the snapshot (unit); first payment, trial, length, renewals waiting for payment, cancel at the end and now, the guest account, rescheduling, the admin routes, the account page and privacy (integration); the product page, a guest checkout and the admin dialog actions (E2E).

**Revisit when (part 2 and later).** Automatic renewal charges through Stripe and PayPal saved payment methods with retries, a reminder email before a renewal, the shopper changing quantity, plan or address, switching between plans with proration, pausing from My Account, and moving WooCommerce Subscriptions in the migration.

## ADR-074: Subscriptions, part 2 item 1 (automatic Stripe renewals)

**Context.** Part 2 of doc 07 item 7, item 1, built on 2026-11-01. Part 1 (ADR-073) emails each renewal for the customer to pay by hand. Most subscribers expect the card they paid with to be charged again, and every manual step loses some of them. Stripe can save the card on the first payment and charge it later with nobody at the screen ("off-session"). Failed charges have to be tried again, and the customer needs a way to put in a new card.

**Decision.**
1. **Gateway contract.** `Payments\RecurringGateway extends PaymentGateway` (internal, like `ExpressGateway`): `saved_method( reference )` reads the method a paid payment saved, `charge_saved( PaymentRequest, SavedMethod )` charges it off-session, `setup_url()` opens the provider's page for a new method, which comes back to the return URL with `RecurringGateway::SETUP_ARG` (`fxc_setup`), and `finish_setup( reference, tag )` reads it back. `SavedMethod` holds the provider's customer id, the method id and a label ("Visa ending in 4242"). `CheckoutContext::$recurring` is true when the cart holds a plan, and when a subscription order is paid again from the pay page. `PaymentService::charge_saved()` makes the payment row, calls the gateway and applies the result as any other payment; a redirect or a bank asking for 3-D Secure is a failure (`authentication_required`).
2. **Stripe saves the card.** For an order with a plan line or a renewal order, `StripeGateway` uses the user's Stripe customer (user option `_fxc_stripe_customer_<mode>`, made on first use with the email, name and user id) and asks for `setup_future_usage=off_session` on the PaymentIntent, the Payment Element (`setupFutureUsage`) and the Checkout Session. A customer deleted in the Stripe Dashboard is replaced and the request sent again. Orders without a plan send no customer, as before.
3. **Capture.** The hourly cron reads the method of each running row that has none recorded yet (`payment_token` NULL, 25 per run, not counted in `run()`'s total): it looks at the Stripe payments of the row's three latest paid orders and stores `payment_customer`, `payment_token` and `payment_label`, with event `payment-method`. A row paid some other way gets `payment_token` '' and keeps renewing by hand. A Stripe error leaves the row for the next run.
4. **Renewal charge.** When a renewal order is made for a row with a token, the order is charged right away to that card through `charge_saved()` (PaymentIntent with `off_session`, `confirm`, metadata `fxc_renewal=1`). Paid: the order goes processing and the row active, as a payment by hand does. Processing (a bank debit): event `renewal-processing`, the webhook pays it later. Failed: the row stays on hold with the pending order; it is tried again after 1, 2 and 4 days (`RETRY_DAYS`, `retry_count`, `retry_at`, event `payment-failed` with Stripe's reason). The customer gets an email on the first failure and on the last one; the tries in between are quiet. After the last try nothing is charged again and the customer pays from the email. Paying the order any other way clears the retry fields, and a payment that was not our own charge sets `payment_token` back to NULL, so the next cron reads the card that paid it. A row without a token, or whose gateway is turned off, gets the part 1 invoice email.
5. **Email.** `customer_renewal_invoice` now listens to `flexacommerce.subscription.renewal_due` (`OrderView`, context `{failed, retry_at, final, attempt, subscription_url}`) instead of order creation, so a renewal the card paid sends nothing. A failed charge changes the subject ("Your {site_title} subscription payment failed") and heading and shows the reason, the next try or "no more tries", the pay button and a "Change the payment method" link to the account page. Notifications still does not depend on Subscriptions: it listens to the hook name.
6. **New card from My Account.** The subscription page shows the payment method ("Visa ending in 4242, charged automatically") and, while a renewal waits, when the next try is. "Change payment method" (or "Pay renewals automatically" for a row paid by hand) is an account form that sends the shopper to a Stripe Checkout Session in `setup` mode, with `client_reference_id` `subscription-<id>`. Back on the page with `fxc_setup`, `finish_update()` reads the session with the secret key and keeps the method only if the session is complete, in setup mode, its SetupIntent succeeded and it names this subscription. The method replaces the old one on this row and on the customer's other running rows that used the same card (event `payment-method`), and an unpaid renewal is charged to it at once; a failure there is shown on the page and does not count as a try. Notices: saved, saved and paid, saved but the payment failed.
7. **Admin.** The subscription dialog shows the saved card and whether renewals are automatic, how many charges failed and the next try, and "Charge the saved method now" while a renewal waits (`POST admin/subscriptions/{id}/actions` with `retry`; the answer adds `charge {result, message}` and a failure counts as a try).
8. **One charge per renewal.** Each saved-method charge has its own payment row (data `saved`) and a random idempotency key. If a request dies after Stripe charged but before the answer was stored, the row stays pending with no reference; the next charge of that order through the same gateway and for the same amount reuses the row and its key, so Stripe answers with the first charge.
9. **Privacy.** Only Stripe ids and the card label are stored, never card data. The eraser clears `payment_customer`, `payment_token` and `payment_label` with the rest of an ended row.
10. **Schema.** `DB_VERSION` 19 adds `payment_customer`, `payment_token` (NULL until read, '' when there is none), `payment_label`, `retry_count`, `retry_at` and the index `status_retry (status, retry_at)` to `fxc_subscriptions`.

**Alternatives.** Stripe Billing subscriptions: Stripe would own the schedule, prices and proration, and the store's own renewals, snapshot and gateways would be bypassed or duplicated. Saving the card on every Stripe order: customers who never subscribe would get a saved card they did not ask for. Reading the card right after the first payment, in the payment request: it adds a Stripe call to every subscription checkout and fails with the webhook-only bank debits; the cron reads it within the hour and before the first renewal. Retrying on every cron run: banks count the declines, and three spaced tries is what card networks advise. Emailing on every failed try: four emails for one renewal reads as spam; the first and the last are the ones the customer can act on.

**Consequences.** One internal interface, one value class, five columns and an index, `DB_VERSION` 19, one hook, no setting (still 67 keys), no boot-time hook. PayPal-paid subscriptions keep renewing by hand until item 5. Tests: the card saved on the first order and charged on renewal with no invoice; a decline tried again three times with emails on the first and the last; a retry that succeeds; a charge cut off before its answer sent again with the same key; the admin charge; the new card from My Account with the session check (integration, FakeStripe); the off-session, saved-method and setup requests against stripe-mock (contract); the account and subscription E2E specs still pass.

**Revisit when.** PayPal vault (item 5). Stripe's own retry schedule (Smart Retries) for stores that want it. A setting for the retry days if merchants ask.

## ADR-075: Subscriptions, part 2 item 2 (renewal reminder email)

**Context.** Part 2 of doc 07 item 7, item 2, built on 2026-11-02. A customer whose card is charged with no warning is the most common source of refund requests and chargebacks for subscriptions, and card networks expect a notice before a free trial turns into a paid plan. Customers who pay by hand also like to know a payment is coming.

**Decision.**
1. **Email.** A new customer email `customer_renewal_reminder` ("Subscription renewal reminder", template `customer-renewal-reminder.php`) is built on the subscription's first order. It says the date, the price with its period, whether the saved card is charged ("We will charge Visa ending in 4242 on that day") or a payment link is emailed, and links to the subscription page to change the card or cancel. At the end of a free trial it says "Your free trial ends on ..." instead.
2. **Days ahead.** The email's settings get `days`, from 1 to 60, 3 by default (`EmailSettings::MAX_DAYS`, stored 0 means the default). Only emails that implement the internal `Notifications\SentAhead` interface take it; the Emails screen shows a "Days before the renewal" field for them. Turning the email off turns the reminders off. `NotificationServices::days_before()` gives the days, or 0 when the email is off, and `Plugin` hands it to `SubscriptionService` as a closure, so Subscriptions does not depend on Notifications.
3. **When.** After the renewals, the hourly cron reads active rows whose `next_payment_at` falls within the days (`to_remind`, 25 per run, not counted in `run()`'s total). It stores the date in `reminded_for` before it sends, so each renewal date is reminded once, and a date moved by the admin or a payment gets a new reminder. A plan whose period is no longer than the notice (a weekly plan with 7 days) gets none, since it would be reminded right after paying; a trial counts the trial length. A row with no first order is skipped. Each reminder adds event `reminder`.
4. **Hook.** `SubscriptionService::RENEWAL_REMINDER` (`flexacommerce.subscription.renewal_reminder`) passes the first order's view and a context with `subscription_id`, `title`, `price`, `renews_at`, `trial`, `automatic`, `payment_label` and `subscription_url`. The email listens to the hook name, as the renewal email does; the send key `subscription_id:renews_at` keeps a second run from emailing twice.
5. **Schema.** `DB_VERSION` 20 adds `reminded_for` to `fxc_subscriptions`. The query uses the existing `status_next` index.

**Alternatives.** A plugin setting for the days: it belongs next to the email it controls, and Settings has a key budget. Remembering sent reminders only in the email history: the history is per email and can be cleared; a column keeps the cron to one indexed query. Reminding every period whatever its length: a weekly plan would get a reminder a few days after each payment. A reminder for pending or on-hold rows: those already get the renewal email with a pay link.

**Consequences.** One email, one template, one internal interface, one column, `DB_VERSION` 20, one hook, no setting (still 67 keys), no boot-time hook. Tests: a reminder days before each renewal, sent once and again for the next date; the card named and the days and on/off taken from the email settings; a trial end reminded and a short period skipped; the Emails screen reads and saves the days (integration); the admin sets the days (E2E with axe).

**Revisit when.** Merchants ask for a second reminder (for yearly plans a month and a week ahead). The customer changes the plan or quantity from My Account (item 3), where the reminder should show the new price.
