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

**Consequences.** No DB_VERSION. Activation and `maybe_upgrade()` create the list empty and autoloaded; P10 now counts four autoloaded options. A deleted field adds nothing to lines already in a cart, although the line keeps its label. A set that is turned off still charges items already in a cart, since the shopper saw that price. File uploads, date pickers, color pickers and per-variation option sets are left out.

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
