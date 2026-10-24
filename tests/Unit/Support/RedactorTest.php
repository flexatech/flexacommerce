<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\Redactor;
use Flexa\Commerce\Tests\Unit\TestCase;

final class RedactorTest extends TestCase {
	public function test_masks_secrets_and_personal_data_recursively(): void {
		$out = ( new Redactor( 'salt' ) )->context(
			[
				'order_id' => 42,
				'api_key'  => 'sk_live_123',
				'request'  => [
					'Authorization' => 'Bearer abc',
					'billing'       => [
						'first_name' => 'Ana',
						'city'       => 'Lyon',
						'email'      => 'ana@example.com',
						'country'    => 'FR',
					],
				],
			]
		);

		self::assertSame( 42, $out['order_id'] );
		self::assertSame( Redactor::MASK, $out['api_key'] );
		self::assertSame( Redactor::MASK, $out['request']['Authorization'] );
		self::assertSame( Redactor::MASK, $out['request']['billing']['first_name'] );
		self::assertSame( Redactor::MASK, $out['request']['billing']['city'] );
		self::assertSame( 'FR', $out['request']['billing']['country'] );
		self::assertStringStartsWith( 'email#', $out['request']['billing']['email'] );
		self::assertStringNotContainsString( 'ana@', (string) json_encode( $out ) );
	}

	public function test_email_hash_is_stable_per_salt(): void {
		$a = new Redactor( 'one' );
		$b = new Redactor( 'two' );
		self::assertSame( $a->email( 'X@Example.com' ), $a->email( 'x@example.com' ) );
		self::assertNotSame( $a->email( 'x@example.com' ), $b->email( 'x@example.com' ) );
	}

	public function test_text_masks_emails_and_card_numbers(): void {
		$text = ( new Redactor( 'salt' ) )->text( 'Customer bob@example.com paid with 4242424242424242.' );
		self::assertStringNotContainsString( 'bob@example.com', $text );
		self::assertStringNotContainsString( '4242424242424242', $text );
		self::assertStringContainsString( '[card]', $text );
	}

	public function test_objects_and_exceptions_are_summarised(): void {
		$out = ( new Redactor( 'salt' ) )->context(
			[
				'e'   => new \RuntimeException( 'boom' ),
				'obj' => new \stdClass(),
			]
		);
		self::assertIsArray( $out['e'] );
		self::assertSame( '[object stdClass]', $out['obj'] );
	}

	public function test_depth_limit(): void {
		$deep = [ 'v' => 1 ];
		for ( $i = 0; $i < 10; $i++ ) {
			$deep = [ 'n' => $deep ];
		}
		$encoded = (string) json_encode( ( new Redactor( 'salt' ) )->context( $deep ) );
		self::assertLessThanOrEqual( 8, substr_count( $encoded, '"n"' ) );
	}
}
