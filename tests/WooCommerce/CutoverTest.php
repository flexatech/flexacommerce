<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Cutover;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Redirects;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

/** The cutover wizard and its redirects (doc 05 §9.2). */
final class CutoverTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	/** Pretends WooCommerce was deactivated; the suite keeps it loaded. */
	private bool $woo = true;

	/** @var array{status:int,body:string}|null fixed response of the URL check, null to echo the expected marker */
	private ?array $response = null;

	public function set_up(): void {
		parent::set_up();
		$this->store = GoldenStore::build();
		// Grouped products are rejected, and a cutover needs a run without rejections.
		wp_delete_post( $this->store->products['grouped'], true );
		$this->service = Plugin::instance()->migration()->service();
		update_option(
			'woocommerce_permalinks',
			[
				'product_base'  => '/shop/%product_cat%',
				'category_base' => '',
				'tag_base'      => 'labels',
			]
		);
		delete_option( Cutover::OPTION );
	}

	public function tear_down(): void {
		delete_option( Cutover::OPTION );
		parent::tear_down();
	}

	public function test_steps_run_in_order(): void {
		$this->assertReason( 'cutover_order', fn () => $this->cutover()->adopt_bases() );
		$this->cutover()->freeze();
		$this->assertReason( 'cutover_order', fn () => $this->cutover()->assign_pages( 'flexacommerce' ) );
	}

	public function test_verify_wants_a_run_after_the_freeze_without_rejections(): void {
		$early = $this->run_job();
		$this->cutover()->freeze();
		// The report predates the freeze only when the clock moved; force it.
		$this->set_report( $early, [ 'verified_at' => '2000-01-01 00:00:00' ] + $early->report );
		$this->assertReason( 'cutover_stale', fn () => $this->cutover()->verify( $early->id, true ) );

		$job = $this->run_job();
		$this->set_counters( $job, [ 'products' => [ 'rejected' => 1 ] ] );
		$this->assertReason( 'cutover_errors', fn () => $this->cutover()->verify( $job->id, true ) );

		$job = $this->run_job();
		$this->set_report( $job, [ 'mismatches' => [ [ 'field' => 'total' ] ] ] + $job->report );
		$this->assertReason( 'cutover_mismatches', fn () => $this->cutover()->verify( $job->id, true ) );

		$job = $this->run_job();
		$this->set_counters( $job, [ 'products' => [ 'warnings' => 2 ] ] );
		$this->assertReason( 'cutover_warnings', fn () => $this->cutover()->verify( $job->id ) );
		$status = $this->cutover()->verify( $job->id, true );
		$this->assertSame( $job->id, $status['job_id'] );
	}

	public function test_wizard_end_to_end_and_rollback(): void {
		$pages = $this->woo_pages();
		$cart  = Settings::int( 'cart_page_id' );
		$this->cutover()->freeze();
		$this->assertTrue( Settings::bool( 'checkout_paused' ) );
		$job = $this->run_job();
		$this->cutover()->verify( $job->id, true );

		$status = $this->cutover()->adopt_bases();
		$this->assertSame(
			[
				'product'  => 'shop',
				'shop'     => 'shop',
				'category' => 'product-category',
				'tag'      => 'labels',
			],
			array_map( static fn ( array $plan ): string => $plan['fxc'], $status['bases'] )
		);
		$this->assertFalse( $status['bases']['product']['identical'] );
		$this->assertSame( 'shop', Settings::string( 'product_base' ) );
		$this->assertSame( 'labels', Settings::string( 'tag_base' ) );

		$this->cutover()->assign_pages( 'woocommerce' );
		$this->assertSame( $pages['cart'], Settings::int( 'cart_page_id' ) );
		$this->assertSame( $pages['myaccount'], Settings::int( 'account_page_id' ) );
		$this->assertSame( '<!-- wp:flexacommerce/checkout /-->', get_post_field( 'post_content', $pages['checkout'] ) );

		$this->assertReason( 'cutover_woocommerce_active', fn () => $this->cutover()->check_urls() );
		$this->woo = false;

		$this->response = [
			'status' => 404,
			'body'   => '',
		];
		$this->assertReason( 'cutover_urls', fn () => $this->cutover()->check_urls() );
		$this->response = null;
		$status         = $this->cutover()->check_urls();
		$this->assertSame( 0, $status['urls']['failed'] );
		$types = array_count_values( array_column( $status['urls']['results'], 'type' ) );
		$this->assertSame( 6, $types['product'] ?? 0, 'published imported products' );
		$this->assertSame( 1, $types['category'] ?? 0 );

		$this->assertReason( 'cutover_order', fn () => $this->cutover()->reopen() );
		$this->cutover()->enable_redirects();
		$this->assertTrue( Cutover::redirecting() );
		$status = $this->cutover()->reopen();
		$this->assertTrue( $status['finished'] );
		$this->assertFalse( Settings::bool( 'checkout_paused' ) );

		$this->cutover()->rollback();
		$this->assertSame( [], Cutover::state() );
		$this->assertSame( '', Settings::string( 'product_base' ) );
		$this->assertSame( $cart, Settings::int( 'cart_page_id' ) );
		$this->assertSame( '[woocommerce_checkout]', get_post_field( 'post_content', $pages['checkout'] ) );
		$this->assertFalse( Settings::bool( 'checkout_paused' ) );
	}

	public function test_redirects_resolve_old_woocommerce_urls(): void {
		$job = $this->run_job();
		$this->assertContains( $job->status, JobStatus::FINISHED );
		$this->cutover()->freeze();
		$this->cutover()->verify( $job->id, true );
		$this->cutover()->adopt_bases();

		$redirects = new Redirects( Plugin::instance()->migration()->ids() );
		$hoodie    = $this->fxc( Record::PRODUCT, $this->store->products['hoodie'] );
		$link      = get_permalink( $hoodie );
		$wc_id     = (string) $this->store->products['hoodie'];
		$slug      = (string) get_post_field( 'post_name', $hoodie );

		$this->assertSame(
			$link,
			$redirects->resolve(
				[
					'post_type' => 'product',
					'p'         => $wc_id,
				],
				'/',
				false
			)
		);
		$this->assertSame( $link, $redirects->resolve( [ 'p' => (string) $this->store->variations['red_large'] ], '/', true ) );
		$this->assertSame( $link, $redirects->resolve( [ 'product' => $slug ], '/', true ) );
		$this->assertSame( $link, $redirects->resolve( [], '/shop/shirts/' . $slug . '/', true ) );
		$this->assertNull( $redirects->resolve( [ 'product' => $slug ], '/', false ), 'a routed request is left alone' );
		$this->assertNull( $redirects->resolve( [], '/shop/shirts/no-such-thing/', true ) );

		$shirts = get_term( $this->fxc( Record::TERM, $this->store->terms['shirts'] ) );
		$this->assertInstanceOf( \WP_Term::class, $shirts );
		$this->assertSame( get_term_link( $shirts ), $redirects->resolve( [ 'product_cat' => 'shirts' ], '/', true ) );
		// The category base stayed identical, so its paths need no redirect.
		$this->assertNull( $redirects->resolve( [], '/product-category/shirts/', true ) );
		$this->assertSame( PostTypes::CATEGORY, $shirts->taxonomy );
	}

	private function cutover(): Cutover {
		$fetch = function ( string $url ): array {
			if ( $this->response !== null ) {
				return $this->response;
			}
			foreach ( get_posts(
				[
					'post_type'   => PostTypes::PRODUCT,
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			) as $id ) {
				if ( get_permalink( $id ) === $url ) {
					return [
						'status' => 200,
						'body'   => '<body class="single postid-' . $id . '">',
					];
				}
			}
			foreach ( get_terms(
				[
					'taxonomy'   => PostTypes::CATEGORY,
					'hide_empty' => false,
				]
			) as $term ) {
				if ( $term instanceof \WP_Term && get_term_link( $term ) === $url ) {
					return [
						'status' => 200,
						'body'   => '<body class="archive term-' . $term->term_id . '">',
					];
				}
			}
			return [
				'status' => 404,
				'body'   => '',
			];
		};
		return new Cutover(
			Plugin::instance()->migration()->jobs(),
			Plugin::instance()->migration()->ids(),
			Plugin::instance()->services()->clock(),
			fn (): bool => $this->woo,
			$fetch,
		);
	}

	private function run_job(): Job {
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}

	/** @param array<string,mixed> $report */
	private function set_report( Job $job, array $report ): void {
		Plugin::instance()->migration()->jobs()->update( $job->id, [ 'report' => $report ], gmdate( 'Y-m-d H:i:s' ) );
	}

	/** @param array<string,array<string,int>> $counters */
	private function set_counters( Job $job, array $counters ): void {
		Plugin::instance()->migration()->jobs()->update( $job->id, [ 'counters' => $counters ], gmdate( 'Y-m-d H:i:s' ) );
	}

	private function fxc( string $entity, int $wc_id ): int {
		$row = Plugin::instance()->migration()->ids()->by_remote( $entity, (string) $wc_id );
		$this->assertNotNull( $row );
		return $row->local_id;
	}

	/** @return array<string,int> */
	private function woo_pages(): array {
		$pages = [];
		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
			$pages[ $page ] = self::factory()->post->create(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page,
					'post_content' => '[woocommerce_' . $page . ']',
				]
			);
			update_option( 'woocommerce_' . $page . '_page_id', $pages[ $page ] );
		}
		return $pages;
	}

	private function assertReason( string $reason, callable $work ): void {
		try {
			$work();
		} catch ( MigrationError $e ) {
			$this->assertSame( $reason, $e->reason, $e->getMessage() );
			return;
		}
		$this->fail( 'Expected MigrationError ' . $reason );
	}
}
