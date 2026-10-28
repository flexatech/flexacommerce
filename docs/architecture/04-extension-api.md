# 04. Extension API

Status: **Approved 2026-10-24**. Everything in this document is a public promise once 1.0 ships. Anything not listed here (services, repositories, value object constructors, internal hooks) is private and may change in any release.

## 1. Principles

1. **Small surface, real users.** A contract is published only when a core feature uses it. COD, bank transfer, flat rate, free shipping and local pickup are written against the same interfaces as third-party code, so the contracts are exercised from day one.
2. **Interfaces and DTOs, not service internals.** Extensions get `Flexa\Commerce\Contracts\*` types. They never receive a repository or `$wpdb` handle.
3. **Core owns state changes.** Gateways report what happened (`PaymentResult`, `PaymentEvent`). Core decides what that means for the transaction and the order. A gateway cannot set an order status directly.
4. **Hooks are dot-separated and prefixed** `flexacommerce.` (house style). Filters always receive and return a typed value documented here. Return values of the wrong type are discarded with a `_doing_it_wrong()` notice and the unfiltered value is used.
5. **No WooCommerce hook emulation.** `woocommerce_*` hooks are not fired. A compatibility layer is a non-goal (doc 01 §8).

## 2. Registration

```php
add_action( 'flexacommerce.loaded', static function ( \Flexa\Commerce\Contracts\Extensions $ext ): void {
	$ext->require_api( '1.0' );                 // throws IncompatibleApi, see §11

	$ext->payment_gateways()->register( new Acme\Pay\Gateway() );
	$ext->shipping_methods()->register( 'acme_freight', static fn () => new Acme\Ship\Freight() );
} );
```

- `flexacommerce.loaded` fires on `plugins_loaded` priority 20, after core registered its own gateways and methods. Extensions loaded before FlexaCommerce simply attach the listener; extensions that load later should check `did_action( 'flexacommerce.loaded' )` and use `flexacommerce()->extensions()` (global accessor defined in the bootstrap).
- The argument is the `Contracts\Extensions` facade. The internal `Services` container is never exposed (doc 02 §4.2, ADR-017).

```php
interface Extensions {
	public function api_version(): string;                       // "1.0"
	public function require_api( string $min ): void;
	public function payment_gateways(): GatewayRegistry;
	public function shipping_methods(): ShippingMethodRegistry;
	public function money(): MoneyFactory;                        // from_minor, from_decimal_string, format
	public function orders(): OrderReader;                        // read-only, §5.3
	public function catalog(): CatalogReader;                     // read-only product/variant lookups
	public function settings( string $extension_id ): ExtensionSettings; // namespaced option storage
	public function logger( string $channel ): Logger;           // PSR-3 shaped, writes to Status > Logs
}

interface GatewayRegistry {
	public function register( PaymentGateway $gateway ): void;   // duplicate id → DuplicateRegistration
	public function get( string $id ): ?PaymentGateway;
	/** @return array<string,PaymentGateway> */
	public function all(): array;
}

interface ShippingMethodRegistry {
	/** @param callable():ShippingMethod $factory  instantiated lazily, per zone instance */
	public function register( string $type, callable $factory ): void;
}
```

Ids: `[a-z0-9_]{1,64}`, globally unique. Core reserves `cod`, `bacs`, `flat_rate`, `free_shipping`, `local_pickup` and anything prefixed `fxc_`. Registration after `init` priority 0 is rejected with `_doing_it_wrong()` because gateway lists are needed by REST routes and blocks.

## 3. Payment gateway contract

```php
namespace Flexa\Commerce\Contracts\Payments;

interface PaymentGateway {
	public function id(): string;
	public function title(): string;                                  // admin label
	public function supports( string $feature ): bool;               // §3.2
	/** @return list<SettingField> */
	public function settings_fields(): array;                         // rendered by core settings screen
	public function is_available( Checkout $checkout ): bool;        // currency, country, min/max amount
	public function checkout_fields( Checkout $checkout ): CheckoutFieldsDescriptor; // description, extra fields, script module handle
	public function validate( Checkout $checkout, array $gateway_input ): ValidationResult;
	public function process_payment( PaymentRequest $request ): PaymentResult;
	public function verify_return( ReturnRequest $request ): ?PaymentEvent;   // null = unknown, wait for webhook
	/** @return list<PaymentEvent> */
	public function parse_webhook( \WP_REST_Request $request ): array;        // throws InvalidSignature
	public function refund( RefundRequest $request ): RefundResult;           // only if supports('refunds')
	/** @param array<string,mixed> $data  @return array<string,mixed> */
	public function redact( array $data ): array;                             // before anything is stored or logged
}
```

### 3.1 DTOs

| DTO | Fields (all readonly) |
|---|---|
| `PaymentRequest` | `transaction_id`, `idempotency_key`, `order` (`OrderView`), `amount` (`Money`), `return_url`, `cancel_url`, `webhook_url`, `gateway_input` (sanitized array from `validate`) |
| `PaymentResult` | factory methods: `redirect( url, ?reference )`, `succeeded( reference )`, `pending( ?reference, ?instructions )`, `authorized( reference )`, `requires_action( payload, ?reference )`, `failed( code, message )` |
| `PaymentEvent` | `event_id`, `type` (`succeeded`, `failed`, `authorized`, `cancelled`, `refund_succeeded`, `refund_failed`), `transaction_ref` (our transaction id or gateway reference), `amount` (`Money`), `occurred_at`, `raw` (redacted array) |
| `RefundRequest` | `refund_transaction_id`, `payment_reference`, `amount`, `reason`, `idempotency_key` |
| `RefundResult` | `succeeded( reference )`, `pending( reference )`, `failed( code, message )` |
| `CheckoutFields` | `description`, `fields` (list of `SettingField`), `script_module` (URL of an ES module), `data` (public values for the module, rendered as `data-fxc-config`; never a secret) |

Core rules applied to gateway output (doc 02 §7.7 and §7.8):
- An event amount different from the transaction amount marks the order `needs_attention` and does **not** mark it paid.
- Currency mismatch is rejected.
- Terminal transactions never change.
- `verify_return()` may return a `succeeded` event only if `supports('signed_returns')` is true. Otherwise core treats returns as "pending confirmation".

### 3.2 Feature flags

`refunds`, `partial_refunds`, `webhooks`, `signed_returns`, `authorize_capture` (post-MVP UI), `tokenization` (post-MVP). Unknown flags return false.

### 3.3 Core gateways

| Gateway | Behavior |
|---|---|
| `cod` | `process_payment` returns `pending(null)`. Order goes to `processing` (stock held, paid later). Admin marks paid with "Record payment" (manual transaction). Optional: enabled shipping methods filter. |
| `bacs` | Returns `pending(null, instructions)`. Order goes to `on-hold`. Bank details shown on confirmation and email. Admin records payment. |
| `stripe` | ADR-052. The Payment Element makes a payment method in the browser, `process_payment` confirms a PaymentIntent with it; 3-D Secure returns `requires_action` and ends on the return URL, which `verify_return` checks with the secret key. Without a payment method (scripts off, order-pay page) it returns `redirect` to a Stripe Checkout Session. Refunds by PaymentIntent, or by charge for orders imported from the WooCommerce Stripe extension. Saving the keys checks the account, creates the webhook endpoint and registers the domain for Apple Pay. Apple Pay and Google Pay buttons on the product page, the cart and the checkout (ADR-053). |
| `paypal` | ADR-054. PayPal's buttons stand in for the place button: a click checks the form (`fxc:place`), places the order with `flow=popup`, and `process_payment` returns `requires_action` with the PayPal order id that PayPal's window opens. Without scripts it returns `redirect` to PayPal's approval page. `verify_return` captures an approved order with the secret; webhooks are checked by PayPal (`verify-webhook-signature`). Refunds by capture, also for orders imported from WooCommerce PayPal Payments. Saving the credentials gets a token and creates the webhook. PayPal buttons on the product page, the cart and the checkout, where the shopper picks the address on PayPal (`ExpressSession`). |

### 3.4 Gateway security obligations (documented for authors, enforced where possible)

- Verify webhook signatures in `parse_webhook()` with a constant-time comparison. Core provides `Contracts\Security\Signature::hmac_sha256_equals()`.
- Never return card data, CVC or full bank account numbers in `raw` or `data`. `redact()` runs before every write. Core additionally drops keys matching `/card|cvc|cvv|pan|secret|password|iban/i` as a safety net.
- Secrets go into `settings_fields()` with `type: secret`, stored in the gateway option, never sent to the browser or REST responses (masked as `••••last4`).

### 3.5 Checkout scripts

A gateway with a `script_module` gets it loaded as an ES module on the checkout, once for each method box `[data-fxc-method="{id}"]`, which carries `data-fxc-config` with the JSON of `CheckoutFields::data`. The module talks to checkout.js through two events on the form, both with `detail.wait( promise )`:

- `fxc:payment` (`detail.method`) fires when the shopper places the order, before the request. A module for that method puts what it made into hidden `gateway_input[{id}][...]` inputs inside its box. A rejected promise stops the submit and shows its `message` at the payment methods.
- `fxc:payment-action` (`detail.method`, `detail.payload`) fires when the result is `requires_action`. The payload is what the gateway put in `PaymentResult::requires_action()`; it is sent to the browser once and never stored. When the promise settles, checkout.js goes to the redirect, which is the return URL, so `verify_return()` decides.

A module whose own button places the order dispatches `fxc:place` on the form: with `detail.check` it only checks the form (`detail.ok`); without it checkout.js also submits and `detail.placed` says whether the request went out (PayPal, ADR-054).

checkout.js does not render the method boxes again on a requote, so a mounted element stays. A gateway whose module fails to load should still work without it (Stripe falls back to a hosted page).

An internal `Payments\ExpressGateway` interface (core gateways only, ADR-053) adds wallet buttons: `express_config( place )` for `product`, `cart` or `checkout` returns the public config and the `script` that draws them, or null. The script gets the box `[data-fxc-express]` with that config, the store route `store/express` for quotes and `store/express/order` to place the order, with the PaymentMethod in `gateway_input`. Each gateway gets its own row in the box. A gateway whose sheet keeps its own order open (PayPal, ADR-054) also implements `Payments\ExpressSession`: `store/express/session` opens it (`open_express( quote )`) or brings it to the new address and rate (`update_express`), and `store/express/order` takes the payer and address from the gateway (`express_contact( session )`) instead of the browser.

An internal `Payments\ConnectedGateway` interface (core gateways only, not part of the contract) adds `connect( values, before )`, run when the settings are saved: it may add to the values or throw `InvalidArgumentException`, which the admin endpoint answers with 400 and nothing is saved; and `notes()`, shown on the gateway's card.

## 4. Shipping method contract

```php
namespace Flexa\Commerce\Contracts\Shipping;

interface ShippingMethod {
	public function type(): string;                      // "flat_rate"
	public function title(): string;
	/** @return list<SettingField> */
	public function settings_fields(): array;            // per zone instance
	/** @param array<string,mixed> $settings  @return list<ShippingRate> */
	public function rates( ShippingPackage $package, array $settings ): array;
}
```

- `ShippingPackage`: destination (`country`, `state`, `postcode`, `city`), lines (`variant_id`, `qty`, `weight_g`, `dimensions_mm`, `line_total` Money), `contents_total`, `currency`, `free_shipping` (bool, true when the cart has a coupon that gives free shipping; an optional constructor argument, default `false`), `subtotal` (optional `?Money`, the contents total before coupons, set by the cart only when it has coupons; read it through `subtotal_before_discounts()`, which falls back to `contents_total`).
- `ShippingRate`: `id` (`{type}:{instance_id}[:{suffix}]`), `label`, `cost` (Money), `meta` (array of display strings), `taxable` (bool, default `true`; `false` means the tax engine never taxes this rate, doc 02 §7.12.4).
- `rates()` must be fast and side-effect free. It may be called on every cart read. Methods that call remote APIs must cache per package hash and time out (≤ 3 s). Core wraps the call: an exception or timeout removes that method's rates and logs it, so one failing carrier never breaks checkout.
- Core methods: `flat_rate` (cost per order or per item), `free_shipping` (optional minimum contents total, a free-shipping coupon, or both, doc 02 §7.13.8), `local_pickup` (no address required, configurable label).

## 5. Order lifecycle hooks

### 5.1 Actions (all fire after commit through AfterCommit)

| Hook | Arguments | When |
|---|---|---|
| `flexacommerce.order.created` | `OrderView $order` | Checkout or admin creation (not migration) |
| `flexacommerce.order.status_changed` | `OrderView $order, string $from, string $to, string $actor_type` | Every transition |
| `flexacommerce.order.status.{to}` | `OrderView $order, string $from` | Convenience per target status |
| `flexacommerce.order.paid` | `OrderView $order, TransactionView $txn` | `payment_status` becomes `paid` |
| `flexacommerce.order.refunded` | `OrderView $order, TransactionView $refund` | refund succeeded |
| `flexacommerce.order.needs_attention` | `OrderView $order, string $reason` | flag set |
| `flexacommerce.order.note_added` | `OrderView $order, int $history_id, bool $customer_visible` | |
| `flexacommerce.stock.changed` | `int $variant_id, int $old, int $new, string $reason` | any stock mutation |
| `flexacommerce.stock.low` / `.out` | `int $variant_id, int $qty` | threshold crossed |
| `flexacommerce.migration.entity_imported` | `string $entity, string $remote_id, int $local_id, string $direction` | per entity, migration only |

Because these fire after commit, a listener that throws cannot roll back the order. Exceptions are caught per listener, logged, and do not stop other listeners.

### 5.2 Filters (inside the transaction, must be pure)

| Filter | Value | Notes |
|---|---|---|
| `flexacommerce.order.can_transition` | `bool $allowed, string $from, string $to, OrderView $order` | May only **forbid** (true → false). Turning a forbidden transition into an allowed one is ignored. |
| `flexacommerce.order.initial_status` | `string $status, OrderView $order, string $gateway_id` | Restricted to `pending`, `on-hold`, `processing`. |
| `flexacommerce.order.number` | `string $formatted, int $sequence_value` | Display format only. Must remain unique (DB enforced). |
| `flexacommerce.order.pending_hold_minutes` | `int` | Per order override for expiry. |

### 5.3 Reading orders

`OrderReader::find( int $id ): ?OrderView`, `find_by_number()`, `search( OrderQuery )`. `OrderView` is an immutable read model (items, addresses, totals as Money, history ids). Extension metadata: `OrderMeta::get( order_id, key )`, `set()`, `delete()` (writes `fxc_order_meta`, key must not start with `_fxc_`, value string or JSON-serializable array).

## 6. Product types

[Decision] No public product-type registration in 1.0 (doc 01 §6). Internally `Catalog\ProductTypes` is a registry with `simple` and `variable`. It will be published as `Contracts\Catalog\ProductType` once a second real consumer exists (likely grouped or digital). Until then extensions can attach data to products with post meta and to variants through `VariantMeta` (JSON `meta` column, namespaced keys).

## 7. Catalog, pricing and cart hooks

| Filter | Value / args | Notes |
|---|---|---|
| `flexacommerce.price.unit` | `Money $price, VariantView $variant, PriceContext $ctx` | `ctx` has `user_id`, `quantity`, `at`. Result clamped to ≥ 0, currency must match. Called on cart read and inside checkout, so it must be deterministic for the same input. Cached per request. |
| `flexacommerce.price.display_html` | `string $html, VariantView $variant, PriceResolution $price` | Presentation only. |
| `flexacommerce.cart.validate_add` | `ValidationResult $result, int $variant_id, int $qty, CartView $cart` | Can add errors (min/max quantity rules). |
| `flexacommerce.cart.line_max_quantity` | `int $max, VariantView $variant` | |
| `flexacommerce.cart.fees` | `list<Fee> $fees, CartView $cart` | Fees become `fee` order lines. |
| `flexacommerce.checkout.fields` | `FieldSchema $schema, string $country, string $type` | Add, remove or require fields of the billing or shipping address. Runs after the checkout layout (doc 11), so it sees the merchant's order, labels and custom fields. Custom fields whose cart conditions fail are already left out; a field limited to some countries is in the schema with `CheckoutField::$countries` set, and `shown_in( $country )` says whether it applies (doc 11 §11). Values of extra fields go to `fxc_order_meta`. |
| `flexacommerce.checkout.additional_fields` | `FieldSchema $schema` (type `additional`) | The same for Additional information: the order notes and the merchant's own questions, with the same conditions. Values go to `_additional_{id}`. |
| `flexacommerce.checkout.layout` | `CheckoutLayout $layout` | The normalised builder layout, once per request, before the schemas are built. It also runs on the layout of a preview (`?fxc_preview=`, doc 11 §10), so an extension sees what the page will show. |
| `flexacommerce.checkout.validate` | `ValidationResult $result, CheckoutView $checkout` | |
| `flexacommerce.shipping.rates` | `list<ShippingRate> $rates, ShippingPackage $package` | Reorder, hide, rename. |
| `flexacommerce.product.is_purchasable` | `bool, VariantView $variant` | May only forbid. |
| `flexacommerce.tax.calculator` | `?TaxCalculator $calc` (default `null`: the core engine) | Replace the whole tax calculation (§7.1). **Experimental** in 1.0 (§10). |
| `flexacommerce.tax.rate_provider` | `TaxRateProvider $provider` (default: the rate table) | Rates from a service; core still calculates (§7.1). **Experimental** in 1.0. |
| `flexacommerce.tax.rates` | `list<TaxRate> $rates, TaxLocation $location, string $tax_class` | Change or drop matched rates. Must be deterministic. |
| `flexacommerce.tax.exempt` | `bool $exempt, TaxRequest $request` | Return `true` for an exempt customer (resale certificate, non-profit). Default `false`. |
| `flexacommerce.tax.adjust_non_base_prices` | `bool $adjust` (default `true`) | Inclusive prices only: `false` keeps the gross price the same in every location (doc 02 §7.12.5). |
| `flexacommerce.tax.presets` | `list<TaxPreset> $presets` | Add a market preset next to the core `us` preset. |

Rule: filters that affect money run on the server only. The browser never receives a price it can send back.

### Checkout layout action

| Action | Args | Notes |
|---|---|---|
| `flexacommerce.checkout.layout.saved` | `CheckoutLayout $layout` | After the builder saves or resets the layout (`CheckoutLayout::save()`, `reset()`). |

The builder previews an unsaved layout through `POST flexacommerce/v1/admin/checkout-layout/preview`; the checkout reads `fxc_preview` (a nonce) only for its author. `CheckoutLayout::previewing()` tells an extension that the page shows a draft, where Place order is disabled.

Custom checkout answers are order meta `_{section}_{id}`; `_fxc_fields` (reserved) keeps the label, the value and the email switch of each one as placed. Read them with `Orders\OrderFields::for_order()`.

### 7.0 Coupon actions

| Action | Args | Notes |
|---|---|---|
| `flexacommerce.coupon.saved` | `Coupon $coupon, bool $created` | After the admin creates or edits a coupon (REST or `CouponEditor::save()`). `$created` is `true` for a new code. |
| `flexacommerce.coupon.deleted` | `Coupon $coupon` | After the row is deleted. Orders that used the code keep their `discount` lines (doc 02 §7.13.5). |

Coupons have no money filters in 1.0: the discount rules are fixed (doc 02 §7.13.1) so the cart, the checkout quote and the placed order always agree. A filter on the coupon amount is a candidate for a later minor release.

### 7.1 Tax contracts

Published in `Contracts\Tax` in phase 4.5 (ADR-020, doc 02 §7.12). They replace the phase 3 seam `TaxCalculator::tax(): Money`, which was experimental and never released.

```php
namespace Flexa\Commerce\Contracts\Tax;

final class TaxLocation {        // normalized (doc 02 §7.12.3)
	public function __construct( public readonly string $country, public readonly string $state,
		public readonly string $postcode, public readonly string $city ) {}
}

final class TaxRate {
	public function __construct( public readonly int $id, public readonly string $code, public readonly string $label,
		public readonly int $rate /* millionths: 7.25 % = 72500 */, public readonly int $priority,
		public readonly bool $compound, public readonly bool $shipping ) {}
}

interface TaxRateProvider {
	/** @return list<TaxRate> ordered as doc 02 §7.12.3 step 5 */
	public function rates( TaxLocation $location, string $tax_class ): array;
	public function fallback_allowed(): bool;     // false: checkout refuses when this provider fails
}

interface TaxCalculator {
	public function calculate( TaxRequest $request ): TaxResult;
}
```

- `TaxRequest`: `currency`, `prices_include_tax`, `location` (`?TaxLocation`, null before an address), `lines` (each: `key`, `type` product/shipping/fee, `amount` Money after discount, `quantity`, `tax_class`, `tax_status`, `variant_id`), `customer` (`user_id`, `email`), `context` (`cart`, `checkout`, `admin`).
- `TaxResult`: per line key a list of `TaxAmount { rate: TaxRate, amount: Money }`; per rate the totals for items and shipping; `total`. Core rejects a result whose line keys, currency or sums do not match the request, and falls back as doc 02 §7.12.7 says.
- Rate ids from a provider that are not table rows are negative or zero, and their `code` must be stable; the order's `tax` lines store the code and label, not a foreign key.
- Both are called on cart reads and twice per checkout: they must be fast and side-effect free, and a remote provider must cache per location (core also caches the rate provider's answers, doc 02 §7.12.7).

## 8. Storefront extension

- Templates: overridable from `{theme}/flexacommerce/` (classic) and the Site Editor (block). Every template file carries a `@version` header; the Status screen lists outdated overrides (same mechanism shop owners know from WooCommerce).
- Render hooks inside shared renderers: `flexacommerce.render.{component}.before` / `.after` actions, and `flexacommerce.render.{component}.html` filter on the output. Components: `product_price`, `add_to_cart`, `gallery`, `cart_table`, `checkout_form`, `order_summary`, `account_nav`.
- Blocks: core blocks are extendable with standard block APIs (block variations, block hooks, `render_block` filters). FlexaCommerce adds no custom JS extension registry in 1.0.
- Interactivity: the store namespace `flexacommerce` state is documented read-only for extensions (`state.cart.count`, `state.cart.total`). Extensions add their own namespaced stores.
- Emails: `flexacommerce.email.recipients`, `.subject`, `.heading`, `.content_html` filters per email id, and `flexacommerce.emails.register` to add custom emails (`Contracts\Email\Email` interface with `id()`, `trigger_hooks()`, `render()`). With `defer_emails` on, these filters still run during the request that triggered the email, because the message is built there and only the sending waits for cron; a filter that needs the order's state at send time cannot rely on deferral.

## 9. REST extension

- New routes: on `flexacommerce.rest.register_routes`, under the extension's **own** namespace. The `flexacommerce/v1` namespace is reserved for core.
- Response extension: `flexacommerce.rest.{resource}.response` filter (`array $data, object $view, \WP_REST_Request $request`) for `order`, `product`, `variant`, `cart`. Extensions must namespace added keys under `extensions.{id}`.
- Store API checkout payload: extra input arrives in `extensions.{id}` and is passed to the extension's `checkout.validate` filter and gateway `validate()`.

## 10. Versioning, stability and deprecation

- **API version** is separate from the plugin version: `Contracts\Extensions::API_VERSION = '1.0'`. Minor versions add methods only through new interfaces or default-implemented abstract base classes (`AbstractPaymentGateway`), never by adding a method to an existing interface, which would fatal every implementation.
- Stability tags in docblocks: `@api` (stable, semver), `@api-experimental` (may change in a minor version, changes listed in the changelog), anything without a tag is internal.
- Deprecation: a deprecated hook or method keeps working for at least **two minor releases and 6 months**, whichever is longer. It triggers `_deprecated_hook()` / `_deprecated_function()` (visible only with `WP_DEBUG`). Removal happens only in a major version, and the changelog has a "Removed" section.
- Breaking changes need an ADR and a migration note in `docs/extending/UPGRADING.md`.

## 11. Dependency declaration

- Extensions declare `Requires Plugins: flexacommerce` in their plugin header (core plugin dependency feature, WP 6.5+), so WordPress blocks activation without FlexaCommerce.
- At runtime, `require_api( '1.0' )` checks the API version. If unmet it throws `IncompatibleApi`. Core catches it, deactivates nothing, skips that extension's registrations, and shows an admin notice naming the extension and the required version.
- Extension version checks against the plugin version are discouraged; use `api_version()`.

## 12. Error handling

| Situation | Core behavior |
|---|---|
| Gateway `process_payment()` throws | Transaction → `failed` with code `gateway_exception`, order stays `pending`, customer sees a generic message, details logged (redacted). |
| Gateway returns invalid result type | Treated as exception above, plus `_doing_it_wrong()`. |
| `parse_webhook()` throws `InvalidSignature` | 401, no state change, logged with IP and gateway id. |
| `parse_webhook()` throws anything else | 500 (gateway retries), logged. |
| Shipping method throws or times out | Its rates are omitted, logged, checkout continues with other methods. |
| Filter returns wrong type | Unfiltered value used, `_doing_it_wrong()`. |
| Action listener throws (after commit) | Caught per listener, logged, other listeners run. |
| Extension writes reserved meta key | `InvalidArgumentException`. |

All extension-facing exceptions extend `Contracts\CommerceException` with a stable `code()` string.
