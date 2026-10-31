<?php

declare(strict_types=1);

namespace Flexa\Commerce\Reviews;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Product reviews (ADR-056): WordPress comments of type `review` on a
 * product, with the star rating and the verified-buyer flag in comment
 * meta, as WooCommerce stores them. Core posts, moderates and notifies;
 * this class sets the type, checks who may review and keeps the rating
 * summary in product meta, so lists and the rating sort read no comments.
 */
final class Reviews {
	use HasInstance;

	public const TYPE          = 'review';
	public const META_RATING   = 'rating';
	public const META_VERIFIED = 'verified';

	/** Product meta: the average rating, the number of rated reviews and the reviews per star. */
	public const META_AVERAGE = '_fxc_rating';
	public const META_COUNT   = '_fxc_review_count';
	public const META_COUNTS  = '_fxc_rating_counts';

	/** Who may review: nobody, anyone (with the Discussion settings), customers who bought the product. */
	public const OFF    = 'off';
	public const ALL    = 'all';
	public const BUYERS = 'buyers';

	public function __construct( private readonly ReviewRepository $repository = new ReviewRepository() ) {}

	/**
	 * Hooks for requests that show or write reviews. Plugin adds them in
	 * admin, REST and WP-CLI requests and when a comment form is posted;
	 * the storefront adds them when it renders reviews. Other pages carry
	 * none (P1).
	 */
	public function register(): void {
		if ( has_filter( 'comments_open', [ $this, 'open' ] ) !== false ) {
			return;
		}
		add_filter( 'comments_open', [ $this, 'open' ], 10, 2 );
		add_filter( 'preprocess_comment', [ $this, 'prepare' ], 5 );
		add_filter( 'pre_comment_approved', [ $this, 'check' ], 20, 2 );
		add_action( 'wp_insert_comment', [ $this, 'on_inserted' ], 10, 2 );
		add_action( 'edit_comment', [ $this, 'on_edited' ] );
		add_action( 'wp_update_comment_count', [ $this, 'refresh' ] );
		if ( is_admin() ) {
			add_filter( 'admin_comment_types_dropdown', [ $this, 'comment_types' ] );
			add_filter( 'comment_text', [ $this, 'admin_text' ], 10, 2 );
		}
	}

	public static function mode(): string {
		$mode = Settings::string( 'reviews' );
		return in_array( $mode, [ self::OFF, self::ALL, self::BUYERS ], true ) ? $mode : self::ALL;
	}

	/**
	 * The rating summary of a product, from its meta.
	 *
	 * @return array{average:float,count:int,counts:array<int,int>}
	 */
	public static function summary( int $product_id ): array {
		$counts = get_post_meta( $product_id, self::META_COUNTS, true );
		$out    = [];
		for ( $star = 5; $star >= 1; --$star ) {
			$out[ $star ] = is_array( $counts ) ? max( 0, (int) ( $counts[ $star ] ?? 0 ) ) : 0;
		}
		return [
			'average' => (float) get_post_meta( $product_id, self::META_AVERAGE, true ),
			'count'   => (int) get_post_meta( $product_id, self::META_COUNT, true ),
			'counts'  => $out,
		];
	}

	/** Reviews on products follow the setting, whatever the product's own comment status is. */
	public function open( bool $open, int|string $post_id ): bool {
		return get_post_type( (int) $post_id ) === PostTypes::PRODUCT ? self::mode() !== self::OFF : $open;
	}

	/**
	 * Why the current visitor may not review the product, or '' when they
	 * may: `off`, `login` (buyers only, not logged in) or `buyers`.
	 */
	public function refusal( int $product_id ): string {
		$mode = self::mode();
		if ( $mode === self::OFF ) {
			return self::OFF;
		}
		if ( $mode === self::ALL || current_user_can( 'moderate_comments' ) ) {
			return '';
		}
		$user = wp_get_current_user();
		if ( $user->ID === 0 ) {
			return 'login';
		}
		return $this->repository->bought( $product_id, $user->ID, (string) $user->user_email ) ? '' : self::BUYERS;
	}

	/**
	 * A comment on a product is a review; a top-level one gets its rating
	 * from the form and the verified flag from the orders.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function prepare( array $data ): array {
		$product_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( get_post_type( $product_id ) !== PostTypes::PRODUCT || ! in_array( (string) ( $data['comment_type'] ?? '' ), [ '', 'comment', self::TYPE ], true ) ) {
			return $data;
		}
		$data['comment_type'] = self::TYPE;
		if ( (int) ( $data['comment_parent'] ?? 0 ) > 0 ) {
			return $data;
		}
		$meta = is_array( $data['comment_meta'] ?? null ) ? $data['comment_meta'] : [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core's comment form has no nonce; wp-comments-post.php handles the submission.
		$rating = isset( $_POST['rating'] ) ? absint( wp_unslash( $_POST['rating'] ) ) : (int) ( $meta[ self::META_RATING ] ?? 0 );
		unset( $meta[ self::META_RATING ] );
		if ( $rating >= 1 && $rating <= 5 ) {
			$meta[ self::META_RATING ] = $rating;
		}
		$meta[ self::META_VERIFIED ] = $this->repository->bought( $product_id, (int) ( $data['user_id'] ?? 0 ), (string) ( $data['comment_author_email'] ?? '' ) ) ? 1 : 0;
		$data['comment_meta']        = $meta;
		return $data;
	}

	/**
	 * Refuses a review without a rating, or from someone the setting leaves
	 * out. Moderators may always write, so a store can answer or add notes.
	 *
	 * @param int|string|\WP_Error $approved
	 * @param array<string,mixed>  $data
	 * @return int|string|\WP_Error
	 */
	public function check( int|string|\WP_Error $approved, array $data ): int|string|\WP_Error {
		if ( $approved instanceof \WP_Error || ( $data['comment_type'] ?? '' ) !== self::TYPE || current_user_can( 'moderate_comments' ) ) {
			return $approved;
		}
		if ( get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) ) !== PostTypes::PRODUCT ) {
			return $approved;
		}
		$mode = self::mode();
		if ( $mode === self::OFF ) {
			return new \WP_Error( 'fxc_reviews_off', __( 'This store does not take reviews.', 'flexacommerce' ), 403 );
		}
		if ( (int) ( $data['comment_parent'] ?? 0 ) > 0 ) {
			return new \WP_Error( 'fxc_review_reply', __( 'Only the store can reply to a review.', 'flexacommerce' ), 403 );
		}
		$meta = is_array( $data['comment_meta'] ?? null ) ? $data['comment_meta'] : [];
		if ( ! isset( $meta[ self::META_RATING ] ) ) {
			return new \WP_Error( 'fxc_review_rating', __( 'Please choose a rating for your review.', 'flexacommerce' ), 400 );
		}
		if ( $mode === self::BUYERS && ( (int) ( $data['user_id'] ?? 0 ) === 0 || empty( $meta[ self::META_VERIFIED ] ) ) ) {
			return new \WP_Error( 'fxc_review_buyers', __( 'Only logged-in customers who bought this product can review it.', 'flexacommerce' ), 403 );
		}
		return $approved;
	}

	public function on_inserted( int $comment_id, \WP_Comment $comment ): void {
		unset( $comment_id );
		if ( $comment->comment_type === self::TYPE ) {
			$this->refresh( (int) $comment->comment_post_ID );
		}
	}

	public function on_edited( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment && $comment->comment_type === self::TYPE ) {
			$this->refresh( (int) $comment->comment_post_ID );
		}
	}

	/** Recounts the rating summary of a product from its approved reviews. */
	public function refresh( int $post_id ): void {
		if ( get_post_type( $post_id ) !== PostTypes::PRODUCT ) {
			return;
		}
		$counts = $this->repository->rating_counts( $post_id );
		$total  = array_sum( $counts );
		if ( $total === 0 ) {
			delete_post_meta( $post_id, self::META_AVERAGE );
			delete_post_meta( $post_id, self::META_COUNT );
			delete_post_meta( $post_id, self::META_COUNTS );
			return;
		}
		$sum = 0;
		foreach ( $counts as $rating => $reviews ) {
			$sum += $rating * $reviews;
		}
		update_post_meta( $post_id, self::META_AVERAGE, number_format( $sum / $total, 2, '.', '' ) );
		update_post_meta( $post_id, self::META_COUNT, $total );
		update_post_meta( $post_id, self::META_COUNTS, $counts );
	}

	/**
	 * @param array<string,string> $types
	 * @return array<string,string>
	 */
	public function comment_types( array $types ): array {
		$types[ self::TYPE ] = __( 'Product reviews', 'flexacommerce' );
		return $types;
	}

	/** The comments screen shows a review's stars before its text. */
	public function admin_text( string $text, ?\WP_Comment $comment = null ): string {
		if ( ! $comment instanceof \WP_Comment || $comment->comment_type !== self::TYPE ) {
			return $text;
		}
		$rating = (int) get_comment_meta( (int) $comment->comment_ID, self::META_RATING, true );
		if ( $rating < 1 || $rating > 5 ) {
			return $text;
		}
		/* translators: %d: star rating, 1 to 5. */
		$label = sprintf( _n( '%d star', '%d stars', $rating, 'flexacommerce' ), $rating );
		$badge = get_comment_meta( (int) $comment->comment_ID, self::META_VERIFIED, true ) ? ' · ' . esc_html__( 'Verified buyer', 'flexacommerce' ) : '';
		return '<p><span aria-hidden="true" style="color:#dba617">' . str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) . '</span> <span class="screen-reader-text">' . esc_html( $label ) . '</span>' . $badge . '</p>' . $text;
	}
}
