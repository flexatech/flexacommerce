<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\PayPal;

use Flexa\Commerce\Contracts\Exceptions\InvalidSignature;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Payments\PaymentEvent;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal webhooks: the signature check, which PayPal does for us against
 * the webhook id, and the events the store acts on. Every payment event
 * names the PayPal order, which is the transaction's reference; a refund
 * event names the refund.
 */
final class PayPalEvents {
	/** What the webhook the gateway creates listens to. */
	public const ENABLED = [
		'CHECKOUT.ORDER.APPROVED',
		'PAYMENT.CAPTURE.COMPLETED',
		'PAYMENT.CAPTURE.DENIED',
		'PAYMENT.CAPTURE.DECLINED',
		'PAYMENT.CAPTURE.REFUNDED',
	];

	/** The headers PayPal signs a delivery with, by the field the check takes. */
	private const HEADERS = [
		'auth_algo'         => 'paypal_auth_algo',
		'cert_url'          => 'paypal_cert_url',
		'transmission_id'   => 'paypal_transmission_id',
		'transmission_sig'  => 'paypal_transmission_sig',
		'transmission_time' => 'paypal_transmission_time',
	];

	/**
	 * Asks PayPal whether the delivery is its own. The body goes back as it
	 * came, byte for byte: decoding and encoding it again would change the
	 * signed text.
	 *
	 * @param array<string,string>                   $headers by the names in HEADERS
	 * @param list<array{0:PayPalClient,1:string}> $hooks   a client and its webhook id, per mode
	 * @return PayPalClient the client of the mode that sent it
	 * @throws InvalidSignature
	 */
	public static function verify( string $body, array $headers, array $hooks ): PayPalClient {
		$fields = [];
		foreach ( self::HEADERS as $field => $header ) {
			$value = trim( (string) ( $headers[ $header ] ?? '' ) );
			if ( $value === '' ) {
				throw new InvalidSignature( 'A PayPal signature header is missing.' );
			}
			$fields[ $field ] = $value;
		}
		if ( json_decode( $body ) === null ) {
			throw new InvalidSignature( 'The PayPal webhook body is not JSON.' );
		}
		foreach ( $hooks as [ $client, $webhook_id ] ) {
			if ( $webhook_id === '' ) {
				continue;
			}
			$json = substr( PayPalClient::json( $fields + [ 'webhook_id' => $webhook_id ] ), 0, -1 ) . ',"webhook_event":' . $body . '}';
			try {
				$answer = $client->post( 'v1/notifications/verify-webhook-signature', $json );
			} catch ( PayPalError $e ) {
				if ( $e->refused() ) {
					continue;
				}
				throw $e;
			}
			if ( ( $answer['verification_status'] ?? '' ) === 'SUCCESS' ) {
				return $client;
			}
		}
		throw new InvalidSignature( 'PayPal did not confirm the webhook signature.' );
	}

	/**
	 * Capture and refund events. CHECKOUT.ORDER.APPROVED needs a capture
	 * first, so the gateway handles it.
	 *
	 * @param array<string,mixed> $event a decoded PayPal event
	 * @return list<PaymentEvent>
	 */
	public static function map( array $event ): array {
		$id       = is_string( $event['id'] ?? null ) ? $event['id'] : '';
		$type     = is_string( $event['event_type'] ?? null ) ? $event['event_type'] : '';
		$resource = is_array( $event['resource'] ?? null ) ? $event['resource'] : [];
		if ( $id === '' || ! in_array( $type, self::ENABLED, true ) ) {
			return [];
		}
		$when = is_string( $event['create_time'] ?? null ) && strtotime( $event['create_time'] ) !== false ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $event['create_time'] ) ) : '';
		if ( $type === 'PAYMENT.CAPTURE.REFUNDED' ) {
			$ref = is_string( $resource['id'] ?? null ) ? $resource['id'] : '';
			if ( $ref === '' || ( $resource['status'] ?? '' ) !== 'COMPLETED' ) {
				return [];
			}
			return [ new PaymentEvent( $id, 'refund_succeeded', $ref, self::money( $resource ), $when, self::raw( $resource ) ) ];
		}
		$order = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
		if ( ! is_string( $order ) || $order === '' || $type === 'CHECKOUT.ORDER.APPROVED' ) {
			return [];
		}
		if ( $type === 'PAYMENT.CAPTURE.COMPLETED' ) {
			return [ new PaymentEvent( $id, 'succeeded', $order, self::money( $resource ), $when, self::raw( $resource ) ) ];
		}
		return [ new PaymentEvent( $id, 'failed', $order, self::money( $resource ), $when, self::raw( $resource ), 'declined', __( 'PayPal declined the payment.', 'flexacommerce' ) ) ];
	}

	/**
	 * The capture of an order PayPal returned, as an event: null while it is
	 * pending (an eCheck, a review), since the webhook brings the end.
	 *
	 * @param array<string,mixed> $order a PayPal order, captured
	 */
	public static function captured( array $order, string $event_id ): ?PaymentEvent {
		$ref     = is_string( $order['id'] ?? null ) ? $order['id'] : '';
		$capture = self::capture( $order );
		if ( $ref === '' || $capture === [] ) {
			return null;
		}
		return match ( $capture['status'] ?? '' ) {
			'COMPLETED'                    => new PaymentEvent( $event_id, 'succeeded', $ref, self::money( $capture ), '', self::raw( $capture, $ref ) ),
			'DECLINED', 'FAILED'           => new PaymentEvent( $event_id, 'failed', $ref, self::money( $capture ), '', self::raw( $capture, $ref ), 'declined', __( 'PayPal declined the payment.', 'flexacommerce' ) ),
			default                        => null,
		};
	}

	/**
	 * The first capture of an order's first purchase unit.
	 *
	 * @param array<string,mixed> $order
	 * @return array<string,mixed>
	 */
	public static function capture( array $order ): array {
		$capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
		return is_array( $capture ) ? $capture : [];
	}

	/** @param array<string,mixed> $resource with an `amount` */
	public static function money( array $resource ): Money {
		$amount = is_array( $resource['amount'] ?? null ) ? $resource['amount'] : [];
		return PayPalAmount::from_paypal( (string) ( $amount['value'] ?? '0' ), (string) ( $amount['currency_code'] ?? '' ) );
	}

	/**
	 * What the transaction keeps of a PayPal object: ids and status, never
	 * the payer's details.
	 *
	 * @param array<string,mixed> $object
	 * @return array<string,mixed>
	 */
	public static function raw( array $object, string $order = '' ): array {
		return array_filter(
			[
				'object' => is_string( $object['id'] ?? null ) ? $object['id'] : null,
				'status' => is_string( $object['status'] ?? null ) ? $object['status'] : null,
				'order'  => $order !== '' ? $order : null,
			],
			static fn ( mixed $value ): bool => $value !== null
		);
	}
}
