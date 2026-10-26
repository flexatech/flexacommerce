<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Badges\Badge;
use Flexa\Commerce\Badges\BadgeMatcher;
use Flexa\Commerce\Badges\BadgeRepository;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Exceptions\InvalidBadge;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Storefront;

/** Product badges (ADR-023): what the list keeps, which products get a badge, and the overlay. */
final class BadgesTest extends CartTestCase {
	private const NOW = '2026-06-01 12:00:00';

	public function tear_down(): void {
		update_option( BadgeRepository::OPTION, [], true );
		parent::tear_down();
	}

	public function test_the_list_keeps_badges_in_order(): void {
		$sale = $this->badges()->save( [ 'label' => ' -{percent}% ' ] );
		$new  = $this->badges()->save(
			[
				'label'     => 'New',
				'condition' => Badge::NEW,
				'bg_color'  => '#2271B1',
				'starts_at' => '2026-05-01T00:00:00.000Z',
			]
		);
		self::assertSame( [ 1, 2 ], [ $sale->id, $new->id ] );
		self::assertSame( '-{percent}%', $sale->label );
		self::assertSame( '#2271b1', $new->bg_color );
		self::assertSame( '2026-05-01 00:00:00', $new->starts_at );

		$changed = $this->badges()->save( [ 'enabled' => false ], $sale->id );
		self::assertFalse( $changed->enabled );
		self::assertSame( '-{percent}%', $changed->label, 'fields not sent stay' );

		$this->badges()->reorder( [ $new->id ] );
		self::assertSame( [ 2, 1 ], array_map( static fn ( Badge $b ): int => $b->id, $this->badges()->all() ) );
		$this->badges()->delete( $new->id );
		self::assertSame( [ 1 ], array_map( static fn ( Badge $b ): int => $b->id, $this->badges()->all() ) );
		self::assertSame( 2, $this->badges()->save( [ 'label' => 'Next' ] )->id, 'the next id follows the largest one kept' );
	}

	public function test_the_editor_refuses_what_it_cannot_draw(): void {
		foreach (
			[
				'label'        => [ 'label' => '' ],
				'bg_color'     => [
					'label'    => 'x',
					'bg_color' => 'red',
				],
				'position'     => [
					'label'    => 'x',
					'position' => 'middle',
				],
				'new_days'     => [
					'label'    => 'x',
					'new_days' => 0,
				],
				'product_ids'  => [
					'label'      => 'x',
					'applies_to' => Badge::PRODUCTS,
				],
				'category_ids' => [
					'label'        => 'x',
					'applies_to'   => Badge::CATEGORIES,
					'category_ids' => [ 'a' ],
				],
				'ends_at'      => [
					'label'     => 'x',
					'starts_at' => '2026-06-02 00:00',
					'ends_at'   => '2026-06-01 00:00',
				],
			] as $field => $input
		) {
			try {
				$this->badges()->save( $input );
				self::fail( $field . ' is refused' );
			} catch ( InvalidBadge $e ) {
				self::assertSame( $field, $e->field );
			}
		}
		self::assertSame( [], $this->badges()->all() );

		$this->expectException( InvalidBadge::class );
		$this->badges()->save( [ 'label' => 'x' ], 99 );
	}

	public function test_conditions_pick_the_products(): void {
		$sale = $this->simple( 2000 );
		$this->save( $sale, [ 'base' => [ 'sale_price' => 1500 ] ] );
		$plain    = $this->simple( 1000, 2 );
		$out      = $this->simple( 1000, 0 );
		$featured = $this->simple();
		$this->save( $featured, [ 'featured' => true ] );
		foreach ( [
			$sale     => '2026-01-01',
			$plain    => '2026-05-25',
			$out      => '2026-01-01',
			$featured => '2026-01-01',
		] as $id => $date ) {
			wp_update_post(
				[
					'ID'            => $id,
					'post_date_gmt' => $date . ' 00:00:00',
					'post_date'     => $date . ' 00:00:00',
				]
			);
		}

		$this->badges()->save( [ 'label' => '-{percent}%' ] );
		$this->badges()->save(
			[
				'label'     => 'Sale',
				'condition' => Badge::ON_SALE,
			]
		);
		$this->badges()->save(
			[
				'label'     => 'New',
				'condition' => Badge::NEW,
				'new_days'  => 10,
			]
		);
		$this->badges()->save(
			[
				'label'     => 'Hot',
				'condition' => Badge::FEATURED,
			]
		);
		$this->badges()->save(
			[
				'label'     => 'Few',
				'condition' => Badge::LOW_STOCK,
			]
		);
		$this->badges()->save(
			[
				'label'     => 'Gone',
				'condition' => Badge::OUT_OF_STOCK,
			]
		);

		self::assertSame( [ '-25%', 'Sale' ], $this->labels( $sale ) );
		self::assertSame( [ 'New', 'Few' ], $this->labels( $plain ), 'two left is at the default threshold' );
		self::assertSame( [ 'Gone' ], $this->labels( $out ) );
		self::assertSame( [ 'Hot' ], $this->labels( $featured ) );
		self::assertSame( 25, BadgeMatcher::sale_percent( $this->find( $sale ), self::NOW ) );
	}

	public function test_targets_places_and_dates(): void {
		$shirt  = $this->simple();
		$mug    = $this->simple();
		$parent = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Clothing',
			]
		);
		$child  = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Shirts',
				'parent'   => $parent,
			]
		);
		wp_set_object_terms( $shirt, [ $child ], PostTypes::CATEGORY );

		$this->badges()->save(
			[
				'label'        => 'Clothes',
				'applies_to'   => Badge::CATEGORIES,
				'category_ids' => [ $parent ],
			]
		);
		$this->badges()->save(
			[
				'label'       => 'Mug',
				'applies_to'  => Badge::PRODUCTS,
				'product_ids' => [ $mug, $shirt ],
				'places'      => Badge::LOOP,
			]
		);
		$this->badges()->save(
			[
				'label'               => 'All but mug',
				'exclude_product_ids' => [ $mug ],
				'ends_at'             => '2026-06-01 12:00',
			]
		);
		$this->badges()->save(
			[
				'label'     => 'Later',
				'starts_at' => '2026-06-02 00:00',
			]
		);

		self::assertSame( [ 'Clothes', 'Mug' ], $this->labels( $shirt ), 'a parent category counts; an ended badge does not show' );
		self::assertSame( [ 'Clothes' ], $this->labels( $shirt, Badge::SINGLE ) );
		self::assertSame( [ 'Mug' ], $this->labels( $mug ) );
		self::assertSame( [ 'Mug', 'All but mug' ], array_slice( $this->labels( $shirt, Badge::LOOP, '2026-05-01 00:00:00' ), 1 ) );
	}

	public function test_the_overlay_in_the_gallery_and_in_lists(): void {
		$id = $this->simple();
		$this->badges()->save(
			[
				'label'    => 'Top',
				'shape'    => 'pill',
				'position' => 'top-right',
			]
		);
		$this->badges()->save(
			[
				'label'  => '<b>Lists</b>',
				'places' => Badge::LOOP,
			]
		);

		$part = Storefront::instance()->part( 'badges', $id );
		self::assertStringContainsString( '<div class="fxc-badges"><span class="fxc-badges__corner fxc-badges__corner--top-left"><span class="fxc-badge fxc-badge--rounded" style="--fxc-badge-bg:#d63638;--fxc-badge-fg:#ffffff">Lists</span></span>', $part, 'tags are stripped from the text' );
		self::assertStringContainsString( 'fxc-badges__corner--top-right"><span class="fxc-badge fxc-badge--pill"', $part );
		self::assertLessThan( strpos( $part, 'top-right' ), strpos( $part, 'top-left' ), 'corners in a fixed order' );

		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		$this->track_post( $image );
		set_post_thumbnail( $id, $image );
		$gallery = do_blocks( '<!-- wp:flexacommerce/product-gallery {"productId":' . $id . '} /-->' );
		self::assertMatchesRegularExpression( '#<figure class="fxc-gallery__main"[^>]*>.*<div class="fxc-badges">.*Top.*</div></figure>#s', $gallery );
		self::assertStringNotContainsString( 'Lists', $gallery, 'a list badge stays off the product page' );

		$featured = Plugin::instance()->on_featured_image( '<figure class="wp-block-post-featured-image"><a href="#"><img src="x"></a></figure>', [], null );
		self::assertStringNotContainsString( 'fxc-badges', $featured, 'no product in the loop' );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$featured        = Plugin::instance()->on_featured_image( '<figure class="wp-block-post-featured-image"><a href="#"><img src="x"></a></figure>', [], null );
		self::assertStringEndsWith( '</span></div></figure>', $featured );
		self::assertStringContainsString( 'Lists', $featured );

		$context = static fn ( array $c ): array => [ 'postId' => $id ] + $c;
		$pre     = [ Plugin::instance(), 'on_pre_render_block' ];
		add_filter( 'render_block_context', $context );
		$added = has_filter( 'pre_render_block', $pre ) === false;
		add_filter( 'pre_render_block', $pre, 10, 3 );
		$block = render_block( parse_blocks( '<!-- wp:post-featured-image {"isLink":true} /-->' )[0] );
		remove_filter( 'render_block_context', $context );
		if ( $added ) {
			remove_filter( 'pre_render_block', $pre );
		}
		remove_filter( 'render_block_core/post-featured-image', [ Plugin::instance(), 'on_featured_image' ] );
		unset( $GLOBALS['post'] );
		self::assertMatchesRegularExpression( '#<figure class="wp-block-post-featured-image">.*<div class="fxc-badges">.*Lists.*</figure>#s', $block, 'the block filter is added on first use' );
	}

	public function test_the_routes(): void {
		$server = $this->server();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/badges' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = $this->send( $server, 'POST', '', [ 'label' => 'A' ] );
		self::assertSame( 201, $response->get_status() );
		$response = $this->send(
			$server,
			'POST',
			'',
			[
				'label'   => 'B',
				'ends_at' => '2026-07-01T10:00:00Z',
			]
		);
		self::assertSame( [ 'A', 'B' ], array_column( $response->get_data()['items'], 'label' ) );
		self::assertSame( '2026-07-01T10:00:00Z', $response->get_data()['items'][1]['ends_at'] );

		$response = $this->send( $server, 'PATCH', '/1', [ 'enabled' => false ] );
		self::assertSame( 200, $response->get_status() );
		self::assertFalse( $response->get_data()['items'][0]['enabled'] );
		self::assertSame( [ 'B', 'A' ], array_column( $this->send( $server, 'POST', '/reorder', [ 'ids' => [ 2 ] ] )->get_data()['items'], 'label' ) );

		$bad = $this->send( $server, 'PATCH', '/1', [ 'text_color' => 'white' ] );
		self::assertSame( 400, $bad->get_status() );
		self::assertSame( 'text_color', $bad->get_data()['data']['field'] );
		self::assertSame( 404, $this->send( $server, 'DELETE', '/9' )->get_status() );
		self::assertSame( [ 'B' ], array_column( $this->send( $server, 'DELETE', '/1' )->get_data()['items'], 'label' ) );
	}

	private function badges(): BadgeRepository {
		return Plugin::instance()->badges();
	}

	private function find( int $id ): \Flexa\Commerce\Catalog\Product {
		$product = Plugin::instance()->catalog()->products()->find( $id );
		self::assertNotNull( $product );
		return $product;
	}

	/** @return list<string> */
	private function labels( int $id, string $place = Badge::LOOP, string $now = self::NOW ): array {
		return array_column( ( new BadgeMatcher( $this->badges() ) )->for_product( $this->find( $id ), $place, $now ), 'label' );
	}

	private function server(): \WP_REST_Server {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		return $wp_rest_server;
	}

	/** @param array<string,mixed> $body */
	private function send( \WP_REST_Server $server, string $method, string $path, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1/admin/badges' . $path );
		$request->set_body_params( $body );
		return $server->dispatch( $request );
	}
}
