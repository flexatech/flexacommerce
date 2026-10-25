<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Payments;

use Flexa\Commerce\Contracts\Exceptions\InvalidSignature;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Payments\AbstractPaymentGateway;
use Flexa\Commerce\Contracts\Payments\PaymentEvent;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\Payments\RefundRequest;
use Flexa\Commerce\Contracts\Payments\RefundResult;
use Flexa\Commerce\Contracts\Payments\ReturnRequest;
use Flexa\Commerce\Contracts\SettingField;

/** An online gateway that signs its webhooks with HMAC-SHA256. */
final class FakeGateway extends AbstractPaymentGateway {
	public const ID     = 'fake_pay';
	public const SECRET = 'whsec_test_1234';

	protected const SUPPORTS = [ 'refunds', 'partial_refunds', 'webhooks' ];

	/** What refund() answers: succeeded, pending, failed or throw. */
	public static string $refund_mode = 'succeeded';

	/** What process_payment() answers: redirect, succeeded, failed or throw. */
	public static string $payment_mode = 'redirect';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return 'Fake pay';
	}

	public function settings_fields(): array {
		return [ new SettingField( 'webhook_secret', 'secret', 'Webhook secret' ) ];
	}

	public function process_payment( PaymentRequest $request ): PaymentResult {
		$ref = 'pi_' . $request->transaction_id;
		return match ( self::$payment_mode ) {
			'succeeded' => PaymentResult::succeeded( $ref ),
			'failed'    => PaymentResult::failed( 'card_declined', 'Card declined' ),
			'throw'     => throw new \RuntimeException( 'network down' ),
			default     => PaymentResult::redirect( 'https://pay.example.test/' . $ref, $ref ),
		};
	}

	public function verify_return( ReturnRequest $request ): ?PaymentEvent {
		return new PaymentEvent( 'return-' . $request->transaction_id, 'succeeded', (string) $request->gateway_reference, new Money( (int) ( $request->query['amount'] ?? 0 ), 'USD' ) );
	}

	public function parse_webhook( \WP_REST_Request $request ): array {
		$body = $request->get_body();
		$sig  = (string) $request->get_header( 'x_fake_signature' );
		if ( ! hash_equals( hash_hmac( 'sha256', $body, (string) $this->setting( 'webhook_secret' ) ), $sig ) ) {
			throw new InvalidSignature( 'Bad signature.' );
		}
		$data = (array) json_decode( $body, true );
		return [
			new PaymentEvent(
				(string) $data['id'],
				(string) $data['type'],
				(string) $data['ref'],
				new Money( (int) $data['amount'], (string) ( $data['currency'] ?? 'USD' ) ),
				'',
				[
					'card_last4' => '4242',
					'status'     => 'ok',
				]
			),
		];
	}

	public function refund( RefundRequest $request ): RefundResult {
		return match ( self::$refund_mode ) {
			'pending' => RefundResult::pending( 're_' . $request->refund_transaction_id ),
			'failed'  => RefundResult::failed( 'insufficient_funds', 'Balance too low' ),
			'throw'   => throw new \RuntimeException( 'network down' ),
			default   => RefundResult::succeeded( 're_' . $request->refund_transaction_id ),
		};
	}

	/** @param array<string,mixed> $payload */
	public static function signed( array $payload ): \WP_REST_Request {
		$body    = (string) wp_json_encode( $payload );
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/webhooks/' . self::ID );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-fake-signature', hash_hmac( 'sha256', $body, self::SECRET ) );
		return $request;
	}
}
