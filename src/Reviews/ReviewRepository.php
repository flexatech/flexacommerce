<?php

declare(strict_types=1);

namespace Flexa\Commerce\Reviews;

use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads the comment and order tables for the rating summary and the buyer check (ADR-056); the summary is stored in product meta, so pages do not run these.

/** SQL of product reviews (ADR-056). */
final class ReviewRepository {
	/**
	 * Approved top-level reviews of a product per star rating.
	 *
	 * @return array<int,int> rating 1 to 5 => reviews
	 */
	public function rating_counts( int $product_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST( m.meta_value AS UNSIGNED ) AS rating, COUNT(*) AS reviews FROM {$wpdb->comments} AS c INNER JOIN {$wpdb->commentmeta} AS m ON m.comment_id = c.comment_ID AND m.meta_key = %s WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_type = %s AND c.comment_parent = 0 GROUP BY rating",
				Reviews::META_RATING,
				$product_id,
				Reviews::TYPE
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$rating = (int) $row['rating'];
			if ( $rating >= 1 && $rating <= 5 ) {
				$out[ $rating ] = (int) $row['reviews'];
			}
		}
		return $out;
	}

	/**
	 * Whether a customer has a processing or completed order with the
	 * product, by account or by order email. The statuses are written out
	 * so a product page loads no Orders class (O7).
	 */
	public function bought( int $product_id, int $user_id, string $email ): bool {
		global $wpdb;
		if ( $user_id <= 0 && $email === '' ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i AS o INNER JOIN %i AS i ON i.order_id = o.id WHERE i.product_id = %d AND i.type = %s AND o.status IN ( %s, %s ) AND o.deleted_at IS NULL AND ( ( %d > 0 AND o.customer_id = %d ) OR ( %s <> \'\' AND o.customer_email = %s ) ) LIMIT 1',
				Schema::table( 'orders' ),
				Schema::table( 'order_items' ),
				$product_id,
				'product',
				'processing',
				'completed',
				$user_id,
				$user_id,
				$email,
				$email
			)
		);
		return $found !== null;
	}
}
