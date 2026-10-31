<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Reviews\Reviews;
use Flexa\Commerce\Support\Settings;

/** The review stage (doc 05 §5.9, ADR-056), both directions. */
final class ReviewMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
	}

	public function test_reviews_and_replies_are_imported_with_the_summary(): void {
		$tee    = $this->store->products['tee'];
		$good   = self::wc_review( $tee, 'review', 4, '1', $this->store->customer, true );
		$held   = self::wc_review( $tee, 'review', 2, '0' );
		$reply  = self::wc_review( $tee, 'comment', 0, '1', 0, false, $good );
		$spam   = self::wc_review( $tee, 'review', 1, 'spam' );
		$hoodie = self::wc_review( $this->store->products['hoodie'], 'review', 5, '1' );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 4, $job->counter( 'reviews', 'created' ) );
		$this->assertSame( 0, $this->local_id( 'review', $spam ), 'Spam stays behind.' );
		$this->assertSame( Reviews::BUYERS, Settings::string( 'reviews' ), 'Verified owners only is the buyers mode.' );

		$product = $this->local_id( 'product', $tee );
		$review  = get_comment( $this->local_id( 'review', $good ) );
		$this->assertInstanceOf( \WP_Comment::class, $review );
		$this->assertSame( $product, (int) $review->comment_post_ID );
		$this->assertSame( Reviews::TYPE, $review->comment_type );
		$this->assertSame( '1', $review->comment_approved );
		$this->assertSame( $this->store->customer, (int) $review->user_id );
		$this->assertSame( get_comment( $good )->comment_date_gmt, $review->comment_date_gmt );
		$this->assertSame( '4', get_comment_meta( (int) $review->comment_ID, Reviews::META_RATING, true ) );
		$this->assertSame( '1', get_comment_meta( (int) $review->comment_ID, Reviews::META_VERIFIED, true ) );

		$this->assertSame( '0', get_comment( $this->local_id( 'review', $held ) )->comment_approved, 'A held review stays held.' );
		$answer = get_comment( $this->local_id( 'review', $reply ) );
		$this->assertSame( Reviews::TYPE, $answer->comment_type, 'A reply is a review comment in FlexaCommerce.' );
		$this->assertSame( (int) $review->comment_ID, (int) $answer->comment_parent );
		$this->assertSame( '', get_comment_meta( (int) $answer->comment_ID, Reviews::META_RATING, true ) );

		// Approved top-level reviews only.
		$summary = Reviews::summary( $product );
		$this->assertSame( 4.0, $summary['average'] );
		$this->assertSame( 1, $summary['count'] );
		$this->assertSame( 5.0, Reviews::summary( $this->local_id( 'product', $this->store->products['hoodie'] ) )['average'] );
		$this->assertGreaterThan( 0, $this->local_id( 'review', $hoodie ) );

		// A second run finds nothing new.
		$again = $this->run_job( Direction::WC_TO_FXC );
		$this->assertSame( 0, $again->counter( 'reviews', 'created' ) );
	}

	public function test_a_reply_without_its_review_is_rejected(): void {
		$tee   = $this->store->products['tee'];
		$spam  = self::wc_review( $tee, 'review', 3, 'spam' );
		$reply = self::wc_review( $tee, 'comment', 0, '1', 0, false, $spam );

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertSame( 0, $this->local_id( 'review', $reply ) );
		$codes = array_column( $this->service->log( $job->id )['rows'], 'code' );
		$this->assertNotSame( [], array_intersect( [ 'missing_review', 'missing_dependency' ], $codes ), (string) wp_json_encode( $codes ) );
	}

	public function test_reviews_go_back_to_woocommerce(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$product = $this->local_id( 'product', $this->store->products['tee'] );
		$review  = (int) wp_insert_comment(
			[
				'comment_post_ID'      => $product,
				'comment_type'         => Reviews::TYPE,
				'comment_author'       => 'Ana',
				'comment_author_email' => 'ana@example.com',
				'comment_content'      => 'Soft cotton.',
				'comment_approved'     => 1,
				'comment_meta'         => [
					Reviews::META_RATING   => 5,
					Reviews::META_VERIFIED => 1,
				],
			]
		);
		$reply   = (int) wp_insert_comment(
			[
				'comment_post_ID'  => $product,
				'comment_parent'   => $review,
				'comment_type'     => Reviews::TYPE,
				'comment_author'   => 'Store',
				'comment_content'  => 'Thank you.',
				'comment_approved' => 1,
			]
		);

		Settings::update( [ 'reviews' => Reviews::OFF ] );

		$job = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 2, $job->counter( 'reviews', 'created' ) );

		$wc_review = get_comment( $this->remote_id( 'review', $review ) );
		$this->assertSame( $this->store->products['tee'], (int) $wc_review->comment_post_ID );
		$this->assertSame( 'review', $wc_review->comment_type );
		$this->assertSame( '5', get_comment_meta( (int) $wc_review->comment_ID, 'rating', true ) );
		$this->assertSame( '1', get_comment_meta( (int) $wc_review->comment_ID, 'verified', true ) );
		$wc_reply = get_comment( $this->remote_id( 'review', $reply ) );
		$this->assertSame( 'comment', $wc_reply->comment_type, 'WooCommerce keeps replies as plain comments.' );
		$this->assertSame( (int) $wc_review->comment_ID, (int) $wc_reply->comment_parent );
		$this->assertSame( 'no', get_option( 'woocommerce_enable_reviews' ) );
		$this->assertSame( '5.00', wc_get_product( $this->store->products['tee'] )->get_average_rating() );
	}

	private static function wc_review( int $product, string $type, int $rating, string $approved, int $user = 0, bool $verified = false, int $parent = 0 ): int {
		$meta = $rating > 0 ? [ 'rating' => $rating ] : [];
		if ( $verified ) {
			$meta['verified'] = 1;
		}
		return (int) wp_insert_comment(
			[
				'comment_post_ID'      => $product,
				'comment_parent'       => $parent,
				'comment_type'         => $type,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'reviewer@example.com',
				'comment_content'      => 'Review ' . wp_generate_password( 6, false ),
				'comment_approved'     => $approved,
				'comment_date_gmt'     => '2026-03-01 10:00:00',
				'comment_date'         => '2026-03-01 10:00:00',
				'user_id'              => $user,
				'comment_meta'         => $meta,
			]
		);
	}

	private function local_id( string $entity, int $wc_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), $entity, (string) $wc_id ) );
	}

	private function remote_id( string $entity, int $local_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT remote_id FROM %i WHERE entity = %s AND local_id = %d', Schema::table( 'id_map' ), $entity, $local_id ) );
	}

	private function run_job( string $direction ): Job {
		$job = $this->service->create(
			$direction,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'settings', 'terms', 'attributes', 'products', 'customers', 'reviews' ],
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}
}
