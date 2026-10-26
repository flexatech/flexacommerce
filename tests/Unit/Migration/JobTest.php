<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Migration;

use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus as S;
use Flexa\Commerce\Tests\Unit\TestCase;

final class JobTest extends TestCase {
	/** Doc 05 §4.3, pair by pair so a change to the table shows up here. */
	private const ALLOWED = [
		'draft>ready',
		'draft>cancelled',
		'ready>draft',
		'ready>running',
		'ready>cancelled',
		'running>paused',
		'running>failed',
		'running>cancelled',
		'running>verifying',
		'paused>running',
		'paused>cancelled',
		'failed>running',
		'failed>cancelled',
		'verifying>completed',
		'verifying>completed_with_warnings',
		'verifying>failed',
		'completed>running',
		'completed>verifying',
		'completed_with_warnings>running',
		'completed_with_warnings>verifying',
	];

	public function test_every_pair_of_statuses(): void {
		foreach ( S::ALL as $from ) {
			foreach ( S::ALL as $to ) {
				$pair = "{$from}>{$to}";
				self::assertSame( in_array( $pair, self::ALLOWED, true ), S::can_move( $from, $to ), $pair );
			}
		}
		self::assertFalse( S::can_move( 'unknown', S::RUNNING ) );
	}

	public function test_options_merge_over_the_defaults_and_drop_unknown_keys(): void {
		self::assertSame( Job::DEFAULT_OPTIONS, Job::options( [] ) );

		$options = Job::options(
			[
				'stages'        => [ 'orders', 'products', 'nope' ],
				'batch_orders'  => '25',
				'batch_default' => 0,
				'unknown'       => true,
			]
		);
		self::assertSame( [ 'products', 'orders' ], $options['stages'], 'stage order comes from Job::STAGES' );
		self::assertSame( 25, $options['batch_orders'] );
		self::assertSame( 1, $options['batch_default'] );
		self::assertArrayNotHasKey( 'unknown', $options );
	}

	public function test_options_fall_back_on_unknown_policies(): void {
		$options = Job::options(
			[
				'target_modified'       => 'overwrite',
				'duplicate_sku'         => 'merge',
				'order_number_conflict' => 'renumber',
				'status_map'            => 'wc-processing',
				'stages'                => 'products',
				'backup_confirmed'      => 1,
			]
		);
		self::assertSame( 'skip', $options['target_modified'] );
		self::assertSame( 'null', $options['duplicate_sku'] );
		self::assertSame( 'prefix', $options['order_number_conflict'] );
		self::assertSame( [], $options['status_map'] );
		self::assertSame( Job::STAGES, $options['stages'] );
		self::assertTrue( $options['backup_confirmed'] );

		$options = Job::options(
			[
				'target_modified'       => 'source_wins',
				'duplicate_sku'         => 'suffix',
				'order_number_conflict' => 'skip',
				'status_map'            => [ 'wc-shipped' => 'completed' ],
			]
		);
		self::assertSame( 'source_wins', $options['target_modified'] );
		self::assertSame( 'suffix', $options['duplicate_sku'] );
		self::assertSame( 'skip', $options['order_number_conflict'] );
		self::assertSame( [ 'wc-shipped' => 'completed' ], $options['status_map'] );
	}

	public function test_a_row_decodes_into_a_job(): void {
		$job = Job::from_row(
			[
				'id'           => '7',
				'direction'    => Direction::FXC_TO_WC,
				'status'       => S::RUNNING,
				'dry_run'      => '0',
				'options'      => '{"stages":["products","orders"],"batch_products":10}',
				'stage'        => 'products',
				'cursor_state' => '{"products":{"last_id":40,"upper_id":90}}',
				'counters'     => '{"products":{"created":3,"warnings":1},"orders":{"created":2,"rejected":1}}',
				'lock_token'   => null,
				'locked_until' => null,
				'created_by'   => '1',
				'report'       => 'not json',
				'created_at'   => '2026-10-01 00:00:00',
				'updated_at'   => '2026-10-01 00:00:00',
				'started_at'   => null,
				'finished_at'  => null,
			]
		);
		self::assertFalse( $job->dry_run );
		self::assertSame( [ 'products', 'orders' ], $job->stages() );
		self::assertSame( 10, $job->batch_size( 'products' ) );
		self::assertSame( 100, $job->batch_size( 'orders' ) );
		self::assertSame( 200, $job->batch_size( 'terms' ) );
		self::assertSame( 'fxc', $job->source() );
		self::assertSame( 'wc', $job->target() );
		self::assertSame( 40, $job->cursor['products']['last_id'] );
		self::assertSame( 3, $job->counter( 'products', 'created' ) );
		self::assertSame( 0, $job->counter( 'terms', 'created' ) );
		self::assertSame(
			[
				'created'  => 5,
				'warnings' => 1,
				'rejected' => 1,
			],
			$job->totals()
		);
		self::assertSame( [], $job->report );
	}

	public function test_stages_and_entities(): void {
		self::assertSame( 'tax_rate', Job::entity( 'taxes' ) );
		self::assertSame( 'variant', Job::entity( 'variant' ) );
		self::assertSame( 'orders', Job::stage_of( 'order' ) );
		self::assertNull( Job::stage_of( 'variant' ) );
	}

	public function test_directions(): void {
		self::assertSame( [ 'wc', 'fxc' ], [ Direction::source( Direction::WC_TO_FXC ), Direction::target( Direction::WC_TO_FXC ) ] );
		self::assertSame( [ 'fxc', 'wc' ], [ Direction::source( Direction::FXC_TO_WC ), Direction::target( Direction::FXC_TO_WC ) ] );
		self::assertSame( Direction::WC_TO_FXC, Direction::reverse( Direction::FXC_TO_WC ) );
		self::assertSame( Direction::FXC_TO_WC, Direction::reverse( Direction::WC_TO_FXC ) );
	}
}
