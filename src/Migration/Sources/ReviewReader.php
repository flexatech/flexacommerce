<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;

defined( 'ABSPATH' ) || exit;

/**
 * A product review as a canonical record (doc 05 §5.9, ADR-056). Both
 * stores keep reviews as WordPress comments on the product with `rating`
 * and `verified` in comment meta, so one reader serves both; replies come
 * along and depend on the review they answer.
 */
final class ReviewReader {
	/** Comment types on a product that are reviews or replies to one. */
	public const TYPES = [ 'review', 'comment' ];

	public function __construct(
		private readonly string $system,
		private readonly string $post_type,
	) {}

	public function review( int $id ): ?Record {
		$comment = get_comment( $id );
		if ( ! $comment instanceof \WP_Comment || ! in_array( $comment->comment_type, self::TYPES, true ) || ! in_array( $comment->comment_approved, [ '0', '1' ], true ) ) {
			return null;
		}
		$product = (int) $comment->comment_post_ID;
		if ( get_post_type( $product ) !== $this->post_type ) {
			return null;
		}
		$depends = [ Ref::make( $this->system, Record::PRODUCT, $product ) ];
		$parent  = (int) $comment->comment_parent;
		if ( $parent > 0 ) {
			$depends[] = Ref::make( $this->system, Record::REVIEW, $parent );
		}
		$rating = (int) get_comment_meta( $id, 'rating', true );
		$user   = (int) $comment->user_id;
		return new Record(
			Record::REVIEW,
			Ref::make( $this->system, Record::REVIEW, $id ),
			[
				'product'      => $depends[0],
				'parent'       => $parent > 0 ? $depends[1] : null,
				'customer'     => $user > 0 ? Ref::make( 'wp', 'user', $user ) : null,
				'author'       => (string) $comment->comment_author,
				'author_email' => (string) $comment->comment_author_email,
				'content'      => (string) $comment->comment_content,
				'rating'       => $parent === 0 && $rating >= 1 && $rating <= 5 ? $rating : null,
				'verified'     => (bool) get_comment_meta( $id, 'verified', true ),
				'approved'     => $comment->comment_approved === '1',
				'created_at'   => Dates::from_db( $comment->comment_date_gmt ),
			],
			$depends
		);
	}
}
