# 05. Migration Specification

Status: **Approved 2026-10-24**. Covers WooCommerce → FlexaCommerce and FlexaCommerce → WooCommerce on the same site (assumption A3). Labels: **[Fact]** verified in WooCommerce 11.0.1 source, **[Decision]**, **[Assumption]**, **[Spike]** (must be confirmed with a prototype in roadmap phase 2 or 6 before the design is final).

## 1. Principles

1. **Migration, not sync.** A job copies data from a source store to a target store. The source is read-only for the job. Re-running a job imports what changed since the last run (§8.4).
2. **Both directions use one pipeline:** `Source adapter → canonical record → validate → resolve ids → Target adapter`. The four adapters are the only code that knows either store's storage.
3. **Read and write WooCommerce through its CRUD API** (`wc_get_product`, `wc_get_order`, `WC_Product_*`, `WC_Order`, `wc_create_refund`). The only direct SQL against WooCommerce tables is id enumeration for cursors. CRUD is what keeps legacy storage, HPOS and HPOS compatibility mode (sync on) correct without branching per storage.
4. **Historical import has no side effects:** no emails, no payments, no stock movement, no webhooks we can control (§8.3).
5. **Idempotent and resumable.** Every imported entity is recorded in `fxc_id_map`. A batch that crashes is re-run safely.
6. **Never silently drop data.** Anything not mapped is preserved in an import bucket or reported in `fxc_migration_log`.

## 2. Verified WooCommerce facts that shape the design

| # | Fact (WooCommerce 11.0.1) | Consequence |
|---|---|---|
| F1 | `OrderUtil::custom_orders_table_usage_is_enabled()` tells which store is authoritative. `OrderUtil::get_table_for_orders()` returns `{prefix}wc_orders` or `{prefix}posts`. | Cursor queries pick the table at job start and store it in `cursor_state`. If the authoritative store changes during a job, the job pauses with an error. |
| F2 | HPOS tables: `wc_orders`, `wc_order_addresses`, `wc_order_operational_data`, `wc_orders_meta`. Legacy: `shop_order` posts + postmeta. HPOS default for new installs since 8.2. | Both are read via `wc_get_order()`. |
| F3 | Compatibility mode is the option `woocommerce_custom_orders_table_data_sync_enabled`. With sync on, writes go to both stores. | FXC→WC batches are halved when sync is on. Preflight reports the mode. |
| F4 | `field_query` in `wc_get_orders()` exists only for HPOS. | Do not use `wc_get_orders()` for cursoring. Use keyset SQL on ids (§4.4). |
| F5 | A new `WC_Order` has an empty status until set. Setting the status before the first `save()` records no status transition, so no `woocommerce_order_status_*` hooks and no transition emails. | FXC→WC creates orders with their final status before the first save. |
| F6 | `woocommerce_new_order` fires on creation in both data stores. | Cannot be suppressed safely. Listeners (Woo webhooks, CRMs) will see imported orders. Preflight lists them (§5.5). |
| F7 | Stock is reduced on `payment_complete` and on transitions to processing, completed, on-hold, unless the order's `_order_stock_reduced` flag is set. Filter `woocommerce_payment_complete_reduce_order_stock` and `woocommerce_can_reduce_order_stock` also gate it. Stock restore on cancelled/pending/failed uses per-item `_reduced_stock` meta. | FXC→WC sets `set_order_stock_reduced()` and `_reduced_stock` to mirror FXC `stock_reduced_qty`. No transitions fire on import anyway (F5). |
| F8 | Emails are triggered by `woocommerce_order_status_{from}_to_{to}_notification`, refund and customer note hooks; each email can be disabled with `woocommerce_email_enabled_{id}`. `add_order_note( $note, 1 )` triggers `woocommerce_new_customer_note`. | ImportGuard disables every WooCommerce email and short-circuits `wp_mail` (§8.3). |
| F9 | `wc_create_refund( [ ..., 'refund_payment' => false, 'restock_items' => false ] )` creates a refund without calling the gateway or restocking. It fires refund actions that may set the parent status to `refunded`. | Refunds are created **before** the final status save, then the status is set explicitly (§5.4). |
| F10 | Variation attributes are stored as `attribute_{name}` meta; empty value means "any". `WC_Product_Variation::get_manage_stock()` returns `'parent'` when stock is managed by the parent. | Maps to wildcard `*` and `stock_from_base = 1`. |
| F11 | `WC_Order::get_order_number()` is filterable (sequential number plugins). | WC→FXC reads `get_order_number()`, not the id. |
| F12 | WooCommerce 11.x requires WP 6.9+ and PHP 7.4+. | Our minimums are stricter (doc 06 §6), so supported WooCommerce versions are whatever runs on our stack, within the tested range. |

## 3. Canonical format v1

The canonical format is the contract between adapters. Same-site jobs keep it in memory. It is also the export file format (JSON Lines, one record per line, §4.6).

### 3.1 Rules

- `format_version: "1.0"`. Readers reject a higher major version.
- **Ids** are strings `"{system}:{entity}:{id}"`, e.g. `"wc:product:123"`. References use the same form.
- **Money** is a decimal string plus currency: `{ "amount": "19.99", "currency": "USD" }`. Decimal strings avoid float loss and exponent ambiguity across systems. Adapters convert with `Money::from_decimal_string()` (doc 03 §2.1).
- **Dates**: ISO 8601 UTC with `Z`. Missing dates are `null`.
- **Quantities**: integers. A fractional source quantity is a validation error for that entity.
- **Weight/dimensions**: grams and millimetres as decimal strings.
- **Unknown data**: `"extra": { "source": "woocommerce", "meta": { key: value } }`. Values are JSON (serialized PHP is decoded with `allowed_classes => false`, see doc 03 §2.4).
- Record envelope: `{ "type": "product", "id": "...", "checksum": "sha1 of the canonical body", "data": { ... } }`.

### 3.2 Record types

`attribute`, `term` (category, tag, attribute value), `product` (with embedded `variants`), `customer`, `order` (with embedded `items`, `addresses`, `transactions`, `history`), `coupon`. Terms come first, then products, then customers, then orders, then coupons, so references always resolve to already imported records.

### 3.3 Example: simple product

```json
{
  "type": "product", "id": "wc:product:101", "checksum": "8f1c...",
  "data": {
    "kind": "simple", "status": "publish", "slug": "linen-shirt",
    "title": "Linen Shirt", "content": "<p>...</p>", "excerpt": "Breathable.",
    "created_at": "2024-03-01T10:00:00Z", "modified_at": "2026-09-12T08:30:00Z",
    "menu_order": 0, "visibility": "visible", "featured": false,
    "image": "wp:attachment:55", "gallery": ["wp:attachment:56", "wp:attachment:57"],
    "categories": ["wc:term:product_cat:12"], "tags": ["wc:term:product_tag:40"],
    "attributes": [],
    "base": {
      "sku": "LS-001",
      "regular_price": { "amount": "49.00", "currency": "USD" },
      "sale_price": { "amount": "39.00", "currency": "USD" },
      "sale_from": "2026-11-27T00:00:00Z", "sale_to": "2026-12-01T00:00:00Z",
      "manage_stock": true, "stock_quantity": 14, "backorders": "no", "stock_status": "instock",
      "low_stock_threshold": null,
      "weight_g": "250", "length_mm": null, "width_mm": null, "height_mm": null,
      "tax_class": ""
    },
    "variants": [],
    "extra": { "source": "woocommerce", "meta": { "_yoast_wpseo_title": "Linen Shirt | Shop" } }
  }
}
```

### 3.4 Example: variable product with a wildcard variation

```json
{
  "type": "product", "id": "wc:product:200", "checksum": "1b2e...",
  "data": {
    "kind": "variable", "status": "publish", "slug": "classic-tee", "title": "Classic Tee",
    "attributes": [
      { "key": "wc:attribute:3", "name": "Color", "global": true,
        "values": ["wc:term:pa_color:31", "wc:term:pa_color:32"],
        "visible": true, "variation": true, "position": 0 },
      { "key": "local:size", "name": "Size", "global": false,
        "values": ["S", "M", "L"], "visible": true, "variation": true, "position": 1 }
    ],
    "base": { "sku": "TEE", "manage_stock": true, "stock_quantity": 100, "backorders": "no",
              "weight_g": "180", "regular_price": null },
    "variants": [
      { "id": "wc:variation:201", "enabled": true, "menu_order": 0, "sku": "TEE-RED-S",
        "combination": { "wc:attribute:3": "wc:term:pa_color:31", "local:size": "S" },
        "regular_price": { "amount": "20.00", "currency": "USD" }, "sale_price": null,
        "stock_from_base": false, "manage_stock": true, "stock_quantity": 5,
        "backorders": "no", "stock_status": "instock", "weight_g": null, "image": null },
      { "id": "wc:variation:202", "enabled": true, "menu_order": 1, "sku": "TEE-BLUE-ANY",
        "combination": { "wc:attribute:3": "wc:term:pa_color:32", "local:size": "*" },
        "regular_price": { "amount": "22.00", "currency": "USD" }, "sale_price": null,
        "stock_from_base": true, "manage_stock": false, "stock_quantity": null,
        "backorders": "no", "stock_status": "instock", "weight_g": null, "image": "wp:attachment:90" }
    ]
  }
}
```

### 3.5 Example: order with partial refund

```json
{
  "type": "order", "id": "wc:order:5012", "checksum": "c03d...",
  "data": {
    "number": "5012", "status": "processing", "currency": "USD", "prices_include_tax": false,
    "created_via": "checkout", "created_at": "2026-09-01T14:22:05Z", "paid_at": "2026-09-01T14:22:40Z",
    "completed_at": null, "trashed": false,
    "customer": "wp:user:17", "customer_ip": "203.0.113.9", "customer_note": "Leave at the door",
    "order_key": "wc_order_AbC123", "payment_method": "stripe", "payment_method_title": "Credit card",
    "totals": { "subtotal": "60.00", "discount": "6.00", "shipping": "5.00", "fees": "0.00",
                "tax": "0.00", "total": "59.00" },
    "addresses": {
      "billing":  { "first_name": "Ana", "last_name": "Silva", "email": "ana@example.com",
                    "phone": "+351...", "address_1": "Rua 1", "city": "Lisboa", "postcode": "1000-001",
                    "country": "PT", "state": "", "company": "", "address_2": "" },
      "shipping": { "first_name": "Ana", "last_name": "Silva", "address_1": "Rua 1", "city": "Lisboa",
                    "postcode": "1000-001", "country": "PT" }
    },
    "items": [
      { "id": "wc:order_item:9001", "type": "product", "product": "wc:product:200",
        "variant": "wc:variation:202", "name": "Classic Tee - Blue, M", "sku": "TEE-BLUE-ANY",
        "attributes": [ { "label": "Color", "value": "Blue" }, { "label": "Size", "value": "M" } ],
        "quantity": 3, "subtotal": "66.00", "total": "59.40", "tax": "0.00", "reduced_stock": 3,
        "extra": { "source": "woocommerce", "meta": { "_custom_engraving": "A.S." } } },
      { "id": "wc:order_item:9002", "type": "coupon", "code": "WELCOME10", "total": "6.00" },
      { "id": "wc:order_item:9003", "type": "shipping", "code": "flat_rate:3", "name": "Flat rate",
        "total": "5.00", "tax": "0.00" }
    ],
    "transactions": [
      { "type": "payment", "status": "succeeded", "gateway": "stripe", "reference": "pi_3P...",
        "amount": { "amount": "59.00", "currency": "USD" }, "completed_at": "2026-09-01T14:22:40Z" },
      { "id": "wc:refund:5100", "type": "refund", "status": "succeeded", "gateway": "stripe",
        "reference": "re_1Q...", "amount": { "amount": "19.80", "currency": "USD" },
        "reason": "One shirt returned", "completed_at": "2026-09-10T09:00:00Z",
        "lines": [ { "item": "wc:order_item:9001", "qty": 1, "amount": "19.80", "restock": true } ] }
    ],
    "history": [
      { "type": "note", "message": "Payment via Stripe (pi_3P...)", "customer_visible": false,
        "author": "system", "created_at": "2026-09-01T14:22:41Z" }
    ],
    "extra": { "source": "woocommerce", "meta": { "_wc_order_attribution_source_type": "organic" } }
  }
}
```

(The example's subtotal arithmetic is illustrative; validators recompute and compare, §6.)

## 4. Architecture

### 4.1 Components

```
Migration\
  MigrationService         jobs, preflight, verify, cutover helpers (doc 02 §8)
  Runner                   run_batch( job_id ): lease, stage loop, budgets, cursor persistence
  Stages\                  AttributesStage, TermsStage, ProductsStage, CustomersStage, OrdersStage
  Canonical\               record DTOs, Validator, Checksum
  Sources\
    WooSource              enumerate + read WooCommerce via CRUD → canonical
    FxcSource              enumerate + read FlexaCommerce repositories → canonical
  Targets\
    WooTarget              canonical → WooCommerce CRUD (inside ImportGuard)
    FxcTarget              canonical → FlexaCommerce repositories in import mode
  JobRepository            fxc_migration_jobs / fxc_migration_log access
  Verifier                 verification report (§9.1)
  IdMap                    fxc_id_map access, resolve( ref ) / remember( ... )
  ImportGuard              side-effect suppression (§8.3)
  Transport\FileExport     writes one store as a JSONL file (§4.6)
  Transport\FileIndex      sidecar index of a JSONL file: id → byte offset per stage
  Transport\FileSource     a JSONL file as the SourceAdapter of a job
  Transport\Relocation     users and images of the site a file came from
```

Dependency rule: adapters depend on Canonical and their own store; stages depend on adapters through interfaces `SourceAdapter` / `TargetAdapter`; nothing outside `Migration\` depends on migration code.

### 4.2 Tables

Defined in doc 03 §4.11. Usage:

| Table | Use |
|---|---|
| `fxc_id_map` | One row per imported entity, shared by both directions. `remote_system = 'wc'`, `entity` (`attribute`, `term`, `product`, `variant`, `order`, `order_item`, `transaction`, `customer`), `remote_id` (WooCommerce id), `local_id` (FlexaCommerce id). `origin` = `wc` if the entity was created by WC→FXC, `fxc` if created by FXC→WC. `checksum` is the canonical checksum at the time of the last import, used for change detection. |
| `fxc_migration_jobs` | Job state machine, options, cursor, counters, lease. |
| `fxc_migration_log` | Warnings and errors per entity. Info-level rows only in dry runs or with `verbose` option. |

Both directions share the same map, so a product imported WC→FXC and later exported FXC→WC updates the original WooCommerce product instead of creating a duplicate (the round trip in §10).

### 4.3 Job state machine

```
draft ──preflight ok──► ready ──start──► running ⇄ paused
                                           │  └──► failed (fatal error, resumable after fix)
                                           ├──► cancelled
                                           └──► verifying ──► completed | completed_with_warnings
```

### 4.4 Batches, cursors, budgets

- Stage order: settings → tax rates → shipping zones (§5.7) → attributes → terms (categories, tags, attribute values) → products (with variants) → customers → orders (with items, addresses, transactions, notes).
- Cursor per stage: `{ "stage": "orders", "table": "wp_wc_orders", "last_id": 50231, "upper_id": 98765 }`. `upper_id` is captured at job start, so orders created during the job are left for the next incremental run instead of moving the target.
- Enumeration SQL (read-only, the only raw SQL on WooCommerce tables):
  - Products: `SELECT ID FROM {posts} WHERE post_type = 'product' AND post_status <> 'auto-draft' AND ID > %d AND ID <= %d ORDER BY ID LIMIT %d`.
  - Orders HPOS: `SELECT id FROM {wc_orders} WHERE type = 'shop_order' AND status <> 'wc-checkout-draft' AND id > %d AND id <= %d ORDER BY id LIMIT %d`.
  - Orders legacy: same on `{posts}` with `post_type = 'shop_order'`.
  - FlexaCommerce side: same keyset pattern on `fxc_orders.id` and `fxc_product` posts.
- Batch sizes (defaults, adjustable): products 50, orders 100 (50 when sync is on). Each batch stops early at **20 s wall time**, when its memory has **grown by 64 MB** since the batch started (`memory_budget`), or when memory in use reaches **80 % of `memory_limit`**, whichever comes first, and persists the cursor after every entity. The checks run after each entity, so a batch always does at least one. The budget counts growth, not total use, because the loaded site alone can be near 128 MB (about 110 MB for WordPress with WooCommerce 11.2.1 in WP-CLI, measured 2026-10-26): as an absolute cap, a site with many plugins would run one entity per batch. P9 (doc 06 §3) is still the batch's peak, `memory_get_peak_usage()` after a reset at batch start, measured on the bench store; the batch result also reports the growth. Preflight warns when `memory_limit` leaves less than the budget above what the site already uses.
- Between batches: `wp_cache_flush_runtime()` (WP 6.0+) and WooCommerce object cache cleanup to keep memory flat.
- Lease: `lock_token` + `locked_until` (now + 60 s). A batch extends the lease. A second runner (admin tab + Action Scheduler) cannot run the same job; an expired lease can be taken over (crash recovery).
- Drivers: admin screen (REST `admin/migrations/{id}/batch`, polled), WP-CLI `wp flexacommerce migrate run <job>` (loops batches, recommended for > 10,000 orders), Action Scheduler (`flexacommerce.migration.batch`), all calling `Runner::run_batch()`.
- Per entity: one DB transaction on the FlexaCommerce side. WooCommerce CRUD writes cannot share our transaction reliably (plugins hook into saves), so on FXC→WC an entity is written, then its id map row; a crash between the two is detected on resume by a lookup on `_fxc_source_id` meta that WooTarget writes on every entity it creates (§8.1).

### 4.5 Workflow (admin and CLI)

1. **Preflight** (§5.5): environment, data inventory, risks. Produces a report and blocks start on errors.
2. **Plan**: direction, entity selection, policies (§6), date range for orders, dry run toggle.
3. **Backup confirmation**: the admin must tick "I have a current database backup" (or pass `--yes-i-have-a-backup` in CLI). Required for non-dry runs.
4. **Dry run** (recommended, default on for the first job): full read + validate + resolve, target replaced by a recording no-op target. Writes only job and log rows. Report shows "would create / update / skip" counts and every warning.
5. **Run.**
6. **Verify** (§9.1). Automatic at the end, re-runnable.
7. **Incremental re-run** as often as needed before cutover.
8. **Cutover** (§9), WC→FXC only.

### 4.6 File transport (cross-site)

A store moves to another site through a JSON Lines file. The exporting site needs no FlexaCommerce job; the importing site runs an ordinary job whose source is the file.

```
wp flexacommerce migrate export store.jsonl --from=wc [--stages=...] [--orders-from=... --orders-to=...]
wp flexacommerce migrate import store.jsonl [--stages=...] [--dry-run] [--media=download|skip] --yes-i-have-a-backup
```

**File layout.** The first line is the header:

```json
{ "type": "header", "format_version": "1.0", "system": "wc",
  "site": { "id": "6f0c…", "url": "https://old.example/" },
  "created_at": "2026-10-26T09:00:00Z", "stages": ["attributes", "terms", "products", "customers", "orders"],
  "plugin_version": "0.6.0" }
```

Every other line is a record envelope (§3.1) with an optional `depends` list, or a side line:

- `{ "type": "user", "id": "wp:user:17", "data": { "email", "login", "display_name", "first_name", "last_name", "registered" } }`
- `{ "type": "media", "id": "wp:attachment:55", "data": { "url", "title", "alt", "caption", "description", "mime", "filename" } }`

Records follow stage order and ascending id inside a stage. A dependency that the file would hold later, or not at all (its stage was not exported), is written just before the record that needs it, and skipped when its own stage reaches it. A side line comes before the first record that points at it. `site.id` is a uuid kept in the option `flexacommerce_site_id` (made on the first export, not autoloaded).

**Direction.** The header's `system` decides it: a `wc` file imports into FlexaCommerce, an `fxc` file into WooCommerce. A file job cannot be used for cutover (§9); cutover stays a same-site step.

**Index.** Before the job is created the whole file is read once and checked line by line (JSON object, header first, type, id and data present, known type, numeric id, no duplicate id). A bad file is refused with the line number, so no job exists for it. The check writes a sidecar `<file>.index`: a JSON meta line (file size, mtime, header, each section's start and count) and then 16-byte entries `pack('J2', id, offset)` sorted by id per section. Sections are the stages plus `user` and `media`. Batches seek through the sidecar, so a batch costs the same at the end of a 1 GB file as at the start. A sidecar whose size or mtime does not match the file is built again. The folder holding the file must be writable.

**Reading.** `FileSource` serves ids and records from the index. Each record's checksum is recomputed; a mismatch rejects that entity (`checksum_mismatch`), the rest of the job goes on. Preflight checks:

| Code | Level | When |
|---|---|---|
| `file_version` | error | the file's major format version differs from ours |
| `file_direction` | error | the header's system does not match the job's source |
| `file_changed` | error | the file now comes from another site than when the job was created |
| `file_stage_missing` | warning | a selected stage is not in the file |
| `file_source` | info | source URL, export date, plugin version, user and image counts |
| `media_download` | info | how many images will be downloaded |

**Id map scope.** The id map rows of a file job use `remote_system = "{system}:{hash}"`, where the hash is the first 24 hex characters of sha256 of `site.id`. Imports from two different source sites never share rows, and same-site rows (`wc`) are untouched. A second import of the same file, or of a newer export of the same site, updates what the first one created (§8.1). The gateway reference of imported transactions carries the same scope: `import:{system}:{source_id}:payment`.

**Users and images (`Relocation`).** User and attachment ids of the source site mean nothing on the target, so file jobs resolve them through the side lines:

- A user is matched by email. When a customer or an order needs one and none exists, it is created with role `customer` (or the site's default role) and a random password; it gets no notification. On multisite, a matched user who is not a member of the site is added to it. A product author is only matched, never created.
- An image is downloaded from its source URL into the media library (`download_url` + `media_handle_sideload`, 30 s timeout), with title, caption, description and alt text. `--media=skip` leaves images out. A failed download is a warning on the entity, and is not retried in the same request.
- Both are kept in the id map as entities `user` and `attachment`, so a re-run reuses them.

**Privacy and removal.** An export holds customer names, emails, addresses and order history. The CLI says so after writing; treat the file like a database backup and delete it after the import. Removing imported data (§9.3) leaves the users and images an import created, since other content may use them by then.

**Admin upload.** On the Migration screen, "New migration" can copy from an export file. The browser sends the file in chunks, so `upload_max_filesize` does not cap its size:

- `POST /admin/migrations/uploads` with `{name, size}` checks free disk space (the size plus 10% for the index) and returns a random 32-hex token and the chunk size, at most 8 MB and below `wp_max_upload_size()`.
- `POST /admin/migrations/uploads/{token}?offset=n` appends the raw request body. A chunk at the wrong offset gets 409 `upload_offset` with `received`, and the screen resends from there. Bytes past the announced size are refused.
- `POST /admin/migrations/import` with `{upload, dry_run, options}` renames the part to `<token>.jsonl` and runs the same check as `migrate import`. A broken file is deleted and no job is created.
- `DELETE /admin/migrations/uploads/{token}` drops an unfinished upload.

Files live in `uploads/flexacommerce-imports`, which carries `.htaccess`, `index.php` and `web.config` guards; where the server ignores them, the 128-bit names keep the files unguessable. A token belongs to the user who began it. Parts left for more than a day are deleted on the next upload. The job page shows the file and can delete it (`POST {id}/remove-file`) once the job is not running or verifying; a job whose file is gone keeps its history but cannot start or verify again (`file_missing`). Only files in that folder can be deleted from the screen, so a file given to the CLI stays where it is. The store reset (`Maintenance\Eraser`) deletes the folder.

**Limits.** A REST request still cannot point a job at a path on the server; `file` and `file_name` in the options are dropped. The file must reach the importing server, by upload or by any other copy.

## 5. Mapping: WooCommerce → FlexaCommerce

### 5.1 Attributes and terms

| WooCommerce | FlexaCommerce |
|---|---|
| `wc_get_attribute_taxonomies()` row (`attribute_id`, `attribute_label`, `attribute_name`, `attribute_orderby`) | top-level `fxc_attribute` term (name = label, slug = `attribute_name`, term meta `_fxc_orderby`) |
| `pa_{name}` terms (with hierarchy flattened, order meta) | child terms of that attribute term, slug preserved when no other attribute uses it (all values share one taxonomy, so WordPress suffixes a taken slug), `order` meta preserved |
| `product_cat` / `product_tag` terms | `fxc_product_cat` / `fxc_product_tag`: name, slug, description, parent, `thumbnail_id` term meta, display order |

Slug collisions inside a target taxonomy are impossible for a fresh store; on re-run the id map is used, not the slug.

### 5.2 Products and variations

| WooCommerce (`'edit'` context) | FlexaCommerce |
|---|---|
| post fields (title, content, excerpt, slug, status, dates, author, menu_order, comment_status) | new `fxc_product` post, same values (`post_name` identical, so URLs can stay identical after cutover) |
| `get_type()` `simple` / `variable` | `_fxc_type`. Other types → skipped and reported (§7). |
| `get_image_id()`, `get_gallery_image_ids()` | `_thumbnail_id`, `_fxc_gallery_ids` (same attachment ids, same site) |
| `get_catalog_visibility()`, `get_featured()` | `_fxc_visibility`, `_fxc_featured` |
| `get_attributes()` (`WC_Product_Attribute`: id, name, options, visible, variation, position) | `_fxc_attributes` definitions + global value terms set on the post |
| simple: SKU, prices, sale dates, stock fields, weight, dimensions, tax class, tax status | base variant row (`tax_status`, `tax_class`; doc 03 §4.1) |
| variable: parent SKU, parent stock fields, weight/dimensions | base variant row (not purchasable) |
| `WC_Product_Variation`: `get_attributes()` (empty value → `*`), status `publish`/`private` → `enabled`, prices, `get_manage_stock()` (`'parent'` → `stock_from_base = 1`), stock, image, description, menu_order | variation rows; sale end + 1 s (WooCommerce's end is inclusive), position after sorting by `menu_order` then id written as `menu_order` (doc 03 §3.9) |
| weight/dimension units (`woocommerce_weight_unit`, `woocommerce_dimension_unit`) | converted to g / mm with string arithmetic, 3 decimals |
| `get_reviews_allowed()`, reviews (comments) | comments stay attached to the **WooCommerce** post id (Q14), comment_status copied |
| unknown postmeta (not `_` WooCommerce-core keys listed in the adapter's allow list) | `_fxc_import` bucket |

Derived after save: `_fxc_price_min/max`, `_fxc_stock_status`.

### 5.3 Customers

Same site, so customers are already WordPress users. The stage maps WooCommerce user meta (`billing_first_name` … `billing_phone`, `shipping_*`) to `fxc_billing` / `fxc_shipping` JSON. It never creates or deletes users. Guest customers exist only on orders.

### 5.4 Orders

| WooCommerce (`WC_Order`, `'edit'` context) | FlexaCommerce |
|---|---|
| `get_order_number()` | `order_number` (collision → prefix, doc 03 §2.7) |
| `get_order_key()` | `order_key` (kept so old order links in customer emails keep working after cutover) |
| status `wc-*` | §5.6 |
| `get_currency()`, `get_prices_include_tax()` | `currency`, `currency_exponent`, `prices_include_tax` |
| totals: `get_subtotal()`, `get_discount_total()`, `get_shipping_total()`, `get_total_fees()`, `get_total_tax()`, `get_total()` | order totals. Source `total` wins; recomputed difference > 0 logs `totals_mismatch` warning. |
| billing/shipping getters | `fxc_order_addresses` rows; `customer_email`, `customer_name` denormalized |
| `get_customer_id()` | `customer_id` (0 → NULL) |
| `get_customer_ip_address()`, `get_customer_note()`, `get_created_via()` | same columns, `created_via` kept as source value |
| dates created/modified/paid/completed | `created_at`, `updated_at`, `paid_at`, `completed_at` (UTC) |
| trashed order | `deleted_at` |
| line items (`WC_Order_Item_Product`) | `product` lines: `subtotal` = WC subtotal, `total` = WC total, `discount_total` = subtotal − total, `tax_total` = WC total tax, `product_id`/`variant_id` resolved through the id map (NULL if the product was not imported or deleted), `stock_reduced_qty` from `_reduced_stock` meta when `_order_stock_reduced` is set, refunded qty from refunds |
| variation attribute item meta (`pa_color` → `blue`) | `attributes` snapshot with display labels |
| other item meta | item `meta` JSON (`extra`) |
| shipping / fee items | `shipping` / `fee` lines |
| coupon items | `discount` lines (`code`, `total` = discount amount). Informational: the amounts are already inside product lines. |
| tax items | `tax` lines, one per WooCommerce tax item: `code` = rate code, `name` = label, `total` = tax + shipping tax, `meta` = `{rate_id (mapped, or 0 if the rate no longer exists), rate, compound, items_tax, shipping_tax}`. The order `tax_total` stays the source of truth; per-line `meta.taxes` comes from the item's `taxes` array when present. |
| tax classes (`wc_tax_rate_classes`), rates with locations (`woocommerce_tax_rates`, `woocommerce_tax_rate_locations`), tax options (doc 02 §7.12.1) | `flexacommerce_settings.tax_classes`, `fxc_tax_rates` and `fxc_tax_rate_locations` with `source = 'migration'`, tax settings; rate ids recorded in the id map. Rate percent strings are parsed with string arithmetic (`"7.2500"` → `72500`). FXC→WC writes the same tables through `WC_Tax::_insert_tax_rate()`. |
| paid order (date paid set, or status processing/completed/refunded, total > 0) | one `payment` transaction: `method = import`, `succeeded`, gateway = `payment_method`, `gateway_reference` = `get_transaction_id()` or NULL, `idempotency_key = import:wc:{order_id}:payment` |
| `get_refunds()` | `refund` transactions (`method = import`, `succeeded`), `refund_lines` from refund items, amounts, reason, date; `amount_refunded`, `payment_status` derived |
| order notes (`wc_get_order_notes()`) | `fxc_order_history` rows, `type = note`, `customer_visible` from `is_customer_note`, `actor_type = migration`, original date and author name in `context` |
| (always) | one history row `type = import` naming the job |
| unknown order meta | `fxc_order_meta`, key prefixed `wc:` (e.g. `wc:_billing_vat_number`), restored without the prefix on FXC→WC |

### 5.5 Preflight checks

| Check | Severity |
|---|---|
| WooCommerce active, version in tested range | error if inactive, warning if outside range |
| HPOS mode, sync mode, orders pending sync (if WooCommerce exposes the count) | warning if pending sync > 0 ("let WooCommerce finish syncing first") |
| Store currency WC vs FXC | error if FXC already has orders in another currency |
| Product types present (counts by type) | warning per unsupported type |
| Duplicate SKUs (case-insensitive) in source | warning, count + list |
| Custom order statuses | warning, mapping required |
| Active WooCommerce webhooks (`wc_webhooks` with status active) and listeners on `woocommerce_new_order` (FXC→WC only) | warning: "these will receive imported orders, pause them" |
| Sequential order number plugin detected (number ≠ id on a sample) | info |
| Multi-currency orders present | warning |
| Tax-inclusive orders present while the target store has `prices_include_tax` off (or the reverse) | warning: historic orders keep their own flag, new orders follow the store setting |
| Tax enabled in the source but not in the target | warning: rates are imported and taxes stay off until the admin enables them |
| Estimated duration (entity counts × measured rate of a 20-entity sample) | info |
| `DISABLE_WP_CRON`, max execution time, memory limit | info / warning |

### 5.6 Status mapping

| WooCommerce | FlexaCommerce |
|---|---|
| `wc-pending` | `pending` (exempt from the expiry cron: `created_via = migration` orders are never auto-cancelled) |
| `wc-on-hold` | `on-hold` |
| `wc-processing` | `processing` |
| `wc-completed` | `completed` |
| `wc-cancelled` | `cancelled` |
| `wc-refunded` | `refunded` |
| `wc-failed` | `failed` |
| `wc-checkout-draft` | not imported |
| custom statuses | per-status mapping chosen in the plan; default `on-hold` + `needs_attention` + history note with the original status |

Reverse mapping is the inverse. FXC → WC never produces custom statuses.

### 5.7 Shipping zones

The `shipping` stage runs after `taxes` and, like `settings`, is one record (id 1) that holds every zone in match order plus the methods of "everywhere else". Amounts are decimals in the store currency, converted to minor units on the FlexaCommerce side. Zone and method ids go through the id map as `shipping_zone` and `shipping_method`.

- Locations: countries and states are copied; a continent becomes its countries; postcodes are copied as patterns. A zone left with no known country or state is skipped with a warning.
- `flat_rate`: the cost formula must be a number, `N * [qty]`, or the two added. Any other formula (`[fee]`, `[cost]`, more terms) and any shipping class cost make the method import **turned off** with a warning, so checkout never charges an amount the old store did not.
- `free_shipping`: `requires` empty or `min_amount` maps directly. `either` keeps the minimum and warns that the coupon path is gone; `coupon` and `both` import turned off.
- `local_pickup`: title, cost and tax status. FlexaCommerce's pickup address has no WooCommerce field, so it is dropped with a warning on FXC→WC.
- Tax status (`taxable` / `none`) is kept on flat rate and local pickup.
- Other method types have no equivalent and are skipped with a warning.

A second run replaces the zones and methods it created, in place; zones and methods made in the target by hand stay, and new zones are added after the existing ones, before "everywhere else". A method deleted in the source since the last run stays in the target.

### 5.8 Coupons

The `coupons` stage runs last, after orders. One `coupon` record per `shop_coupon` post (auto-drafts and trash are skipped) or `fxc_coupons` row. Amounts are decimals: a percentage as a number of percent (`12.5`), money in the store currency. FlexaCommerce keeps a percentage in hundredths and money in minor units; more decimals than that are rounded with the warning `coupon_rounded`. Products, variations and categories are references, and the coupon depends on them, so they are imported first.

- Status: a published WooCommerce coupon is `active`; any other status is `disabled`. FXC→WC writes `publish` or `draft`.
- Copied as they are: code, description, type (`percent`, `fixed_cart`, `fixed_product`), amount, expiry, minimum and maximum spend, usage limit and count, per-customer limit, individual use, exclude sale items, product and category rules (both lists), allowed emails. WooCommerce keeps codes in lower case.
- Used by: FXC→WC writes the `_used_by` meta from `coupon_uses`. WC→FXC rebuilds `coupon_uses` from the imported orders' `discount` lines (not cancelled), once at the end of the orders stage and once at the end of the coupons stage, so per-customer limits count the old orders.
- A coupon that gives free shipping only is rejected (`unsupported_coupon`): FlexaCommerce coupons have no free shipping yet. One that also has an amount is imported with the discount alone and the warning `coupon_free_shipping`.
- A limit on the number of items (`limit_usage_to_x_items`) has no equivalent; the coupon is imported **disabled** with the warning `coupon_item_limit`.
- A product or category in the rules that was not imported is dropped with `coupon_product_missing` / `coupon_category_missing`. If that empties an "only these" list, the coupon is imported disabled (`coupon_rules_lost`), so it never applies to every product.
- WooCommerce has no start date. A FlexaCommerce coupon that starts later goes back as a draft with the warning `coupon_starts_later`.
- A code the target already uses for another coupon rejects the record (`duplicate_code`); the target's coupon stays as it is. On WooCommerce only published coupons are checked, as WooCommerce does.
- A money coupon from a store in another currency is copied as it is, with the warning `coupon_currency`. Percentages are not affected.

A second run updates the coupon it created, in place, and its usage count.

## 6. Conflict and validation policies

| Situation | Default | Options |
|---|---|---|
| Duplicate SKU within the source | first entity (lowest id) keeps the SKU, the others import with SKU NULL, warning lists both | `skip` the later entities, `suffix` (`-2`, `-3`) |
| SKU already used in the target by a non-migrated entity | import with SKU NULL, warning | `skip`, `suffix` |
| Order number already exists in target | `migration_number_prefix` + number, warning | `skip` |
| Gateway reference duplicated across orders | second transaction stored with reference NULL, original in `data`, warning | none |
| Target entity changed since last import (current target checksum ≠ stored checksum) | skip, warning `target_modified` | `source_wins` |
| Source entity deleted since last import | not deleted in target, warning `source_deleted` | none (deletions are never propagated) |
| Referenced product missing (order item for deleted product) | line imported with NULL product/variant, snapshot intact | none |
| Price with more decimals than currency exponent | round half up, warning with original string | none |
| Fractional quantity, negative total on product line, unknown currency | entity rejected, error | none |
| Variation combination duplicates another (exact same values) | second variation skipped, warning with its source id, SKU and stock (doc 03 §3.9) | none |
| Variation uses an attribute value the product no longer offers | variation skipped, warning | none |
| Sale price not below the regular price, or a sale price without a regular price | sale dropped, warning with the original values | none |
| Totals mismatch | import, source totals kept, warning | none |

Errors reject one entity, never the batch. A job ends `completed_with_warnings` if any warnings or rejected entities exist.

## 7. Unsupported and preserved data

| Data | Handling |
|---|---|
| Grouped, external, and other product types; bundles; subscriptions | skipped, listed in report with ids and edit links |
| Downloadable/virtual flags on simple products | imported as physical product, warning (A5) |
| Product reviews | stay on the WooCommerce post (Q14) |
| Product add-on and SEO plugin meta | `_fxc_import` bucket on the product, round-tripped on FXC→WC |
| Unknown order and item meta | `fxc_order_meta` (`wc:` prefix) and item `meta` JSON, round-tripped |
| Payment gateway settings | not migrated in MVP (tax rates and classes are, since ADR-020; shipping zones are, see §5.7; coupons are, see §5.8) |
| Free shipping on a coupon, item limits | see §5.8: free-shipping-only coupons are rejected, item-limited ones import disabled |
| Subscriptions, memberships, bookings | not migrated, warning if their plugins are active |
| WooCommerce analytics tables, customer lookup, sessions, carts | not migrated (derived or transient data) |

## 8. Guarantees, side effects and sync

### 8.1 Idempotency

- An entity with an id map row is **updated** (if changed and policy allows) and never created twice.
- FXC→WC: WooTarget writes `_fxc_source_id` meta (`fxc:{entity}:{id}`) on every WooCommerce product, variation and order it creates, in the same save. Resume after a crash looks up this meta before creating, which closes the gap between the WooCommerce save and the id map insert.
- WC→FXC: the entity and its id map row are written in one FlexaCommerce transaction.

### 8.2 Resumability

Cursor persisted per entity; leases; stage restart on schema-level failure. `wp flexacommerce migrate resume <job>`.

### 8.3 Side-effect suppression (ImportGuard)

Active only inside `Runner::run_batch()` and removed in `finally`:

| Side effect | Suppression |
|---|---|
| FlexaCommerce emails | `Notifications` skips while the guard is active; migration never calls `OrderService::transition()` (it writes final state through repositories in import mode). |
| WooCommerce emails | `woocommerce_email_enabled_{id}` → false for every registered email id; plus `pre_wp_mail` → false for the whole batch as a safety net (no mail of any kind leaves the site during a batch). |
| WooCommerce stock | F5 + F7: status set before first save (no transition), `set_order_stock_reduced()` mirrors FXC state, `woocommerce_payment_complete_reduce_order_stock` and `woocommerce_can_reduce_order_stock` → false. `payment_complete()` is never called. |
| FlexaCommerce stock | repositories in import mode never call `StockService`; variant stock is copied as a value. |
| Gateways | never called; transactions are written with `method = import`. |
| FlexaCommerce extension hooks | `order.created`, `order.status_changed`, `order.paid` are **not** fired; only `flexacommerce.migration.entity_imported`. |
| WooCommerce `woocommerce_new_order` and webhooks | **cannot** be suppressed safely (F6). Preflight warning + docs: pause WooCommerce webhooks and CRM integrations during FXC→WC jobs. |
| Search/indexing plugins reacting to `save_post` | not suppressed; documented. |

Tested by integration tests that count `wp_mail` calls, stock values and fired hooks around a full job (doc 06 §5).

### 8.4 Migration versus sync

| | Migration (in scope) | Sync (out of scope) |
|---|---|---|
| Authority | one source per job | both stores writable |
| Trigger | admin or CLI starts a job | continuous, event-driven |
| Changes | created and updated entities since last run, by checksum | every write, including deletions |
| Conflicts | target-modified entities skipped by default | needs merge rules per field |
| Deletions | never propagated, reported | propagated |
| Stock | copied as a value at run time | must be reconciled live (oversell risk) |

Re-running a job before cutover gives a near-current copy. Stock and orders placed in WooCommerce between the last run and cutover are the reason for the cutover freeze (§9).

## 9. Cutover (WC → FXC) and verification

### 9.1 Verification report

- Counts per entity: source, imported, updated, skipped, rejected.
- Per order: total, paid, refunded, item count, status compared with source. All orders if ≤ 20,000, otherwise all orders with warnings plus a random 10 % sample (seed stored in the report).
- Per product: SKU set, active price, stock quantity, variant count.
- Sums: stock units per SKU, order totals per month and currency.
- Output: admin screen summary, downloadable JSON and CSV, `wp flexacommerce migrate verify <job> --format=json`.

### 9.2 Cutover steps (wizard, each step confirmable)

1. Put WooCommerce checkout on hold (admin instruction: enable a maintenance mode or set products to "catalog only"; FlexaCommerce cannot control WooCommerce checkout).
2. Final incremental run + verify. Zero errors required (warnings allowed with acknowledgement).
3. Adopt URL bases: read `woocommerce_permalinks` (`product_base`, `category_base`, `tag_base`) and the shop page slug, and set FlexaCommerce rewrite bases to the same values. Slugs are identical (§5.2), so URLs stay identical.
4. Assign pages: FlexaCommerce cart/checkout/account pages, or reuse WooCommerce page ids by replacing their content with FlexaCommerce blocks (admin choice).
5. Admin deactivates WooCommerce (we never deactivate another plugin automatically).
6. Flush rewrite rules, run a URL check of 20 sample product and category URLs (HTTP 200, correct product).
7. Redirects for anything that cannot stay identical (custom bases, `?post_type=product&p=ID`): a small redirect map from the id map, served on `template_redirect` with 301, only while WooCommerce is inactive.
8. Reopen checkout on FlexaCommerce.

Rollback before step 5: nothing changed for WooCommerce. After step 5: reactivate WooCommerce and restore the FlexaCommerce bases (`shop-item`/`shop`). Orders placed in FlexaCommerce after cutover can be exported back with an FXC→WC job.

### 9.3 Removing imported data

`wp flexacommerce migrate purge <job>` deletes target entities created by that job whose current checksum still matches (never touches entities created or edited afterwards). Available in both directions; for FXC→WC it deletes WooCommerce entities through CRUD (`delete( true )`). After a file import, purge leaves the users and images the import created (§4.6).

## 10. FlexaCommerce → WooCommerce specifics

- Products: `WC_Product_Simple` / `WC_Product_Variable` + `WC_Product_Variation` objects, attributes via `WC_Product_Attribute` (global attributes created with `wc_create_attribute()` when missing), `*` → empty attribute value, `stock_from_base` → variation `manage_stock = 'parent'`. Saved with `$product->save()`; `WC_Product_Variable::sync( $id )` after variations.
- If the product came from WooCommerce originally (`origin = wc` in id map), the original WooCommerce product is **updated**, not duplicated.
- Orders: `new WC_Order()`, set every field and the final status, `set_order_stock_reduced()`, then `save()` (F5). Items via `WC_Order_Item_*` with `_reduced_stock` meta. Refunds with `wc_create_refund( refund_payment false, restock_items false )` (F9), after which the final status is re-asserted with `set_status()` + `save()` inside ImportGuard if a refund action changed it. Notes with `add_order_note()` and their dates corrected with `wp_update_comment()`.
- Order numbers: WooCommerce core shows the order id. The FlexaCommerce number is stored in `_order_number` meta (the convention used by common sequential-number plugins) and `_fxc_order_number`. [Assumption: acceptable; see Q12.]
- `order_key` preserved (`set_order_key()`), so customer links keep working.
- Customers: `fxc_billing` / `fxc_shipping` → WooCommerce user meta, only for keys that are empty in WooCommerce unless `source_wins`.

## 11. Test plan (summary; details in doc 06 §5)

| Test | Storage modes |
|---|---|
| Golden fixtures: 30 products (all variant shapes, wildcard, parent stock, sale schedules, unicode, missing images) and 40 orders (each status, partial/full refunds, coupons, fees, tax lines, deleted products, guest, custom status) | legacy, HPOS, HPOS + sync |
| Round trip WC → FXC → WC produces equal canonical records (excluding ids and timestamps) | all three |
| Re-run with no changes: zero writes | all |
| Crash injection after N entities, resume: no duplicates, equal result | all |
| ImportGuard: zero `wp_mail`, zero stock change, no transition hooks | all |
| 100,000-order synthetic job: completes, memory < 128 MB per batch, throughput reported (no expected number is claimed before measuring) | HPOS, legacy |
