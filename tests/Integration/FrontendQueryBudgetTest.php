<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

/**
 * Performance budget P1 (doc 06): a blog post request runs no FlexaCommerce
 * query at all.
 */
final class FrontendQueryBudgetTest extends \WP_UnitTestCase {
	public function test_blog_post_runs_no_plugin_queries(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( [ 'post_content' => 'Hello' ] );

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		$wpdb->queries = [];

		$this->go_to( (string) get_permalink( $post_id ) );
		do_action( 'template_redirect' );
		ob_start();
		do_action( 'wp_head' );
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		do_action( 'wp_footer' );
		ob_end_clean();

		$ours = array_filter(
			array_map( static fn ( array $q ): string => (string) $q[0], (array) $wpdb->queries ),
			static fn ( string $sql ): bool => str_contains( $sql, 'fxc_' ) || str_contains( $sql, 'flexacommerce' )
		);
		self::assertGreaterThan( 0, count( (array) $wpdb->queries ), 'SAVEQUERIES did not record anything' );
		self::assertSame( [], array_values( $ours ) );
	}
}
