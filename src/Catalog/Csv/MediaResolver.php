<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an image cell (a URL or an attachment ID) into an attachment. An
 * image of this site is found by its URL; another URL is downloaded once
 * and remembered by `_fxc_source_url`, so importing the file again reuses
 * the copy.
 */
final class MediaResolver {
	public const SOURCE_META = '_fxc_source_url';

	/** @var array<string,int> */
	private array $seen = [];

	/**
	 * The attachment id, or a message saying why there is none. In a check
	 * nothing is downloaded and a valid URL resolves to -1.
	 *
	 * @return int|string
	 */
	public function resolve( string $reference, bool $dry_run ): int|string {
		$reference = trim( $reference );
		if ( ctype_digit( $reference ) ) {
			return wp_attachment_is_image( (int) $reference )
				? (int) $reference
				/* translators: %s: attachment ID */
				: sprintf( __( 'Image %s is not in the media library.', 'flexacommerce' ), $reference );
		}
		if ( preg_match( '#^https?://#i', $reference ) !== 1 || filter_var( $reference, FILTER_VALIDATE_URL ) === false ) {
			/* translators: %s: what the cell holds */
			return sprintf( __( 'The image "%s" is not a URL.', 'flexacommerce' ), $reference );
		}
		if ( isset( $this->seen[ $reference ] ) ) {
			return $this->seen[ $reference ];
		}
		$id = attachment_url_to_postid( $reference );
		if ( $id === 0 ) {
			$id = $this->copy_of( $reference );
		}
		if ( $id === 0 && $dry_run ) {
			return -1;
		}
		if ( $id === 0 ) {
			$id = $this->sideload( $reference );
		}
		if ( $id === 0 ) {
			/* translators: %s: image URL */
			return sprintf( __( 'The image %s could not be downloaded.', 'flexacommerce' ), $reference );
		}
		$this->seen[ $reference ] = $id;
		return $id;
	}

	private function copy_of( string $url ): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per new image URL.
				'meta_query'             => [
					[
						'key'   => self::SOURCE_META,
						'value' => $url,
					],
				],
			]
		);
		return isset( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/** Same steps as the store migration's relocation (doc 05), kept here because Catalog may not use Migration. */
	private function sideload( string $url ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = download_url( $url, 30 );
		if ( is_wp_error( $file ) ) {
			return 0;
		}
		$id = media_handle_sideload(
			[
				'name'     => wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				'tmp_name' => $file,
			],
			0
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file );
			return 0;
		}
		update_post_meta( (int) $id, self::SOURCE_META, $url );
		return (int) $id;
	}
}
