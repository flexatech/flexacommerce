<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Product reviews into either store (doc 05 §5.9, ADR-056), as comments on
 * the imported product of type `review`. A reply takes the store's own
 * type: `comment` in WooCommerce, `review` in FlexaCommerce. Comments are
 * written directly, past the comment form checks, and the product's
 * rating summary is rebuilt after.
 */
final class ReviewWriter {
	/**
	 * @param \Closure(int):void $refresh rebuilds a product's rating summary
	 */
	public function __construct(
		private readonly string $post_type,
		private readonly string $reply_type,
		private readonly \Closure $refresh,
	) {}

	public function review( Record $record, Mapping $map ): WriteResult {
		$data    = $record->data;
		$product = $map->target_id( Record::PRODUCT, Ref::parse( is_string( $data['product'] ?? null ) ? $data['product'] : null )->id ?? '' );
		if ( $product <= 0 || get_post_type( $product ) !== $this->post_type ) {
			throw new Rejected( 'missing_product', 'The product of this review was not imported.' );
		}
		$parent_ref = is_string( $data['parent'] ?? null ) ? $data['parent'] : null;
		$parent     = $parent_ref === null ? 0 : $map->target_id( Record::REVIEW, Ref::parse( $parent_ref )->id ?? '' );
		if ( $parent_ref !== null && $parent <= 0 ) {
			throw new Rejected( 'missing_review', 'The review this reply answers was not imported.' );
		}
		$rating = (int) ( $data['rating'] ?? 0 );
		$date   = Dates::to_db( is_string( $data['created_at'] ?? null ) ? $data['created_at'] : null ) ?? gmdate( 'Y-m-d H:i:s' );
		$fields = [
			'comment_post_ID'      => $product,
			'comment_parent'       => $parent,
			'comment_type'         => $parent > 0 ? $this->reply_type : 'review',
			'comment_author'       => (string) ( $data['author'] ?? '' ),
			'comment_author_email' => (string) ( $data['author_email'] ?? '' ),
			'comment_content'      => (string) ( $data['content'] ?? '' ),
			'comment_approved'     => ! empty( $data['approved'] ) ? 1 : 0,
			'comment_date'         => get_date_from_gmt( $date ),
			'comment_date_gmt'     => $date,
			'user_id'              => $map->user_id( Ref::int_id( is_string( $data['customer'] ?? null ) ? $data['customer'] : null ) ),
		];

		$target = $map->target_id( Record::REVIEW, $record->source_id() );
		if ( $target > 0 && get_comment( $target ) instanceof \WP_Comment ) {
			wp_update_comment( [ 'comment_ID' => $target ] + $fields );
			$action = WriteResult::UPDATED;
		} else {
			$target = (int) wp_insert_comment( $fields );
			if ( $target <= 0 ) {
				throw new Rejected( 'review_not_saved', 'WordPress could not save the review.' );
			}
			$action = WriteResult::CREATED;
		}
		if ( $parent === 0 && $rating >= 1 && $rating <= 5 ) {
			update_comment_meta( $target, 'rating', $rating );
		} else {
			delete_comment_meta( $target, 'rating' );
		}
		update_comment_meta( $target, 'verified', ! empty( $data['verified'] ) ? 1 : 0 );
		( $this->refresh )( $product );

		$map->remember( Record::REVIEW, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target );
	}

	public function delete( int $target_id ): bool {
		$comment = get_comment( $target_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return false;
		}
		$deleted = wp_delete_comment( $target_id, true );
		( $this->refresh )( (int) $comment->comment_post_ID );
		return $deleted;
	}
}
