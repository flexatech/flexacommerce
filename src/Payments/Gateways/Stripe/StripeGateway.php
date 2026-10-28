<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\Stripe;

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
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe (ADR-052), the way the WooCommerce Stripe extension takes cards:
 * the Payment Element on the checkout makes a payment method, the server
 * confirms a PaymentIntent with it and Stripe.js finishes 3-D Secure in a
 * modal. Without scripts, and on the order-pay page, the shopper pays on a
 * Stripe Checkout page instead. Returns are checked with the secret key,
 * so a forged return URL cannot mark an order paid. Apple Pay and Google
 * Pay buttons on the product page, the cart and the checkout use the
 * Express Checkout Element (ADR-053).
 */
final class StripeGateway extends AbstractPaymentGateway implements ConnectedGateway, ExpressGateway {
	public const ID = 'stripe';

	/** What the last save learned from Stripe, per mode. Erased with the store's options. */
	public const STATUS_OPTION = 'flexacommerce_stripe_account';

	protected const SUPPORTS = [ 'refunds', 'partial_refunds', 'webhooks', 'signed_returns' ];

	/** Methods that need the shopper's agreement to a debit mandate. */
	private const MANDATE = [ 'acss_debit', 'au_becs_debit', 'bacs_debit', 'sepa_debit', 'us_bank_account' ];

	public function __construct( private readonly string $api_base = StripeClient::BASE ) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Credit card (Stripe)', 'flexacommerce' );
	}

	public function settings_fields(): array {
		$fields = [
			new SettingField(
				'mode',
				'select',
				__( 'Mode', 'flexacommerce' ),
				'test',
				__( 'Test mode takes Stripe test cards, like 4242 4242 4242 4242, and charges nothing.', 'flexacommerce' ),
				[
					'test' => __( 'Test', 'flexacommerce' ),
					'live' => __( 'Live', 'flexacommerce' ),
				]
			),
		];
		foreach ( [ 'test', 'live' ] as $mode ) {
			$test     = $mode === 'test';
			$fields[] = new SettingField(
				$mode . '_publishable_key',
				'text',
				$test ? __( 'Test publishable key', 'flexacommerce' ) : __( 'Live publishable key', 'flexacommerce' ),
				'',
				/* translators: %s: the key prefix, like pk_test_ */
				sprintf( __( 'Starts with %s. In the Stripe Dashboard: Developers, API keys.', 'flexacommerce' ), 'pk_' . $mode . '_' )
			);
			$fields[] = new SettingField(
				$mode . '_secret_key',
				'secret',
				$test ? __( 'Test secret key', 'flexacommerce' ) : __( 'Live secret key', 'flexacommerce' ),
				'',
				/* translators: 1: the secret key prefix, like sk_test_; 2: the restricted key prefix, like rk_test_ */
				sprintf( __( 'Starts with %1$s, or %2$s for a restricted key.', 'flexacommerce' ), 'sk_' . $mode . '_', 'rk_' . $mode . '_' )
			);
			$fields[] = new SettingField(
				$mode . '_webhook_secret',
				'secret',
				$test ? __( 'Test webhook signing secret', 'flexacommerce' ) : __( 'Live webhook signing secret', 'flexacommerce' ),
				'',
				__( 'Filled in for you when the keys are saved. Starts with whsec_.', 'flexacommerce' )
			);
		}
		$places = [
			'product'  => __( 'Wallet buttons on product pages', 'flexacommerce' ),
			'cart'     => __( 'Wallet buttons on the cart', 'flexacommerce' ),
			'checkout' => __( 'Wallet buttons on the checkout', 'flexacommerce' ),
		];
		foreach ( $places as $place => $label ) {
			$fields[] = new SettingField(
				'express_' . $place,
				'checkbox',
				$label,
				true,
				$place === 'product' ? __( 'Apple Pay and Google Pay, shown when the shopper\'s device supports them. On a product page the button buys that product alone and leaves the cart as it is.', 'flexacommerce' ) : ''
			);
		}
		$fields[] = new SettingField(
			'express_theme',
			'select',
			__( 'Wallet button color', 'flexacommerce' ),
			'black',
			'',
			[
				'black'         => __( 'Black', 'flexacommerce' ),
				'white'         => __( 'White', 'flexacommerce' ),
				'white-outline' => __( 'White with outline', 'flexacommerce' ),
			]
		);
		return $fields;
	}

	public function express_config( string $place ): ?array {
		if ( ! in_array( $place, self::PLACES, true ) || ! (bool) $this->setting( 'express_' . $place ) || $this->key( 'publishable_key' ) === '' || $this->key( 'secret_key' ) === '' ) {
			return null;
		}
		$currency = Settings::string( 'currency' );
		$theme    = (string) $this->setting( 'express_theme' );
		return [
			'script'   => FLEXACOMMERCE_URL . 'assets/frontend/stripe-express.js',
			'gateway'  => self::ID,
			'key'      => $this->key( 'publishable_key' ),
			'currency' => strtolower( $currency ),
			'shift'    => StripeAmount::exponent( $currency ) - Currency::exponent( $currency ),
			'locale'   => str_replace( '_', '-', determine_locale() ),
			'theme'    => in_array( $theme, [ 'black', 'white', 'white-outline' ], true ) ? $theme : 'black',
		];
	}

	public function mode(): string {
		return $this->setting( 'mode' ) === 'live' ? 'live' : 'test';
	}

	public function is_available( CheckoutContext $checkout ): bool {
		return $this->key( 'publishable_key' ) !== '' && $this->key( 'secret_key' ) !== '' && StripeAmount::to_stripe( $checkout->total ) !== null;
	}

	public function checkout_fields( CheckoutContext $checkout ): CheckoutFields {
		$currency = $checkout->total->currency;
		return new CheckoutFields(
			(string) $this->setting( 'description' ),
			[],
			FLEXACOMMERCE_URL . 'assets/frontend/stripe.js?ver=' . FLEXACOMMERCE_VERSION,
			[
				'key'      => $this->key( 'publishable_key' ),
				'currency' => strtolower( $currency ),
				'amount'   => (int) StripeAmount::to_stripe( $checkout->total ),
				// Store minor units to Stripe's, for the total the form holds.
				'shift'    => StripeAmount::exponent( $currency ) - Currency::exponent( $currency ),
				'locale'   => str_replace( '_', '-', determine_locale() ),
				'fallback' => __( 'You will enter your payment details on a secure Stripe page after placing the order.', 'flexacommerce' ),
			]
		);
	}

	public function validate( CheckoutContext $checkout, array $gateway_input ): ValidationResult {
		unset( $checkout );
		$result = new ValidationResult();
		$method = $gateway_input['payment_method'] ?? '';
		$type   = $gateway_input['payment_method_type'] ?? '';
		$wallet = $gateway_input['express'] ?? '';
		if ( ! is_string( $method ) || ( $method !== '' && preg_match( '/^pm_[A-Za-z0-9_]{1,250}$/', $method ) !== 1 ) || ! is_string( $type ) || preg_match( '/^[a-z_]{0,40}$/', $type ) !== 1 || ! is_string( $wallet ) || ( $wallet !== '' && $method === '' ) ) {
			$result = $result->with_error( 'stripe', __( 'The payment details could not be read. Enter them again.', 'flexacommerce' ) );
		}
		return $result;
	}

	public function process_payment( PaymentRequest $request ): PaymentResult {
		$amount = StripeAmount::to_stripe( $request->amount );
		if ( $amount === null ) {
			return PaymentResult::failed( 'currency', __( 'Stripe cannot take this amount in this currency.', 'flexacommerce' ) );
		}
		$metadata = [
			'order_id'       => (string) $request->order->id,
			'order_number'   => $request->order->number,
			'transaction_id' => (string) $request->transaction_id,
		];
		$method   = is_string( $request->gateway_input['payment_method'] ?? null ) ? $request->gateway_input['payment_method'] : '';
		if ( $method === '' ) {
			return $this->checkout_session( $request, $amount, $metadata );
		}
		$params = [
			'amount'                    => $amount,
			'currency'                  => strtolower( $request->amount->currency ),
			'payment_method'            => $method,
			'confirm'                   => true,
			'use_stripe_sdk'            => true,
			'return_url'                => $request->return_url,
			'automatic_payment_methods' => [ 'enabled' => true ],
			'description'               => $this->description( $request ),
			'metadata'                  => $metadata,
		];
		if ( in_array( $request->gateway_input['payment_method_type'] ?? '', self::MANDATE, true ) ) {
			$params['mandate_data'] = [
				'customer_acceptance' => [
					'type'   => 'online',
					'online' => [
						'ip_address' => ClientIp::get(),
						'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
					],
				],
			];
		}
		try {
			$intent = $this->client()->post( 'payment_intents', $params, $request->idempotency_key );
		} catch ( StripeError $e ) {
			// A declined card, or an amount under Stripe's minimum: the shopper can act on these.
			if ( $e->is_card_error() || in_array( $e->error['code'] ?? '', [ 'amount_too_small', 'amount_too_large' ], true ) ) {
				return PaymentResult::failed( $e->reason(), $e->getMessage() );
			}
			throw $e;
		}
		return self::intent_result( $intent );
	}

	/** @param array<string,mixed> $intent */
	public static function intent_result( array $intent ): PaymentResult {
		$id = (string) ( $intent['id'] ?? '' );
		switch ( $intent['status'] ?? '' ) {
			case 'succeeded':
				return PaymentResult::succeeded( $id );
			case 'processing':
				return PaymentResult::pending( $id );
			case 'requires_capture':
				return PaymentResult::authorized( $id );
			case 'requires_action':
				return PaymentResult::requires_action( [ 'client_secret' => (string) ( $intent['client_secret'] ?? '' ) ], $id );
		}
		$error = is_array( $intent['last_payment_error'] ?? null ) ? $intent['last_payment_error'] : [];
		return PaymentResult::failed(
			$error !== [] ? StripeEvents::reason( $error ) : 'canceled',
			is_string( $error['message'] ?? null ) && $error['message'] !== '' ? $error['message'] : __( 'The payment did not go through. Try again or use another payment method.', 'flexacommerce' )
		);
	}

	/**
	 * Return from Stripe: what Stripe says now, read with the secret key.
	 * Null while the payment is still settling; the webhook brings the end.
	 */
	public function verify_return( ReturnRequest $request ): ?PaymentEvent {
		$ref = (string) $request->gateway_reference;
		if ( str_starts_with( $ref, 'cs_' ) ) {
			$session = $this->client()->get( 'checkout/sessions/' . $ref );
			if ( ( $session['payment_status'] ?? '' ) !== 'paid' ) {
				return null;
			}
			return new PaymentEvent( 'return-' . $ref, 'succeeded', $ref, StripeAmount::from_stripe( (int) ( $session['amount_total'] ?? 0 ), (string) ( $session['currency'] ?? '' ) ), '', StripeEvents::raw( $session, (bool) ( $session['livemode'] ?? false ) ) );
		}
		if ( ! str_starts_with( $ref, 'pi_' ) ) {
			return null;
		}
		$intent = $this->client()->get( 'payment_intents/' . $ref );
		$error  = is_array( $intent['last_payment_error'] ?? null ) ? $intent['last_payment_error'] : [];
		$make   = static fn ( string $type, string $amount_key, string $code = '', string $message = '' ): PaymentEvent => new PaymentEvent(
			'return-' . $ref,
			$type,
			$ref,
			StripeAmount::from_stripe( (int) ( $intent[ $amount_key ] ?? 0 ), (string) ( $intent['currency'] ?? '' ) ),
			'',
			StripeEvents::raw( $intent, (bool) ( $intent['livemode'] ?? false ) ),
			$code,
			$message
		);
		return match ( true ) {
			( $intent['status'] ?? '' ) === 'succeeded'        => $make( 'succeeded', 'amount_received' ),
			( $intent['status'] ?? '' ) === 'requires_capture' => $make( 'authorized', 'amount' ),
			( $intent['status'] ?? '' ) === 'requires_payment_method' && $error !== [] => $make( 'failed', 'amount', StripeEvents::reason( $error ), (string) ( $error['message'] ?? '' ) ),
			default                                            => null,
		};
	}

	public function parse_webhook( \WP_REST_Request $request ): array {
		$body = $request->get_body();
		StripeEvents::verify( $body, (string) $request->get_header( 'stripe_signature' ), [ (string) $this->setting( 'test_webhook_secret' ), (string) $this->setting( 'live_webhook_secret' ) ], time() );
		$event = json_decode( $body, true );
		return is_array( $event ) ? StripeEvents::map( $event ) : [];
	}

	public function refund( RefundRequest $request ): RefundResult {
		$amount = StripeAmount::to_stripe( $request->amount );
		$ref    = $request->payment_reference;
		if ( $amount === null ) {
			return RefundResult::failed( 'currency', __( 'Stripe cannot refund this amount in this currency.', 'flexacommerce' ) );
		}
		$client = $this->client();
		if ( str_starts_with( $ref, 'cs_' ) ) {
			$ref = $client->get( 'checkout/sessions/' . $ref )['payment_intent'] ?? '';
			$ref = is_string( $ref ) ? $ref : '';
		}
		$params = match ( true ) {
			str_starts_with( $ref, 'pi_' )                                  => [ 'payment_intent' => $ref ],
			// Orders paid through the WooCommerce Stripe extension keep the charge.
			str_starts_with( $ref, 'ch_' ) || str_starts_with( $ref, 'py_' ) => [ 'charge' => $ref ],
			default                                                         => null,
		};
		if ( $params === null ) {
			return RefundResult::failed( 'no_payment', __( 'Stripe has no payment for this order to refund.', 'flexacommerce' ) );
		}
		$params += [
			'amount'   => $amount,
			'metadata' => [
				'refund_transaction_id' => (string) $request->refund_transaction_id,
				'reason'                => mb_substr( $request->reason, 0, 500 ),
			],
		];
		try {
			$refund = $client->post( 'refunds', $params, $request->idempotency_key );
		} catch ( StripeError $e ) {
			// Stripe said no (already refunded, more than was paid): tell the merchant why.
			if ( $e->status >= 400 && $e->status < 500 && $e->status !== 429 ) {
				return RefundResult::failed( $e->reason(), $e->getMessage() );
			}
			throw $e;
		}
		$id = (string) ( $refund['id'] ?? '' );
		return match ( $refund['status'] ?? '' ) {
			'succeeded'          => RefundResult::succeeded( $id ),
			'failed', 'canceled' => RefundResult::failed( (string) ( $refund['failure_reason'] ?? 'failed' ), __( 'Stripe could not refund this payment.', 'flexacommerce' ) ),
			default              => RefundResult::pending( $id ),
		};
	}

	public function connect( array $values, array $before ): array {
		$stored = get_option( self::STATUS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$status = [];
		$labels = [];
		foreach ( $this->settings_fields() as $field ) {
			$labels[ $field->id ] = $field->label;
		}
		foreach ( [ 'test', 'live' ] as $mode ) {
			$label       = $mode === 'test' ? __( 'test', 'flexacommerce' ) : __( 'live', 'flexacommerce' );
			$publishable = trim( (string) ( $values[ $mode . '_publishable_key' ] ?? '' ) );
			$secret      = trim( (string) ( $values[ $mode . '_secret_key' ] ?? '' ) );
			$webhook     = trim( (string) ( $values[ $mode . '_webhook_secret' ] ?? '' ) );
			self::check_prefix( $publishable, [ 'pk_' . $mode . '_' ], $labels[ $mode . '_publishable_key' ] );
			self::check_prefix( $secret, [ 'sk_' . $mode . '_', 'rk_' . $mode . '_' ], $labels[ $mode . '_secret_key' ] );
			self::check_prefix( $webhook, [ 'whsec_' ], $labels[ $mode . '_webhook_secret' ] );
			if ( $secret === '' ) {
				continue;
			}
			$new_key   = $secret !== (string) ( $before[ $mode . '_secret_key' ] ?? '' );
			$typed_own = $webhook !== '' && $webhook !== (string) ( $before[ $mode . '_webhook_secret' ] ?? '' );
			if ( ! $new_key && $webhook !== '' ) {
				if ( is_array( $stored[ $mode ] ?? null ) ) {
					$status[ $mode ] = $stored[ $mode ];
				}
				continue;
			}
			$client = new StripeClient( $secret, $this->api_base );
			$error  = '';
			try {
				$account = $client->get( 'account' );
			} catch ( StripeError $e ) {
				if ( $e->status === 401 ) {
					/* translators: 1: test or live; 2: Stripe's message */
					throw new \InvalidArgumentException( sprintf( __( 'Stripe did not accept the %1$s secret key: %2$s', 'flexacommerce' ), $label, $e->getMessage() ) );
				}
				// Stripe down, or a restricted key that cannot read the account: keep the keys.
				$account = [];
				$error   = $e->getMessage();
			}
			$entry  = [
				'account' => self::account_name( $account ),
				'webhook' => '',
				'error'   => $error,
			];
			$entry += $this->register_domain( $client );
			if ( $typed_own ) {
				$entry['webhook'] = 'manual';
			} else {
				try {
					$values[ $mode . '_webhook_secret' ] = $this->create_webhook( $client );
					$entry['webhook']                    = 'created';
				} catch ( StripeError $e ) {
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
		$label = $mode === 'test' ? __( 'test', 'flexacommerce' ) : __( 'live', 'flexacommerce' );
		if ( $this->key( 'publishable_key' ) === '' || $this->key( 'secret_key' ) === '' ) {
			return [
				[
					'tone' => 'info',
					/* translators: %s: test or live */
					'text' => sprintf( __( 'Add your %s keys from the Stripe Dashboard (Developers, API keys) and save. The webhook is set up for you.', 'flexacommerce' ), $label ),
				],
			];
		}
		$notes = [];
		if ( $mode === 'live' && ! is_ssl() && ! str_starts_with( home_url(), 'https://' ) ) {
			$notes[] = [
				'tone' => 'warning',
				'text' => __( 'Live payments need the site on HTTPS. Stripe.js will not load the card form without it.', 'flexacommerce' ),
			];
		}
		$stored = get_option( self::STATUS_OPTION, [] );
		$entry  = is_array( $stored ) && is_array( $stored[ $mode ] ?? null ) ? $stored[ $mode ] : [];
		$url    = rest_url( 'flexacommerce/v1/webhooks/' . self::ID );
		if ( ( $entry['account'] ?? '' ) !== '' ) {
			$notes[] = [
				'tone' => 'success',
				/* translators: 1: Stripe account name; 2: test or live */
				'text' => sprintf( __( 'Connected to the Stripe account %1$s in %2$s mode.', 'flexacommerce' ), (string) $entry['account'], $label ),
			];
		}
		if ( ( $entry['webhook'] ?? '' ) === 'created' ) {
			$notes[] = [
				'tone' => 'success',
				/* translators: %s: the webhook URL */
				'text' => sprintf( __( 'Stripe sends payment updates to %s.', 'flexacommerce' ), $url ),
			];
		} elseif ( $this->key( 'webhook_secret' ) === '' ) {
			$why     = (string) ( $entry['error'] ?? '' );
			$notes[] = [
				'tone' => 'warning',
				'text' => ( $why !== ''
					/* translators: %s: Stripe's message */
					? sprintf( __( 'Stripe could not add the webhook: %s', 'flexacommerce' ), $why ) . ' '
					: '' )
					/* translators: 1: the webhook URL; 2: the list of events */
					. sprintf( __( 'Payments still confirm when the shopper comes back from Stripe. For payments that settle later and for refunds, add an endpoint for %1$s in the Stripe Dashboard (Developers, Webhooks) with the events %2$s, then paste its signing secret here.', 'flexacommerce' ), $url, implode( ', ', StripeEvents::ENABLED ) ),
			];
		}
		$express = false;
		foreach ( self::PLACES as $place ) {
			$express = $express || (bool) $this->setting( 'express_' . $place );
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $express && ( $entry['domain'] ?? '' ) === 'registered' ) {
			$notes[] = [
				'tone' => 'success',
				/* translators: %s: the site's domain */
				'text' => sprintf( __( 'Apple Pay and Google Pay can show on %s.', 'flexacommerce' ), $host ),
			];
		} elseif ( $express && ( $entry['domain_error'] ?? '' ) !== '' ) {
			$notes[] = [
				'tone' => 'warning',
				/* translators: 1: the site's domain; 2: Stripe's message */
				'text' => sprintf( __( 'Stripe could not register %1$s for Apple Pay: %2$s Add it in the Stripe Dashboard (Settings, Payment method domains), or save the keys again later. Google Pay works without it.', 'flexacommerce' ), $host, (string) $entry['domain_error'] ),
			];
		}
		return $notes;
	}

	/**
	 * Registers the site's domain for Apple Pay, which shows its button only
	 * on registered domains (ADR-053). Stripe keeps one entry per domain,
	 * so saving again does no harm.
	 *
	 * @return array{domain:string,domain_error:string}
	 */
	private function register_domain( StripeClient $client ): array {
		try {
			$domain = $client->post( 'payment_method_domains', [ 'domain_name' => (string) wp_parse_url( home_url(), PHP_URL_HOST ) ] );
		} catch ( StripeError $e ) {
			return [
				'domain'       => '',
				'domain_error' => $e->getMessage(),
			];
		}
		$apple = is_array( $domain['apple_pay'] ?? null ) ? $domain['apple_pay'] : [];
		$error = is_string( $apple['status_details']['error_message'] ?? null ) ? $apple['status_details']['error_message'] : '';
		return ( $apple['status'] ?? 'active' ) === 'active'
			? [
				'domain'       => 'registered',
				'domain_error' => '',
			]
			: [
				'domain'       => '',
				'domain_error' => $error !== '' ? $error : __( 'Stripe has not verified the domain yet.', 'flexacommerce' ),
			];
	}

	/** One of the keys of the current mode: publishable_key, secret_key or webhook_secret. */
	private function key( string $kind ): string {
		return trim( (string) $this->setting( $this->mode() . '_' . $kind ) );
	}

	private function client(): StripeClient {
		return new StripeClient( $this->key( 'secret_key' ), $this->api_base );
	}

	/**
	 * The Stripe Checkout page, for a shopper without the card form.
	 *
	 * @param array<string,string> $metadata
	 */
	private function checkout_session( PaymentRequest $request, int $amount, array $metadata ): PaymentResult {
		$params = [
			'mode'                => 'payment',
			'line_items'          => [
				[
					'quantity'   => 1,
					'price_data' => [
						'currency'     => strtolower( $request->amount->currency ),
						'unit_amount'  => $amount,
						'product_data' => [
							/* translators: %s: order number */
							'name' => sprintf( __( 'Order %s', 'flexacommerce' ), $request->order->number ),
						],
					],
				],
			],
			'success_url'         => $request->return_url,
			'cancel_url'          => $request->cancel_url,
			'client_reference_id' => (string) $request->transaction_id,
			'metadata'            => $metadata,
			'payment_intent_data' => [
				'description' => $this->description( $request ),
				// The session's own events carry the result; see StripeEvents::map().
				'metadata'    => $metadata + [ 'fxc_session' => '1' ],
			],
		];
		if ( is_email( $request->order->customer_email ) ) {
			$params['customer_email'] = $request->order->customer_email;
		}
		$session = $this->client()->post( 'checkout/sessions', $params, $request->idempotency_key );
		return PaymentResult::redirect( (string) ( $session['url'] ?? '' ), (string) ( $session['id'] ?? '' ) );
	}

	private function description( PaymentRequest $request ): string {
		/* translators: 1: site name; 2: order number */
		return sprintf( __( '%1$s order %2$s', 'flexacommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $request->order->number );
	}

	/**
	 * A fresh webhook endpoint for this site: an older one for the same URL
	 * is removed first, since Stripe shows its signing secret only once.
	 */
	private function create_webhook( StripeClient $client ): string {
		$url = rest_url( 'flexacommerce/v1/webhooks/' . self::ID );
		foreach ( (array) ( $client->get( 'webhook_endpoints', [ 'limit' => 100 ] )['data'] ?? [] ) as $endpoint ) {
			if ( is_array( $endpoint ) && ( $endpoint['url'] ?? '' ) === $url && is_string( $endpoint['id'] ?? null ) ) {
				$client->delete( 'webhook_endpoints/' . $endpoint['id'] );
			}
		}
		$created = $client->post(
			'webhook_endpoints',
			[
				'url'            => $url,
				'enabled_events' => StripeEvents::ENABLED,
				'api_version'    => StripeClient::API_VERSION,
				'description'    => 'FlexaCommerce',
				'metadata'       => [ 'flexacommerce' => home_url() ],
			]
		);
		$secret  = $created['secret'] ?? '';
		if ( ! is_string( $secret ) || $secret === '' ) {
			throw new StripeError( 'Stripe did not return the webhook signing secret.' );
		}
		return $secret;
	}

	/** @param array<string,mixed> $account */
	private static function account_name( array $account ): string {
		foreach ( [ $account['settings']['dashboard']['display_name'] ?? null, $account['business_profile']['name'] ?? null, $account['email'] ?? null, $account['id'] ?? null ] as $name ) {
			if ( is_string( $name ) && $name !== '' ) {
				return $name;
			}
		}
		return '';
	}

	/** @param list<string> $prefixes */
	private static function check_prefix( string $key, array $prefixes, string $field ): void {
		if ( $key === '' ) {
			return;
		}
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return;
			}
		}
		/* translators: 1: a field label, like "Test secret key"; 2: the expected start, like "sk_test_" */
		throw new \InvalidArgumentException( sprintf( __( '%1$s: this key should start with %2$s. Check that it comes from the right mode and sits in the right field.', 'flexacommerce' ), $field, implode( ' / ', $prefixes ) ) );
	}
}
