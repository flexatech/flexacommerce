<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Payments;

use Flexa\Commerce\Contracts\Exceptions\InvalidSignature;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeAmount;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeClient;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeEvents;
use Flexa\Commerce\Tests\Unit\TestCase;

final class StripeTest extends TestCase {
	private const SECRET = 'whsec_unit';

	public function test_amounts_follow_stripes_units(): void {
		self::assertSame( 1999, StripeAmount::to_stripe( new Money( 1999, 'USD' ) ) );
		self::assertSame( 500, StripeAmount::to_stripe( new Money( 500, 'JPY' ) ) );
		self::assertSame( 12345, StripeAmount::to_stripe( new Money( 12345, 'KWD' ) ) );
		// ISK has no minor unit in ISO 4217; Stripe counts it in hundredths.
		self::assertSame( 150000, StripeAmount::to_stripe( new Money( 1500, 'ISK' ) ) );
		self::assertSame( 1500, StripeAmount::from_stripe( 150000, 'isk' )->amount );
		self::assertSame( 'ISK', StripeAmount::from_stripe( 150000, 'isk' )->currency );
		self::assertSame( 1999, StripeAmount::from_stripe( 1999, 'usd' )->amount );
	}

	public function test_encode_writes_nested_keys_lists_and_booleans(): void {
		$body = StripeClient::encode(
			[
				'confirm'  => true,
				'capture'  => false,
				'metadata' => [ 'order_id' => '7' ],
				'events'   => [ 'a.b', 'c.d' ],
			]
		);
		self::assertSame( 'confirm=true&capture=false&metadata%5Border_id%5D=7&events%5B0%5D=a.b&events%5B1%5D=c.d', $body );
	}

	public function test_a_signature_must_match_one_secret_and_be_recent(): void {
		$body = '{"id":"evt_1"}';
		$now  = 1_800_000_000;
		$good = hash_hmac( 'sha256', $now . '.' . $body, self::SECRET );

		StripeEvents::verify( $body, 't=' . $now . ',v1=' . $good, [ self::SECRET ], $now );
		StripeEvents::verify( $body, 't=' . $now . ',v1=' . str_repeat( '0', 64 ) . ',v1=' . $good, [ '', 'whsec_other', self::SECRET ], $now + 299 );

		$bad = [
			'no header'       => [ '', $now ],
			'no v1'           => [ 't=' . $now . ',v0=' . $good, $now ],
			'too old'         => [ 't=' . $now . ',v1=' . $good, $now + 301 ],
			'another time'    => [ 't=' . ( $now + 1 ) . ',v1=' . $good, $now ],
			'not a timestamp' => [ 't=abc,v1=' . $good, $now ],
		];
		foreach ( $bad as $case => [ $header, $at ] ) {
			try {
				StripeEvents::verify( $body, $header, [ self::SECRET ], $at );
				self::fail( 'Accepted: ' . $case );
			} catch ( InvalidSignature $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_events_map_to_what_the_store_acts_on(): void {
		$intent = [
			'id'              => 'pi_1',
			'object'          => 'payment_intent',
			'status'          => 'succeeded',
			'amount'          => 1500,
			'amount_received' => 1500,
			'currency'        => 'usd',
			'client_secret'   => 'pi_1_secret_x',
		];
		$paid   = StripeEvents::map( $this->event( 'payment_intent.succeeded', $intent ) );
		self::assertCount( 1, $paid );
		self::assertSame( 'succeeded', $paid[0]->type );
		self::assertSame( 'pi_1', $paid[0]->transaction_ref );
		self::assertSame( 1500, $paid[0]->amount->amount );
		self::assertSame( 'USD', $paid[0]->amount->currency );
		self::assertSame( '2027-01-15 08:00:00', $paid[0]->occurred_at );
		self::assertStringNotContainsString( 'secret', (string) json_encode( $paid[0]->raw ) );

		self::assertSame( [], StripeEvents::map( $this->event( 'payment_intent.succeeded', [ 'metadata' => [ 'fxc_session' => '1' ] ] + $intent ) ) );
		self::assertSame( [], StripeEvents::map( $this->event( 'payment_intent.payment_failed', [ 'last_payment_error' => [ 'payment_method' => [ 'type' => 'card' ] ] ] + $intent ) ) );
		$bounced = StripeEvents::map(
			$this->event(
				'payment_intent.payment_failed',
				[
					'last_payment_error' => [
						'decline_code'   => 'insufficient_funds',
						'payment_method' => [ 'type' => 'sepa_debit' ],
					],
				] + $intent
			)
		);
		self::assertSame( 'failed', $bounced[0]->type );
		self::assertSame( 'insufficient_funds', $bounced[0]->failure_code );
		self::assertSame( 'cancelled', StripeEvents::map( $this->event( 'payment_intent.canceled', $intent ) )[0]->type );

		$session = [
			'id'             => 'cs_1',
			'object'         => 'checkout.session',
			'payment_status' => 'unpaid',
			'amount_total'   => 900,
			'currency'       => 'eur',
		];
		self::assertSame( [], StripeEvents::map( $this->event( 'checkout.session.completed', $session ) ), 'a delayed method has not paid yet' );
		self::assertSame( 'succeeded', StripeEvents::map( $this->event( 'checkout.session.completed', [ 'payment_status' => 'paid' ] + $session ) )[0]->type );
		self::assertSame( 'succeeded', StripeEvents::map( $this->event( 'checkout.session.async_payment_succeeded', $session ) )[0]->type );
		self::assertSame( 'failed', StripeEvents::map( $this->event( 'checkout.session.async_payment_failed', $session ) )[0]->type );
		self::assertSame( 'cancelled', StripeEvents::map( $this->event( 'checkout.session.expired', $session ) )[0]->type );

		$refund = [
			'id'       => 're_1',
			'object'   => 'refund',
			'status'   => 'succeeded',
			'amount'   => 300,
			'currency' => 'usd',
		];
		self::assertSame( 'refund_succeeded', StripeEvents::map( $this->event( 'charge.refund.updated', $refund ) )[0]->type );
		self::assertSame( 'refund_failed', StripeEvents::map( $this->event( 'charge.refund.updated', [ 'status' => 'failed' ] + $refund ) )[0]->type );
		self::assertSame( [], StripeEvents::map( $this->event( 'charge.refund.updated', [ 'status' => 'pending' ] + $refund ) ) );

		self::assertSame( [], StripeEvents::map( $this->event( 'customer.created', [ 'id' => 'cus_1' ] ) ) );
		self::assertSame( [], StripeEvents::map( [ 'type' => 'payment_intent.succeeded' ] ) );
	}

	/**
	 * @param array<string,mixed> $object
	 * @return array<string,mixed>
	 */
	private function event( string $type, array $object ): array {
		return [
			'id'      => 'evt_' . md5( $type . wp_json_encode( $object ) ),
			'type'    => $type,
			'created' => 1_800_000_000,
			'data'    => [ 'object' => $object ],
		];
	}
}
