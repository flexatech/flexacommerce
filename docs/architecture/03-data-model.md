# 03. Data Model

Status: **Approved 2026-10-24**. All table names below are shown without `{$wpdb->prefix}`. SQL is written in `dbDelta` style (lowercase types, one column per line, `PRIMARY KEY  (` with two spaces, `KEY` not `INDEX`), matching `flexa-pricing/src/Database/Schema.php`.

## 1. Storage map

| Data | Storage | Why |
|---|---|---|
| Product (title, description, slug, status, dates, author, featured image) | `wp_posts` as `fxc_product` | Gives the block editor, revisions, permalinks, search, Query Loop, SEO plugin and sitemap support for free. |
| Product-level settings (type, attributes definition, gallery, visibility, denormalized price/stock) | `wp_postmeta`, keys prefixed `_fxc_` | Small, read with the post, cached by core meta cache. |
| Categories, tags | `fxc_product_cat`, `fxc_product_tag` taxonomies | Core taxonomy UI, archives, hierarchy. |
| Global attributes and their values | `fxc_attribute` hierarchical taxonomy (attribute = top-level term, value = child term) | Filterable with `tax_query`, no dynamic taxonomy registration (ADR-006). |
| Purchasable units (simple product base row, variations) | `fxc_variants` table | SKU uniqueness, atomic stock, single-query loading (ADR-001). |
| Orders and everything under them | `fxc_orders`, `fxc_order_items`, `fxc_order_addresses`, `fxc_order_history`, `fxc_order_meta` | Query efficiency, transactions, no post-table pollution (the same reasons WooCommerce moved to HPOS). |
| Payments and refunds | `fxc_transactions` | One row per money movement attempt. |
| Webhook dedupe | `fxc_webhook_events` | DB-level replay protection. |
| Cart sessions | `fxc_sessions` | Server-side, lazily created (ADR-008). |
| Order number allocation | `fxc_sequences` | Atomic counter. |
| Plugin log | `fxc_logs` | Filterable by channel and level, pruned by cron (§4.12). |
| Migration | `fxc_id_map`, `fxc_migration_jobs`, `fxc_migration_log` | Idempotency and resumability (doc 05). |
| Customers | `wp_users` + user meta `fxc_billing`, `fxc_shipping` (JSON) | No separate customer table (§7). |
| Settings | `wp_options`: `flexacommerce_settings` (autoload), `flexacommerce_shipping_zones`, `flexacommerce_gateway_{id}`, `flexacommerce_email_{id}` (no autoload) | Small configuration data. |
| Media | Media Library attachments | Product meta and variants store attachment IDs only. |

## 2. Cross-cutting rules

### 2.1 Money

- **Representation:** signed `bigint(20)` in the currency's **minor unit** (cents for USD, yen for JPY, fils for KWD). Column names have no suffix inside `fxc_` tables because every amount column follows this rule. PHP type: `Contracts\Money { public readonly int $amount; public readonly string $currency; }`.
- **Exponent:** from a bundled ISO 4217 table in `Support\Currency` (USD 2, JPY 0, KWD 3, ...), filterable through `flexacommerce.currency.exponent` for non-standard needs. Orders snapshot `currency` and `currency_exponent` so history stays correct even if the store currency changes.
- **No floats anywhere in money paths.** Decimal input (admin forms, WooCommerce strings like `"19.990"`) is parsed by `Money::from_decimal_string( string, currency, exponent )` using string arithmetic: split on the decimal separator, pad or round the fraction to the exponent. A value with more fractional digits than the exponent is rounded **half up (away from zero)** and, during migration, logged as a precision warning with the original string preserved.
- **Exponent is a parameter, not object state (phase 1 decision):** `Money` holds only `amount` and `currency`. Methods that convert to or from decimals (`from_decimal_string()`, `to_decimal_string()`, `is_exact_decimal()`) take the exponent explicitly, and the `Contracts\MoneyFactory` service (implemented by `Support\StoreMoneyFactory`) supplies it from the currency table. This keeps `Contracts` free of any dependency on `Support` and keeps the value object cheap to construct from a database row.
- **Calculation order:** `line subtotal = unit_price × qty` (exact integer). Discounts and taxes (when enabled) are calculated **per line** and rounded per line half-up. Order totals are sums of rounded line values, so totals always equal the sum of their lines. Ratios use `Money::allocate()` (largest-remainder method) when an amount must be split across lines, for example a partial refund across items.
- **Currency change:** allowed only while no product has a price and no order exists, or when the new currency has the same exponent (admin warning: prices are not converted). Converting a catalog to a different exponent is post-MVP.

### 2.2 Quantity

`int(10) unsigned`, whole units only. Per-line maximum 999 by default (setting). Stock quantities are signed `int(11)` so backorders can go negative.

### 2.3 Dates

`datetime` columns in **UTC**, named `*_at`. PHP uses `DateTimeImmutable` with `UTC` via `Support\Clock` (injectable for tests). Display converts with `wp_date()`. WordPress post dates for products keep core behavior (`post_date` local + `post_date_gmt`).

### 2.4 JSON metadata policy

- JSON is stored in `longtext` columns. The `JSON` column type is not used, because MariaDB treats it as an alias of `longtext` with a check constraint, and `dbDelta` compares types textually.
- Encoding: `json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR )`. Decoding with `JSON_THROW_ON_ERROR`, failures logged and treated as empty.
- **PHP `serialize()` is never written by FlexaCommerce** into its own tables or `_fxc_` meta. Imported serialized WooCommerce metadata is decoded with `maybe_unserialize()` semantics disabled for objects (`unserialize( $s, [ 'allowed_classes' => false ] )`) and re-encoded as JSON.
- JSON columns are never filtered in SQL. Anything that must be queried gets its own column or goes to `fxc_order_meta`.
- Maximum JSON size per column: 64 KB, enforced in repositories (`MetadataTooLarge` exception, migration logs and truncates the import bucket instead).

### 2.5 SKU

- Trimmed, internal whitespace preserved, max 100 characters, stored as `NULL` when empty.
- Unique across **all** rows of `fxc_variants` (simple product base rows, variable product base rows, variations) via `UNIQUE KEY sku (sku)`. MySQL/MariaDB allow multiple `NULL`s in a unique index, which gives "empty SKU allowed, non-empty unique" without application logic.
- Case-insensitive uniqueness through the table collation (`utf8mb4_unicode_520_ci` or the site default `_ci` collation). `ABC-1` and `abc-1` conflict, which matches what shop owners expect.
- Insert/update failing with duplicate key `1062` on `sku` maps to `DuplicateSku` with the conflicting product id.

### 2.6 Identifiers

- Internal ids are `bigint(20) unsigned AUTO_INCREMENT`.
- Order numbers, order keys, session tokens and idempotency keys are separate from ids, never derived from them in a guessable way except the human order number.

### 2.7 Order numbering

- `fxc_sequences` row `order_number`. Allocation: `UPDATE fxc_sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = 'order_number'` then `SELECT LAST_INSERT_ID()`, executed in autocommit **before** the checkout transaction. This keeps the sequence row lock out of the long checkout transaction. A rolled-back checkout leaves a gap. Numbers are unique and increasing, not gapless (stated in docs; legal invoice numbering is a separate post-MVP feature).
- Display: `settings.order_number_prefix` + number (default prefix empty). Stored formatted in `orders.order_number` (`UNIQUE`).
- Migration keeps source numbers (`WC_Order::get_order_number()`, which respects sequential-number plugins). After import, the sequence is raised to `max(numeric imported) + 1`. If an imported number already exists, the import uses `settings.migration_number_prefix` (default `WC-`) and logs a warning.

### 2.8 Referential integrity

- No `FOREIGN KEY` constraints: WordPress tables are not designed for them, `dbDelta` does not manage them, and plugins/hosts may still have mixed engines. Integrity is enforced by repositories inside transactions (children inserted and deleted with their parent).
- `Maintenance\Doctor` (Status screen + `wp flexacommerce doctor`) counts orphans: items without order, addresses without order, variants without product post, transactions without order. Release gate: zero orphans after the full test suite.
- Order rows reference products and variants **by id without dependency**: deleting a product leaves order items intact because they hold snapshots.

### 2.9 Charset, engine, key lengths

- `$wpdb->get_charset_collate()` for every table (utf8mb4 on all supported servers).
- InnoDB required (checked after install, doc 02 §4.3).
- Indexed `varchar` columns are ≤ 191 characters so indexes stay under 767 bytes even on servers with the old COMPACT row format.

## 3. Product and variant model

### 3.1 Concepts

```
fxc_product (post)
 ├── type: simple | variable                          (_fxc_type)
 ├── attribute definitions                           (_fxc_attributes JSON)
 │     global: { key:"g:12", attribute_term_id:12, value_term_ids:[31,32], visible, variation, position }
 │     local:  { key:"l:fabric", name:"Fabric", values:["Cotton","Linen"], visible, variation, position }
 ├── base row in fxc_variants (exactly one, combination = {})
 │     simple:   purchasable; holds SKU, prices, stock, weight, dimensions, image
 │     variable: not purchasable; holds parent SKU, default weight/dimensions, shared stock pool
 └── variation rows in fxc_variants (variable only), combination = { "g:12":"31", "l:fabric":"linen" | "*" }
```

[Decision] (ADR-001) **Every product has a base row.** This puts every SKU in one unique index, gives shared ("product-level") stock a home, and lets simple and variable products use the same pricing, stock and cart code. A simple product is "a product whose only purchasable row is its base row".

### 3.2 Attribute keys and combination normalization

- Global attribute key: `g:{attribute_term_id}`. Value: the child term id as a string. Using ids (not slugs) means renaming a term never breaks variants.
- Local attribute key: `l:{sanitize_title(name)}`. Value: `sanitize_title(value)`, with the display value kept in `_fxc_attributes`.
- Wildcard ("any value"): `*`.
- **Canonical combination string:** keys sorted by byte order, `key=value` pairs joined with `&`, e.g. `g:12=31&l:fabric=linen`. Base row: empty string.
- `combination_hash = sha1( canonical string )`, `UNIQUE( product_id, combination_hash )`. Two variations with the same exact combination cannot exist. Overlapping wildcard rows (`g:12=*` and `g:12=31`) are allowed, as in WooCommerce, and resolved by `VariantMatcher`: exact match beats wildcard, fewer wildcards win, then `menu_order`, then id. The admin editor shows an "overlapping variations" warning.
- Only attributes flagged `variation: true` may appear in combinations. When a save removes an attribute or a value, stored variations that used it become stale: deleted when they hold no stock, otherwise disabled. Dropping a whole axis can make two stored rows identical; the later one is treated as stale the same way. A row whose combination is sent explicitly in the save must only use offered values, or the save fails with `InvalidProduct`, so a merchant's own edit is never silently dropped. The editor sends a variation's combination only when the merchant changed it.
- A store attribute or value deleted from the Attributes screen is dropped from every product the next time the product is read, so later saves do not fail on a missing term.

### 3.3 Price resolution

```
active_price(variant, now):
  if regular_price is NULL → not purchasable
  if sale_price is not NULL
     and sale_price < regular_price
     and (sale_from is NULL or sale_from <= now)
     and (sale_to   is NULL or now < sale_to)
       → sale_price
  else → regular_price
  then apply filter flexacommerce.price.unit (doc 04 §7), result clamped to ≥ 0
```

Variable products show a price range from `_fxc_price_min` / `_fxc_price_max` (active prices of enabled, purchasable variations). These meta values are recomputed by `ProductRepository::sync_denormalized()` (called by `ProductService`) on product save, stock change that alters purchasability, and by an hourly cron for products whose sale schedule starts or ends (`_fxc_next_price_change` meta, indexed via a meta query limited to 200 posts per run).

### 3.4 Stock ownership

[Decision] (ADR-004) Stock lives on exactly one row. No implicit inheritance.

- Simple product: the base row.
- Variation with `stock_from_base = 0`: its own row.
- Variation with `stock_from_base = 1`: the product's base row (WooCommerce "manage stock at product level" with variation `manage_stock = parent`).
- `StockService` resolves the **stock owner row** before any lock or decrement. Rows are locked in ascending owner id order.
- `stock_status` (`instock`, `outofstock`, `onbackorder`) is stored and recalculated on every stock change: `manage_stock = 0` → keep the manually chosen status. `manage_stock = 1` → `instock` if qty > 0, `onbackorder` if qty ≤ 0 and backorders allowed, else `outofstock`.

### 3.5 Variant availability

A variant is purchasable when: the product post is `publish` (or `private` and the viewer can read it), the variant row is `enabled = 1`, `regular_price` is not NULL, it is not the base row of a variable product, and stock allows at least 1 unit (or backorders allowed).

### 3.6 Weight and dimensions

Stored in **grams and millimetres** as `decimal(12,3)`, converted from the store's display units on input. A variation's `NULL` weight or dimension falls back to the base row value (the single documented fallback, matching WooCommerce behavior so migrated stores keep shipping rules working).

### 3.7 Product post meta keys

| Key | Type | Notes |
|---|---|---|
| `_fxc_type` | `simple` \| `variable` | Required. |
| `_fxc_attributes` | JSON list | Definitions (§3.1). |
| `_fxc_gallery_ids` | comma-separated attachment ids | `_thumbnail_id` (core) is the main image. |
| `_fxc_visibility` | `visible` \| `catalog` \| `search` \| `hidden` | |
| `_fxc_featured` | `0`/`1` | |
| `_fxc_price_min`, `_fxc_price_max` | integer minor units | Denormalized, for sorting and range display. |
| `_fxc_stock_status` | `instock` \| `outofstock` \| `onbackorder` | Product-level aggregate (in stock if any purchasable variation is). |
| `_fxc_next_price_change` | UTC datetime string or absent | For the sale schedule cron. |
| `_fxc_short_description` | HTML | Kept in meta (excerpt is also acceptable; decision: use `post_excerpt`, so this key is **not** created). |
| `_fxc_import` | JSON | Unsupported source metadata bucket (doc 05 §7). |

Global attribute value terms used by the product are also assigned to the post (`wp_set_object_terms( $id, $value_term_ids, 'fxc_attribute' )`) so storefront filters can use `tax_query`.

### 3.8 Variation table vs alternatives

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| **A. `fxc_variants` table incl. base rows** | One query per page for all variants. Atomic conditional stock UPDATE on one row. DB-enforced SKU uniqueness across everything. Indexable price/stock columns. No `wp_posts` growth (a 30-variant product is 1 post, not 31). | Variants are not posts: no revisions, no post meta API for extensions, custom admin UI needed. | **Chosen.** |
| B. Child posts (`fxc_variation`) like WooCommerce | Familiar to extension authors, meta API, revisions. | N+1 meta loading, stock in postmeta (string values, non-atomic without custom SQL), SKU uniqueness impossible to enforce in DB, `wp_posts` bloat. These are the problems WooCommerce created lookup tables for. | Rejected. |
| C. Variants as JSON in product meta | Simplest to write. | No atomic stock, no SKU index, full rewrite on every stock change, size limits. | Rejected. |
| D. Simple products in postmeta, variations in table (initial proposal) | Simple products look "normal" in postmeta. | Two stock code paths, SKU uniqueness split across two stores, cart must handle two kinds of purchasable. | Rejected in favour of A. |

Testing the decision: unit tests for `VariantMatcher` and combination normalization; integration tests asserting SKU duplicate rejection across simple/variable/variation rows; query-count test asserting 1 variant query per archive page; concurrency test on stock (doc 06 §5.4).

### 3.9 WooCommerce mapping spike (phase 2)

Done 2026-10-24. Ten WooCommerce product shapes were mapped by a throwaway hand-written mapper to `ProductInput` and saved through `ProductService` (`tests/Integration/Catalog/WooCommerceMappingSpikeTest.php`, fixture `tests/fixtures/woocommerce-products.php`). Six records were exported read-only from a local WooCommerce 11.0.1 store (units kg and cm, USD, two decimals). That store had no wildcard-with-parent-stock, scheduled sale, weight or broken-data examples, so four more records were written by hand in the same postmeta format.

| # | Source | Shape | Result |
|---|---|---|---|
| 1 | real 60 | variable, local attribute `Size: Small \| Large`, variation meta holds the display text (`Small`) | `l:size=small`; `sanitize_title` also accepts the older slug form |
| 2 | real 703 | variable, 4 global attributes, variations set only Color (3 axes empty) | empty value → `*`; `VariantMatcher` picks the right row for a full selection |
| 3 | real 1412 | variable, 3 global attributes, full 4 × 2 × 3 grid (24 rows), all `menu_order` 0, featured, parent SKU | 24 rows, `_fxc_price_min/max` 3800/12700, featured kept, parent SKU on the base row |
| 4 | real 1403 | simple, sale price without dates | sale with open schedule |
| 5 | real 1406 | simple, managed stock 2 | base row stock 2 |
| 6 | real 203 | simple, draft, no SKU, `48.99` | SKU NULL, 4899 |
| 7 | synthetic 9001 | variable, parent manages stock 12, one variation unmanaged, one with own stock and backorders, kg/cm with decimals, empty variation weight | `stock_from_base = 1` for the unmanaged row, owner = base row; 0.35 kg → 350 g, 10.5 cm → 105 mm; empty weight stays NULL (falls back to base, §3.6) |
| 8 | synthetic 9010 | simple, scheduled sale, stock 0 with backorders, low stock 3, excluded from search | dates converted, `onbackorder`, visibility `catalog` |
| 9 | synthetic 9020 | simple, no price, SKU with spaces, `_stock = "5.000000"`, excluded from catalog and search | not purchasable, SKU trimmed, stock 5, visibility `hidden` |
| 10 | synthetic 9030 | variable with data WooCommerce accepts and we reject: sale = regular, two rows with the same values, a private row, a row using a value the product no longer offers | 2 rows kept (private one disabled), 3 warnings |

Outcome: **the model in §3.1 to §3.8 holds; no schema change.** Every shape fits one base row plus variation rows, and no WooCommerce field needed a column we do not have. The spike changed one piece of code and several migration rules:

- **Two global attributes can share a label.** The store has `pa_size` and `pa_print-size`, both labelled "Size". The Attributes screen rejects a duplicate name, so `AttributeRepository::create_attribute()` and `create_value()` now take an optional slug: with a slug (the importer keeps `attribute_name`) the name check is skipped and WordPress keeps the two terms apart by slug. Shoppers keep seeing "Size" on both products.
- **Value slugs cannot always be preserved.** All values live in one `fxc_attribute` taxonomy, so the same slug under two attributes (`large` in Size and in another attribute) is made unique by WordPress with a suffix. Combinations use term ids, so nothing breaks; only filter URLs differ. Doc 05 §5.1 now says "preserved when free".
- **"Manage stock at parent level" is not stored.** A variation's `_manage_stock` is `no` and `get_manage_stock()` returns `'parent'` only because the parent's is `yes`. Rule: variation unmanaged and parent managed → `stock_from_base = 1`.
- **Sale end date is inclusive in WooCommerce** (`_sale_price_dates_to` is 23:59:59 of the last day), ours is exclusive (§3.3), so the importer adds one second. Start dates map as they are. Both are UTC timestamps.
- **WooCommerce accepts a sale price equal to or above the regular price, or a sale without a regular price.** We reject both on save. The importer drops the sale and warns.
- **A variation using a value its parent no longer offers** is kept by WooCommerce and shown as unavailable. An explicit combination with an unoffered value fails our save, so the importer skips the row and warns (new conflict row in doc 05 §6).
- **Exact duplicate combinations** cannot be stored at all (`UNIQUE (product_id, combination_hash)`, and a hash suffix would break the canonical hash in §3.2). Doc 05 §6 said "imported disabled with a hash suffix"; it now says skipped with a warning that names the source id, SKU and stock, so the merchant can fix it by hand.
- **Ties in `menu_order`** (WooCommerce sorts by `menu_order`, then id) are common; the importer writes the resulting position as an explicit `menu_order`.
- **Stock strings** can be `"5.000000"`; whole values import as integers, a real fraction rejects the product (same rule as order quantities in doc 05 §6).
- Weight and dimension conversion for kg/g and cm/mm/m is a decimal point shift. lb, oz, in and yd need real string arithmetic with rounding to 3 decimals; that stays in the phase 6 adapter (doc 05 §5.2).

Not covered by the spike (no real data to test against): attachments and gallery ids (same site, ids kept), tax classes, product types other than simple and variable (skipped and reported per doc 05 §7), and plugin meta in the `_fxc_import` bucket.

## 4. Tables

Common lifecycle notes: all tables are created on activation and updated through numbered schema steps (§6). All are dropped only by the opt-in uninstall path.

### 4.1 `fxc_variants`

Purpose: purchasable units and product base rows.

```sql
CREATE TABLE fxc_variants (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  is_base tinyint(1) NOT NULL DEFAULT 0,
  combination_hash char(40) NOT NULL,
  attributes longtext NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  menu_order int(11) NOT NULL DEFAULT 0,
  sku varchar(100) NULL,
  regular_price bigint(20) NULL,
  sale_price bigint(20) NULL,
  sale_from datetime NULL,
  sale_to datetime NULL,
  manage_stock tinyint(1) NOT NULL DEFAULT 0,
  stock_from_base tinyint(1) NOT NULL DEFAULT 0,
  stock_quantity int(11) NULL,
  backorders varchar(10) NOT NULL DEFAULT 'no',
  low_stock_threshold int(11) NULL,
  stock_status varchar(20) NOT NULL DEFAULT 'instock',
  weight decimal(12,3) NULL,
  length decimal(12,3) NULL,
  width decimal(12,3) NULL,
  height decimal(12,3) NULL,
  image_id bigint(20) unsigned NULL,
  tax_class varchar(50) NOT NULL DEFAULT '',
  description text NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY product_combination (product_id,combination_hash),
  UNIQUE KEY sku (sku),
  KEY product_order (product_id,menu_order),
  KEY stock_status (stock_status)
) {charset_collate};
```

| Column | Notes |
|---|---|
| `attributes` | JSON object of the canonical combination (`{}` for base rows). |
| `backorders` | `no` \| `notify` \| `yes`. |
| `stock_quantity` | NULL when `manage_stock = 0`. |
| `meta` | JSON, extension and import data for this variant. Not queryable. |

Relationships: `product_id` → `wp_posts.ID` (`fxc_product`). Lifecycle: created/updated by `ProductService`, deleted with the product (`before_delete_post`). Trashing a product keeps variants (restorable) but makes them unpurchasable because the post is not published.

### 4.2 `fxc_orders`

```sql
CREATE TABLE fxc_orders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_number varchar(40) NOT NULL,
  order_key varchar(64) NOT NULL,
  status varchar(20) NOT NULL,
  payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
  needs_attention tinyint(1) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL,
  currency_exponent tinyint(3) unsigned NOT NULL,
  prices_include_tax tinyint(1) NOT NULL DEFAULT 0,
  subtotal bigint(20) NOT NULL DEFAULT 0,
  discount_total bigint(20) NOT NULL DEFAULT 0,
  shipping_total bigint(20) NOT NULL DEFAULT 0,
  fee_total bigint(20) NOT NULL DEFAULT 0,
  tax_total bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  amount_paid bigint(20) NOT NULL DEFAULT 0,
  amount_refunded bigint(20) NOT NULL DEFAULT 0,
  customer_id bigint(20) unsigned NULL,
  customer_email varchar(190) NOT NULL DEFAULT '',
  customer_name varchar(200) NOT NULL DEFAULT '',
  customer_ip varchar(45) NOT NULL DEFAULT '',
  customer_note text NULL,
  payment_method varchar(64) NOT NULL DEFAULT '',
  payment_method_title varchar(190) NOT NULL DEFAULT '',
  checkout_token varchar(64) NULL,
  created_via varchar(32) NOT NULL DEFAULT 'checkout',
  locale varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  paid_at datetime NULL,
  completed_at datetime NULL,
  cancelled_at datetime NULL,
  deleted_at datetime NULL,
  anonymized_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_number (order_number),
  UNIQUE KEY checkout_token (checkout_token),
  KEY status_created (status,created_at),
  KEY created_at (created_at),
  KEY customer_created (customer_id,created_at),
  KEY customer_email (customer_email),
  KEY payment_status (payment_status,created_at),
  KEY attention_created (needs_attention,created_at)
) {charset_collate};
```

Notes:
- `total = subtotal − discount_total + shipping_total + fee_total + tax_total`. Asserted on every insert (`OrderTotalsMismatch`) except migration, where the source total wins and a mismatch is logged.
- `customer_name` and `customer_email` are denormalized from the billing address for the admin list and search.
- `created_via`: `checkout`, `admin`, `migration`, `rest`.
- `deleted_at`: trash. Trashed orders are excluded by default from lists and reports. Permanent deletion removes the order and all children in one transaction.
- `customer_ip`: stored only when the setting `store_customer_ip` is on (default on, can be disabled for privacy), cleared on anonymization.
- Lifecycle: created by `OrderService::create()` or migration import; mutated only by `OrderService` / `PaymentService` via `OrderRepository`.

### 4.3 `fxc_order_items`

```sql
CREATE TABLE fxc_order_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  type varchar(16) NOT NULL,
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NULL,
  variant_id bigint(20) unsigned NULL,
  code varchar(100) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL,
  sku varchar(100) NULL,
  attributes longtext NULL,
  quantity int(10) unsigned NOT NULL DEFAULT 1,
  unit_price bigint(20) NOT NULL DEFAULT 0,
  subtotal bigint(20) NOT NULL DEFAULT 0,
  discount_total bigint(20) NOT NULL DEFAULT 0,
  tax_total bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  stock_reduced_qty int(10) unsigned NOT NULL DEFAULT 0,
  refunded_qty int(10) unsigned NOT NULL DEFAULT 0,
  refunded_total bigint(20) NOT NULL DEFAULT 0,
  restocked_qty int(10) unsigned NOT NULL DEFAULT 0,
  meta longtext NULL,
  PRIMARY KEY  (id),
  KEY order_position (order_id,position),
  KEY variant_id (variant_id),
  KEY product_id (product_id)
) {charset_collate};
```

- `type`: `product`, `shipping`, `fee`, `discount`, `tax`. Only `product` lines touch stock. `tax` lines are summary lines (rate label in `name`, amount in `total`) used for migrated WooCommerce tax lines and future tax engines.
- `code`: shipping rate id (`flat_rate:3`), fee code, coupon code, tax rate code.
- Amounts are **exclusive of tax**; `tax_total` holds the line's tax. `total = subtotal − discount_total` (ex-tax).
- `attributes`: JSON snapshot `[{"label":"Color","value":"Red"}]` with display strings at purchase time.
- Snapshot rule: `name`, `sku`, `attributes`, `unit_price` are copied at order time and never refreshed from the catalog.

### 4.4 `fxc_order_addresses`

```sql
CREATE TABLE fxc_order_addresses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  type varchar(10) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  company varchar(150) NOT NULL DEFAULT '',
  address_1 varchar(200) NOT NULL DEFAULT '',
  address_2 varchar(200) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  state varchar(100) NOT NULL DEFAULT '',
  postcode varchar(20) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY order_type (order_id,type)
) {charset_collate};
```

`type`: `billing` or `shipping`. A shipping row exists only when the order ships (local pickup or "same as billing" still stores an explicit shipping row when a shipping address applies, to keep snapshots self-contained). Evaluated alternative: 26 address columns on `fxc_orders`. Rejected because the order row would be wide for every list query, and a separate table mirrors WooCommerce HPOS `wc_order_addresses`, which simplifies mapping.

### 4.5 `fxc_order_history`

Status history, notes, and audit trail in one table.

```sql
CREATE TABLE fxc_order_history (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  type varchar(16) NOT NULL,
  from_status varchar(20) NULL,
  to_status varchar(20) NULL,
  message text NULL,
  customer_visible tinyint(1) NOT NULL DEFAULT 0,
  actor_type varchar(16) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_created (order_id,created_at)
) {charset_collate};
```

- `type`: `status`, `note`, `payment`, `refund`, `stock`, `email`, `system`, `import`.
- `actor_type`: `system`, `customer`, `admin`, `gateway`, `cron`, `migration`, `cli`.
- Append-only. Rows are deleted only with the order (permanent delete) and anonymized (message scrub of personal data is not attempted; admins are told notes may contain personal data and the eraser blanks `message` for `customer_visible` rows).
- Alternative: separate status-log and notes tables. Rejected: one timeline is what the admin screen and the customer order page show, and it halves the queries.

### 4.6 `fxc_order_meta`

Queryable key/value metadata for extensions and preserved import data.

```sql
CREATE TABLE fxc_order_meta (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  meta_key varchar(191) NOT NULL,
  meta_value longtext NULL,
  PRIMARY KEY  (id),
  KEY order_key (order_id,meta_key),
  KEY meta_key_value (meta_key,meta_value(100))
) {charset_collate};
```

Justification: extensions need a supported place for order data (tracking numbers, gateway-specific references) that can be looked up (`find order by tracking number`). WooCommerce HPOS has the same `wc_orders_meta` shape, which simplifies round-trip migration of unknown keys. Values are strings or JSON (never PHP serialized). Keys starting with `_fxc_` are reserved.

### 4.7 `fxc_transactions`

```sql
CREATE TABLE fxc_transactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NULL,
  type varchar(10) NOT NULL,
  status varchar(20) NOT NULL,
  method varchar(10) NOT NULL DEFAULT 'gateway',
  gateway varchar(64) NOT NULL,
  amount bigint(20) NOT NULL,
  currency char(3) NOT NULL,
  idempotency_key varchar(64) NOT NULL,
  gateway_reference varchar(191) NULL,
  failure_code varchar(64) NULL,
  failure_message varchar(255) NULL,
  refund_lines longtext NULL,
  data longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  completed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY gateway_reference (gateway,gateway_reference),
  KEY order_type (order_id,type),
  KEY status_updated (status,updated_at)
) {charset_collate};
```

- `type`: `payment` or `refund`. `parent_id` links a refund to the payment it refunds.
- `status`: doc 02 §7.7.
- `method`: `gateway`, `manual` (admin recorded), `import`.
- `idempotency_key`: random, sent to gateways that accept idempotency keys, so a retried API call cannot double charge.
- `gateway_reference`: gateway's charge/refund id. Unique per gateway, NULL until known.
- `refund_lines`: JSON for refunds (named so because `LINES` is a reserved word in MySQL): `[{ "order_item_id": 12, "qty": 1, "amount": 1500, "restock": true }]`.
- `data`: JSON subset of the gateway response allowed by the gateway's `redact()` (doc 04 §3). Never card numbers, CVC, full bank details or secrets.
- `status_updated` supports a reconciliation job that finds `pending`/`requires_action` transactions older than N hours (gateway `reconcile()` capability, post-MVP).
- Alternative: separate `payments` and `refunds` tables (stay-suite). Rejected: refunds and payments share state, gateway reference and idempotency handling, and "sum of succeeded payments minus succeeded refunds" becomes one indexed query.

### 4.8 `fxc_webhook_events`

```sql
CREATE TABLE fxc_webhook_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  gateway varchar(64) NOT NULL,
  event_id varchar(191) NOT NULL,
  event_type varchar(100) NOT NULL DEFAULT '',
  transaction_id bigint(20) unsigned NULL,
  result varchar(20) NOT NULL DEFAULT 'received',
  received_at datetime NOT NULL,
  processed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY gateway_event (gateway,event_id),
  KEY received_at (received_at)
) {charset_collate};
```

MVP gateways (COD, bank transfer) do not receive webhooks, but the table is part of the gateway contract: the core performs deduplication so every gateway add-on gets it right. Retention: 90 days (daily cron, 1,000 rows per run). Gateways whose events have no id must derive one (`sha256` of the raw body) in `parse_webhook()`.

### 4.9 `fxc_sessions`

```sql
CREATE TABLE fxc_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  user_id bigint(20) unsigned NULL,
  data longtext NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  UNIQUE KEY user_id (user_id),
  KEY expires_at (expires_at)
) {charset_collate};
```

`data` JSON: `{ "v":1, "lines":[{ "key":"<sha1 of variant+attributes>", "variant_id":0, "qty":1, "attributes":{}, "added_at":"" }], "shipping_rate":"", "destination":{country,state,postcode}, "checkout_token":"", "notices":[] }`. No prices and no personal data besides the shipping destination needed for rate quotes. Checkout form drafts stay in the browser (`sessionStorage`). Purge: daily cron deletes `expires_at < now` in batches of 1,000.

### 4.10 `fxc_sequences`

```sql
CREATE TABLE fxc_sequences (
  name varchar(32) NOT NULL,
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) {charset_collate};
```

Seeded with `order_number = 0` on install. Alternative: an option updated with the `LAST_INSERT_ID` trick. Rejected: options are cached in memory and persistent object caches, so an atomic SQL update behind the Options API is fragile.

### 4.11 Migration tables

Specified with their workflow in doc 05 §4. Summary:

```sql
CREATE TABLE fxc_id_map (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  remote_system varchar(32) NOT NULL,
  entity varchar(32) NOT NULL,
  remote_id varchar(64) NOT NULL,
  local_id bigint(20) unsigned NOT NULL,
  origin varchar(10) NOT NULL,
  checksum char(40) NOT NULL DEFAULT '',
  job_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY remote (remote_system,entity,remote_id),
  UNIQUE KEY local (remote_system,entity,local_id)
) {charset_collate};

CREATE TABLE fxc_migration_jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  direction varchar(16) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  dry_run tinyint(1) NOT NULL DEFAULT 0,
  format_version varchar(10) NOT NULL,
  options longtext NOT NULL,
  stage varchar(32) NOT NULL DEFAULT '',
  cursor_state longtext NULL,
  counters longtext NULL,
  lock_token char(36) NULL,
  locked_until datetime NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  report longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  started_at datetime NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {charset_collate};

CREATE TABLE fxc_migration_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  entity varchar(32) NOT NULL,
  source_id varchar(64) NOT NULL DEFAULT '',
  level varchar(10) NOT NULL,
  code varchar(64) NOT NULL,
  message text NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job_level (job_id,level),
  KEY job_entity_source (job_id,entity,source_id)
) {charset_collate};
```

### 4.12 `fxc_logs`

Added in phase 1 for the plugin log (Status > Logs). Every channel writes here: `core` in phase 1, then the payment, webhook and migration channels as those modules land, plus any extension channel.

```sql
CREATE TABLE fxc_logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL,
  channel varchar(64) NOT NULL,
  message text NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY channel_created (channel,created_at),
  KEY level_created (level,created_at)
) {charset_collate};
```

- `context` is JSON, written after `Support\Redactor` has masked secrets and personal fields (doc 06 §2.8). Messages are interpolated and redacted the same way.
- Entries below `settings.log_level` are never written. The write waits until no transaction is open (`Transaction::when_idle()`), so a rollback cannot erase the entry that explains it. If the insert fails, the entry goes to the PHP error log instead.
- The daily cron (`flexacommerce.cron.daily`) deletes rows older than `settings.log_retention_days` (default 30) using the `created_at` index.

## 5. Query patterns and indexes

| Query | Index used |
|---|---|
| All variants for products on a page | `product_order (product_id, menu_order)` with `IN (...)` |
| SKU lookup (admin search, migration dedupe) | `sku` |
| Admin orders list, filter status, sort newest | `status_created`; unfiltered: `created_at` |
| Customer's orders (account page) | `customer_created` |
| Search by email | `customer_email` (exact or prefix `LIKE 'abc%'`) |
| Search by order number | `order_number` |
| Search by customer name | `customer_name` is **not indexed**. Free-text name search runs `LIKE '%x%'` limited to the latest 10,000 orders. Revisit with FULLTEXT if stores complain (Q11). |
| Pending order expiry cron | `status_created` (`status='pending' AND created_at < X`) |
| Needs attention widget | `attention_created` |
| Webhook dedupe | `gateway_event` |
| Transaction by gateway reference | `gateway_reference` |
| Session lookup | `token_hash`, `user_id` |
| Shop sort by price | `wp_postmeta` `meta_key/meta_value` on `_fxc_price_min` (core index on `meta_key`). See revisit condition below. |

**Revisit condition (product lookup table):** if the benchmark (doc 06 §4) shows shop archive queries sorted or filtered by price above 50 ms at 20,000 products, add `fxc_product_lookup (product_id PK, price_min, price_max, stock_status, featured, visibility, total_sales)` maintained by `ProductService`, and switch archive queries to join it. Not built in MVP because postmeta sorting is adequate for the assumed catalog size (A1) and the table adds a sync burden.

## 6. Schema upgrades

- `Database\Schema::DB_VERSION` is an integer string, incremented with every schema change.
- `migrate()` runs `dbDelta()` for all `CREATE TABLE` statements (adds tables, columns, indexes) and then the numbered **upgrade steps** greater than the stored version.
- `dbDelta` cannot drop columns, rename columns, change a `KEY` into a `UNIQUE KEY`, or change primary keys reliably. Those changes are explicit `ALTER TABLE` statements inside an upgrade step, each guarded by an `INFORMATION_SCHEMA` check so it can run twice safely.
- Data backfills (for example computing a new column) run in batches of 500 rows through WP-Cron with progress stored in `flexacommerce_upgrade_state`. Checkout stays enabled during backfills only if the step declares `safe_while_running`; otherwise the store shows the maintenance notice (`checkout_paused` setting) until the step finishes.
- Rules for every schema change (enforced in code review):
  1. Additive first (new nullable column, deploy, backfill, then constrain).
  2. No column removal in the same release that stops using it.
  3. Each step has an integration test that runs it against a fixture DB from the previous version.
- Downgrades are not supported. The Status screen shows the DB version, and the plugin refuses to run checkout if the DB version is newer than the code understands (prevents an old plugin copy from writing to a newer schema).

## 7. Customers

- Account holders are WordPress users (default role: `subscriber`, setting). Saved addresses live in user meta `fxc_billing` and `fxc_shipping` (JSON with the same fields as `fxc_order_addresses`).
- Guests exist only as order snapshots. The admin **Customers** screen lists registered users with orders plus distinct guest emails (query over `fxc_orders` grouped by `customer_email`, paginated, uses the `customer_email` index).
- Guest orders are linked to an account when the user registers with the same, verified email (manual admin action or the opt-in setting `link_guest_orders_on_registration`, which only links after the user confirms the email through WordPress's account email).
- Alternative: a `fxc_customers` table (WooCommerce `wc_customer_lookup`). Rejected for MVP: it is an analytics cache with its own sync bugs. Revisit with the analytics feature.

## 8. Structures evaluated and rejected

| Proposed structure | Decision | Reason |
|---|---|---|
| Stock reservations table | Not built | The order is the reservation (ADR-005). Revisit only if online gateways show long payment windows causing complaints about stock held by abandoned pending orders, which `hold_minutes` already bounds. |
| Separate refunds table | Merged into `fxc_transactions` | §4.7. |
| Separate status history table | Merged into `fxc_order_history` | §4.5. |
| Shipping zones / methods tables | `wp_options` JSON | A store has tens of zones at most. One option read per rate calculation, cached. Revisit above 200 zones. |
| Migration job items table (one row per migrated entity) | Replaced by `fxc_id_map` (success) + `fxc_migration_log` (problems) | A 500,000-order migration would write 500,000+ job item rows that duplicate the id map. |
| Customer table | Not built | §7. |
| Product lookup table | Deferred with a measurable revisit condition | §5. |
| Coupons tables | Post-MVP | Discount lines in orders cover history. |
