<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\PayPal;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Payments\AbstractPaymentGateway;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\CheckoutFields;
use Flexa\Commerce\Contracts\Payments\PaymentEvent;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\Payments\RefundRequest;
use Flexa\Commerce\Contracts\Payments\RefundResult;
use Flexa\Commerce\Contracts\Payments\ReturnRequest;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\ValidationResult;
use Flexa\Commerce\Payments\ConnectedGateway;
use Flexa\Commerce\Payments\ExpressGateway;
use Flexa\Commerce\Payments\ExpressSession;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal (ADR-054), on the Orders v2 API. At checkout the PayPal buttons
 * take the place of "Place order": a click places the store order, which
 * opens a PayPal order, and the shopper approves it in PayPal's window.
 * Without scripts, and on the order-pay page, the shopper goes to PayPal
 * and back. The store captures when the shopper returns, or from the
 * approval webhook when they never do. The transaction's reference is the
 * PayPal order id throughout.
 *
 * The express buttons on the product page, the cart and the top of the
 * checkout open the PayPal order first, keep it in step with the quote
 * while the shopper picks an address and a rate on PayPal's sheet, then
 * place the store order with the contact PayPal shares (ExpressSession).
 */
final class PayPalGateway extends AbstractPaymentGateway implements ConnectedGateway, ExpressGateway, ExpressSession {
	public const ID = 'paypal';

	/** What the last save learned from PayPal, per mode. Erased with the store's options. */
	public const STATUS_OPTION = 'flexacommerce_paypal_account';

	/** The script module id of the express buttons. */
	public const EXPRESS_MODULE = '@flexacommerce/paypal-express';

	/** Funding sources the buttons can pay with, named on the order (CheckoutService::WALLETS). */
	public const FUNDING = [ 'paypal', 'venmo', 'paylater', 'card' ];

	protected const SUPPORTS = [ 'refunds', 'partial_refunds', 'webhooks', 'signed_returns' ];

	private const COLORS = [ 'gold', 'blue', 'silver', 'white', 'black' ];

	/** @var array<string,array<string,mixed>> PayPal orders read in this request */
	private array $orders = [];

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'PayPal', 'flexacommerce' );
	}

	public function settings_fields(): array {
		$fields = [
			new SettingField(
				'mode',
				'select',
				__( 'Mode', 'flexacommerce' ),
				'test',
				__( 'Sandbox mode pays with PayPal sandbox accounts and charges nothing.', 'flexacommerce' ),
				[
					'test' => __( 'Sandbox', 'flexacommerce' ),
					'live' => __( 'Live', 'flexacommerce' ),
				]
			),
		];
		foreach ( [ 'test', 'live' ] as $mode ) {
			$test     = $mode === 'test';
			$fields[] = new SettingField(
				$mode . '_client_id',
				'text',
				$test ? __( 'Sandbox client ID', 'flexacommerce' ) : __( 'Live client ID', 'flexacommerce' ),
				'',
				$test ? __( 'In the PayPal Developer Dashboard: Apps & Credentials, Sandbox, your app.', 'flexacommerce' ) : __( 'In the PayPal Developer Dashboard: Apps & Credentials, Live, your app.', 'flexacommerce' )
			);
			$fields[] = new SettingField(
				$mode . '_client_secret',
				'secret',
				$test ? __( 'Sandbox secret', 'flexacommerce' ) : __( 'Live secret', 'flexacommerce' ),
				''
			);
			$fields[] = new SettingField(
				$mode . '_webhook_id',
				'text',
				$test ? __( 'Sandbox webhook ID', 'flexacommerce' ) : __( 'Live webhook ID', 'flexacommerce' ),
				'',
				__( 'Filled in for you when the credentials are saved.', 'flexacommerce' )
			);
		}
		$places = [
			'product'  => __( 'PayPal buttons on product pages', 'flexacommerce' ),
			'cart'     => __( 'PayPal buttons on the cart', 'flexacommerce' ),
			'checkout' => __( 'PayPal buttons at the top of the checkout', 'flexacommerce' ),
		];
		foreach ( $places as $place => $label ) {
			$fields[] = new SettingField(
				'express_' . $place,
				'checkbox',
				$label,
				true,
				$place === 'product' ? __( 'The shopper picks the address and shipping on PayPal. On a product page the button buys that product alone and leaves the cart as it is.', 'flexacommerce' ) : ''
			);
		}
		$fields[] = new SettingField(
			'button_color',
			'select',
			__( 'Button color', 'flexacommerce' ),
			'gold',
			'',
			[
				'gold'   => __( 'Gold', 'flexacommerce' ),
				'blue'   => __( 'Blue', 'flexacommerce' ),
				'silver' => __( 'Silver', 'flexacommerce' ),
				'white'  => __( 'White', 'flexacommerce' ),
				'black'  => __( 'Black', 'flexacommerce' ),
			]
		);
		$fields[] = new SettingField(
			'pay_later',
			'checkbox',
			__( 'Show Pay Later', 'flexacommerce' ),
			true,
			__( 'PayPal\'s pay-in-installments button, where PayPal offers it to the shopper.', 'flexacommerce' )
		);
		return $fields;
	}

	public function mode(): string {
		return $this->setting( 'mode' ) === 'live' ? 'live' : 'test';
	}

	public function express_config( string $place ): ?array {
		$currency = Settings::string( 'currency' );
		if ( ! in_array( $place, self::PLACES, true ) || ! (bool) $this->setting( 'express_' . $place ) || ! $this->ready() || ! PayPalAmount::supports( $currency ) ) {
			return null;
		}
		return [
			'script' => FLEXACOMMERCE_URL . 'assets/frontend/paypal-express.js',
			'module' => self::EXPRESS_MODULE,
		] + $this->button_config( $currency );
	}

	public function is_available( CheckoutContext $checkout ): bool {
		return $this->ready() && PayPalAmount::to_paypal( $checkout->total ) !== null;
	}

	public function checkout_fields( CheckoutContext $checkout ): CheckoutFields {
		return new CheckoutFields(
			(string) $this->setting( 'description' ),
			[],
			FLEXACOMMERCE_URL . 'assets/frontend/paypal.js?ver=' . FLEXACOMMERCE_VERSION,
			$this->button_config( $checkout->total->currency ) + [
				'fallback' => __( 'You will pay on PayPal after placing the order.', 'flexacommerce' ),
			]
		);
	}

	public function validate( CheckoutContext $checkout, array $gateway_input ): ValidationResult {
		unset( $checkout );
		$result  = new ValidationResult();
		$session = $gateway_input['session'] ?? '';
		$flow    = $gateway_input['flow'] ?? '';
		$funding = $gateway_input['express'] ?? '';
		if ( ! is_string( $session ) || preg_match( '/^[A-Z0-9]{0,36}$/', $session ) !== 1 || ! in_array( $flow, [ '', 'popup', 'redirect' ], true ) || ! in_array( $funding, array_merge( [ '' ], self::FUNDING ), true ) ) {
			$result = $result->with_error( 'paypal', __( 'The PayPal payment could not be read. Try again.', 'flexacommerce' ) );
		}
		return $result;
	}

	public function process_payment( PaymentRequest $request ): PaymentResult {
		$value = PayPalAmount::to_paypal( $request->amount );
		if ( $value === null ) {
			return PaymentResult::failed( 'currency', __( 'PayPal cannot take this amount in this currency.', 'flexacommerce' ) );
		}
		$session = is_string( $request->gateway_input['session'] ?? null ) ? $request->gateway_input['session'] : '';
		if ( $session !== '' ) {
			return $this->capture_session( $session, $request->amount );
		}
		$unit     = [
			'reference_id' => 'default',
			'custom_id'    => (string) $request->transaction_id,
			'description'  => mb_substr( $this->description( $request->order->number ), 0, 127 ),
			'amount'       => PayPalAmount::field( $request->amount ),
		];
		$shipping = $request->order->shipping;
		$context  = [
			'return_url'          => $request->return_url,
			'cancel_url'          => $request->cancel_url,
			'user_action'         => 'PAY_NOW',
			'brand_name'          => $this->brand(),
			'shipping_preference' => 'NO_SHIPPING',
		];
		$source   = [ 'experience_context' => $context ];
		$billing  = $request->order->billing;
		if ( is_email( $request->order->customer_email ) ) {
			$source['email_address'] = $request->order->customer_email;
		}
		if ( $billing !== null && $billing->first_name !== '' && $billing->last_name !== '' ) {
			$source['name'] = [
				'given_name' => mb_substr( $billing->first_name, 0, 140 ),
				'surname'    => mb_substr( $billing->last_name, 0, 140 ),
			];
		}
		$body   = [
			'intent'         => 'CAPTURE',
			'purchase_units' => [ $unit ],
			'payment_source' => [ 'paypal' => $source ],
		];
		$client = $this->client();
		$order  = null;
		if ( $shipping !== null && $shipping->address_1 !== '' && $shipping->country !== '' ) {
			$with                                  = $body;
			$with['purchase_units'][0]['shipping'] = [
				'name'    => [ 'full_name' => mb_substr( $shipping->name(), 0, 300 ) ],
				'address' => self::address( $shipping->to_array() ),
			];
			$with['payment_source']['paypal']['experience_context']['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
			try {
				$order = $client->post( 'v2/checkout/orders', $with, $request->idempotency_key );
			} catch ( PayPalError $e ) {
				// PayPal checks the address against its own rules; pay without it rather than not at all.
				if ( ! $e->refused() ) {
					throw $e;
				}
				$order = null;
			}
		}
		$order ??= $client->post( 'v2/checkout/orders', $body, $request->idempotency_key . '-no-shipping' );
		$id      = (string) ( $order['id'] ?? '' );
		if ( ( $request->gateway_input['flow'] ?? '' ) === 'popup' ) {
			// The buttons on the checkout open PayPal's window for this order (assets/frontend/paypal.js).
			return PaymentResult::requires_action( [ 'order_id' => $id ], $id );
		}
		foreach ( (array) ( $order['links'] ?? [] ) as $link ) {
			if ( is_array( $link ) && in_array( $link['rel'] ?? '', [ 'payer-action', 'approve' ], true ) && is_string( $link['href'] ?? null ) ) {
				return PaymentResult::redirect( $link['href'], $id );
			}
		}
		throw new PayPalError( 'PayPal did not return a page to approve the payment on.' );
	}

	/**
	 * Return from PayPal: what PayPal says now, read with the secret. An
	 * approved order is captured here; one the shopper left unapproved
	 * fails the attempt, so they can pay again.
	 */
	public function verify_return( ReturnRequest $request ): ?PaymentEvent {
		$ref = (string) $request->gateway_reference;
		if ( preg_match( '/^[A-Z0-9]{1,36}$/', $ref ) !== 1 ) {
			return null;
		}
		$client = $this->client();
		$order  = $client->get( 'v2/checkout/orders/' . $ref );
		$money  = PayPalEvents::money( is_array( $order['purchase_units'][0] ?? null ) ? $order['purchase_units'][0] : [] );
		switch ( $order['status'] ?? '' ) {
			case 'APPROVED':
				return $this->capture( $client, $ref, 'return-' . $ref );
			case 'COMPLETED':
				return PayPalEvents::captured( $order, 'return-' . $ref );
			case 'CREATED':
			case 'SAVED':
			case 'PAYER_ACTION_REQUIRED':
				return new PaymentEvent( 'return-' . $ref, 'failed', $ref, $money, '', PayPalEvents::raw( $order ), 'cancelled', __( 'The PayPal payment was not completed.', 'flexacommerce' ) );
			case 'VOIDED':
				return new PaymentEvent( 'return-' . $ref, 'failed', $ref, $money, '', PayPalEvents::raw( $order ), 'voided', __( 'The PayPal payment was cancelled.', 'flexacommerce' ) );
		}
		return null;
	}

	public function parse_webhook( \WP_REST_Request $request ): array {
		$headers = [];
		foreach ( [ 'paypal_auth_algo', 'paypal_cert_url', 'paypal_transmission_id', 'paypal_transmission_sig', 'paypal_transmission_time' ] as $name ) {
			$headers[ $name ] = (string) $request->get_header( $name );
		}
		$body   = $request->get_body();
		$client = PayPalEvents::verify( $body, $headers, $this->hooks() );
		$event  = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return [];
		}
		if ( ( $event['event_type'] ?? '' ) !== 'CHECKOUT.ORDER.APPROVED' ) {
			return PayPalEvents::map( $event );
		}
		// The shopper approved and never came back: capture here. Express
		// orders carry no custom_id and are captured when the store order is placed.
		$order = is_array( $event['resource'] ?? null ) ? $event['resource'] : [];
		$id    = is_string( $order['id'] ?? null ) ? $order['id'] : '';
		$txn   = $order['purchase_units'][0]['custom_id'] ?? '';
		if ( $id === '' || ! is_string( $txn ) || ! ctype_digit( $txn ) || ! is_string( $event['id'] ?? null ) ) {
			return [];
		}
		try {
			$captured = $this->capture( $client, $id, $event['id'] );
		} catch ( PayPalError $e ) {
			if ( $e->refused() ) {
				return [];
			}
			throw $e;
		}
		return $captured !== null && $captured->type === 'succeeded' ? [ $captured ] : [];
	}

	public function refund( RefundRequest $request ): RefundResult {
		if ( PayPalAmount::to_paypal( $request->amount ) === null ) {
			return RefundResult::failed( 'currency', __( 'PayPal cannot refund this amount in this currency.', 'flexacommerce' ) );
		}
		$client  = $this->client();
		$ref     = $request->payment_reference;
		$capture = '';
		try {
			$capture = (string) ( PayPalEvents::capture( $client->get( 'v2/checkout/orders/' . rawurlencode( $ref ) ) )['id'] ?? '' );
		} catch ( PayPalError $e ) {
			// Orders paid through WooCommerce PayPal Payments keep the capture id.
			if ( $e->status !== 404 ) {
				throw $e;
			}
			$capture = $ref;
		}
		if ( $capture === '' ) {
			return RefundResult::failed( 'no_payment', __( 'PayPal has no payment for this order to refund.', 'flexacommerce' ) );
		}
		$body = [ 'amount' => PayPalAmount::field( $request->amount ) ];
		if ( $request->reason !== '' ) {
			$body['note_to_payer'] = mb_substr( $request->reason, 0, 255 );
		}
		try {
			$refund = $client->post( 'v2/payments/captures/' . rawurlencode( $capture ) . '/refund', $body, $request->idempotency_key );
		} catch ( PayPalError $e ) {
			// PayPal said no (already refunded, too old, more than was paid): tell the merchant why.
			if ( $e->refused() ) {
				return RefundResult::failed( strtolower( $e->issue() ), $e->getMessage() );
			}
			throw $e;
		}
		$id = (string) ( $refund['id'] ?? '' );
		return match ( $refund['status'] ?? '' ) {
			'COMPLETED'           => RefundResult::succeeded( $id ),
			'CANCELLED', 'FAILED' => RefundResult::failed( 'failed', __( 'PayPal could not refund this payment.', 'flexacommerce' ) ),
			default               => RefundResult::pending( $id ),
		};
	}

	public function open_express( array $quote ): string {
		$amount = $this->amount( $quote );
		$unit   = [
			'reference_id' => 'default',
			'description'  => mb_substr( $this->description( '' ), 0, 127 ),
			'amount'       => $amount,
		];
		$needs  = (bool) ( $quote['needs_shipping'] ?? false );
		$rates  = $this->options( $quote );
		if ( $needs && $rates !== [] ) {
			$unit['shipping'] = [ 'options' => $rates ];
		}
		$order = $this->client()->post(
			'v2/checkout/orders',
			[
				'intent'         => 'CAPTURE',
				'purchase_units' => [ $unit ],
				'payment_source' => [
					'paypal' => [
						'experience_context' => [
							'user_action'         => 'PAY_NOW',
							'brand_name'          => $this->brand(),
							'shipping_preference' => $needs ? 'GET_FROM_FILE' : 'NO_SHIPPING',
						],
					],
				],
			],
			wp_generate_uuid4()
		);
		$id    = (string) ( $order['id'] ?? '' );
		if ( $id === '' ) {
			throw new PayPalError( 'PayPal did not open an order.' );
		}
		return $id;
	}

	public function update_express( string $session, array $quote ): void {
		$path       = "/purchase_units/@reference_id=='default'";
		$operations = [
			[
				'op'    => 'replace',
				'path'  => $path . '/amount',
				'value' => $this->amount( $quote ),
			],
		];
		$rates      = $this->options( $quote );
		if ( (bool) ( $quote['needs_shipping'] ?? false ) && $rates !== [] ) {
			$operations[] = [
				'op'    => 'add',
				'path'  => $path . '/shipping/options',
				'value' => $rates,
			];
		}
		$client = $this->client();
		try {
			$client->patch( 'v2/checkout/orders/' . rawurlencode( $session ), $operations );
		} catch ( PayPalError $e ) {
			// Options the order already has may want a replace rather than an add.
			if ( ! $e->refused() || count( $operations ) < 2 ) {
				throw $e;
			}
			$operations[1]['op'] = 'replace';
			$client->patch( 'v2/checkout/orders/' . rawurlencode( $session ), $operations );
		}
		unset( $this->orders[ $session ] );
	}

	public function express_contact( string $session ): array {
		$order    = $this->order( $session );
		$payer    = is_array( $order['payer'] ?? null ) ? $order['payer'] : [];
		$unit     = is_array( $order['purchase_units'][0] ?? null ) ? $order['purchase_units'][0] : [];
		$ship     = is_array( $unit['shipping'] ?? null ) ? $unit['shipping'] : [];
		$name     = [
			'first_name' => (string) ( $payer['name']['given_name'] ?? '' ),
			'last_name'  => (string) ( $payer['name']['surname'] ?? '' ),
		];
		$contact  = [
			'email' => (string) ( $payer['email_address'] ?? '' ),
			'phone' => (string) ( $payer['phone']['phone_number']['national_number'] ?? '' ),
		];
		$shipping = null;
		if ( is_array( $ship['address'] ?? null ) && ( $ship['address']['country_code'] ?? '' ) !== '' ) {
			[ $first, $last ] = array_pad( explode( ' ', trim( (string) ( $ship['name']['full_name'] ?? '' ) ), 2 ), 2, '' );
			$shipping         = [
				'first_name' => $first !== '' ? $first : $name['first_name'],
				'last_name'  => $first !== '' ? $last : $name['last_name'],
			] + self::fields( $ship['address'] );
		}
		$own     = is_array( $payer['address'] ?? null ) && ( $payer['address']['address_line_1'] ?? '' ) !== '' ? self::fields( $payer['address'] ) : null;
		$billing = $name + ( $own ?? ( $shipping !== null ? array_diff_key( $shipping, $name ) : self::fields( is_array( $payer['address'] ?? null ) ? $payer['address'] : [] ) ) ) + $contact;
		$rate    = '';
		foreach ( is_array( $ship['options'] ?? null ) ? $ship['options'] : [] as $option ) {
			if ( is_array( $option ) && ( $option['selected'] ?? false ) === true && is_string( $option['id'] ?? null ) ) {
				$rate = $option['id'];
			}
		}
		return [
			'billing'       => array_map( 'strval', $billing ),
			'shipping'      => $shipping,
			'shipping_rate' => $rate,
		];
	}

	public function connect( array $values, array $before ): array {
		$stored = get_option( self::STATUS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$status = [];
		foreach ( [ 'test', 'live' ] as $mode ) {
			$label  = $mode === 'test' ? __( 'sandbox', 'flexacommerce' ) : __( 'live', 'flexacommerce' );
			$id     = trim( (string) ( $values[ $mode . '_client_id' ] ?? '' ) );
			$secret = trim( (string) ( $values[ $mode . '_client_secret' ] ?? '' ) );
			$hook   = trim( (string) ( $values[ $mode . '_webhook_id' ] ?? '' ) );
			if ( $id === '' && $secret === '' ) {
				continue;
			}
			if ( $id === '' || $secret === '' ) {
				/* translators: %s: sandbox or live */
				throw new \InvalidArgumentException( sprintf( __( 'Fill in both the %s client ID and the secret.', 'flexacommerce' ), $label ) );
			}
			$changed   = $id !== (string) ( $before[ $mode . '_client_id' ] ?? '' ) || $secret !== (string) ( $before[ $mode . '_client_secret' ] ?? '' );
			$typed_own = $hook !== '' && $hook !== (string) ( $before[ $mode . '_webhook_id' ] ?? '' );
			if ( ! $changed && $hook !== '' && ! $typed_own ) {
				if ( is_array( $stored[ $mode ] ?? null ) ) {
					$status[ $mode ] = $stored[ $mode ];
				}
				continue;
			}
			$client = new PayPalClient( $id, $secret, $mode === 'live' );
			$entry  = [
				'connected' => false,
				'webhook'   => '',
				'error'     => '',
			];
			try {
				$client->token( true );
				$entry['connected'] = true;
			} catch ( PayPalError $e ) {
				if ( $e->status === 401 || $e->status === 400 ) {
					/* translators: 1: sandbox or live; 2: PayPal's message */
					throw new \InvalidArgumentException( sprintf( __( 'PayPal did not accept the %1$s client ID and secret: %2$s Check that they come from the right mode.', 'flexacommerce' ), $label, rtrim( $e->getMessage(), '.' ) . '.' ) );
				}
				// PayPal down: keep the credentials, try the webhook on the next save.
				$entry['error'] = $e->getMessage();
			}
			if ( $typed_own ) {
				$entry['webhook'] = 'manual';
			} elseif ( $entry['connected'] ) {
				try {
					$values[ $mode . '_webhook_id' ] = $this->create_webhook( $client );
					$entry['webhook']                = 'created';
				} catch ( PayPalError $e ) {
					$entry['error'] = $e->getMessage();
				}
			}
			$status[ $mode ] = $entry;
		}
		update_option( self::STATUS_OPTION, $status, false );
		return $values;
	}

	public function notes(): array {
		$mode  = $this->mode();
		$label = $mode === 'test' ? __( 'sandbox', 'flexacommerce' ) : __( 'live', 'flexacommerce' );
		if ( ! $this->ready() ) {
			return [
				[
					'tone' => 'info',
					/* translators: %s: sandbox or live */
					'text' => sprintf( __( 'Add the %s client ID and secret of your app in the PayPal Developer Dashboard (Apps & Credentials) and save. The webhook is set up for you.', 'flexacommerce' ), $label ),
				],
			];
		}
		$notes = [];
		if ( $mode === 'live' && ! is_ssl() && ! str_starts_with( home_url(), 'https://' ) ) {
			$notes[] = [
				'tone' => 'warning',
				'text' => __( 'Live payments need the site on HTTPS.', 'flexacommerce' ),
			];
		}
		$currency = Settings::string( 'currency' );
		if ( ! PayPalAmount::supports( $currency ) ) {
			$notes[] = [
				'tone' => 'warning',
				/* translators: %s: currency code */
				'text' => sprintf( __( 'PayPal does not take payments in %s, so it stays hidden at checkout.', 'flexacommerce' ), $currency ),
			];
		}
		$stored = get_option( self::STATUS_OPTION, [] );
		$entry  = is_array( $stored ) && is_array( $stored[ $mode ] ?? null ) ? $stored[ $mode ] : [];
		$url    = rest_url( 'flexacommerce/v1/webhooks/' . self::ID );
		if ( ( $entry['connected'] ?? false ) === true ) {
			$notes[] = [
				'tone' => 'success',
				/* translators: %s: sandbox or live */
				'text' => sprintf( __( 'Connected to PayPal in %s mode.', 'flexacommerce' ), $label ),
			];
		}
		if ( ( $entry['webhook'] ?? '' ) === 'created' ) {
			$notes[] = [
				'tone' => 'success',
				/* translators: %s: the webhook URL */
				'text' => sprintf( __( 'PayPal sends payment updates to %s.', 'flexacommerce' ), $url ),
			];
		} elseif ( $this->key( 'webhook_id' ) === '' ) {
			$why     = (string) ( $entry['error'] ?? '' );
			$notes[] = [
				'tone' => 'warning',
				'text' => ( $why !== ''
					/* translators: %s: PayPal's message */
					? sprintf( __( 'PayPal could not add the webhook: %s', 'flexacommerce' ), $why ) . ' '
					: '' )
					/* translators: 1: the webhook URL; 2: the list of events */
					. sprintf( __( 'Payments still confirm when the shopper comes back from PayPal. For refunds and payments that settle later, add a webhook for %1$s to your app in the PayPal Developer Dashboard with the events %2$s, then paste its ID here. PayPal only sends to public HTTPS addresses.', 'flexacommerce' ), $url, implode( ', ', PayPalEvents::ENABLED ) ),
			];
		}
		return $notes;
	}

	/**
	 * Captures an approved order. One capture per order, whoever asks
	 * first: the return, the webhook and a retry all send the same request id.
	 */
	private function capture( PayPalClient $client, string $order_id, string $event_id ): ?PaymentEvent {
		try {
			$order = $client->post( 'v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', [], 'fxc-capture-' . $order_id );
		} catch ( PayPalError $e ) {
			if ( $e->issue() === 'ORDER_ALREADY_CAPTURED' ) {
				return PayPalEvents::captured( $client->get( 'v2/checkout/orders/' . rawurlencode( $order_id ) ), $event_id );
			}
			if ( $e->refused() && in_array( $e->issue(), [ 'INSTRUMENT_DECLINED', 'PAYER_ACTION_REQUIRED', 'TRANSACTION_REFUSED', 'PAYER_CANNOT_PAY' ], true ) ) {
				$money = PayPalEvents::money( is_array( $e->body['purchase_units'][0] ?? null ) ? $e->body['purchase_units'][0] : [] );
				return new PaymentEvent( $event_id, 'failed', $order_id, $money->amount > 0 ? $money : $this->order_money( $client, $order_id ), '', [ 'object' => $order_id ], strtolower( $e->issue() ), __( 'PayPal declined the payment. Try again with another way to pay.', 'flexacommerce' ) );
			}
			throw $e;
		}
		$this->orders[ $order_id ] = $order;
		return PayPalEvents::captured( $order, $event_id );
	}

	/** An express order: approved for this amount, then captured. */
	private function capture_session( string $session, Money $amount ): PaymentResult {
		$order = $this->order( $session );
		$unit  = is_array( $order['purchase_units'][0] ?? null ) ? $order['purchase_units'][0] : [];
		$money = PayPalEvents::money( $unit );
		if ( ( $order['status'] ?? '' ) !== 'APPROVED' ) {
			return PaymentResult::failed( 'not_approved', __( 'PayPal has not approved this payment. Try again.', 'flexacommerce' ) );
		}
		if ( $money->currency !== strtoupper( $amount->currency ) || $money->amount !== $amount->amount ) {
			return PaymentResult::failed( 'amount_changed', __( 'The order total changed after PayPal approved the payment. Pay again with PayPal.', 'flexacommerce' ) );
		}
		$event = $this->capture( $this->client(), $session, 'capture-' . $session );
		if ( $event === null ) {
			return PaymentResult::pending( $session );
		}
		return $event->type === 'succeeded' ? PaymentResult::succeeded( $session ) : PaymentResult::failed( $event->failure_code, $event->failure_message );
	}

	private function order_money( PayPalClient $client, string $order_id ): Money {
		$order = $client->get( 'v2/checkout/orders/' . rawurlencode( $order_id ) );
		return PayPalEvents::money( is_array( $order['purchase_units'][0] ?? null ) ? $order['purchase_units'][0] : [] );
	}

	/** @return array<string,mixed> */
	private function order( string $id ): array {
		if ( preg_match( '/^[A-Z0-9]{1,36}$/', $id ) !== 1 ) {
			throw new PayPalError( 'Not a PayPal order id.', 400 );
		}
		return $this->orders[ $id ] ??= $this->client()->get( 'v2/checkout/orders/' . $id );
	}

	/**
	 * The amount PayPal shows for a quote, with the breakdown when its parts
	 * add up to the total.
	 *
	 * @param array<string,mixed> $quote
	 * @return array<string,mixed>
	 */
	private function amount( array $quote ): array {
		$currency = (string) ( $quote['currency'] ?? '' );
		$total    = (int) ( $quote['total'] ?? 0 );
		if ( PayPalAmount::to_paypal( new Money( $total, $currency ) ) === null ) {
			throw new PayPalError( 'PayPal cannot take this amount in this currency.', 400 );
		}
		$parts = [
			'item_total' => 0,
			'tax_total'  => 0,
			'shipping'   => 0,
			'handling'   => 0,
			'discount'   => 0,
		];
		foreach ( (array) ( $quote['lines'] ?? [] ) as $line ) {
			$value = (int) ( is_array( $line ) ? ( $line['amount'] ?? 0 ) : 0 );
			$key   = match ( is_array( $line ) ? ( $line['kind'] ?? '' ) : '' ) {
				'subtotal' => 'item_total',
				'tax'      => 'tax_total',
				default    => $value < 0 ? 'discount' : 'handling',
			};
			$parts[ $key ] += abs( $value );
		}
		foreach ( (array) ( $quote['shipping_rates'] ?? [] ) as $rate ) {
			if ( is_array( $rate ) && ( $rate['id'] ?? '' ) === ( $quote['shipping_rate'] ?? '' ) ) {
				$parts['shipping'] = (int) ( $rate['amount'] ?? 0 );
			}
		}
		$amount = PayPalAmount::field( new Money( $total, $currency ) );
		if ( $parts['item_total'] + $parts['tax_total'] + $parts['shipping'] + $parts['handling'] - $parts['discount'] !== $total ) {
			return $amount;
		}
		$breakdown = [];
		foreach ( $parts as $key => $value ) {
			$field = PayPalAmount::to_paypal( new Money( $value, $currency ) );
			if ( $field === null ) {
				return $amount;
			}
			if ( $value > 0 || $key === 'item_total' ) {
				$breakdown[ $key ] = [
					'currency_code' => $amount['currency_code'],
					'value'         => $field,
				];
			}
		}
		return $amount + [ 'breakdown' => $breakdown ];
	}

	/**
	 * The quote's shipping rates as PayPal's shipping options.
	 *
	 * @param array<string,mixed> $quote
	 * @return list<array<string,mixed>>
	 */
	private function options( array $quote ): array {
		$currency = (string) ( $quote['currency'] ?? '' );
		$selected = (string) ( $quote['shipping_rate'] ?? '' );
		$options  = [];
		foreach ( (array) ( $quote['shipping_rates'] ?? [] ) as $rate ) {
			if ( ! is_array( $rate ) || ! is_string( $rate['id'] ?? null ) || strlen( $rate['id'] ) > 127 ) {
				continue;
			}
			$value = PayPalAmount::to_paypal( new Money( (int) ( $rate['amount'] ?? 0 ), $currency ) );
			if ( $value === null ) {
				continue;
			}
			$options[] = [
				'id'       => $rate['id'],
				'label'    => mb_substr( wp_strip_all_tags( (string) ( $rate['label'] ?? '' ) ), 0, 127 ),
				'type'     => 'SHIPPING',
				'selected' => $rate['id'] === $selected,
				'amount'   => [
					'currency_code' => strtoupper( $currency ),
					'value'         => $value,
				],
			];
		}
		if ( $options !== [] && ! in_array( true, array_column( $options, 'selected' ), true ) ) {
			$options[0]['selected'] = true;
		}
		return $options;
	}

	/**
	 * What both kinds of buttons need: the PayPal SDK for the store's
	 * currency, the color, and the funding sources the merchant allows.
	 *
	 * @return array<string,mixed>
	 */
	private function button_config( string $currency ): array {
		$color = (string) $this->setting( 'button_color', 'gold' );
		$args  = [
			'client-id'      => $this->key( 'client_id' ),
			'currency'       => strtoupper( $currency ),
			'intent'         => 'capture',
			'commit'         => 'true',
			'components'     => 'buttons',
			'enable-funding' => (bool) $this->setting( 'pay_later', true ) ? 'venmo,paylater' : 'venmo',
		];
		if ( ! (bool) $this->setting( 'pay_later', true ) ) {
			$args['disable-funding'] = 'paylater';
		}
		return [
			'gateway' => self::ID,
			'sdk'     => 'https://www.paypal.com/sdk/js?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ),
			'color'   => in_array( $color, self::COLORS, true ) ? $color : 'gold',
		];
	}

	/** The webhook for this site, made once and found again on the next save. */
	private function create_webhook( PayPalClient $client ): string {
		$url   = rest_url( 'flexacommerce/v1/webhooks/' . self::ID );
		$types = array_map( static fn ( string $name ): array => [ 'name' => $name ], PayPalEvents::ENABLED );
		try {
			$created = $client->post(
				'v1/notifications/webhooks',
				[
					'url'         => $url,
					'event_types' => $types,
				]
			);
		} catch ( PayPalError $e ) {
			if ( ( $e->body['name'] ?? '' ) !== 'WEBHOOK_URL_ALREADY_EXISTS' && $e->issue() !== 'WEBHOOK_URL_ALREADY_EXISTS' ) {
				throw $e;
			}
			foreach ( (array) ( $client->get( 'v1/notifications/webhooks' )['webhooks'] ?? [] ) as $hook ) {
				if ( is_array( $hook ) && ( $hook['url'] ?? '' ) === $url && is_string( $hook['id'] ?? null ) ) {
					try {
						$client->patch(
							'v1/notifications/webhooks/' . rawurlencode( $hook['id'] ),
							[
								[
									'op'    => 'replace',
									'path'  => '/event_types',
									'value' => $types,
								],
							]
						);
					} catch ( PayPalError $patch ) {
						// The events it listens to stay as they were; the webhook still works.
						unset( $patch );
					}
					return $hook['id'];
				}
			}
			throw $e;
		}
		$id = $created['id'] ?? '';
		if ( ! is_string( $id ) || $id === '' ) {
			throw new PayPalError( 'PayPal did not return the webhook ID.' );
		}
		return $id;
	}

	/**
	 * A client and its webhook id per mode with credentials, the current
	 * mode first: a sandbox event may still come in after going live.
	 *
	 * @return list<array{0:PayPalClient,1:string}>
	 */
	private function hooks(): array {
		$hooks = [];
		foreach ( array_unique( [ $this->mode(), 'test', 'live' ] ) as $mode ) {
			$id     = trim( (string) $this->setting( $mode . '_client_id' ) );
			$secret = trim( (string) $this->setting( $mode . '_client_secret' ) );
			$hook   = trim( (string) $this->setting( $mode . '_webhook_id' ) );
			if ( $id !== '' && $secret !== '' && $hook !== '' ) {
				$hooks[] = [ new PayPalClient( $id, $secret, $mode === 'live' ), $hook ];
			}
		}
		return $hooks;
	}

	private function ready(): bool {
		return $this->key( 'client_id' ) !== '' && $this->key( 'client_secret' ) !== '';
	}

	/** One of the current mode's credentials: client_id, client_secret or webhook_id. */
	private function key( string $kind ): string {
		return trim( (string) $this->setting( $this->mode() . '_' . $kind ) );
	}

	private function client(): PayPalClient {
		return new PayPalClient( $this->key( 'client_id' ), $this->key( 'client_secret' ), $this->mode() === 'live' );
	}

	private function brand(): string {
		return mb_substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 127 );
	}

	private function description( string $number ): string {
		return $number === ''
			/* translators: %s: site name */
			? sprintf( __( '%s order', 'flexacommerce' ), $this->brand() )
			/* translators: 1: site name; 2: order number */
			: sprintf( __( '%1$s order %2$s', 'flexacommerce' ), $this->brand(), $number );
	}

	/**
	 * A store address as PayPal's.
	 *
	 * @param array<string,string> $address
	 * @return array<string,string>
	 */
	private static function address( array $address ): array {
		return array_filter(
			[
				'address_line_1' => mb_substr( $address['address_1'] ?? '', 0, 300 ),
				'address_line_2' => mb_substr( $address['address_2'] ?? '', 0, 300 ),
				'admin_area_2'   => mb_substr( $address['city'] ?? '', 0, 120 ),
				'admin_area_1'   => mb_substr( $address['state'] ?? '', 0, 300 ),
				'postal_code'    => mb_substr( $address['postcode'] ?? '', 0, 60 ),
				'country_code'   => strtoupper( $address['country'] ?? '' ),
			],
			static fn ( string $value ): bool => $value !== ''
		);
	}

	/**
	 * PayPal's address as checkout fields.
	 *
	 * @param array<string,mixed> $address
	 * @return array<string,string>
	 */
	private static function fields( array $address ): array {
		return [
			'address_1' => (string) ( $address['address_line_1'] ?? '' ),
			'address_2' => (string) ( $address['address_line_2'] ?? '' ),
			'city'      => (string) ( $address['admin_area_2'] ?? '' ),
			'state'     => (string) ( $address['admin_area_1'] ?? '' ),
			'postcode'  => (string) ( $address['postal_code'] ?? '' ),
			'country'   => strtoupper( (string) ( $address['country_code'] ?? '' ) ),
		];
	}
}
