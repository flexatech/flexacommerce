<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Setup\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The single destructive path: deletes the products and their terms, then
 * drops every table, option, role, saved address and cron event of the
 * current site, and the export files uploaded to the Migration screen. Uninstall and WP-CLI both call this.
 */
final class Eraser {
	public static function erase_site(): void {
		Cron::unschedule();
		self::erase_catalog();
		Schema::drop_all();
		Roles::uninstall();
		CustomerProfile::delete_all();
		self::erase_imports();

		do_action( 'flexacommerce.data_erased', get_current_blog_id() );
	}

	private static function erase_catalog(): void {
		// Uninstall runs without the plugin booted, so the types may be missing.
		if ( ! post_type_exists( PostTypes::PRODUCT ) ) {
			PostTypes::instance()->register_types();
		}
		do {
			$ids = get_posts(
				[
					'post_type'        => PostTypes::PRODUCT,
					'post_status'      => 'any',
					'fields'           => 'ids',
					'posts_per_page'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- batched delete loop.
					'no_found_rows'    => true,
					'suppress_filters' => true,
				]
			);
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( $ids !== [] );

		foreach ( [ PostTypes::CATEGORY, PostTypes::TAG, PostTypes::ATTRIBUTE ] as $taxonomy ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			);
			foreach ( is_array( $terms ) ? $terms : [] as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}

	/** The folder Migration\Transport\Uploads keeps uploaded export files in. */
	private static function erase_imports(): void {
		$dir = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] ) . 'flexacommerce-imports';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_string( $name ) && is_file( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort, the folder may hold files from elsewhere.
	}
}
