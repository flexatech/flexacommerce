<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Performance;

use PHPUnit\Framework\TestCase;

/**
 * T1 budgets (doc 10 §5.2): each request runs in a fresh process with and
 * without FlexaCommerce, so boot cost is measured. A plain TestCase, because
 * WP_UnitTestCase keeps fixtures in a transaction the probes cannot see.
 *
 * FXC_PERF_RECORD=1 rewrites baseline.json for the running platform and PHP version.
 */
final class FrontendBudgetTest extends TestCase {
	private static int $post_id = 0;

	/** @var array<string,mixed> */
	private static array $budgets = [];

	public static function setUpBeforeClass(): void {
		global $wpdb;
		if ( is_multisite() ) {
			return;
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		self::$post_id = (int) wp_insert_post(
			[
				'post_title'   => 'Budget probe post',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			]
		);
		self::$budgets = require __DIR__ . '/budgets.php';
	}

	public static function tearDownAfterClass(): void {
		if ( self::$post_id > 0 ) {
			wp_delete_post( self::$post_id, true );
		}
	}

	protected function setUp(): void {
		if ( is_multisite() ) {
			self::markTestSkipped( 'The probe boots a single site.' );
		}
	}

	/** @return array<string,array{string}> */
	public static function pages(): array {
		return [
			'blog-post' => [ '/?p={post}' ],
			'home'      => [ '/' ],
		];
	}

	/** @dataProvider pages */
	public function test_page_without_commerce_content_stays_in_budget( string $uri ): void {
		$scenario = (string) $this->dataName();
		$budget   = self::$budgets['P1'];
		$run      = Probe::compare( str_replace( '{post}', (string) self::$post_id, $uri ) );
		$on       = $run['on'];
		$off      = $run['off'];

		self::assertSame( 200, $on['status'], 'The probe page did not render.' );
		self::assertGreaterThan( 0, $on['html'], 'The probe page rendered nothing.' );
		self::assertContains( 'Flexa\\Commerce\\Plugin', $on['own'], 'FlexaCommerce did not load in the probe.' );

		$this->assert_queries( $on, $off, (int) $budget['queries'] );
		self::assertSame(
			[],
			array_values( array_diff( $on['own'], $budget['classes'] ) ),
			'Classes outside the P1 allowlist were loaded.'
		);
		self::assertLessThanOrEqual(
			$budget['callbacks'],
			$on['callbacks'] - $off['callbacks'],
			'Callbacks added over the P1 budget.'
		);
		self::assertSame( [], $on['assets'], 'FlexaCommerce printed assets on a non-commerce page.' );
		$this->assert_autoload( $on );
		$this->assert_memory( $scenario, $on['peak'] - $off['peak'], (int) $budget['memory'] );
	}

	public function test_rest_request_for_another_namespace_builds_no_endpoint(): void {
		$run    = Probe::compare( '/?rest_route=/wp/v2/posts' );
		$prefix = self::$budgets['R-REST']['classes'];

		self::assertSame( 200, $run['on']['status'] );
		$this->assert_queries( $run['on'], $run['off'], (int) self::$budgets['R-REST']['queries'] );
		$built = array_filter(
			$run['on']['own'],
			static function ( string $name ) use ( $prefix ): bool {
				foreach ( $prefix as $p ) {
					if ( str_starts_with( $name, $p ) ) {
						return true;
					}
				}
				return false;
			}
		);
		self::assertSame( [], array_values( $built ), 'Endpoint classes were built for a foreign route.' );
	}

	/** Guards the test above: our own route must still load its endpoints. */
	public function test_rest_request_for_our_namespace_builds_endpoints(): void {
		$on = Probe::run( '/?rest_route=/' . FLEXACOMMERCE_REST_NAMESPACE . '/admin/settings', true );

		self::assertContains( 'Flexa\\Commerce\\Api\\Admin\\SettingsEndpoint', $on['own'] );
		self::assertSame( 401, $on['status'], 'Anonymous access to admin settings must be refused.' );
	}

	/**
	 * @param array<string,mixed> $on
	 * @param array<string,mixed> $off
	 */
	private function assert_queries( array $on, array $off, int $budget ): void {
		$extra = count( $on['queries'] ) - count( $off['queries'] );
		if ( $extra <= $budget ) {
			self::assertLessThanOrEqual( $budget, $extra );
			return;
		}
		$core = array_count_values( array_column( $off['queries'], 'sql' ) );
		$list = [];
		foreach ( $on['queries'] as $query ) {
			if ( ( $core[ $query['sql'] ] ?? 0 ) > 0 ) {
				--$core[ $query['sql'] ];
				continue;
			}
			$list[] = $query['sql'] . "\n    from " . $query['caller'];
		}
		self::fail( "{$extra} extra queries (budget {$budget}):\n  " . implode( "\n  ", $list ) );
	}

	/** @param array<string,mixed> $on */
	private function assert_autoload( array $on ): void {
		$budget = self::$budgets['P10'];
		self::assertLessThanOrEqual( $budget['options'], count( $on['autoload'] ), 'Too many autoloaded options.' );
		self::assertLessThanOrEqual( $budget['bytes'], array_sum( $on['autoload'] ), 'Autoloaded options too large.' );
	}

	private function assert_memory( string $scenario, int $delta, int $budget ): void {
		if ( ! Probe::has_opcache() ) {
			// Without OPcache compiled code counts as request memory; not comparable.
			return;
		}
		self::assertLessThanOrEqual( $budget, $delta, "Extra peak memory {$delta} B is over the budget." );

		$file     = __DIR__ . '/baseline.json';
		// Allocation sizes differ between platforms, so a baseline only gates
		// the platform and PHP minor version it was recorded on.
		$version  = PHP_OS_FAMILY . '-' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$baseline = is_file( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : [];

		if ( getenv( 'FXC_PERF_RECORD' ) === '1' ) {
			$baseline[ $version ][ $scenario ] = $delta;
			ksort( $baseline );
			file_put_contents( $file, json_encode( $baseline, JSON_PRETTY_PRINT ) . "\n" );
			return;
		}
		$recorded = $baseline[ $version ][ $scenario ] ?? null;
		if ( ! is_int( $recorded ) ) {
			fwrite( STDERR, "\nNo memory baseline for {$version} {$scenario}: measured {$delta} B.\n" );
			return;
		}
		$limit = (int) ceil( $recorded * ( 1 + self::$budgets['memory_band'] ) );
		self::assertLessThanOrEqual(
			$limit,
			$delta,
			"Extra peak memory {$delta} B is more than 5 % over the baseline {$recorded} B (PHP {$version})."
		);
	}
}
