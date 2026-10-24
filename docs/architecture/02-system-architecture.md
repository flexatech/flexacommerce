# 02. System Architecture

Status: **Approved 2026-10-24**. Related: doc 03 (data), doc 04 (extension API), doc 08 (ADRs).

Labels used in this document: **[Fact]** verified in WordPress 7.1.3 / WooCommerce 11.0.1 source or official docs. **[Decision]** recommended design. **[Assumption]** needs validation (doc 09).

## 1. Architectural style

A modular monolith inside one WordPress plugin. Modules are PHP namespaces under `Flexa\Commerce\`, each owning its domain objects, repositories and services. There is no framework, no container library, no ORM, and no runtime composer dependency. This follows the Flexa lineage, which ships a `spl_autoload_register` fallback so `vendor/` never ships.

Three rules carry most of the design:

1. **The server owns every number.** Prices, totals, shipping and stock are recomputed on the server from stored data. Request payloads carry identifiers and quantities only.
2. **State changes go through one service per aggregate.** Only `OrderService` changes order status. Only `StockService` changes stock. Only `PaymentService` changes transaction state. Repositories persist. Services decide.
3. **Side effects run after commit.** Emails, extension hooks and payment initiation never run inside a DB transaction, and never run if the transaction rolled back (§4.4).

## 2. Directory and module layout

```
flexacommerce/
├── flexacommerce.php            bootstrap: headers, PHP check, define() block, autoloader, hooks
├── uninstall.php                guarded by WP_UNINSTALL_PLUGIN, delegates to Maintenance\Uninstaller
├── readme.txt
├── src/
│   ├── Plugin.php               composition root + boot sequence
│   ├── Concerns/HasInstance.php house singleton trait (hook registrars only)
│   ├── Contracts/               PUBLIC API: interfaces, DTOs, Money (doc 04)
│   ├── Support/                 Settings, Capabilities, Currency, Clock, Logger, Redactor, Services
│   ├── Database/                Schema, Transaction, Sequence, AfterCommit
│   ├── Setup/                   Activator, Deactivator, Requirements, Roles, PageInstaller
│   ├── Catalog/                 Product, Variant, Attribute VOs; ProductRepository, VariantRepository,
│   │                            AttributeRepository; ProductService; VariantMatcher; PostTypes registrar
│   ├── Pricing/                 PricingService, PriceResolution VO
│   ├── Inventory/               StockService, StockLedger (writes via VariantRepository)
│   ├── Cart/                    Cart, CartLine VOs; CartService; SessionStore; SessionRepository
│   ├── Shipping/                ShippingService, ZoneRepository, MethodRegistry, Methods/{FlatRate,FreeShipping,LocalPickup}
│   ├── Checkout/                CheckoutService, CheckoutRequest, CheckoutValidator, FieldSchema
│   ├── Orders/                  Order, OrderItem, Address VOs; OrderRepository, HistoryRepository;
│   │                            OrderService; OrderStateMachine; OrderNumber; OrderAccess
│   ├── Payments/                PaymentService, GatewayRegistry, TransactionRepository,
│   │                            WebhookEventRepository, Gateways/{CashOnDelivery,BankTransfer}
│   ├── Tax/                     TaxCalculator seam; NullTaxCalculator (MVP default, see Q2)
│   ├── Customers/               CustomerProfile (user meta), AccountService
│   ├── Notifications/           EmailRegistry, Mailer, Emails/*, listens to domain events
│   ├── Storefront/              Render/* (shared renderers), Blocks/Registrar, Templates/Loader,
│   │                            Shortcodes, Assets, Routes (cart/checkout/account endpoints)
│   ├── Api/                     Router, Endpoint (abstract), Admin/*Endpoint, Store/*Endpoint, WebhookEndpoint
│   ├── Admin/                   Menu, Enqueue, ProductEditor (metabox mount), OrderScreens, Notices
│   ├── Migration/               Canonical/*, Sources/*, Targets/*, Stages/*, Transport/*, MigrationService,
│   │                            Runner, JobRepository, IdMap, Verifier, ImportGuard (doc 05 §4.1)
│   ├── Integrations/WooCommerce Compatibility (HPOS declaration), loaded only when WooCommerce is active
│   ├── Privacy/                 Exporter, Eraser (core personal-data tools)
│   ├── Cli/                     ShopCommand, MigrateCommand, BenchCommand (dev)
│   └── Maintenance/             Cron, Uninstaller, Eraser (store reset)
├── blocks/                      block.json + render.php + view.js (script modules) per block
├── templates/                   classic-theme PHP templates + email templates (theme-overridable)
├── client/                      React admin source (Flexa UI system), built to assets/dist
├── assets/                      frontend CSS, built admin bundle
└── tests/                       unit/, integration/, e2e/, fixtures/
```

[Decision] **Module-first folders instead of a single `Domain/` folder.** The Flexa canonical plugins put value objects and repositories in `Domain/`. A commerce engine has around ten aggregates, so a flat `Domain/` would hold 60+ files with no visible boundaries. Module folders make the dependency rules in §3 checkable. Inside each module the house rules still apply: `public readonly` value objects with `from_row()` / `to_array()`, repositories with `find()`/`create()`/`update()`, all SQL in repositories, `$wpdb->prepare()` always. (ADR-002)

## 3. Module dependencies

### 3.1 Layers

```
 L5  Presentation & adapters   Storefront   Api   Admin   Cli   Notifications   Migration   Privacy
                                    │         │      │      │         │ (events)     │
 L4  Orchestration              Checkout ──────────────────────────────┼──────────────┤
                                    │                                   │              │
 L3  Session                     Cart                                   │              │
                                    │                                   │              │
 L2  Policies               Pricing   Inventory   Shipping   Tax   Payments           │
                                │         │                            │               │
 L1  Aggregates            Catalog ───────┘        Orders  ◄───────────┘ ◄─────────────┘
                                │                    │
 L0  Foundation            Contracts   Support   Database
```

### 3.2 Allowed dependencies (a module may `use` only the modules listed)

| Module | May depend on | Must not depend on |
|---|---|---|
| Contracts | nothing internal (PHP + WordPress only) | everything else |
| Support, Database | Contracts, each other (foundation layer) | all domain modules |
| Catalog | Support, Database, Contracts | Orders, Cart, Checkout, Payments |
| Orders | Support, Database, Contracts, Inventory (release/restore only) | Catalog services, Cart, Checkout, Payments |
| Inventory | Catalog (VariantRepository), Database | Orders, Cart, Checkout |
| Pricing | Catalog, Tax, Support, Contracts | Cart, Orders |
| Shipping | Support, Contracts | Cart internals (receives a `ShippingPackage` DTO) |
| Tax | Support, Contracts | Cart, Orders |
| Payments | Orders (OrderService), Database, Contracts | Cart, Checkout, Catalog |
| Cart | Catalog, Pricing, Inventory (availability reads), Shipping, Support | Orders, Payments, Checkout |
| Checkout | Cart, Pricing, Inventory, Shipping, Tax, Orders, Payments | Storefront, Api |
| Notifications | Orders (read), Support. Triggered by hooks only | any service write method |
| Storefront, Api, Admin, Cli | any L1 to L4 service and read repositories | each other |
| Migration | Catalog, Orders, Payments repositories (import mode), Contracts, Support | Checkout, Cart, Notifications |

### 3.3 How cycles are prevented

- **Payments → Orders, never the reverse.** `OrderService` does not know gateways. When a payment event arrives, `PaymentService` records the transaction and calls `OrderService::apply_payment_outcome()`.
- **Orders → Inventory only through a narrow method** (`StockService::release_for_order_lines(list<StockLine>)`). Inventory receives plain line DTOs, never `Order`.
- **Notifications are event subscribers.** They react to `flexacommerce.order.*` actions after commit and read through `OrderRepository`. No service calls the mailer.
- **Enforcement:** an architecture test (`tests/unit/Architecture/DependencyRulesTest.php`) tokenizes every file in `src/` and fails if a `use Flexa\Commerce\X\...` statement violates the table above. No external tool such as deptrac is needed. CI runs it on every change.

## 4. Bootstrap, service construction, transactions

### 4.1 Boot sequence

```
flexacommerce.php (file load, every request)
  ├─ PHP version guard (admin notice + return)
  ├─ define() block, autoloader (vendor if present, else spl fallback)
  ├─ register_activation_hook / register_deactivation_hook
  ├─ before_woocommerce_init → Integrations\WooCommerce\Compatibility::declare()  [only a closure, ~0 cost]
  └─ plugins_loaded → Plugin::instance()->boot()

Plugin::boot()
  ├─ always:   Catalog\PostTypes (init), Database\Schema::maybe_upgrade (admin_init + WP-CLI),
  │            Maintenance\Cron (registers handlers only), Api\Router (rest_api_init),
  │            Storefront\Routes (rewrite endpoints), Notifications (event subscribers, lazy)
  ├─ is_admin(): Admin\Menu, Admin\Enqueue, Admin\ProductEditor, Privacy
  ├─ ! is_admin(): Storefront\Blocks (init), Storefront\Templates, Storefront\Shortcodes, Storefront\Assets
  ├─ WP_CLI:    Cli\* commands
  ├─ class_exists( 'WooCommerce' ) && ( is_admin() || WP_CLI ): Migration module
  └─ do_action( 'flexacommerce.loaded', $extensions ) // Contracts\Extensions facade (doc 04 §2)
```

[Decision] Domain services are never constructed during boot. Registrars only call `add_action`/`add_filter` with closures that resolve services on first use, so a request that never touches commerce code creates no service objects (performance budget P1 in doc 06).

### 4.2 Dependency injection

[Decision] (ADR-003) A hand-written composition root, `Support\Services`, with one lazy factory per service:

```php
final class Services {
	/** @var array<string,object> */
	private array $resolved = [];

	public function orders(): OrderService {
		return $this->resolved[ OrderService::class ] ??= new OrderService(
			$this->order_repository(),
			$this->history_repository(),
			$this->stock(),
			$this->state_machine(),
			$this->after_commit(),
			$this->clock(),
		);
	}
	// one method per service, explicit, no reflection or autowiring
}
```

- Domain services receive collaborators through the constructor. Tests build them with fakes.
- `Concerns\HasInstance` (house style) is kept for **hook registrars and REST endpoints** only, which hold no state and need one instance per request.
- `Plugin::instance()->services()` returns the single `Services` instance. It is internal. Extensions receive the narrower `Contracts\Extensions` facade as the argument of `flexacommerce.loaded` (doc 04 §2), so the container can change without breaking them.
- Alternative considered: `HasInstance` on every service (house default). Rejected because services would call `OtherService::instance()` internally, which hides dependencies and blocks unit tests with fakes. Alternative: PHP-DI or League Container. Rejected: a runtime composer dependency for about 30 factories.

### 4.3 Transactions

`Database\Transaction::run( callable $work ): mixed` wraps `START TRANSACTION` / `COMMIT` / `ROLLBACK` on `$wpdb`.

- [Fact] WordPress creates core tables with the server default engine, and `dbDelta` does the same for plugin tables. InnoDB is the default on MySQL ≥ 5.5.5 and MariaDB. [Decision] `Setup\Requirements` checks `SHOW TABLE STATUS` for the `fxc_` tables after install and refuses to enable checkout if any is not InnoDB (admin notice with the fix).
- Nesting: `run()` inside `run()` joins the outer transaction (depth counter). Only the outermost call commits.
- Deadlocks (`ER_LOCK_DEADLOCK` 1213) and lock wait timeouts (1205): the outermost `run()` retries the closure up to 3 times with jittered backoff (20 to 80 ms). Closures must therefore be free of side effects, which §4.4 guarantees.
- Row locks: stock rows are always locked in ascending `variant_id` order to avoid lock-order deadlocks between concurrent checkouts.

### 4.4 After-commit side effects

`Database\AfterCommit` is a per-request buffer. Inside a transaction, services call `$after_commit->defer( fn() => do_action( 'flexacommerce.order.created', $order_id ) )`. The buffer flushes after the outermost `COMMIT`, in insertion order, each callback wrapped in `try/catch` (errors are logged and recorded in order history, never rethrown into the checkout response). On `ROLLBACK` the buffer is discarded. Outside a transaction, `defer()` runs immediately.

Everything that leaves the process goes through this buffer: emails, public `do_action` events, payment initiation, cache invalidation of product pages.

## 5. Domain services (summary)

Full method signatures are in §8. Responsibilities:

| Service | Owns | Notes |
|---|---|---|
| ProductService | Product create/update/delete rules, variant generation, SKU validation, denormalized product meta (price range, stock status) | Writes through ProductRepository + VariantRepository |
| StockService | Every stock mutation: deduct, restore, manual adjust | Atomic conditional SQL. Idempotent per order line |
| PricingService | Effective unit price of a variant at a time, line totals, order totals | Pure calculation + one read. Exposes the `flexacommerce.price.*` filters |
| CartService | Cart contents, validation, totals preview | Session-backed. Never writes orders |
| ShippingService | Zone matching, rates for a package | Calls registered `ShippingMethod`s |
| CheckoutService | Turns a validated cart + checkout input into an order and starts payment | The only orchestrator |
| PaymentService | Transactions, gateway calls, webhook application, refunds | Only module that talks to gateways |
| OrderService | Order status transitions, notes, refunds bookkeeping, cancellation | Enforces OrderStateMachine |
| MigrationService | Jobs, batches, mapping, verification | Admin/CLI only |

## 6. Presentation, REST, extension, migration layers

### 6.1 REST layer

- One namespace `flexacommerce/v1`, all routes registered in `Api\Router::register_routes()` (house rule: an endpoint not listed there is dead), ending with `do_action( 'flexacommerce.rest.register_routes' )`.
- Two route families with different security models:

| Family | Prefix | Auth | Examples |
|---|---|---|---|
| Admin | `/flexacommerce/v1/admin/*` | Cookie + `X-WP-Nonce` (`wp_rest`), capability check per route | `admin/orders`, `admin/orders/{id}/transitions`, `admin/products/{id}/variants`, `admin/settings`, `admin/migrations` |
| Store | `/flexacommerce/v1/store/*` | Public, but every mutating route requires the cart session token + `X-FXC-Nonce` (doc 06 §2.3) | `store/cart`, `store/cart/items`, `store/cart/items/{key}`, `store/shipping-rates`, `store/checkout`, `store/products/{id}/variants` |
| Webhooks | `/flexacommerce/v1/webhooks/{gateway}` | Public, gateway signature verification | |

- Responses never expose table columns directly. Each endpoint has a schema (`get_item_schema`) and maps value objects through `to_array()`.

### 6.2 Admin

- Products are edited in the normal WordPress post editor (block editor for the description). A **Product data** metabox mounts a React app (Flexa UI system, `client/admin/features/product`) that edits type, prices, attributes, variants, stock and shipping data through `admin/products/{id}` endpoints. Saving the post and saving product data are separate requests. The metabox shows unsaved-changes state.
- [Decision] The editor is split so it can move out of the metabox later (ADR-019). `useProductDraft` holds the draft, save, field errors and tab routing; `ProductDataPanel` renders it and knows nothing about where it is mounted; `ProductEditor` is the metabox host that saves with the post and reports errors as block editor notices. A standalone editor app reuses the first two.
- The admin product API already covers a full editor: `GET/POST admin/products` (list with search, status, type, term filters and `X-WP-Total`; create), `GET/POST/DELETE admin/products/{id}`. The same payload carries the post fields (title, status, slug, content, excerpt, date, menu order, categories, tags, featured image; `Catalog\ProductPostInput`) and the commerce fields. The metabox sends only commerce fields, because the block editor owns the post.
- `flexacommerce.admin.product_editor` returns `post` (default, metabox) or `app` (no metabox, no metabox script; for a standalone editor screen). There is no setting for it until such a screen ships.
- Orders, customers (read-only list derived from orders + users), settings, shipping zones, payments, and migration are React screens under one top-level **FlexaCommerce** menu (`client/admin`).
- Admin order list is backed by `OrderRepository::search()` (indexed columns only, doc 03 §5).

### 6.3 Storefront

[Decision] One rendering core, three entry points (ADR-009):

```
           Block (render.php)      Classic template (templates/*.php)      Shortcode
                   \                         |                               /
                    └──────────────►  Storefront\Render\*  ◄────────────────┘
                                      (ProductPrice, AddToCartForm, Gallery, CartTable,
                                       CheckoutForm, OrderSummary, Account*)
                                              │
                                     services (read-only calls)
```

Blocks (all dynamic, server-rendered with `render.php`, registered from one metadata collection with `wp_register_block_types_from_metadata_collection()` [Fact: available since WP 6.8, present in 7.1]):

`flexacommerce/product-price`, `product-add-to-cart` (includes variant selector), `product-gallery`, `product-sku`, `product-stock`, `product-attributes`, `cart`, `mini-cart`, `checkout`, `order-confirmation`, `account`, `breadcrumbs`. Product listings use core `core/query` through a registered block variation for `fxc_product`, so themes keep full layout control.

Interactivity: the Interactivity API and script modules (core since 6.5 [Fact]) for variant selection, cart updates and mini-cart. No frontend framework, no jQuery dependency.

Without JavaScript: add to cart, cart update and checkout are plain HTML forms that POST to `admin-post.php` handlers sharing the same services. JavaScript replaces the full-page round trip with REST calls.

Templates:
- Block themes: plugin templates registered with `register_block_template()` [Fact: since 6.7] for `single-fxc_product`, `archive-fxc_product`, `taxonomy-fxc_product_cat`, `taxonomy-fxc_product_tag`. Themes override them in the Site Editor or by shipping a template with the same slug.
- Classic themes: `template_include` loads `templates/single-product.php` etc. unless the theme has `flexacommerce/single-product.php` (override path), matching the familiar WooCommerce pattern.
- Cart, checkout, account and order confirmation are normal WordPress pages created on activation (`Setup\PageInstaller`) containing the block (block theme) or shortcode (classic theme). Page IDs are stored in settings. Order confirmation and account sub-pages are rewrite endpoints on those pages.

Shortcodes: `[flexacommerce_cart]`, `[flexacommerce_checkout]`, `[flexacommerce_account]`, `[flexacommerce_order_confirmation]`, `[flexacommerce_products ids="" category="" limit="" columns=""]`, `[flexacommerce_add_to_cart id=""]`.

### 6.4 Extension layer

Public PHP contracts live in `Flexa\Commerce\Contracts\` and are versioned (doc 04). Extensions register on `flexacommerce.loaded`. Core gateways (COD, bank transfer) and core shipping methods are implemented against the same public interfaces, which keeps the contracts honest.

### 6.5 Migration layer

Loaded only for administrators in wp-admin or WP-CLI when WooCommerce is active. Architecture in doc 05. It uses repositories in **import mode** (`ImportGuard` active) and never calls `CheckoutService` or `Notifications`.

### 6.6 Background processing

[Decision] (ADR-012) No queue library. Three mechanisms:

| Need | Mechanism |
|---|---|
| Expire unpaid `pending` orders, purge expired sessions, prune webhook records | WP-Cron recurring events (`flexacommerce.cron.expire_pending` every 5 min, `flexacommerce.cron.daily`). Handlers are idempotent and bounded (order expiry: max 200 orders per run; purges: max 1,000 rows per run). Docs recommend a real system cron for production. |
| Deferred emails (optional setting) | `wp_schedule_single_event()` per email, falling back to inline send. |
| Migration batches | Transport-agnostic `Migration\Runner::run_batch( job_id )`. Driven by the admin screen (REST polling), by WP-CLI (loop), or by Action Scheduler when present. Action Scheduler ships with WooCommerce, and migration always requires WooCommerce to be active, so it is available without adding a dependency. |

## 7. Commerce lifecycle (Phase 3 specification)

### 7.1 Product loading

1. `WP_Query` loads `fxc_product` posts (core).
2. `ProductRepository::prime( list<int> $post_ids )` loads all variants of all products on the page with **one** query (`WHERE product_id IN (...)`) and caches them in the object cache group `flexacommerce_variants`. Shop archives therefore cost one extra query per page regardless of product count.
3. Product page: the variant matrix (id, attributes, price, availability, image) for the selector is rendered into the page as Interactivity API state. Up to 250 variants are inlined. Above that the selector fetches `store/products/{id}/variants` on demand.

### 7.2 Variation selection

- Client: the selected attribute values are matched against the inlined matrix using the same algorithm as `VariantMatcher` (exact match first, then fewest wildcards, then lowest `menu_order`, then lowest id). The UI disables combinations with no purchasable variant.
- Server: add to cart sends `variant_id` **and** the selected attributes. The server re-runs `VariantMatcher` and rejects the request if the result differs from `variant_id` or if a wildcard attribute value is not one of the product's allowed values. For wildcard variants the chosen concrete values are stored on the cart line and later on the order line.

### 7.3 Add to cart, persistence, recalculation

1. `CartService::add( variant_id, qty, attributes )`: validates the product is published and purchasable, the variant belongs to it, qty ≥ 1 and ≤ per-line max (default 999), and stock availability (`StockService::available()`) for the **total** quantity of that variant in the cart.
2. The session is created on this first write (§7.3.1).
3. Cart lines store `variant_id`, `qty`, `attributes`, `added_at`. **No prices are stored in the cart.**
4. Every cart read recalculates: `PricingService` resolves the current unit price, lines whose product became unpurchasable are flagged (not silently dropped), and the response lists notices ("price changed", "only 3 left").

#### 7.3.1 Session storage

- [Decision] (ADR-008) Server-side sessions in `fxc_sessions` (doc 03 §4.9). The cookie `fxc_session` holds a random 32-byte token (base64url). The table stores `SHA-256(token)` so a database leak does not allow session hijacking.
- The cookie is set **only** on the first cart write. Browsing the catalog never creates a session or a cookie, so page caches can serve catalog pages to everyone.
- Logged-in users: on login the guest session is merged into the user's session (quantities summed, capped by stock) and the cart is keyed by user id.
- Lifetime: 48 hours sliding for guests, 14 days for users, configurable. Expired rows are purged by the daily cron.
- Page caching guidance (docs + readme): exclude cart, checkout and account pages, and bypass cache when `fxc_session` is present on those pages only. Catalog pages stay cacheable because the mini-cart markup is the same for every visitor: the block script reads the count from the `fxc_cart_count` cookie (doc 10 §3.7, Q19).

### 7.4 Stock: when, how, idempotency

[Decision] (ADR-005) **Stock is deducted when the order is placed, inside the checkout transaction.** The order is the reservation. There is no cart-level reservation and no reservations table.

Deduction, per order line, in ascending `variant_id` order:

```sql
UPDATE {prefix}fxc_variants
   SET stock_quantity = stock_quantity - %d, updated_at = %s
 WHERE id = %d
   AND manage_stock = 1
   AND ( backorders <> 'no' OR stock_quantity >= %d )
```

`affected_rows = 1` means success. `0` means insufficient stock, so the transaction rolls back and the customer sees which line failed. Variants with `manage_stock = 0` skip the update. Because the condition and the decrement are a single statement on an InnoDB row, two concurrent checkouts for the last unit cannot both succeed (oversell prevention). This is tested by the concurrency test in doc 06 §5.4.

Each order item records `stock_reduced_qty`. Restoration (`release_for_order_lines`) adds back exactly `stock_reduced_qty` and sets it to 0 in the same transaction, so double cancellation or a replayed event cannot restore twice. Stock-affecting events append an `order_history` row of type `stock`.

When stock is held, by order status:

| Status | Stock held? | Entering this status from a held status | Entering from a released status |
|---|---|---|---|
| pending, on-hold, processing, completed | yes | no change | deduct again (may fail: transition then rejected or flagged, see §7.6) |
| failed, cancelled | no | restore | no change |
| refunded | per refund | restock only lines the admin chose to restock | no change |

Unpaid online orders: a `pending` order older than `hold_minutes` (default 60, setting) is moved to `cancelled` by cron, releasing stock. Bank transfer orders sit in `on-hold` and keep stock until an admin acts. An optional setting auto-cancels them after N days (default off).

### 7.5 Checkout

`CheckoutService::place_order( CheckoutRequest $request ): CheckoutResult`

```
 1. Load cart from session. Empty → error.
 2. Idempotency: request carries checkout_token (issued with the checkout form, rotates after success).
    If an order with this token exists → return that order's result (no second order).
 3. Validate input (CheckoutValidator): fields per FieldSchema and country rules, email, terms checkbox if
    enabled, shipping method id is one of the rates just computed, gateway enabled and is_available().
 4. Recompute everything on the server: unit prices (PricingService), shipping (ShippingService), tax
    (TaxCalculator), totals. If the grand total differs from the total the customer last saw
    (`expected_total`, sent only as a consistency check), stop and return "totals changed" with the new
    summary. The submitted value is never used as the amount.
 5. Transaction::run(
      a. lock + deduct stock per line (§7.4)
      b. allocate order number (Sequence, doc 03 §2.7)
      c. insert order, items (snapshots of name, SKU, attributes, unit price), addresses, history row
      d. create transaction row (status pending) for the chosen gateway
      e. initial status from gateway: COD → processing, bank transfer → on-hold, online → pending,
         zero total → processing
      f. defer: flexacommerce.order.created, emails, gateway initiation
    )
 6. After commit: PaymentService::initiate( transaction ) calls the gateway's process_payment().
    Result: redirect URL (online), or "done" (offline). Failure here never deletes the order: the order
    stays pending (online) and the customer can retry from the order-pay page.
 7. Empty the cart, rotate checkout_token, return redirect to order confirmation (or gateway).
```

Guest order access (`Orders\OrderAccess`):
- Every order has an `order_key` (32 random bytes, base64url, ~43 chars) generated with `random_bytes()`.
- Order confirmation and order-pay URLs carry `?key=`. Comparison uses `hash_equals()`.
- Logged-in owners can view their orders without the key. Admins with `view_fxc_orders` can view any order.
- Pages accessed by key send `Cache-Control: private, no-store` and `Referrer-Policy: no-referrer`, and do not show the full address in the page `<title>` or meta.
- Key links in emails do not expire in MVP (the WooCommerce behavior customers expect). Rotation from the admin order screen is available.

### 7.6 Order state machine

States: `pending` (awaiting online payment), `on-hold` (awaiting offline payment or review), `processing` (paid or COD, awaiting fulfillment), `completed` (fulfilled), `cancelled`, `failed` (payment failed, can be retried), `refunded` (fully refunded).

```
            ┌─────────── checkout ───────────┬──────────────────────┐
            ▼ (online)                       ▼ (bank transfer)      ▼ (COD / zero total / instant success)
        pending ──payment ok──────────► processing ──fulfil──► completed
         │  │ ▲                            ▲   │                   │
         │  │ └──retry── failed ◄─fail─────┼───┘(n/a)              │
         │  │            │  ▲              │                       │
         │  └─fail───────┘  │           confirm                   │
         │               late success   payment                    │
         │               (stock ok)        │                       │
         │                  │          on-hold                     │
         │                  │              │                       │
         └──── cancel ──────┴── cancel ────┴──► cancelled          │
                                                                   │
              processing ──full refund──► refunded ◄──full refund──┘
```

Allowed transitions (`OrderStateMachine::TRANSITIONS`):

| From \ To | pending | on-hold | processing | completed | cancelled | failed | refunded |
|---|---|---|---|---|---|---|---|
| (new) | ✔ online | ✔ offline | ✔ COD/zero | | | | |
| pending | | ✔ gateway review | ✔ paid | | ✔ customer/admin/timeout | ✔ payment failed | |
| on-hold | | | ✔ admin confirm / capture | | ✔ admin | | |
| processing | | ✔ admin | | ✔ admin | ✔ admin | | ✔ full refund |
| completed | | | ✔ admin reopen | | | | ✔ full refund |
| failed | ✔ retry (re-deduct) | | ✔ late success (re-deduct) | | ✔ admin/timeout | | |
| cancelled | terminal | | | | | | |
| refunded | terminal | | | | | | |

Rules:
- Every transition goes through `OrderService::transition( order_id, to, TransitionContext )`, which locks the order row (`SELECT ... FOR UPDATE`), checks the table, applies stock effects from §7.4, writes `order_history`, updates `status` and `updated_at`, and defers `flexacommerce.order.status_changed` plus status-specific events.
- Same-status transitions are no-ops returning success, which makes repeated callbacks harmless.
- Transitions that need stock re-deduction fail cleanly if stock is insufficient. A late payment success on a `failed` order with no stock left moves the order to `on-hold` with `needs_attention = 1` and an admin email. A payment success on a `cancelled` order records the transaction as succeeded, leaves the order cancelled, sets `needs_attention = 1`, and notifies the admin to refund.
- Extensions may veto a transition through the `flexacommerce.order.can_transition` filter (doc 04 §5) but cannot add states in v1.

### 7.7 Payment transaction state model

A transaction row is one attempt to move money. A retry creates a new row. Refunds are separate rows of `type = refund`.

```
payment:   pending ──► requires_action ──► succeeded
             │  │            │  └──────► failed
             │  │            └─────────► cancelled
             │  └──► authorized ──capture──► succeeded
             │             └──void───► cancelled
             ├──► succeeded
             ├──► failed
             └──► cancelled

refund:    pending ──► succeeded | failed
```

- `succeeded`, `failed`, `cancelled` are terminal. A terminal-to-terminal change is rejected and logged (protects against out-of-order webhooks reverting a success).
- Mapping to orders (performed by `PaymentService` calling `OrderService`):

| Transaction event | Order effect |
|---|---|
| payment → succeeded | `amount_paid` += amount. If paid ≥ total: pending/on-hold/failed → processing (failed re-deducts stock). |
| payment → authorized | pending → on-hold (note: "authorized, capture pending"). |
| payment → failed | pending → failed (stock released). Other statuses unchanged (a failed retry on a processing order changes nothing). |
| payment → cancelled | Note only. Timeout cron handles abandoned orders. |
| refund → succeeded | `amount_refunded` += amount. Restock chosen lines. If refunded ≥ paid: → refunded. Else stays, `payment_status = partially_refunded`. |
| refund → failed | Note + admin notice. No status change. |

- `orders.payment_status` (`unpaid`, `paid`, `partially_refunded`, `refunded`) is derived from the sums on every payment event and stored for list filtering.

### 7.8 Payment confirmation, webhooks, duplicates

```
POST /webhooks/{gateway}
  1. Resolve gateway (must be registered; it may be disabled, so late webhooks are still accepted).
  2. $events = $gateway->parse_webhook( $request )  → verifies signature, returns list<PaymentEvent>
     or throws InvalidSignature (→ 401, logged with redaction, no state change).
  3. For each event:
       INSERT INTO fxc_webhook_events (gateway, event_id, ...)  -- UNIQUE(gateway,event_id)
       duplicate key → already processed → skip (respond 200 so the gateway stops retrying)
       Transaction::run( lock transaction row FOR UPDATE, apply state change if allowed, call
                         OrderService, mark webhook row processed )
  4. Respond 200. Any unexpected exception → 500 so the gateway retries; the webhook row is rolled back
     with the transaction, so the retry is not treated as a duplicate.
```

- Redirect returns (`return_url`) are treated as a hint: the order page shows "confirming payment" and asks the gateway (`verify_return()`) or waits for the webhook. A return URL alone never marks an order paid unless the gateway contract says the return is signed (doc 04 §3).
- Gateways that need it get a replay window check (`PaymentEvent::occurred_at` older than the gateway's tolerance → reject) in addition to the dedupe table.

### 7.9 Emails

| Email | Recipient | Trigger (after commit) |
|---|---|---|
| New order | admin | order created with status processing or on-hold; pending → processing |
| Order received (bank details) | customer | → on-hold from checkout |
| Order processing | customer | → processing |
| Order completed | customer | → completed |
| Order cancelled | admin + customer (customer only if they had paid or the admin chose to notify) | → cancelled |
| Order refunded (full/partial) | customer | refund → succeeded |
| Payment failed | admin | pending → failed |
| Needs attention | admin | needs_attention set |

Each send writes an `order_history` row (`type = email`, success or failure). Templates live in `templates/emails/` and are overridable in `{theme}/flexacommerce/emails/`. Emails are disabled entirely while `ImportGuard` is active (doc 05 §8.3).

### 7.10 Cancellation and refunds

- Customer cancel: only `pending` orders, from the order page with the order key or as the owner, nonce-protected.
- Admin cancel: any non-terminal order. If money was received, the admin is shown the paid amount and offered a refund. Cancel never refunds automatically.
- Refund (`OrderService::refund( order_id, RefundRequest )`): amount ≤ paid − already refunded, optional per-line quantities and restock flags. If the gateway supports refunds and the admin chose "refund via gateway", `PaymentService` calls the gateway after the refund transaction row is committed as `pending`. Otherwise the refund is recorded as `succeeded` with method `manual`.

### 7.11 Concurrency and partial failure catalogue

| Risk | Mitigation |
|---|---|
| Two buyers, one unit left | Conditional atomic UPDATE (§7.4). |
| Double click / resubmitted checkout | `checkout_token` UNIQUE on orders. Second request returns the first order. |
| Two tabs updating the same cart | Session row has `version`. Writes use `UPDATE ... WHERE version = %d`. A conflict reloads and reapplies the single mutation once. |
| Webhook replay or duplicate delivery | `UNIQUE(gateway, event_id)` + terminal-state rules. |
| Webhook and redirect race | Both go through `PaymentService::apply()` which locks the transaction row. Second one is a no-op. |
| Out-of-order webhooks (failed after succeeded) | Terminal transaction states cannot change. |
| Crash after commit, before email | Email lost, order intact. History shows no email row, and admin can resend. Acceptable for MVP. |
| Gateway call fails after order commit | Order stays pending with a pending transaction, customer can retry. Cron cancels after `hold_minutes`. |
| Gateway charged but our DB write failed | Webhook later finds the transaction by gateway reference and applies it (returning 500 until our DB is healthy). |
| Cron and customer act on the same pending order | Both transitions lock the order row. The second sees the new status and the transition table rejects or no-ops it. |
| Deadlock between concurrent checkouts | Ordered locking + retry (§4.3). |

## 8. Service contracts (internal, signatures illustrative)

Value objects referenced: `Money` (amount int + currency), `Product`, `Variant`, `Cart`, `CartLine`, `Order`, `OrderItem`, `Address`, `Transaction`, `TransitionContext`, `ShippingPackage`, `ShippingRate`. Exceptions extend `Flexa\Commerce\Contracts\CommerceException` and carry a machine code and a translated message.

```php
interface ProductRepository {
	public function find( int $product_id ): ?Product;               // post + meta + variants
	/** @param list<int> $product_ids */
	public function prime( array $product_ids ): void;               // one query for all variants
	public function save_meta( Product $product ): void;              // product-level meta only
	public function sync_denormalized( int $product_id ): void;      // _fxc_price_min/max, _fxc_stock_status
}

interface VariantRepository {
	public function find( int $variant_id ): ?Variant;
	/** @return list<Variant> */
	public function for_product( int $product_id ): array;
	public function find_by_sku( string $sku ): ?Variant;
	public function insert( Variant $variant ): int;                 // throws DuplicateSku
	public function update( Variant $variant ): void;
	public function delete_for_product( int $product_id ): int;
	public function decrement_stock( int $variant_id, int $qty ): bool;   // conditional, see §7.4
	public function increment_stock( int $variant_id, int $qty ): void;
}

final class ProductService {
	public function save( ProductInput $input ): Product;            // validates type rules, SKUs, attributes
	/** @param array<string,list<string>> $attribute_values */
	public function generate_variants( int $product_id, array $attribute_values ): VariantGenerationResult;
	public function change_type( int $product_id, string $type ): Product; // simple <-> variable rules
	public function on_post_deleted( int $product_id ): void;         // variants removed, orders untouched
}

final class StockService {
	public function available( int $variant_id ): Availability;      // in_stock|out_of_stock|backorder + qty
	/** @param list<StockLine> $lines  sorted by variant id internally */
	public function deduct_for_order( int $order_id, array $lines ): void;   // throws InsufficientStock
	/** @param list<StockLine> $lines */
	public function release_for_order_lines( int $order_id, array $lines ): void; // idempotent via stock_reduced_qty
	public function adjust( int $variant_id, int $delta, string $reason, int $actor_id ): int;
}

final class PricingService {
	public function unit_price( Variant $variant, ?\DateTimeImmutable $at = null ): PriceResolution; // regular, sale, active
	/** @param list<CartLine> $lines */
	public function totals( array $lines, ?ShippingRate $shipping, TaxContext $tax ): Totals;
}

final class CartService {
	public function current(): Cart;                                  // read, recalculated, no session creation
	/** @param array<string,string> $attributes */
	public function add( int $variant_id, int $qty, array $attributes = [] ): Cart;
	public function update_quantity( string $line_key, int $qty ): Cart;  // 0 removes
	public function remove( string $line_key ): Cart;
	public function clear(): void;
	public function select_shipping( string $rate_id ): Cart;
	public function merge_on_login( int $user_id ): void;
}

final class ShippingService {
	/** @return list<ShippingRate> */
	public function rates( ShippingPackage $package ): array;
	public function match_zone( Address $destination ): ?ShippingZone;
}

final class CheckoutService {
	public function form_state(): CheckoutFormState;                  // fields, rates, gateways, token
	public function place_order( CheckoutRequest $request ): CheckoutResult;
	public function retry_payment( int $order_id, string $order_key, string $gateway_id ): CheckoutResult;
}

final class PaymentService {
	public function initiate( int $transaction_id ): PaymentResult;   // after commit only
	public function handle_webhook( string $gateway_id, \WP_REST_Request $request ): WebhookOutcome;
	public function apply( PaymentEvent $event ): void;                // locked, idempotent
	public function record_manual_payment( int $order_id, Money $amount, string $note, int $actor_id ): Transaction;
	public function refund( int $order_id, Money $amount, bool $via_gateway, string $reason ): Transaction;
}

interface OrderRepository {
	public function find( int $order_id ): ?Order;                    // with items, addresses
	public function find_by_number( string $order_number ): ?Order;
	public function find_by_checkout_token( string $token ): ?Order;
	public function insert( Order $order ): int;                      // items + addresses in same transaction
	public function lock( int $order_id ): Order;                     // SELECT ... FOR UPDATE
	public function update_status( int $order_id, string $status, \DateTimeImmutable $at ): void;
	public function update_payment_totals( int $order_id, int $paid, int $refunded, string $payment_status ): void;
	public function search( OrderQuery $query ): OrderPage;           // admin list, indexed filters only
}

final class OrderService {
	public function create( NewOrder $order ): Order;                 // called by CheckoutService inside its transaction
	public function transition( int $order_id, string $to, TransitionContext $ctx ): Order;
	public function apply_payment_outcome( int $order_id, PaymentOutcome $outcome ): Order;
	public function cancel( int $order_id, TransitionContext $ctx ): Order;
	public function record_refund( int $order_id, RefundRecord $refund ): Order;
	public function add_note( int $order_id, string $message, bool $customer_visible, int $actor_id ): void;
	public function expire_pending( \DateTimeImmutable $older_than, int $limit ): int;  // cron
}

final class MigrationService {
	public function preflight( MigrationPlan $plan ): PreflightReport;
	public function create_job( MigrationPlan $plan, bool $dry_run ): int;
	public function run_batch( int $job_id ): BatchReport;           // resumable, bounded time/memory
	public function verify( int $job_id ): VerificationReport;
	public function cancel( int $job_id ): void;
}
```

Call rules:

| Caller | May call | Must not call |
|---|---|---|
| CheckoutService | CartService, PricingService, ShippingService, TaxCalculator, StockService (via OrderService::create), OrderService, PaymentService | repositories of other modules directly |
| PaymentService | gateways, TransactionRepository, OrderService | StockService, CartService |
| OrderService | OrderRepository, HistoryRepository, StockService (release/deduct for transitions), AfterCommit | PaymentService, gateways, mailer |
| CartService | Catalog repositories (read), PricingService, StockService::available, ShippingService | OrderService |
| Storefront / Api | any service's public methods | repositories for writes |
| MigrationService | repositories in import mode, ProductService validation helpers | CheckoutService, PaymentService::initiate, Notifications |

## 9. WordPress integration

| Concern | Design |
|---|---|
| Activation | `Setup\Activator::activate()`: requirement check (PHP ≥ 8.2, WP ≥ 6.9, InnoDB), `Database\Schema::migrate()`, seed `flexacommerce_settings` defaults, add roles and capabilities, create pages (only if missing), schedule cron events on `init`, flush rewrite rules once (flag option, flushed on next `init` after CPT registration). Multisite: per-site activation. Network activation iterates sites in batches (≤ 50 per request, remaining sites upgrade lazily on first admin visit). |
| Deactivation | Unschedule cron events, flush rewrite rules. No data removed. |
| Upgrades | `Database\Schema::DB_VERSION` (integer string), `maybe_upgrade()` on `admin_init` and WP-CLI `wp flexacommerce db upgrade`. Data migrations are numbered steps (`Upgrade_0003_backfill_payment_status`) run in batches and recorded in `flexacommerce_db_version`. Details in doc 03 §6. |
| CPT/taxonomies | `fxc_product` (public, `has_archive`, rewrite base from settings, `show_in_rest` true, `capability_type` `fxc_product` with `map_meta_cap`), `fxc_product_cat` (hierarchical), `fxc_product_tag`, `fxc_attribute` (hierarchical, not public, `show_in_rest`). |
| Capabilities | `manage_flexacommerce` (settings, migration), `view_fxc_orders`, `edit_fxc_orders`, `refund_fxc_orders`, plus CPT caps for `fxc_product`. Granted to `administrator` and to a new `fxc_store_manager` role. All checks go through `Support\Capabilities` with filters `flexacommerce.capabilities.*` (house rule). |
| Admin menus | Top-level **FlexaCommerce**: Orders, Products (CPT list), Categories, Tags, Attributes, Customers, Settings (General, Shipping, Payments, Emails, Advanced), Migration (only if WooCommerce active or a job exists), Status (system report + logs). |
| REST | §6.1. |
| Blocks | §6.3. Block category `flexacommerce`. |
| Templates | §6.3. |
| Assets | Frontend CSS (≤ 10 KB gzip target) and script modules only on pages that render a FlexaCommerce block, shortcode or template (tracked by the renderers; `wp_enqueue_*` from inside render callbacks). Admin bundles only on FlexaCommerce screens. |
| Cron | §6.6. |
| Uninstall | Data kept by default. Removing everything requires the setting `remove_data_on_uninstall` (off by default, with a confirmation text field). Then `Maintenance\Uninstaller` drops `fxc_*` tables, deletes options, CPT posts, terms, user meta, roles, pages created by the plugin. Media files are never deleted. |
| Classic and block themes | `wp_is_block_theme()` [Fact] selects block templates or PHP templates. Both use the same renderers. Theme support flag `add_theme_support( 'flexacommerce' )` lets classic themes opt out of default wrappers. |
| Privacy | `wp_privacy_personal_data_exporters` / `_erasers` for orders, addresses, sessions. Erasure replaces names, emails, phones and addresses with anonymized values and sets `orders.anonymized_at`. Retention setting for anonymizing orders older than N months (off by default). Suggested privacy policy text via `wp_add_privacy_policy_content`. |
| WooCommerce coexistence | Declare `custom_order_tables` compatibility on `before_woocommerce_init` with `FeaturesUtil::declare_compatibility( 'custom_order_tables', FLEXACOMMERCE_FILE, true )` [Fact: documented API]. FlexaCommerce never reads or writes WooCommerce tables outside the migration adapters. Rewrite bases default to `shop-item`/`shop` while WooCommerce is active to avoid collisions with `product`/`shop`, and cutover switches them (doc 05 §9). |

## 10. Key runtime flows (sequence sketches)

**Catalog page (no cart):** `WP_Query` → `ProductRepository::prime()` (1 query) → renderers → no session, no cookie, no extra assets beyond CSS.

**Add to cart (JS):** `POST store/cart/items` → nonce + session token check → `CartService::add()` → session created/updated (1 write) → response with recalculated cart → Interactivity store updates the mini-cart.

**Checkout (COD):** `POST store/checkout` → `CheckoutService::place_order()` → transaction (stock, number, order, items, addresses, transaction, history) → commit → events: `order.created`, emails (new order, processing) → response redirect to order confirmation.

**Online gateway (post-MVP add-on):** same as COD up to commit with status `pending` → `PaymentService::initiate()` → gateway returns redirect → customer pays → webhook → `PaymentService::apply()` → `OrderService::apply_payment_outcome()` → processing → emails.
