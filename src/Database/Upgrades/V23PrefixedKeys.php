<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database\Upgrades;

defined( 'ABSPATH' ) || exit;

/**
 * DB_VERSION 23: the plugin's own keys carry its prefix. Reviews on
 * products move from comment type `review` to `fxc_review`, their comment
 * meta from `rating`/`verified` to `_fxc_review_rating`/`_fxc_review_verified`,
 * and category images from term meta `thumbnail_id` to `_fxc_thumbnail_id`.
 * Only rows of FlexaCommerce products and categories change, so
 * WooCommerce's reviews and categories keep their keys. The values stay
 * as they are, so the rating summaries in product meta stay right. The
 * keys are written out: Database depends on no module (doc 02 §3.2), and
 * a step keeps the values of the version it produces.
 */
final class V23PrefixedKeys implements UpgradeStep {
	private const PRODUCT  = 'fxc_product';
	private const CATEGORY = 'fxc_product_cat';

	private const COMMENT_META = [
		'rating'   => '_fxc_review_rating',
		'verified' => '_fxc_review_verified',
	];

	public function run(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off upgrade; the caches of the changed rows are cleaned below.
		$comments = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT c.comment_ID FROM %i AS c INNER JOIN %i AS p ON p.ID = c.comment_post_ID WHERE p.post_type = %s',
					$wpdb->comments,
					$wpdb->posts,
					self::PRODUCT
				)
			)
		);
		foreach ( self::COMMENT_META as $old => $new ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS m INNER JOIN %i AS c ON c.comment_ID = m.comment_id INNER JOIN %i AS p ON p.ID = c.comment_post_ID SET m.meta_key = %s WHERE p.post_type = %s AND m.meta_key = %s',
					$wpdb->commentmeta,
					$wpdb->comments,
					$wpdb->posts,
					$new,
					self::PRODUCT,
					$old
				)
			);
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS c INNER JOIN %i AS p ON p.ID = c.comment_post_ID SET c.comment_type = %s WHERE p.post_type = %s AND c.comment_type = %s',
				$wpdb->comments,
				$wpdb->posts,
				'fxc_review',
				self::PRODUCT,
				'review'
			)
		);

		$terms = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare( 'SELECT term_id FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, self::CATEGORY )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS m INNER JOIN %i AS t ON t.term_id = m.term_id SET m.meta_key = %s WHERE t.taxonomy = %s AND m.meta_key = %s',
				$wpdb->termmeta,
				$wpdb->term_taxonomy,
				'_fxc_thumbnail_id',
				self::CATEGORY,
				'thumbnail_id'
			)
		);
		// phpcs:enable

		if ( $comments !== [] ) {
			clean_comment_cache( $comments );
		}
		foreach ( $terms as $term ) {
			wp_cache_delete( $term, 'term_meta' );
		}
	}
}
