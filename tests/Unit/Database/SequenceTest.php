<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Database;

use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Sequence;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Tests\Support\FakeWpdb;
use Flexa\Commerce\Tests\Unit\TestCase;

final class SequenceTest extends TestCase {
	public function test_refuses_to_run_inside_a_transaction(): void {
		$wpdb     = new FakeWpdb();
		$tx       = new Transaction( $wpdb, new AfterCommit() );
		$sequence = new Sequence( $wpdb, $tx );

		$this->expectException( \LogicException::class );
		$tx->run( static fn (): int => $sequence->next( 'order_number' ) );
	}

	public function test_reads_last_insert_id(): void {
		$wpdb                                 = new FakeWpdb();
		$wpdb->vars['SELECT LAST_INSERT_ID()'] = '17';
		$sequence                             = new Sequence( $wpdb, new Transaction( $wpdb, new AfterCommit() ) );

		self::assertSame( 17, $sequence->next( 'order_number' ) );
		self::assertStringContainsString( 'LAST_INSERT_ID(value + 1)', $wpdb->queries[0] );
	}
}
