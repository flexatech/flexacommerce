<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only scans for the export layout, all values prepared.
/**
 * Reads for the product CSV export: which products, and how many attribute
 * and download columns the file needs so every page has the same header.
 */
final class ProductCsvRepository {
	/** Statuses an export includes: everything but the trash and autosaves. */
	public const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

	/** At most this many custom-field columns. */
	public const MAX_META = 100;

	public function __construct( private readonly \wpdb $wpdb ) {}

	/** @return array{ids:list<int>,total:int} */
	public function page( int $page, int $per_page ): array {
		$query = new \WP_Query(
			[
				'post_type'              => PostTypes::PRODUCT,
				'post_status'            => self::STATUSES,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'posts_per_page'         => $per_page,
				'paged'                  => max( 1, $page ),
				'update_post_term_cache' => false,
				'no_found_rows'          => false,
				'suppress_filters'       => true,
			]
		);
		return [
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		];
	}

	/** The largest number of attributes one product has. */
	public function max_attributes(): int {
		$values = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT pm.meta_value FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status IN (' . self::placeholders() . ')',
				$this->wpdb->postmeta,
				$this->wpdb->posts,
				ProductRepository::META_ATTRIBUTES,
				PostTypes::PRODUCT,
				...self::STATUSES
			)
		);
		$max    = 0;
		foreach ( $values as $json ) {
			$list = json_decode( (string) $json, true );
			$max  = max( $max, is_array( $list ) ? count( $list ) : 0 );
		}
		return $max;
	}

	/** The largest number of files one row sells. */
	public function max_downloads(): int {
		$values = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT v.downloads FROM %i v INNER JOIN %i p ON p.ID = v.product_id WHERE v.downloads IS NOT NULL AND p.post_type = %s AND p.post_status IN (' . self::placeholders() . ')',
				Schema::table( 'variants' ),
				$this->wpdb->posts,
				PostTypes::PRODUCT,
				...self::STATUSES
			)
		);
		$max    = 0;
		foreach ( $values as $json ) {
			$data = json_decode( (string) $json, true );
			$max  = max( $max, is_array( $data ) && is_array( $data['files'] ?? null ) ? count( $data['files'] ) : 0 );
		}
		return $max;
	}

	/**
	 * Public custom-field keys used on products (no leading underscore),
	 * sorted.
	 *
	 * @return list<string>
	 */
	public function meta_keys(): array {
		$keys = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT pm.meta_key FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status IN (' . self::placeholders() . ') AND pm.meta_key NOT LIKE %s ORDER BY pm.meta_key LIMIT %d',
				$this->wpdb->postmeta,
				$this->wpdb->posts,
				PostTypes::PRODUCT,
				...[ ...self::STATUSES, $this->wpdb->esc_like( '_' ) . '%', self::MAX_META ]
			)
		);
		return array_values( array_map( 'strval', $keys ) );
	}

	private static function placeholders(): string {
		return implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
	}
}
