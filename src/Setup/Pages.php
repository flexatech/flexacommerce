<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The cart and checkout pages. Activation creates each one that is not set
 * or whose page was deleted; a page the merchant picked is never replaced.
 * Pages hold the block, which classic themes render through the_content.
 */
final class Pages {
	public static function install(): void {
		$pages   = [
			'cart_page_id'     => [ _x( 'Cart', 'page title', 'flexacommerce' ), 'cart', '<!-- wp:flexacommerce/cart /-->' ],
			'checkout_page_id' => [ _x( 'Checkout', 'page title', 'flexacommerce' ), 'checkout', '<!-- wp:flexacommerce/checkout /-->' ],
		];
		$changes = [];
		foreach ( $pages as $setting => [ $title, $slug, $content ] ) {
			$current = Settings::int( $setting );
			if ( $current > 0 && in_array( get_post_status( $current ), [ 'publish', 'private', 'draft' ], true ) ) {
				continue;
			}
			$id = wp_insert_post(
				[
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => $title,
					'post_name'      => $slug,
					'post_content'   => $content,
					'comment_status' => 'closed',
				],
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$changes[ $setting ] = $id;
			}
		}
		if ( $changes !== [] ) {
			Settings::update( $changes );
		}
	}
}
