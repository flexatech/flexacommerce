# 01. Product Scope

Status: **Approved 2026-10-24**.
Date: 2026-10-24.

## 1. Repository audit (Phase 0 findings)

| Item | Finding |
|---|---|
| Plugin directory `wp-content/plugins/flexacommerce/` | Empty. No source, no git repository, no composer/package manifests, no tests, no CI. |
| Host site | Local by Flywheel site, WordPress **7.1.3**, PHP 8.5 CLI available, MariaDB/MySQL services available, block themes Twenty Twenty-Three/Four/Five installed. WooCommerce is **not** installed on this site. |
| Repository instructions | None in the plugin. The user's global `CLAUDE.md` sets prose rules (no em-dash, no hype wording), which these documents follow. |
| Reusable conventions | The **Flexa plugin lineage** (sibling plugins under `~/Local-Sites/woocommerce/.../plugins/`). The newest generation (`flexa-pricing`, `flexa-formflow`, `flexa-mail`, 2026-09/10) uses: `Flexa\<Product>\` PSR-4 namespace in `src/`, `declare(strict_types=1)`, `final` classes, `Concerns\HasInstance` singleton trait, `Api\Router` + abstract `Api\Endpoint` with mandatory permission callbacks, `Database\Schema` with `DB_VERSION` + `dbDelta()` on `admin_init`, `Setup\Activator`, `Maintenance\Eraser`, dot-separated hooks (`flexa_pricing.booted`), React admin apps in `client/`, a bootstrap `define()` block and a `spl_autoload_register` fallback so no runtime composer dependency ships. |
| Closest domain precedent | `flexatech-stay-suite` 1.6.0 (bookings with checkout and payments). Reusable ideas: integer minor-unit money columns (`*_cents bigint`), `order_status_log` table, `payments` with `UNIQUE(gateway, txn_id)`, `webhook_events` with `UNIQUE(gateway, event_id)`, a gateway interface where "the amount never comes from the client", a gateway registry where only enabled gateways resolve, and a cron that expires unpaid holds. It is a different product (older naming generation), so these are design precedents, not code to copy. |
| WooCommerce reference | WooCommerce **11.0.1** is installed on the sibling `woocommerce` Local site. It was used to verify CRUD, HPOS, stock and email behavior in source (see doc 05 §2). |

**Conclusion:** this is a greenfield plugin inside an established house style. The architecture adopts the Flexa conventions and departs from them only where the commerce domain requires it (each departure has an ADR in doc 08).

### 1.1 Naming vocabulary (proposed, locked at approval)

| Token | Value | Note |
|---|---|---|
| Plugin slug / text domain | `flexacommerce` | Matches the existing folder. See open question Q1 (`flexa-commerce` would match the lineage's hyphenated slugs; the slug is permanent on WordPress.org). |
| PHP namespace | `Flexa\Commerce\` → `src/` | |
| Constants | `FLEXACOMMERCE_VERSION`, `_FILE`, `_PATH`, `_URL`, `_BASENAME`, `_REST_NAMESPACE`, `_TEXT_DOMAIN` | |
| REST namespace | `flexacommerce/v1` | |
| Hook prefix | `flexacommerce.` (dot-separated, newest generation) | e.g. `flexacommerce.order.status_changed` |
| Option prefix | `flexacommerce_` | |
| DB table prefix | `{$wpdb->prefix}fxc_` | Short prefix keeps index names readable. |
| Post type / taxonomies | `fxc_product`, `fxc_product_cat`, `fxc_product_tag`, `fxc_attribute` | Must not collide with WooCommerce's `product`, `product_variation`, `product_cat`, `product_tag`, `pa_*`, because both plugins run side by side during migration. |
| Blocks / shortcodes | `flexacommerce/*`, `[flexacommerce_*]` | |
| JS global / admin mount id / Tailwind prefix | `flexaCommerce`, `flexacommerce-admin-root`, `fxc` | |

## 2. Product vision

FlexaCommerce is a single WordPress plugin for stores that sell **physical products** and want a small, understandable commerce engine. It covers the catalog, cart, checkout, basic shipping, offline payments, order management, and two-way migration with WooCommerce. Everything else comes through a documented extension API.

Positioning statements must be testable. "Lighter than WooCommerce" is a hypothesis, measured by the benchmark protocol in doc 06. Marketing copy may not claim a speed advantage until the benchmark report exists.

## 3. Target users

| Persona | Need | Implication |
|---|---|---|
| Small store owner (10 to 2,000 products, up to a few thousand orders per month) | Sell physical goods with COD or bank transfer and a few shipping rules | Sensible defaults, no setup wizard longer than one screen, works with the active theme |
| Agency developer | Build a client store on an existing block or classic theme | Blocks, template overrides, stable hooks, no proprietary theme |
| Store leaving WooCommerce | Reduce plugin surface while keeping products, customers and order history | Reliable WooCommerce → FlexaCommerce migration with a verification report |
| Store going back to WooCommerce | Not be locked in | FlexaCommerce → WooCommerce migration with the same guarantees |
| Extension developer | Add a payment gateway or shipping method | Versioned PHP interfaces, documented lifecycle, no reliance on table layout |

## 4. Feature matrix

Legend: **MVP** = required for MVP. **FND** = architectural foundation in MVP, user-facing implementation later. **POST** = post-MVP extension. **OUT** = explicitly out of scope.

### 4.1 Catalog

| Feature | Class | Notes and decisions |
|---|---|---|
| Simple products | MVP | One purchasable variant row (doc 03 §3). |
| Variable products | MVP | Up to 250 variants per product supported in the UI. Beyond that the admin editor paginates. |
| Global attributes (shared, e.g. Color) | MVP | Stored in the `fxc_attribute` taxonomy (ADR-006). Needed for WooCommerce `pa_*` parity and storefront filtering. |
| Local (per-product) attributes | MVP | Stored in product meta. WooCommerce has the same concept. |
| Variation combinations, including "any value" wildcards | MVP | Wildcards are needed because many WooCommerce stores use "Any Size" variations. The UI generates explicit combinations by default. |
| SKU, uniqueness | MVP | Unique across all variants of all products, enforced by a DB unique index. Empty SKU is allowed and stored as `NULL`. |
| Regular and sale price | MVP | Sale price may have a schedule (start/end, UTC). |
| Product image and gallery | MVP | Media Library attachment IDs. Variants may have their own image. |
| Categories and tags | MVP | Hierarchical categories, flat tags. |
| Stock management | MVP | Per variant: managed or not, quantity, backorders (no/notify/yes), low-stock threshold. Stock is never inherited from the parent (ADR-004). |
| Product visibility and status | MVP | WordPress post statuses (publish, draft, pending, private, future) plus catalog visibility (visible / search only / catalog only / hidden). |
| Product deletion with order history preserved | MVP | Orders hold snapshots. Deleting a product never deletes or alters order lines. |
| Product weight and dimensions | MVP | Needed by shipping rules and for WooCommerce round-trip. |
| Grouped, external/affiliate, digital/downloadable, subscription, bundle products | OUT (core) / POST (extension) | Migration reports them as unsupported (doc 05 §7). |
| Public product-type registration API | FND | Internal type registry exists in MVP; public contract is post-MVP (doc 04 §6). |
| Reviews and ratings | POST | WordPress comments could back it later. |
| Product import/export CSV | POST | The migration subsystem's canonical format covers bulk transfer in MVP. |

### 4.2 Storefront

| Feature | Class | Notes |
|---|---|---|
| Shop archive | MVP | `fxc_product` post type archive, works with core Query Loop. |
| Product detail page | MVP | Block template for block themes. PHP template for classic themes. |
| Product search and pagination | MVP | WordPress search scoped to `fxc_product`, plus SKU search (exact match). |
| Category/tag archives | MVP | |
| Attribute filtering, price filtering | FND | Data model supports it (taxonomy + denormalized price meta). Filter blocks are post-MVP. |
| Variation selection | MVP | Interactivity API store, server-provided variant matrix, server validation on add to cart. |
| Add to cart, update quantity, remove | MVP | Works without JavaScript through form POST. Enhanced with JavaScript. |
| Mini-cart / cart count | MVP | Fetched client-side so full pages stay cacheable. |
| Guest checkout | MVP | Default on. |
| Customer accounts (register at checkout, login, order history, addresses) | MVP | Uses WordPress users. No custom login system. |
| Order confirmation page | MVP | Access by signed order key (doc 02 §7.5). |
| Guest order lookup (email + order number) | POST | The order key link in the email covers MVP needs. |
| Theme compatibility (block and classic) | MVP | Tested on Twenty Twenty-Five and one popular classic theme (Q9). |
| Blocks | MVP | List in doc 02 §6.3. |
| Shortcodes as fallback | MVP | Same renderers as the blocks. |
| Wishlist, compare, quick view, recently viewed | OUT | |

### 4.3 Commerce

| Feature | Class | Notes |
|---|---|---|
| Single store currency | MVP | ISO 4217 code + exponent. Multi-currency is OUT (an extension may add display conversion later). |
| Monetary precision | MVP | Integers in currency minor units, no floats in calculations (doc 03 §2.1). |
| Cart calculations | MVP | Server-side only. Browser totals are never accepted. |
| Server-side price validation at checkout | MVP | Prices are re-read and re-calculated inside the checkout transaction. |
| Shipping zones (country, state, postcode pattern) | MVP | |
| Flat rate, free shipping (with optional minimum), local pickup | MVP | |
| Live carrier rates | POST | Through the shipping method contract. |
| Cash on delivery | MVP | |
| Manual bank transfer | MVP | |
| Payment gateway extension contract | MVP | Must be stable at 1.0 so the first online gateway (Stripe/PayPal add-on) can ship without core changes. |
| Online gateway (e.g. Stripe) | POST | First-party add-on recommended right after MVP (Q4). |
| Checkout validation | MVP | Required fields per country, email, phone, stock, shipping availability, gateway availability. |
| Order creation | MVP | Atomic: one DB transaction for stock, order, items, addresses, history. |
| Order status transitions | MVP | State machine (doc 02 §7.6). |
| Stock deduction and restoration | MVP | At order placement, idempotent per line (doc 02 §7.4). |
| Email notifications | MVP | 8 emails (5 to customers, 4 to the admin, the cancellation email goes to both), `wp_mail`, overridable templates (doc 02 §7.9). |
| Cancellation | MVP | Customer can cancel only while `pending`. Admin can cancel any non-final order. |
| Refunds | MVP (manual) | Admin records full or partial refunds with optional restock. Gateway-initiated refunds through the contract when the gateway supports them. |
| Taxes | **FND** (see Q2) | Hidden requirement. Schema stores tax per line and per order. MVP ships "prices entered and displayed as final" with tax amounts zero, plus a `TaxCalculator` seam. Whether MVP must calculate tax is open question Q2, the single most important scope question. |
| Coupons / discounts | FND | Schema has discount columns and `discount` order lines so migrated WooCommerce orders keep their coupon history. Coupon management is POST. |
| Invoices / PDF | POST | |
| Abandoned cart recovery | OUT | |
| B2B pricing, roles pricing, quantity rules | OUT (extension via pricing filters) | |
| Analytics dashboard | POST | Admin order list totals and a simple sales summary only in MVP. |
| GDPR personal data export and erasure | MVP | WordPress privacy tools. Hidden requirement: any plugin that stores personal data in custom tables must hook the core exporter/eraser. |

### 4.4 Migration

| Data | WC → FXC | FXC → WC | Notes |
|---|---|---|---|
| Simple products | MVP | MVP | |
| Variable products, attributes, variations | MVP | MVP | Global (`pa_*`) and local attributes. "Any" variation values preserved. |
| Categories and tags | MVP | MVP | Hierarchy and slugs preserved. |
| Images and media | MVP (same site) | MVP (same site) | Same-site migration reuses attachment IDs. Cross-site media transfer is POST. |
| SKU and stock | MVP | MVP | Duplicate SKUs reported and resolved by policy (doc 05 §6). |
| Customers | MVP | MVP | Same site: customers are already WordPress users. Billing/shipping profile meta is mapped. |
| Orders and order items | MVP | MVP | Historical import: no emails, no payments, no stock changes. |
| Billing and shipping addresses | MVP | MVP | Snapshots. |
| Historical payment references | MVP | MVP | Gateway id, transaction id, paid date, as transaction records. |
| Historical order statuses and notes | MVP | MVP | Current status always. WooCommerce has no structured status history, so notes are imported as history entries. |
| Refunds | MVP | MVP | Mapped to refund transactions and WooCommerce refund objects. |
| Coupon lines, fee lines, tax lines on orders | MVP | MVP | Preserved as historical order lines (amounts only). |
| Unsupported third-party metadata | MVP (preserved) | MVP (restored if originally from WC) | Stored verbatim in an import bucket, reported, never interpreted. |
| Cross-site transport (export file → import on another site) | POST | POST | The canonical format is designed for it. MVP transport is same-site. |
| Continuous two-way sync | OUT | OUT | See doc 05 §8. |
| Import/export validation and error reports | MVP | MVP | |

## 5. Hidden requirements and ambiguities found

1. **Tax.** The brief does not mention it. Many jurisdictions require a correct tax line on every order. Decision needed (Q2). The schema is ready either way.
2. **GDPR / privacy.** Custom order tables hold personal data and must be wired to the WordPress personal data exporter and eraser. Erasure anonymizes orders (accounting retention) instead of deleting them.
3. **Coexistence with WooCommerce.** Migration requires both plugins active on the same site at the same time. Every post type, taxonomy, shortcode, rewrite slug, cookie, role and capability must be namespaced. FlexaCommerce must also declare HPOS compatibility so WooCommerce does not flag it as incompatible.
4. **URL continuity.** Stores migrating from WooCommerce will lose search ranking if product URLs change. Cutover must be able to adopt WooCommerce's permalink bases (doc 05 §9).
5. **Page caching.** Cart state must not make every page uncacheable. Session cookies are created only on the first cart write, and dynamic fragments (cart count) load through REST.
6. **Wildcard variations.** WooCommerce "Any value" variations are common. Supporting them affects the matching algorithm and uniqueness rules.
7. **Order numbers.** Migrated orders keep their old numbers. New orders must not collide (doc 03 §2.7).
8. **Customer cancellation and failed-payment retry** need explicit rules because they move stock.
9. **Emails depend on a working `wp_mail`.** The plugin cannot guarantee delivery. It records send attempts in order history and recommends an SMTP plugin.

## 6. Scope risks (features that would make the MVP unnecessarily complex)

| Temptation | Why it is excluded or minimized |
|---|---|
| Stock reservation table with timed holds per cart | The order itself is the reservation (ADR-005). Cart-level holds add a cleanup system and race conditions without preventing oversell better than an atomic decrement at order placement. |
| Real-time two-way sync with WooCommerce | Conflict resolution across two writable stores is a product of its own. One-time migration plus re-runnable incremental import covers the stated need. |
| Full tax engine | Pending Q2. A rate-table tax engine with compound rates, shipping tax, rounding modes and tax-inclusive displays is larger than the shipping module. |
| Coupons | Discount rules interact with tax, shipping and refunds. Deferred, with schema support. |
| Custom React storefront / headless API | Core blocks + Interactivity API meet the need without a frontend framework. |
| Multi-currency | Changes the money model, reporting and refunds. Out. |
| Product-type plugin API in 1.0 | A public API is a promise. Ship an internal registry first and publish the contract once two real types exist. |

## 7. Assumptions (to be validated, see doc 09)

- A1. Typical catalog ≤ 10,000 products and ≤ 100,000 orders. Design targets 50,000 products / 1,000,000 orders without schema changes.
- A2. Primary distribution is WordPress.org (free plugin). Paid add-ons are separate plugins (Guideline 5).
- A3. Migration happens on one site where both plugins are active.
- A4. One store currency, set before the first order.
- A5. Stores sell physical goods only. Quantities are whole numbers.
- A6. The team has PHP, WordPress block and MySQL experience. No dedicated frontend framework is required.

## 8. Non-goals

- Feature parity with WooCommerce or compatibility with WooCommerce extensions.
- Running WooCommerce extensions on top of FlexaCommerce, or emulating WooCommerce hooks.
- A page builder, a proprietary theme, a headless commerce API.
- Marketplace / multi-vendor, POS, subscriptions, bookings, digital downloads.
- Continuous synchronization between two stores.

## 9. MVP definition

Two milestones, both part of version 1.0:

**M1 "Can safely take real orders" (smallest coherent MVP).** Catalog (simple + variable), cart, guest and account checkout, shipping zones with flat/free/pickup, COD and bank transfer, atomic stock, order state machine, admin order management, manual refunds, emails, blocks + templates + shortcodes, GDPR hooks, security gates from doc 06 passed. Taxes per Q2 decision.

**M2 "1.0 release".** M1 plus WooCommerce → FlexaCommerce and FlexaCommerce → WooCommerce migration (legacy storage and HPOS), verification reports, published payment/shipping extension contract v1, benchmark report.

A store may go live on M1 without migration. A store migrating from WooCommerce needs M2.
