<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\Stripe;

use Flexa\Commerce\Contracts\Exceptions\InvalidSignature;
use Flexa\Commerce\Contracts\Payments\PaymentEvent;
use Flexa\Commerce\Contracts\Security\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe webhooks: the `Stripe-Signature` check and the events the store
 * acts on. A failed card attempt is not final at Stripe (the shopper may
 * try again on the same page), so only methods that fail days later, like
 * a bank debit, fail the order from here.
 */
final class StripeEvents {
	/** What the webhook endpoint the gateway creates listens to. */
	public const ENABLED = [
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'payment_intent.canceled',
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'checkout.session.expired',
		'charge.refund.updated',
	];

	/** How old a signed event may be, in seconds, as in Stripe's own libraries. */
	public const TOLERANCE = 300;

	/** Methods whose failure comes after the shopper left. */
	private const DELAYED = [ 'acss_debit', 'au_becs_debit', 'bacs_debit', 'boleto', 'customer_balance', 'konbini', 'multibanco', 'oxxo', 'sepa_debit', 'us_bank_account' ];

	/**
	 * @param list<string> $secrets every signing secret the store has; one must match
	 * @throws InvalidSignature
	 */
	public static function verify( string $payload, string $header, array $secrets, int $now ): void {
		$time       = 0;
		$signatures = [];
		foreach ( explode( ',', $header ) as $part ) {
			[ $key, $value ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
			if ( $key === 't' && ctype_digit( $value ) ) {
				$time = (int) $value;
			} elseif ( $key === 'v1' && $value !== '' ) {
				$signatures[] = $value;
			}
		}
		if ( $time === 0 || $signatures === [] ) {
			throw new InvalidSignature( 'The Stripe-Signature header is missing or malformed.' );
		}
		if ( abs( $now - $time ) > self::TOLERANCE ) {
			throw new InvalidSignature( 'The Stripe-Signature timestamp is too old.' );
		}
		foreach ( array_filter( $secrets ) as $secret ) {
			foreach ( $signatures as $signature ) {
				if ( Signature::hmac_sha256_equals( $time . '.' . $payload, $secret, $signature ) ) {
					return;
				}
			}
		}
		throw new InvalidSignature( 'No Stripe-Signature matches the webhook signing secret.' );
	}

	/**
	 * @param array<string,mixed> $event a decoded Stripe event
	 * @return list<PaymentEvent>
	 */
	public static function map( array $event ): array {
		$id     = is_string( $event['id'] ?? null ) ? $event['id'] : '';
		$type   = is_string( $event['type'] ?? null ) ? $event['type'] : '';
		$object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : [];
		$ref    = is_string( $object['id'] ?? null ) ? $object['id'] : '';
		if ( $id === '' || $ref === '' || ! in_array( $type, self::ENABLED, true ) ) {
			return [];
		}
		$when = is_int( $event['created'] ?? null ) ? gmdate( 'Y-m-d H:i:s', $event['created'] ) : '';
		$make = static fn ( string $kind, string $amount_key, string $code = '', string $message = '' ): PaymentEvent => new PaymentEvent(
			$id,
			$kind,
			$ref,
			StripeAmount::from_stripe( (int) ( $object[ $amount_key ] ?? 0 ), (string) ( $object['currency'] ?? 'usd' ) ),
			$when,
			self::raw( $object, (bool) ( $event['livemode'] ?? false ) ),
			$code,
			$message
		);

		if ( str_starts_with( $type, 'payment_intent.' ) ) {
			// The checkout page's own intent: its session's events say the same.
			if ( ( $object['metadata']['fxc_session'] ?? '' ) === '1' ) {
				return [];
			}
			if ( $type === 'payment_intent.succeeded' ) {
				return [ $make( 'succeeded', 'amount_received' ) ];
			}
			if ( $type === 'payment_intent.canceled' ) {
				return [ $make( 'cancelled', 'amount' ) ];
			}
			$error = is_array( $object['last_payment_error'] ?? null ) ? $object['last_payment_error'] : [];
			if ( ! in_array( $error['payment_method']['type'] ?? '', self::DELAYED, true ) ) {
				return [];
			}
			return [ $make( 'failed', 'amount', self::reason( $error ), (string) ( $error['message'] ?? '' ) ) ];
		}

		if ( str_starts_with( $type, 'checkout.session.' ) ) {
			return match ( $type ) {
				'checkout.session.completed'               => ( $object['payment_status'] ?? '' ) === 'paid' ? [ $make( 'succeeded', 'amount_total' ) ] : [],
				'checkout.session.async_payment_succeeded' => [ $make( 'succeeded', 'amount_total' ) ],
				'checkout.session.async_payment_failed'    => [ $make( 'failed', 'amount_total', 'async_payment_failed' ) ],
				default                                    => [ $make( 'cancelled', 'amount_total' ) ],
			};
		}

		// A refund the store asked for, now settled.
		if ( ( $object['object'] ?? '' ) !== 'refund' ) {
			return [];
		}
		return match ( $object['status'] ?? '' ) {
			'succeeded'          => [ $make( 'refund_succeeded', 'amount' ) ],
			'failed', 'canceled' => [ $make( 'refund_failed', 'amount', (string) ( $object['failure_reason'] ?? 'failed' ) ) ],
			default              => [],
		};
	}

	/** @param array<string,mixed> $error */
	public static function reason( array $error ): string {
		foreach ( [ 'decline_code', 'code' ] as $key ) {
			if ( is_string( $error[ $key ] ?? null ) && $error[ $key ] !== '' ) {
				return $error[ $key ];
			}
		}
		return 'failed';
	}

	/**
	 * What the transaction keeps of a Stripe object: never the client secret
	 * or the payment method details.
	 *
	 * @param array<string,mixed> $object
	 * @return array<string,mixed>
	 */
	public static function raw( array $object, bool $livemode ): array {
		return array_filter(
			[
				'object'   => is_string( $object['id'] ?? null ) ? $object['id'] : null,
				'status'   => is_string( $object['status'] ?? null ) ? $object['status'] : null,
				'intent'   => is_string( $object['payment_intent'] ?? null ) ? $object['payment_intent'] : null,
				'livemode' => $livemode,
			],
			static fn ( mixed $value ): bool => $value !== null
		);
	}
}
