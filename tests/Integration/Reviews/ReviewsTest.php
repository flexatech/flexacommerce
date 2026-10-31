<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Reviews;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Reviews\Reviews;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** Product reviews (ADR-056): who may post, the stored summary, the page and the rating sort. */
final class ReviewsTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		Reviews::instance()->register();
		// Every comment here comes from the same address.
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		parent::tear_down();
		// Orders commit, so the setting would outlive the rollback.
		global $wpdb;
		Settings::update( [ 'reviews' => Reviews::ALL ] );
		$wpdb->query( 'COMMIT' );
	}

	public function test_a_review_gets_its_type_rating_and_the_summary(): void {
		$product = $this->simple();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$first  = $this->post( $product, 4 );
		$second = $this->post( $product, 5 );
		self::assertIsInt( $first );
		self::assertIsInt( $second );
		self::assertSame( Reviews::TYPE, get_comment( $first )->comment_type );
		self::assertSame( '4', get_comment_meta( $first, Reviews::META_RATING, true ) );
		self::assertSame( '0', get_comment_meta( $first, Reviews::META_VERIFIED, true ) );

		$summary = Reviews::summary( $product );
		self::assertSame( 4.5, $summary['average'] );
		self::assertSame( 2, $summary['count'] );
		self::assertSame(
			[
				5 => 1,
				4 => 1,
				3 => 0,
				2 => 0,
				1 => 0,
			],
			$summary['counts']
		);

		// Held for moderation, then trashed: the summary counts approved reviews only.
		wp_set_comment_status( $second, 'hold' );
		self::assertSame( 1, Reviews::summary( $product )['count'] );
		wp_trash_comment( $first );
		self::assertSame( '', get_post_meta( $product, Reviews::META_AVERAGE, true ) );
		self::assertSame( 0, Reviews::summary( $product )['count'] );
	}

	public function test_a_review_needs_a_rating_and_a_reply_needs_a_moderator(): void {
		$product = $this->simple();

		$refused = $this->post( $product, 0 );
		self::assertWPError( $refused );
		self::assertSame( 'fxc_review_rating', $refused->get_error_code() );
		self::assertSame( 400, $refused->get_error_data() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$review = $this->post( $product, 3 );
		self::assertIsInt( $review );

		wp_set_current_user( 0 );
		$reply = $this->post( $product, 0, $review );
		self::assertWPError( $reply );
		self::assertSame( 'fxc_review_reply', $reply->get_error_code() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$answer = $this->post( $product, 0, $review );
		self::assertIsInt( $answer );
		self::assertSame( Reviews::TYPE, get_comment( $answer )->comment_type );
		self::assertSame( '', get_comment_meta( $answer, Reviews::META_RATING, true ) );
		// A reply has no rating, so the summary still counts one review.
		self::assertSame( 1, Reviews::summary( $product )['count'] );
	}

	public function test_buyers_mode_takes_reviews_from_customers_who_bought(): void {
		Settings::update( [ 'reviews' => Reviews::BUYERS ] );
		$product = $this->simple();
		$order   = $this->place( [ $product => 1 ], 0, null, OrderStatus::PROCESSING );
		$buyer   = self::factory()->user->create( [ 'user_email' => 'ada@example.com' ] );
		$other   = self::factory()->user->create( [ 'user_email' => 'grace@example.com' ] );
		self::assertSame( 'ada@example.com', $order->customer_email );

		self::assertSame( 'login', Reviews::instance()->refusal( $product ) );
		$guest = $this->post( $product, 5 );
		self::assertWPError( $guest );
		self::assertSame( 'fxc_review_buyers', $guest->get_error_code() );
		self::assertSame( 403, $guest->get_error_data() );

		wp_set_current_user( $other );
		self::assertSame( Reviews::BUYERS, Reviews::instance()->refusal( $product ) );
		self::assertWPError( $this->post( $product, 5 ) );

		// The order is a guest order with the same email: still the buyer.
		wp_set_current_user( $buyer );
		self::assertSame( '', Reviews::instance()->refusal( $product ) );
		$review = $this->post( $product, 5 );
		self::assertIsInt( $review );
		self::assertSame( '1', get_comment_meta( $review, Reviews::META_VERIFIED, true ) );
	}

	public function test_a_pending_order_does_not_count_as_bought(): void {
		Settings::update( [ 'reviews' => Reviews::BUYERS ] );
		$product = $this->simple();
		$this->place( [ $product => 1 ] );
		wp_set_current_user( self::factory()->user->create( [ 'user_email' => 'ada@example.com' ] ) );
		self::assertSame( Reviews::BUYERS, Reviews::instance()->refusal( $product ) );
	}

	public function test_off_closes_reviews_on_products_only(): void {
		$product = $this->simple();
		$post    = self::factory()->post->create( [ 'comment_status' => 'open' ] );
		self::assertTrue( comments_open( $product ) );

		Settings::update( [ 'reviews' => Reviews::OFF ] );
		self::assertFalse( comments_open( $product ) );
		self::assertTrue( comments_open( $post ) );
		$refused = $this->post( $product, 5 );
		self::assertWPError( $refused );
		self::assertSame( 'fxc_reviews_off', $refused->get_error_code() );
	}

	public function test_the_page_shows_stars_reviews_and_the_form(): void {
		$product = $this->simple();
		self::assertSame( '', do_blocks( '<!-- wp:flexacommerce/product-rating {"productId":' . $product . '} /-->' ) );

		$html = do_blocks( '<!-- wp:flexacommerce/product-reviews {"productId":' . $product . '} /-->' );
		self::assertStringContainsString( 'There are no reviews yet.', $html );
		self::assertStringContainsString( 'name="rating" value="5" required', $html );
		self::assertStringNotContainsString( 'name="url"', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->post( $product, 4, 0, 'Fits well.' );
		wp_set_current_user( 0 );

		$rating = do_blocks( '<!-- wp:flexacommerce/product-rating {"productId":' . $product . '} /-->' );
		self::assertStringContainsString( '--fxc-stars:80%', $rating );
		self::assertStringContainsString( '1 review', $rating );

		$html = do_blocks( '<!-- wp:flexacommerce/product-reviews {"productId":' . $product . '} /-->' );
		self::assertStringContainsString( 'Reviews (1)', $html );
		self::assertStringContainsString( 'Fits well.', $html );
		self::assertStringContainsString( '<meter min="0" max="1" value="1">', $html );

		Settings::update( [ 'reviews' => Reviews::BUYERS ] );
		$html = do_blocks( '<!-- wp:flexacommerce/product-reviews {"productId":' . $product . '} /-->' );
		self::assertStringContainsString( 'Fits well.', $html );
		self::assertStringContainsString( 'Log in', $html );
		self::assertStringNotContainsString( 'name="rating"', $html );
	}

	public function test_the_rating_sort_puts_unrated_products_last(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ids = [
			'low'  => $this->simple(),
			'none' => $this->simple(),
			'high' => $this->simple(),
		];
		$this->post( $ids['low'], 2 );
		$this->post( $ids['high'], 5 );
		$this->post( $ids['high'], 4 );
		wp_set_current_user( 0 );

		$this->go_to(
			add_query_arg(
				[
					'post_type' => PostTypes::PRODUCT,
					'orderby'   => 'rating',
				],
				home_url( '/' )
			)
		);
		$posts = array_map( static fn ( \WP_Post $post ): int => $post->ID, $GLOBALS['wp_query']->posts );
		self::assertSame( [ $ids['high'], $ids['low'], $ids['none'] ], array_values( array_intersect( $posts, $ids ) ) );
	}

	/**
	 * Posts a review the way the comment form does, with `rating` in $_POST.
	 *
	 * @return int|\WP_Error
	 */
	private function post( int $product, int $rating, int $parent = 0, string $text = 'Good.' ): int|\WP_Error {
		$_POST = $rating > 0 ? [ 'rating' => (string) $rating ] : [];
		$user  = wp_get_current_user();
		$id    = wp_new_comment(
			[
				'comment_post_ID'      => $product,
				'comment_parent'       => $parent,
				'comment_content'      => $text . ' ' . wp_generate_password( 8, false ),
				'comment_author'       => $user->ID > 0 ? $user->display_name : 'Guest',
				'comment_author_email' => $user->ID > 0 ? $user->user_email : 'guest@example.com',
				'comment_author_url'   => '',
				'user_id'              => $user->ID,
				'comment_author_IP'    => '127.0.0.1',
				'comment_agent'        => 'phpunit',
			],
			true
		);
		$_POST = [];
		return $id instanceof \WP_Error ? $id : (int) $id;
	}
}
