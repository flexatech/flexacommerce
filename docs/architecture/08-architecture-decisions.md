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
