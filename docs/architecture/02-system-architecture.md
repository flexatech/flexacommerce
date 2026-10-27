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
│   ├── Tax/                     TaxEngine, TaxService, TaxRateRepository, TableRateProvider, Presets,
│   │                            TaxCalculator and TaxRateProvider seams (ADR-020, §7.12)
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
| Tax | Support, Database, Contracts | Cart, Orders, Checkout |
| Payments | Orders (OrderService), Database, Contracts | Cart, Checkout, Catalog |
| Cart | Catalog, Pricing, Options, Inventory (availability reads), Shipping, Coupons, Tax, Support | Orders, Payments, Checkout |
| Options | Catalog, Inventory (`OptionStockRepository`, ADR-040), Support, Contracts | Cart, Orders, Checkout |
| Coupons | Cart (`CartItem` values only), Catalog, Pricing, Support, Database, Contracts | Orders, Payments, Checkout |
| Customers | Support, Contracts (saved addresses in user meta) | every service module |
| Checkout | Cart, Coupons, Pricing, Inventory, Shipping, Tax, Orders, Payments, Customers | Storefront, Api |
| Notifications | Orders (read), Support. Triggered by hooks only | any service write method |
| Storefront, Api, Admin, Cli | any L1 to L4 service and read repositories | each other |
| Migration | Catalog, Orders, Payments repositories (import mode), Contracts, Support, `Checkout\CheckoutLayout` (the checkout fields stage, ADR-036) | the rest of Checkout, Cart, Notifications |

### 3.3 How cycles are prevented

- **Payments → Orders, never the reverse.** `OrderService` does not know gateways. When a payment event arrives, `PaymentService` records the transaction and calls `OrderService::apply_payment_outcome()`.
- **Orders → Inventory only through a narrow method** (`StockService::release_for_order_lines(list<StockLine>)`). Inventory receives plain line DTOs, never `Order`.
- **Cart and Coupons share types, not calls back.** `CartService` asks `CouponService` for the discount; `CouponService` reads `CartItem` values it is handed and never calls into Cart.
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
| Admin | `/flexacommerce/v1/admin/*` | Cookie + `X-WP-Nonce` (`wp_rest`), capability check per route | `admin/orders`, `admin/orders/{id}/transitions`, `admin/products/{id}/variants`, `admin/settings`, `admin/coupons`, `admin/coupons/{id}`, `admin/migrations` |
| Store | `/flexacommerce/v1/store/*` | Public, but every mutating route requires the cart session token + `X-FXC-Nonce` (doc 06 §2.3) | `store/cart`, `store/cart/items`, `store/cart/items/{key}`, `store/cart/coupons`, `store/cart/coupons/{code}`, `store/shipping-rates`, `store/checkout`, `store/products/{id}/variants` |
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

`flexacommerce/product-price`, `product-add-to-cart` (includes variant selector), `product-gallery`, `product-sku`, `product-stock`, `product-attributes`, `related-products`, `catalog-sorting`, `product-filters`, `cart`, `mini-cart`, `checkout`, `order-confirmation`, `account`, `breadcrumbs`. Product listings use core `core/query` through a registered block variation for `fxc_product` (`namespace: flexacommerce/product-query`), so themes keep full layout control. Breadcrumb items pass through the filter `flexacommerce.breadcrumbs`.

Shop query (`Storefront\ShopQuery`, phase 5): sorting reads `?orderby=` (`menu_order`, `popularity`, `date`, `price`, `price-desc`; `popularity` is newest first until sales counts are stored), filters read `?filter_cat=slug,slug` and `?filter_{attribute}=slug,slug` into a `tax_query`. Price sorts order by `_fxc_price_min` or `_fxc_price_max` and keep products without a price. The page size is the `products_per_page` setting, filter `flexacommerce.shop.per_page`. An exact SKU in a search adds its product to the main search query.

Interactivity: the Interactivity API and script modules (core since 6.5 [Fact]) for variant selection, cart updates and mini-cart. No frontend framework, no jQuery dependency.

Without JavaScript: add to cart, cart update and checkout are plain HTML forms that POST to `admin-post.php` handlers sharing the same services. JavaScript replaces the full-page round trip with REST calls.

Templates:
- Block themes: plugin templates registered with `register_block_template()` [Fact: since 6.7] for `single-fxc_product`, `archive-fxc_product`, `taxonomy-fxc_product_cat`, `taxonomy-fxc_product_tag`. Themes override them in the Site Editor or by shipping a template with the same slug.
- Classic themes: `template_include` (`Storefront\TemplateLoader`) loads `templates/single-product.php` or `templates/archive-product.php` (the archive also serves the category and tag pages) unless the theme has `flexacommerce/<name>` (override path), matching the familiar WooCommerce pattern. Every template, the email ones included, has an `@version x.y.z` tag in its header; `TemplateLoader::outdated()` lists theme copies whose version is lower or missing. Filters: `flexacommerce.classic_templates` (false turns the loader off, for a theme that brings its own templates), `flexacommerce.template` (the file found for a name), `flexacommerce.template_wrapper` (the opening and closing markup around the content: `<div id="primary" class="content-area"><main id="main" class="site-main">` by default, a plain `<div>` on Twenty Twenty-One).
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
| Deferred emails (optional setting `defer_emails`) | The message is rendered during the request and stored in a transient `fxc_mail_<32 hex>` (one day); a single event `flexacommerce.cron.send_email` with the queue id sends it. When the transient or the event cannot be stored, the email is sent inline. The handler is registered only under cron and WP-CLI, so page views do not pay for the callback. |
| Migration batches | Transport-agnostic `Migration\Runner::run_batch( job_id )`. Driven by the admin screen (REST polling), by WP-CLI (loop), or by Action Scheduler when present. Action Scheduler ships with WooCommerce, and migration always requires WooCommerce to be active, so it is available without adding a dependency. |

## 7. Commerce lifecycle (Phase 3 specification)

### 7.1 Product loading

1. `WP_Query` loads `fxc_product` posts (core).
2. `ProductRepository::prime( list<int> $post_ids )` loads all variants of all products on the page with **one** query (`WHERE product_id IN (...)`) and caches them in the object cache group `flexacommerce_variants`. Shop archives therefore cost one extra query per page regardless of product count.
3. Product page: the variant matrix (id, attributes, price, availability, image) for the selector is rendered into the page as Interactivity API state. Up to 250 variants are inlined. Above that the selector fetches `store/products/{id}/variants` on demand.

### 7.2 Variation selection

- Client: the selected attribute values are matched against the inlined matrix using the same algorithm as `VariantMatcher` (exact match first, then fewest wildcards, then lowest `menu_order`, then lowest id). The UI disables combinations with no purchasable variant.
- Server: add to cart sends `variant_id` **and** the selected attributes. The server re-runs `VariantMatcher` and rejects the request if the result differs from `variant_id` or if a wildcard attribute value is not one of the product's allowed values. For wildcard variants the chosen concrete values are stored on the cart line and later on the order line.

#### 7.2.1 Swatches

A store attribute can be shown as a dropdown (the default), colour swatches, image swatches or buttons (ADR-022). The type is set per attribute in **Attributes**; the colour or image of each value is set from its chip there. Custom (local) attributes have no terms, so they can only be a dropdown or buttons, chosen once for the store in **Settings > Storefront**.

- **Markup.** A swatch attribute renders as a `fieldset` of radio inputs named `attributes[{key}]`, the same field the dropdown posts. The form works without JavaScript and the server matching (§7.2) is unchanged. Keyboard users get the native radio group (arrow keys); screen readers hear the value name, which sits in a visually hidden span for colour and image swatches. A value with no colour or image falls back to a button with its name.
- **Store.** The radios call the same `actions.choose` and bind `disabled` to the same `state.optionUnavailable` as the dropdown options. `state.chosenLabel` prints the picked value after the legend ("Color: Red").
- **Lists (ADR-038).** In a product list a variable product shows its first swatch attribute under the price, as links: each one opens the product page with `?fxc_pick[{key}]={value}`, which picks that value (and, when every attribute is named, the variation with its price). A value with no variation in stock is crossed out, or left out under `swatch_unavailable = hide`. Past `swatch_list` values a "+N" link opens the product. The links carry `rel="nofollow"`, so crawlers do not follow one URL per value. The card keeps its "Choose options" button.
- **Settings > Storefront.** `swatch_size` (20 to 96 px; lists use three quarters of it), `swatch_shape` (round or square), `swatch_show_choice`, `swatch_unavailable` (cross out or hide; hiding uses `:has()`, so an older browser shows them crossed out), `swatch_custom` (dropdown or buttons), `swatch_list` (values per product in a list, 0 turns list swatches off, default 5). The screen previews the result.
- **Cost.** `PurchaseOptions` primes term meta with the terms it already loads, so swatches add no query per value. The REST variants route (`store/products/{id}/variants`) returns `swatch`, `color` and `image` with each attribute.

#### 7.2.2 Gallery and badges

- **Gallery** (ADR-023). Settings > Storefront sets the thumbnail layout (`gallery_thumbs`: bottom, left from 600 px wide, or dots), the image shape (`gallery_aspect`), arrows, hover zoom and the full-screen view. Arrows stay `hidden` until the store binds them. Swipe works on the main image and in the full-screen view; the view is a `<dialog>` opened with `showModal()`, so Escape and focus return come from the browser, and its image is lazy until opened. One image with the full-screen view and zoom off renders with no store.
- **Badges** (ADR-023). Promotions > Badges keeps an ordered list in `flexacommerce_badges`. Each badge has a condition (always, on sale, new for N days, featured, low stock at the product's threshold, out of stock), a target (all products, chosen products, or categories, which include their child categories), excluded products, places (everywhere, product page, lists) and optional start and end dates in UTC. `BadgeMatcher` picks the live badges for a product and place; `BadgeOverlay` groups them by corner. The overlay is placed in the gallery figure (product page), the archive card, related products and the core featured-image block for products (lists). It has `pointer-events: none`, so the image link under it still works.
- **Pricing rules** (ADR-024). Promotions > Pricing rules keeps an ordered list in `flexacommerce_price_rules`. A rule lowers the unit price for any quantity or by quantity tiers, for all products, chosen products or categories (child categories included), minus excluded products, for everyone, logged-in customers or chosen roles, with optional start and end dates in UTC. `PricingService::unit_price()` asks `PriceRules` for the lowest matching price after the sale price and before the `flexacommerce.price.unit` filter, so the cart, the product page and the API agree. Rules do not stack and never raise a price. A rule can add conditions (ADR-041): items or weight in the cart, country, shipping or payment method, coupons in the cart, and the customer's paid orders, spend and past purchases; it applies only when they are met, so a rule that needs a payment method lowers the price from the moment the shopper picks it at checkout. A bulk rule counts all variations of a product together or each one alone; the product page lists its tiers under the quantity field (`QuantityTiers`, ADR-030) as ranges starting at 1, with the unit price and the saving, and marks the row for the quantity in the form. A graduated rule (ADR-042) uses the same tiers but prices each unit by the tier its position reaches, so 6 units with a tier from 5 pay the full price for units 1 to 4 and the tier price for units 5 and 6; the line keeps the exact total and shows the average unit price, and the table says that each price applies only to the units in its range. A fee rule (ADR-043) changes no price: it adds its own line to the cart total, a fixed amount or a percent of the targeted products after coupons with an optional cap, when a targeted product is in the cart and its conditions pass. Only a fee may check the cart subtotal. Fees add up, carry no tax, and become `fee` order items that add up to `fee_total`.
- **Countdown** (ADR-030). When a pricing rule or a scheduled sale ends within `countdown_days` (Settings > Storefront, 7 by default, 0 turns it off) and one unit then costs more, the add-to-cart form starts with "Offer ends in" and the days, hours, minutes and seconds left. A bulk rule that ends soon gets its own line under the tier table. The server prints the end as a date sentence; `callbacks.countdown` in cart.js ticks it in the browser, so a cached page stays correct. `tier_table` and `tier_table_title` hide the table or rename it.
- **Product options** (ADR-025). The Product options screen (after Attributes) keeps an ordered list of option sets in `flexacommerce_product_options`. A set holds up to 20 fields (text, long text, number, date, color picker, dropdown, radio buttons, checkboxes, color swatches, buttons, and headings; ADR-028) and applies to all products, chosen products or categories, minus excluded products. A field or a choice can add a fixed amount or a percent of the unit price after pricing rules, or a fixed amount once per cart line whatever the quantity (ADR-029), or a formula over the other fields of its set, `base` and `qty`, such as `{1} * {2} / 10000 * 25` for a price by area (ADR-039), and can show or hide itself based on the answers to fields above it. A choice can track stock (ADR-040): its count lives in `fxc_option_stock`, the cart refuses more than is left, orders take it with the variant stock and give it back on cancel or restock, and the form shows a sold-out choice disabled. `AddToCartForm` renders the fields (`OptionFields`), `cart.js` hides fields and shows the extra charge as the shopper picks, and `ProductOptions` checks the posted values again on the server: hidden fields are dropped and required, length and range rules apply. The cart line keeps the values and their labels; `CartService` adds the extra to the unit price each time the cart is priced, and a once-per-line charge to the line total (`CartItem::$fee`, kept on the order item as `meta.fee`). Up to 200 sets, with a search box on the screen once there are 8. WooCommerce Product Add-ons migrate into option sets (doc 05 §5.9).

### 7.3 Add to cart, persistence, recalculation

1. `CartService::add( variant_id, qty, attributes )`: validates the product is published and purchasable, the variant belongs to it, qty ≥ 1 and ≤ per-line max (default 999), and stock availability (`StockService::available()`) for the **total** quantity of that variant in the cart.
2. The session is created on this first write (§7.3.1).
3. Cart lines store `variant_id`, `qty`, `attributes`, `added_at`. **No prices are stored in the cart.**
4. Every cart read recalculates: `PricingService` resolves the current unit price, lines whose product became unpurchasable are flagged (not silently dropped), and the response lists notices ("price changed", "only 3 left").
5. A line can be changed in place (ADR-037). The cart's "Edit" link opens the product page with `?fxc_edit=<line key>`; the form starts from the line's variation, options and quantity and posts them with `replace=<key>` (REST `POST store/cart/items`, or the no-JS form). `CartService::replace()` checks the choices like `add()`, then puts the new line where the old one was, or adds it to a line that already has the same choices.

#### 7.3.1 Session storage

- [Decision] (ADR-008) Server-side sessions in `fxc_sessions` (doc 03 §4.9). The cookie `fxc_session` holds a random 32-byte token (base64url). The table stores `SHA-256(token)` so a database leak does not allow session hijacking.
- The cookie is set **only** on the first cart write. Browsing the catalog never creates a session or a cookie, so page caches can serve catalog pages to everyone.
- Logged-in users: on login the guest session is merged into the user's session (quantities summed, capped by stock) and the cart is keyed by user id.
- Lifetime: 48 hours sliding for guests, 14 days for users, configurable. Expired rows are purged by the daily cron.
- Page caching guidance (docs + readme): exclude cart, checkout and account pages, and bypass cache when `fxc_session` is present on those pages only. Catalog pages stay cacheable because the mini-cart markup is the same for every visitor: the block script reads the count from the `fxc_cart_count` cookie (doc 10 §3.7, Q19).
- Header checks (phase 5): the cart, checkout and account pages call `nocache_headers()` and define `DONOTCACHEPAGE`, which most cache plugins read. Catalog pages send neither, asserted by P11 (doc 10 §4.1).

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
    The schema follows the checkout layout (doc 11): its order, labels and answers, and the merchant's
    custom fields, whose answers the validator turns into order meta, with their labels in `_fxc_fields`.
 4. Recompute everything on the server: unit prices (PricingService), shipping (ShippingService), tax
    (TaxService::calculate, per line and per rate, §7.12), totals. If the grand total differs from the total the customer last saw
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

### 7.12 Taxes (phase 4.5 specification)

ADR-020. The engine runs only when `tax_enabled` is on; with it off, every tax amount is zero, no tax class or rate is read, and the cost is the current one (doc 10 §4.1). Labels as in the rest of this document; every **[WC]** item is WooCommerce 11 behavior that phase 4.5 confirms with golden tests on the bench stack (§7.12.9) before it is treated as a fact.

#### 7.12.1 Settings (WooCommerce equivalents)

All keys live in the `flexacommerce_settings` option (`Support\Settings`), on the **Tax** tab of the settings screen (Settings > Tax, which also holds classes, rates, CSV and presets).

| Key | Values (default) | WooCommerce option |
|---|---|---|
| `tax_enabled` | bool (`false`) | `woocommerce_calc_taxes` |
| `prices_include_tax` | bool (`false`) | `woocommerce_prices_include_tax` |
| `tax_based_on` | `shipping` \| `billing` \| `base` (`shipping`) | `woocommerce_tax_based_on` |
| `tax_default_location` | `base` \| `none` (`base`): the location used before the customer has given an address | `woocommerce_default_customer_address` (without geolocation, which is out of scope) |
| `shipping_tax_class` | `inherit` \| a class slug (`inherit`) | `woocommerce_shipping_tax_class` |
| `tax_round_at_subtotal` | bool (`false`) | `woocommerce_tax_round_at_subtotal` |
| `tax_classes` | list of `{slug, name}` after Standard (`reduced-rate`, `zero-rate`) | `woocommerce_tax_classes` / `wc_tax_rate_classes` |
| `tax_display_shop` | `excl` \| `incl` (`excl`) | `woocommerce_tax_display_shop` |
| `tax_display_cart` | `excl` \| `incl` (`excl`) | `woocommerce_tax_display_cart` |
| `price_display_suffix` | string, may contain `{price_including_tax}` and `{price_excluding_tax}` (`''`) | `woocommerce_price_display_suffix` |
| `tax_total_display` | `itemized` \| `single` (`itemized`) | `woocommerce_tax_total_display` |
| `tax_markets` | list of market overrides (§7.12.6), (`[]`) | none |
| `tax_base_rates` | compiled, not edited: the matched rates for the store base address per class (§7.12.8) | none |

The Standard class is the empty slug `''`, as in WooCommerce. Deleting a class deletes its rates after a confirmation that names the number of rates and products using it; products keep the slug and fall back to Standard with an admin notice.

#### 7.12.2 Product tax settings

- `fxc_variants.tax_status`: `taxable` \| `shipping` \| `none` (doc 03 §4.1). `shipping` means only the shipping on this item is taxed, as in WooCommerce.
- `fxc_variants.tax_class`: a class slug. On variation rows the value `parent` (the default for new variation rows) takes the base row's class. `tax_status` is read from the base row only, as WooCommerce does for variations **[WC]**.
- The product editor shows both on the General tab, and the variation rows show the class select with "Same as parent" first.

#### 7.12.3 Rates and matching

A rate (`fxc_tax_rates`, doc 03 §4.13) has: tax class, country (ISO 3166-1 alpha-2 or empty for all), state (code or empty), postcodes and cities (`fxc_tax_rate_locations`, empty for all), rate (percent with up to 4 decimals), name, priority, compound flag, shipping flag, order.

`TaxLocation` = (country, state, postcode, city), taken from the address chosen by `tax_based_on`, or the store base address (`store_country`, which exists, plus `store_state`, `store_postcode`, `store_city`, added in phase 4.5) for `base` and for `tax_default_location = base` before an address exists. Normalization: country and state upper-case; postcode upper-case without spaces; for US addresses ZIP+4 is cut to the first 5 digits; city upper-case, trimmed.

Matching for one location and one class:

```
1. Candidate rates: class = c AND country IN (location.country, '') AND state IN (location.state, '').
2. Postcode: a rate with postcode rows matches if one row matches:
      exact code, or a prefix ending in * (902* matches 90210), or a numeric range from...to (90210...90299,
      compared as integers; both ends must be digits, low to high, or the rate is refused on save).
3. City: a rate with city rows matches if the normalized city equals one of them.
4. Sort the matches by priority, then specificity (country set, state set, postcode set, city set before
   empty), then rate id. Keep the first match of each priority. [WC] (rate order is only the order of the
   rate table screen and of the CSV export)
5. Result: an ordered list, simple rates first, compound rates after, each in priority order.
```

`flexacommerce.tax.rates` filters the result (doc 04 §7). With a `TaxRateProvider` add-on the provider replaces steps 1 to 5 (§7.12.7).

#### 7.12.4 Calculation

Integer minor units throughout. A rate is stored as millionths of a percent unit (`7.25 %` = `72500`, 100 % = `1000000`), so `tax = amount × rate / 1,000,000`, computed with `Support\IntMath::mul_div_half_up()` (quotient and remainder, no float, no overflow for any `bigint` amount).

```
For each line (product, shipping, fee) with its class and taxable amount (after discount):
  exclusive prices:
     simple rates:   t_i = amount × r_i
     compound rates: t_j = (amount + Σ taxes so far) × r_j
  inclusive prices (the amount already holds the tax):
     taxes are taken back out with the same order; the net amount is amount − Σ t  [WC formula]
  rounding:
     per line (default): each t is rounded half up per line and per rate
     at subtotal: per rate, sum the unrounded line taxes (kept as a numerator over 1,000,000),
                  round the sum once, then split it back to the lines with Money::allocate()
                  (largest remainder), so line taxes still add up to the order tax
```

- **Shipping.** Each shipping line is taxed with the rates of the shipping tax class that have the shipping flag set. With `shipping_tax_class = inherit`: the class of the cart items if they all share one; else Standard if any item uses Standard; else the first class in the `tax_classes` order **[WC]**. Rates whose shipping flag is off are skipped for shipping only. A `ShippingRate` with `taxable = false` (for example local pickup if the admin says so) is never taxed.
- **Status `shipping` items** add to the class choice for shipping but their own amount is not taxed.
- **Fees** (the `flexacommerce.cart.fees` filter, doc 04 §7, not built yet): the `Fee` DTO gets `taxable` (default `true`) and `tax_class` (Standard by default) when fees land, and the engine taxes fee lines like product lines.
- **Exempt customer.** An account marked tax exempt on the Customers screen (user option `fxc_tax_exempt`, store manager only; guests cannot be marked) starts exempt, and `flexacommerce.tax.exempt` (default: that flag) has the last word. Exempt → no tax at all: the lines carry no per-rate split, the order has no `tax` lines, tax 0 and meta `_fxc_tax_exempt = 1` (WooCommerce also writes no tax line for a VAT-exempt customer). With inclusive prices an exempt customer pays the net price (the tax is removed), which is WooCommerce's behavior for a VAT-exempt customer **[WC]**.
- **Determinism.** The same `TaxRequest` gives the same `TaxResult`; checkout calculates twice (form state, submit) and compares totals (§7.5 step 4).

#### 7.12.5 Display

- Catalog prices: the stored price, or the price with or without tax according to `tax_display_shop`, using the store base location (never the session, P11). Price ranges of variable products are converted at both ends. The suffix is appended with its placeholders replaced.
- Cart, checkout, confirmation, account and emails: line prices and subtotal per `tax_display_cart`; then one "Tax" row (`single`) or one row per rate label (`itemized`, rates with the same label are summed, as WooCommerce does **[WC]**). With `excl` display the tax rows come before the total; with `incl` display the total shows "includes X tax".
- Before an address exists and with `tax_default_location = none`, the cart shows "Tax is calculated at checkout" and no tax amount.
- **Phase 4.5 state.** Checkout, confirmation, account, emails and the admin order show the tax rows. Since phase 5 the cart page and the `store/cart` response show it too, for the items only: the cart uses the address it remembers, else `tax_default_location`, and leaves shipping and its tax to checkout (so the cart never reads the shipping zones). Line prices, line totals and the subtotal follow `tax_display_cart`; the response gains `totals.tax`, `totals.total` and a `tax` object (`included`, `pending`, `lines` with `label`, `amount` and the `text` of an "Includes" row). With no location, `tax.pending` is true and the cart says tax is calculated at checkout. The rows reach the cart through a closure that `Plugin` hands to `CartServices`, so Cart still does not depend on Tax or Checkout (`Checkout\CartTaxes`). The cart keeps the city of the quoted address with country, state and postcode (doc 03 §4.9), because city rates need it.
- **Base-location adjustment** (inclusive prices only). When the customer's rate differs from the base rate, WooCommerce keeps the net price and recalculates the tax, so the customer-facing price changes. The net is not rounded before the customer's tax is taken from it (1999 with 7.25 % base and 6.25 % destination gives tax 116, not 117), as in WooCommerce. `flexacommerce.tax.adjust_non_base_prices` (default `true`) keeps that behavior; returning `false` keeps the gross price constant in every location (the EU-style "same price everywhere"). US stores use exclusive prices and are not affected.

#### 7.12.6 Markets

A market is `{ id, name, countries[], overrides{} }`, stored in `tax_markets`. The tax location's country selects the first market that lists it; no match uses the global settings. Overridable keys: `tax_display_shop`, `tax_display_cart`, `price_display_suffix`, `tax_total_display`, `tax_round_at_subtotal`, `tax_based_on`, `intrastate_sourcing`, `origin_states`. A key that is absent inherits the global value. Catalog pages use the market of the store base country, since they never know the visitor's location.

`intrastate_sourcing` (`destination` default, or `origin`) with `origin_states[]`: when the destination state equals the store's base state and that state is in `origin_states`, the store base address is the tax location instead of the customer's. This is how a US store in an origin-based state charges its own local rate on in-state orders while charging destination rates elsewhere.

#### 7.12.7 Extension seams

| Seam | Use | Contract |
|---|---|---|
| `TaxRateProvider` (`flexacommerce.tax.rate_provider`) | Rates from a service (TaxJar, Avalara rates API) while the core engine does the math, rounding, storage and refunds | `rates( TaxLocation, string $class ): list<TaxRate>` (doc 04 §7.1). Called at most once per (location, class) per request; core caches the answer for `HOUR_IN_SECONDS` in the `fxc_tax` cache group keyed by location and class. |
| `TaxCalculator` (`flexacommerce.tax.calculator`) | Replace the whole calculation (product tax codes, per-item exemption certificates) | `calculate( TaxRequest ): TaxResult` (doc 04 §7.1). |
| Presets (`flexacommerce.tax.presets`) | Add a market | `list<TaxPreset>`; core ships `us`. |
| Filters | Exemptions, rate changes, display | `flexacommerce.tax.exempt`, `flexacommerce.tax.rates`, `flexacommerce.tax.adjust_non_base_prices` (doc 04 §7). |

Both interfaces are wrapped like shipping methods: an exception, a timeout (≤ 3 s for remote calls) or an invalid result (wrong currency, negative amount, line keys that do not match the request, line sums that differ from the totals) makes the engine fall back to the table provider. Checkout then still places the order, sets `needs_attention`, writes an order note "Tax calculated from the local rate table: {provider} failed", and logs the error on the `tax` channel. An add-on that must never fall back (`TaxRateProvider::fallback_allowed(): false`) makes checkout refuse with "Tax could not be calculated, please try again" instead.

#### 7.12.8 US market (first preset)

`resources/tax-presets/us.json`, applied from **Taxes > Presets > United States**:

1. The admin picks the **nexus states** (states where the store must collect). Only those states get rows; a sale to any other state has no tax, which is correct for a seller without nexus there.
2. For each picked state the preset inserts one Standard rate at priority 1 with the state base rate, name `"{STATE} State Tax"`, and the shipping flag from the preset's per-state shipping taxability. States with no state sales tax (for example Delaware, Montana, New Hampshire, Oregon; Alaska has only local taxes) get no row. The rows get `source = 'preset:us'`; applying the preset again replaces only rows with that source, after a preview of the changes. Editing a preset row changes its source to `manual`, so a later re-apply leaves it alone.
3. Settings it proposes (shown before apply, each can be unticked): `prices_include_tax = false`, `tax_display_shop = excl`, `tax_display_cart = excl`, `tax_based_on = shipping`, `tax_default_location = none`, `tax_total_display = itemized`, and a `us` market with `intrastate_sourcing = origin` and the preset's `origin_states`.
4. **Local rates** (county, city, special district) are not in the preset: there are thousands of jurisdictions and they change often. The rate table is built for them: one row per jurisdiction at priority 2 (county), 3 (city) and 4 (district), each with its ZIP list or ZIP ranges, so the engine stacks state + county + city + district for one address. They come from a WooCommerce-format CSV (a rate vendor's ZIP file or the store's own) or from a `TaxRateProvider` add-on.
5. Product taxability by category (for example clothing or groceries exempt or reduced in some states) uses tax classes: a "Clothing" class with a 0 % or reduced row for those states.
6. The preset file has an `as_of` date and a source per state. **[Assumption]** The state rates, shipping taxability and origin-state list are checked against each state's revenue department when the file is written (phase 4.5) and on each release. The screen shows the `as_of` date and states that the store owner is responsible for the rates. FlexaCommerce never changes rates by itself.

The US case drives two design limits: a ZIP-level table for a store with nexus in several states can hold tens of thousands of location rows, so matching is one indexed query per location (doc 03 §4.13), and CSV import runs in batches of 500 rows, never in one request. The admin screen parses the file and sends the batches one after another (validate pass first, then write); the server refuses a batch over 500 rows.

#### 7.12.9 CSV import and export, migration, parity tests

- **CSV** columns in WooCommerce's order: `Country code, State code, Postcode / ZIP, City, Rate %, Tax name, Priority, Compound, Shipping, Tax class`. Postcodes and cities are `;`-separated. A file exported from WooCommerce imports unchanged; an exported FlexaCommerce file imports into WooCommerce. Import modes: add, or replace the rates of the selected class. Errors are reported per line, and nothing is written when the file has an error in validate-only mode.
- **Migration** (doc 05 §7): WooCommerce tax classes, rates with locations, the tax settings above and product `_tax_status` / `_tax_class` are migrated in both directions.
- **Golden parity tests** (phase 4.5 exit): a fixture set of carts (classes, compound, shipping flag, inclusive and exclusive, both rounding modes, US stacked rates by ZIP, a ZIP range, ZIP+4, exempt customer) is priced by WooCommerce on the bench stack and the expected totals per rate are stored in `tests/fixtures/tax-golden.json`; the integration suite asserts FlexaCommerce produces the same amounts to the minor unit. A difference is either fixed or listed as an intended divergence in this section.
- **Golden results (2026-10-25, WooCommerce 11.2.1).** 20 cases; `./bench.sh tax-golden` (in `tools/bench`) prices them on the `tax.bench` store through a real cart and `WC_Checkout::create_order()` and writes the expected amounts into the fixture; `tests/Integration/Tax/TaxGoldenTest.php` places the same carts through `CheckoutService::place_order()` and compares total, tax total, shipping tax, items and shipping tax per rate, and net and tax per line. 19 match to the minor unit. Two differences found by the first run were fixed: the base-location adjustment rounded the net before taxing it, and an exempt order stored a `tax` line of 0 per rate.
- **Intended divergences.**
  - *ZIP+4.* WooCommerce normalizes `95014-1234` to `950141234`, so only wildcard rows such as `950*` match it and exact ZIPs and ZIP ranges do not. FlexaCommerce cuts a US ZIP+4 to its first five digits (§7.12.3), so the address gets the same rates as `95014`. The test asserts FlexaCommerce's totals for this case and fails if WooCommerce ever starts to agree.
- **Not covered by the golden set.** Fees (no fee API yet), coupons (§7.13; covered by `tests/Integration/Coupons`, not yet priced against WooCommerce), `tax_status = shipping` items, markets and origin sourcing (FlexaCommerce only), and the form total with `tax_based_on = billing`: the checkout form is quoted for the shipping address, so the shopper sees the billing-based total only when the order is placed (a known limit of the phase 4 requote, which sends one address).

#### 7.12.10 Orders and refunds

- Each product, shipping and fee line stores `tax_total` (sum) and its per-rate split in item `meta.taxes` (`{"<rate_id>": amount}`).
- One `tax` line per rate: `code` = rate code (`{COUNTRY}-{STATE}-{NAME}-{priority}`, as WooCommerce builds it **[WC]**), `name` = label, `total` = items tax + shipping tax for that rate, `meta` = `{rate_id, rate, compound, items_tax, shipping_tax}`. A snapshot: later rate edits never change an order.
- Invariant asserted on insert: `orders.tax_total = Σ tax lines.total = Σ other lines.tax_total`.
- `orders.prices_include_tax` records the setting at order time. Line amounts stay exclusive of tax (doc 03 §4.3).
- Refunds: a line refund amount is gross, as today (`refunded_total`). The engine splits it into net and tax per rate in the line's proportions with `Money::allocate()`; the split is stored in the refund transaction meta (`taxes`), so a tax report can subtract refunded tax per rate. A refund of the whole line refunds exactly the line's tax.

### 7.13 Coupons

Discount codes the shopper enters in the cart. Module `Coupons` (§3.2), tables `fxc_coupons` and `fxc_coupon_uses` (doc 03 §4.14), decision ADR-021 (doc 08).

#### 7.13.1 Types and rules

| Type | `amount` | Effect on each eligible line |
|---|---|---|
| `percent` | hundredths of a percent, 1 to 10000 (10000 = 100 %) | `left × amount / 10000` |
| `fixed_cart` | minor units | the amount split over the eligible lines in proportion to what is left of each (`Money::allocate()`), capped at what is left |
| `fixed_product` | minor units, per unit | `min(left, amount × qty)` |
| `buy_x_get_y` | hundredths of a percent, 1 to 10000 | on the units it discounts (below): `left × n / qty × amount / 10000` |

- **Item limit.** `item_limit` (0 = none) caps the units a `percent` or `fixed_product` coupon discounts, as WooCommerce's "Limit usage to X items". Units are counted from the line with the highest unit price before coupons down (equal prices keep the cart order). A line counted for `n` of its `qty` units is discounted on `left × n / qty` instead of `left`, and a `fixed_product` coupon gives `amount × n` at most. A `fixed_cart` coupon has no item limit; the editor stores 0 for it.

- **Buy X get Y.** `buy_qty` (X) and `get_qty` (Y), each 1 to 999, make one set; `repeat_limit` caps the sets per cart (0 = none). The lines that count as bought are the eligible lines below. The lines a set may discount are chosen by `get_product_ids`, `get_variant_ids` and `get_category_ids` when any is set, else they are the same eligible lines; the exclude lists and `exclude_sale_items` apply to both sides. Sets are formed greedily: each takes Y units from the cheapest lines it may discount, then X units from the dearest lines left that count as bought, and stops when either side falls short. A unit is used once, so with the same lines on both sides "buy 2 get 1" discounts the cheapest unit of every three. A line with `n` of its `qty` units discounted gets `amount` percent of `left × n / qty`. With no complete set the code is refused with its reason. Other types store 0 and empty lists for these fields, and a buy X get Y coupon has no item limit.

- **Eligibility.** `product_ids` and `exclude_product_ids` hold product post ids; a product covers all its variations. `variant_ids` and `exclude_variant_ids` hold variant ids and match that variation only. The two kinds stay in separate lists because a post id and a variant id can be the same number. A line is in when it matches either "only these" list (or both are empty) and in neither exclude list. `category_ids` and `exclude_category_ids` match the product's categories and their parents, so a rule on "Clothing" covers "Clothing > Shirts". `exclude_sale_items` skips lines whose unit price is reduced. A line with no current price (a product that left the catalog) is never eligible. Category terms are read only when a coupon has a category rule.
- **Conditions** checked whenever the cart is priced: `status = active`, `starts_at ≤ now < expires_at` (both optional), `min_subtotal ≤ subtotal ≤ max_subtotal` (both optional, on the subtotal before coupons), `usage_count < usage_limit` (0 = no limit), the per-customer limit for a logged-in shopper, and at least one eligible line. A disabled code reads as unknown to the shopper.
- **Free shipping.** A coupon with `free_shipping` set also turns on the `free_shipping` methods that ask for a coupon (§7.13.8). Such a coupon may have an amount of 0; any other coupon needs an amount above 0.
- **Checked at checkout only:** allowed emails (`emails`, `*` wildcards, case-insensitive) against the billing email or the account email, and the per-customer limit counted by user id or billing email (lower-cased).

#### 7.13.2 Order of application

Codes apply in the order the shopper added them, each to what the earlier ones left of every line. With a 2000 cart, `TEN` (10 %) then `FIVE` (500 fixed) takes 200 then 500, total 700; in the other order it takes 500 then 150, total 650. The cart page and the admin screen state this. Automatic discounts follow the codes (§7.13.9). This matches WooCommerce's sequential mode; FlexaCommerce has no "apply all to the full price" mode.

- `individual_use`: adding such a code replaces the codes already in the cart; adding another code while one is in the cart is refused.
- At most 10 codes per cart. A code is stored in the cart normalized (trimmed, lower-cased); the shopper sees the code as saved by the admin.

#### 7.13.3 Codes that stop applying

A code is checked when it is added (and refused with the reason) and again on every pricing. When it no longer applies (the cart fell under the minimum, the code expired or was disabled) it stays in the cart in `Discounts::rejected` with its message: the cart shows the row with the message and a remove button, and the discount is 0. Checkout refuses while a rejected code is in the cart (`InvalidCheckout`, field `coupon`), so the shopper never pays a different total than the one they expected without seeing why. A code that applies again (the admin cleared the minimum) applies at the next pricing. Removing an unknown code is `unknown_coupon`. On login, guest codes merge into the account cart after its own codes.

#### 7.13.4 Taxes

- **Exclusive prices:** tax is computed on the discounted line (`CartTaxes::request()` passes `line_discount()` per line), so a 10 % coupon on a taxed line also lowers its tax by 10 %.
- **Inclusive prices:** the discount comes off the gross price, and the engine splits what is left into net and tax. With a 10 % tax rate, a 2200 gross cart and a 10 % coupon pay 1980 for the items, of which 180 is tax.
- **Shown discount:** with `tax_display_cart = incl` the discount row includes tax (220 above); with `excl` it is the net (200). The subtotal row shows prices before coupons; the discount row is the difference to what the lines cost after coupons, so the rows always add up to the total. The split of the shown discount per code uses `Money::allocate()` over each code's net share.
- Shipping is never discounted.

#### 7.13.5 Orders: redeem and release

- Product lines store `discount_total` (net) and `total = subtotal - discount_total`; their taxes are on the discounted amount.
- One `discount` line per applied code: `name` and `code` = the code, `total` = its net share, `meta` = `{coupon_id, type, amount, discount_tax}`, plus `free_shipping: true` for a free-shipping coupon (its line can have a total of 0) and `automatic: true` for an automatic discount (§7.13.9). A snapshot: editing or deleting the coupon never changes an order.
- Invariant asserted on insert: `orders.discount_total = Σ product lines.discount_total = Σ discount lines.total` (the last only when the order has discount lines).
- **Redeem** runs inside the `place_order()` transaction after the order row exists: for each code, `take_use()` is one `UPDATE … SET usage_count = usage_count + 1 WHERE id = ? AND status = 'active' AND (usage_limit = 0 OR usage_count < usage_limit)`, which locks the row; the per-customer count read after it is exact; then a `coupon_uses` row is inserted. A failed step throws `InvalidCheckout` and the whole order rolls back, so two shoppers racing for the last use get one order and one clear message.
- **Release:** when an order moves to `cancelled`, `OrderService` calls the release closure in the same transaction: the order's `coupon_uses` rows are deleted (`FOR UPDATE`) and each coupon's `usage_count` goes down by one. Refunds do not release a use (WooCommerce does the same).

#### 7.13.6 Cost when unused

A cart with no codes, in a store with no active automatic discount (§7.13.9), builds no coupon service and runs no coupon query: `CartState::$discounts` is `null`, and the public cart and checkout responses have no `coupons` field and no `totals.discount`. The same holds when automatic discounts exist but none applies and none is close. The performance budgets (doc 10) are unchanged. With codes, the coupons are read once per request (cached by code), plus term reads only for category rules.

#### 7.13.7 Surfaces and hooks

- Store REST: `POST store/cart/coupons` `{code}` (201, the cart), `DELETE store/cart/coupons/{code}` (200, the cart). Both need the cart nonce (doc 06 §2.3). Refusals are 400 (`invalid_cart_request`) with `data.field = code` and `data.reason` (`coupon_empty`, `coupon_applied`, `coupon_invalid`, `coupon_limit`).
- No-JS form: `fxc_op=coupon` with `coupon_code` or `remove_coupon`, redirect with a result code (`coupon_applied`, `coupon_removed`, `coupon_invalid`, `coupon_empty`, `coupon_duplicate`). Without JS the message is a fixed text per result code, not the exact reason.
- Admin REST: `admin/coupons` (GET list with `search`, `status`, `page`, `per_page` and `X-WP-Total` / `X-WP-TotalPages`; POST create, 201) and `admin/coupons/{id}` (GET, PATCH partial, DELETE), all gated by the manage capability. Validation errors are 400 with `data.field`; an unknown id is 404.
- Admin screen: **FlexaCommerce > Promotions**, tab Coupons (the old `flexacommerce-coupons` page link redirects there). The buy X get Y type shows its own fields (X, Y, sets per order, and the Y products and categories). Products and categories are chosen with a picker: search by name or SKU, several picks, each variation listed under its product. `GET admin/coupons/targets` (manage capability) serves it: `kind` (`product` or `category`) with `search` returns up to 20 products or categories (products followed by their variations, at most 60 rows); `product_ids`, `variant_ids` and `category_ids` instead return the names of those ids, leaving out ones that no longer exist. Rows are `{type, id, label, sku}` with `type` `product`, `variant` or `category`. A product found by the SKU of one variation lists that variation only.
- Hooks: `flexacommerce.coupon.saved` (`Coupon $coupon, bool $created`) and `flexacommerce.coupon.deleted` (`Coupon $coupon`), doc 04 §7.
- Migration: WooCommerce coupons are migrated in both directions by the `coupons` stage (doc 05 §5.8), and the order export carries the `discount` lines.

#### 7.13.8 Free shipping

- `Discounts::free_shipping()` is true when an applied coupon has `free_shipping`. `CartService::package()` passes it to `ShippingPackage::$free_shipping` (doc 04 §4), and it is part of the package hash, so a cached quote does not outlive the code.
- The `free_shipping` method has a `requires` setting, the same choices as WooCommerce: empty (the minimum amount when one is set, the behaviour before the setting existed), `coupon`, `either` (a coupon or the minimum) and `both` (a coupon and the minimum). The minimum is compared with the contents total after coupons, as WooCommerce does by default. The `ignore_discounts` setting (off by default, WooCommerce's "Apply minimum order rule before coupon discount") compares it with the subtotal before coupons instead; `ShippingPackage::subtotal_before_discounts()` gives that amount, and it is part of the package hash.
- A coupon that only gives free shipping still counts as used when the order is placed, and its rule checks (spend, dates, limits, products) apply as for any code. Its row in the cart, at checkout and on the confirmation page reads "Free shipping" instead of an amount; a coupon with an amount and free shipping shows the amount.
- Free shipping comes from the method, never from the coupon: a store with no `free_shipping` method that asks for a coupon gives no free shipping, whatever the code says. The coupon form says so.

#### 7.13.9 Automatic discounts

A coupon with `automatic` set (ADR-026) applies to every cart it fits, with no code to type. The admin picks the method at the top of the coupon form (Discount code or Automatic discount; Promotions also has an Add automatic discount button). Its `code` becomes the name shoppers see; allowed emails and the per-customer limit are hidden in the form and stored empty and 0.

- **Order.** After the shopper's codes, the active automatic coupons in id order, each on what the earlier ones left. One that does not fit drops out without a notice. An individual-use code keeps them all out; an individual-use automatic coupon applies only when nothing else did.
- **Shown as** "Discount: <name>" in the cart, at checkout and on the confirmation page, with no remove button. Its order line is a normal `discount` line and it takes a use at checkout like a code. Typing the name as a code gives the unknown-code message.
- **Nudge.** `next_discount` in the cart response (`{label, message, remaining, items, percent}`, or `null`) names the automatic coupon closest to applying when only its minimum spend is missing. Without one, it names the automatic buy X get Y coupon that needs the fewest more units for its first set (ADR-027); then `remaining` is null and `items` is the count. The cart shows `message` ("Spend $30.00 more for this discount: Big basket.", "Add 1 more item for this discount: Buy 2, get 1 free.") above the totals, with a bar that is decoration only.
- **Buy X get Y offers.** An automatic discount of type buy X get Y is the "buy 2, get 1 free" offer. The form's buy X get Y section has quick-fill buttons for the common offers.
- **Cost.** `flexacommerce_automatic_coupons`, an autoloaded flag, is 1 while an active automatic coupon exists. With it off, a cart without codes costs nothing (§7.13.6). With it on, the cart reads the automatic coupons once per request.

#### 7.13.10 Conditions

A coupon can add conditions (ADR-041) under Conditions in its form: items in the cart, cart weight, country, shipping method, payment method, other coupons in the cart, spend before, orders before and products bought before, all of them or any one. A condition that fails refuses the code and says which one. One that waits on a checkout choice (address, shipping or payment method) keeps the code in the cart with "applies once you choose a payment method" in `Discounts::waiting`; it gives no discount yet and checkout does not refuse it. Checkout prices with the live choices, and placing the order checks again with every unknown counted as a fail (`InvalidCheckout`, field `coupon`). An automatic discount applies only when its conditions are met.

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
	public function totals( array $lines, ?ShippingRate $shipping, TaxResult $tax ): Totals;
}

final class TaxService {
	public function enabled(): bool;
	public function location( ?TaxLocation $shipping, ?TaxLocation $billing ): ?TaxLocation;  // tax_based_on, markets, sourcing
	public function calculate( TaxRequest $request ): TaxOutcome;     // TaxResult + fallback note; engine or add-on, guarded (§7.12.7)
	public function display_price( Money $price, string $tax_class, string $context ): Money; // shop|cart, base rates
	public function compile_base_rates(): void;                       // tax_base_rates, on settings and rate changes
}
// Refund splits need only the stored line, so they live on the order item:
// OrderItem::refund_split( int $amount ): array{net:int, taxes:array<string,int>}

interface TaxRateRepository {
	/** @return list<TaxRate> */
	public function matching( TaxLocation $location, string $tax_class ): array;   // one query, §7.12.3
	public function save( TaxRate $rate ): int;
	public function delete( int $rate_id ): void;
	public function replace_source( string $source, array $rates ): int;          // preset re-apply
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
| CheckoutService | CartService, PricingService, ShippingService, TaxService, StockService (via OrderService::create), OrderService, PaymentService | repositories of other modules directly |
| PaymentService | gateways, TransactionRepository, OrderService | StockService, CartService |
| OrderService | OrderRepository, HistoryRepository, StockService (release/deduct for transitions), AfterCommit | PaymentService, gateways, mailer |
| CartService | Catalog repositories (read), PricingService, StockService::available, ShippingService, TaxService | OrderService |
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
| Admin menus | Top-level **FlexaCommerce**, grouped by task: Status (system report + logs), Orders, Customers, Products (CPT list), Categories, Tags, Attributes (with swatches, §7.2.1), Product options (ADR-025), Promotions (tabs: Coupons, which also holds automatic discounts (ADR-026); pricing rules and badges to follow), Settings (General, Storefront, Payments, Shipping, Tax, Emails), Migration (only if WooCommerce active or a job exists). A page that moved keeps its old link: `Admin\Menu::MOVED` redirects it. |
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
