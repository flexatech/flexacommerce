<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Support;

use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\RateLimiter;

/** Transient counters behind the checkout and order key limits (doc 06 §2.9). */
final class RateLimiterTest extends \WP_UnitTestCase {
	private const LIMIT = 'order_key_ip';

	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tear_down();
	}

	public function test_hits_pass_until_the_limit_then_report_the_wait(): void {
		$limiter = $this->limiter( '2026-10-25 10:00:00' );
		for ( $i = 0; $i < 10; $i++ ) {
			$limiter->hit( self::LIMIT, '203.0.113.9' );
		}
		self::assertSame( 10, $limiter->attempts( self::LIMIT, '203.0.113.9' ) );

		try {
			$this->limiter( '2026-10-25 10:20:00' )->hit( self::LIMIT, '203.0.113.9' );
			self::fail( 'The eleventh hit was allowed.' );
		} catch ( RateLimited $e ) {
			self::assertSame( 2400, $e->retry_after, 'the window started at the first hit' );
		}

		$this->limiter( '2026-10-25 10:00:00' )->check( self::LIMIT, '198.51.100.1' );
		$limiter->reset( self::LIMIT, '203.0.113.9' );
		$limiter->check( self::LIMIT, '203.0.113.9' );
		self::assertSame( 0, $limiter->attempts( self::LIMIT, '203.0.113.9' ) );
	}

	public function test_check_does_not_count_and_an_empty_subject_is_never_limited(): void {
		$limiter = $this->limiter( '2026-10-25 10:00:00' );
		for ( $i = 0; $i < 20; $i++ ) {
			$limiter->check( self::LIMIT, '203.0.113.9' );
			$limiter->hit( self::LIMIT, '' );
		}
		self::assertSame( 0, $limiter->attempts( self::LIMIT, '203.0.113.9' ) );
	}

	public function test_limits_are_filterable_and_zero_turns_one_off(): void {
		add_filter(
			'flexacommerce.rate_limit.' . self::LIMIT,
			static fn (): array => [
				'limit'  => 1,
				'window' => 30,
			]
		);
		$limiter = $this->limiter( '2026-10-25 10:00:00' );
		self::assertSame( [ 1, 30 ], $limiter->limit( self::LIMIT ) );
		$limiter->hit( self::LIMIT, '203.0.113.9' );
		$this->expectException( RateLimited::class );
		$limiter->hit( self::LIMIT, '203.0.113.9' );
	}

	public function test_a_zero_limit_never_blocks(): void {
		add_filter(
			'flexacommerce.rate_limit.' . self::LIMIT,
			static fn (): array => [
				'limit'  => 0,
				'window' => 30,
			]
		);
		$limiter = $this->limiter( '2026-10-25 10:00:00' );
		for ( $i = 0; $i < 20; $i++ ) {
			$limiter->hit( self::LIMIT, '203.0.113.9' );
		}
		self::assertSame( 0, $limiter->attempts( self::LIMIT, '203.0.113.9' ) );
	}

	public function test_the_client_ip_reads_a_proxy_header_only_when_the_site_names_it(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'junk, 203.0.113.9, 10.0.0.1';
		self::assertSame( '10.0.0.2', ClientIp::get() );

		add_filter( 'flexacommerce.client_ip_header', static fn (): string => 'HTTP_X_FORWARDED_FOR' );
		self::assertSame( '203.0.113.9', ClientIp::get() );

		// The client wrote the left end; the load balancer appended the real address.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.9, 10.0.0.1';
		self::assertSame( '203.0.113.9', ClientIp::get() );
		// Only internal hops: the nearest one.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 10.0.0.1';
		self::assertSame( '10.0.0.1', ClientIp::get() );
	}

	private function limiter( string $now ): RateLimiter {
		return new RateLimiter( new Clock( new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ) ) );
	}
}
