<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

use Flexa\Commerce\Plugin;

final class SequenceTest extends \WP_UnitTestCase {
	public function test_next_increments_by_one(): void {
		$sequence = Plugin::instance()->services()->sequence();
		$first    = $sequence->next( 'order_number' );
		self::assertSame( $first + 1, $sequence->next( 'order_number' ) );
	}

	public function test_creates_missing_sequences(): void {
		$sequence = Plugin::instance()->services()->sequence();
		$name     = 'test_' . wp_generate_password( 8, false );
		self::assertSame( 1, $sequence->next( $name ) );
		self::assertSame( 2, $sequence->next( $name ) );
	}

	public function test_raise_to_never_lowers(): void {
		$sequence = Plugin::instance()->services()->sequence();
		$name     = 'test_' . wp_generate_password( 8, false );
		$sequence->raise_to( $name, 500 );
		$sequence->raise_to( $name, 100 );
		self::assertSame( 500, $sequence->current( $name ) );
		self::assertSame( 501, $sequence->next( $name ) );
	}
}
