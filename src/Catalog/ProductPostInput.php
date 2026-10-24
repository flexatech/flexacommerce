<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress post side of a product in the admin REST payload: title,
 * status, slug, content, excerpt, terms and featured image (doc 04 §2).
 * Like ProductInput, a null member means "keep what is stored". Content and
 * excerpt go through wp_insert_post(), so the usual KSES filters apply to
 * users without `unfiltered_html`.
 */
final class ProductPostInput {
	public const STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/** Keys of the admin payload that this class reads. */
	public const KEYS = [ 'title', 'status', 'slug', 'content', 'excerpt', 'date', 'menu_order', 'categories', 'tags', 'featured_image' ];

	/**
	 * @param list<int>|null $categories
	 * @param list<int>|null $tags
	 */
	public function __construct(
		public readonly ?string $title = null,
		public readonly ?string $status = null,
		public readonly ?string $slug = null,
		public readonly ?string $content = null,
		public readonly ?string $excerpt = null,
		public readonly ?string $date = null,
		public readonly ?int $menu_order = null,
		public readonly ?array $categories = null,
		public readonly ?array $tags = null,
		public readonly ?int $featured_image = null,
	) {}

	/**
	 * @param array<array-key,mixed> $data
	 * @throws InvalidProduct
	 */
	public static function from_array( array $data ): self {
		$status = null;
		if ( array_key_exists( 'status', $data ) ) {
			$status = is_string( $data['status'] ) ? $data['status'] : '';
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				throw new InvalidProduct( __( 'Unknown product status.', 'flexacommerce' ), 'status' );
			}
		}

		$date = null;
		if ( array_key_exists( 'date', $data ) ) {
			$date = is_string( $data['date'] ) ? $data['date'] : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $date ) !== 1 ) {
				throw new InvalidProduct( __( 'Use the date format YYYY-MM-DD HH:MM:SS.', 'flexacommerce' ), 'date' );
			}
			$date = str_replace( 'T', ' ', $date );
		}

		return new self(
			title: self::text( $data, 'title' ),
			status: $status,
			slug: array_key_exists( 'slug', $data ) ? sanitize_title( self::text( $data, 'slug' ) ?? '' ) : null,
			content: self::text( $data, 'content' ),
			excerpt: self::text( $data, 'excerpt' ),
			date: $date,
			menu_order: array_key_exists( 'menu_order', $data ) ? self::integer( $data['menu_order'], 'menu_order' ) : null,
			categories: self::terms( $data, 'categories', PostTypes::CATEGORY ),
			tags: self::terms( $data, 'tags', PostTypes::TAG ),
			featured_image: self::image( $data ),
		);
	}

	public function is_empty(): bool {
		return $this->post_fields() === [] && $this->categories === null && $this->tags === null && $this->featured_image === null;
	}

	public function changes_terms(): bool {
		return $this->categories !== null || $this->tags !== null;
	}

	/** @return array{post_title?:string,post_status?:string,post_name?:string,post_content?:string,post_excerpt?:string,post_date?:string,menu_order?:int} */
	public function post_fields(): array {
		$fields = [];
		if ( $this->title !== null ) {
			$fields['post_title'] = $this->title;
		}
		if ( $this->status !== null ) {
			$fields['post_status'] = $this->status;
		}
		if ( $this->slug !== null ) {
			$fields['post_name'] = $this->slug;
		}
		if ( $this->content !== null ) {
			$fields['post_content'] = $this->content;
		}
		if ( $this->excerpt !== null ) {
			$fields['post_excerpt'] = $this->excerpt;
		}
		if ( $this->date !== null ) {
			$fields['post_date'] = $this->date;
		}
		if ( $this->menu_order !== null ) {
			$fields['menu_order'] = $this->menu_order;
		}
		return $fields;
	}

	/**
	 * Creates the post when $post_id is 0, else updates it. Terms and the
	 * featured image are written after the post.
	 *
	 * @throws InvalidProduct when WordPress refuses the post
	 */
	public function apply( int $post_id ): int {
		// wp_insert_post() unslashes its input, as the core REST controller expects.
		$fields = wp_slash( $this->post_fields() );
		if ( $post_id === 0 ) {
			$result = wp_insert_post( $fields + [
				'post_type'   => PostTypes::PRODUCT,
				'post_status' => 'draft',
			], true );
		} elseif ( $fields !== [] ) {
			$result = wp_update_post( $fields + [ 'ID' => $post_id ], true );
		} else {
			$result = $post_id;
		}
		if ( $result instanceof \WP_Error ) {
			throw new InvalidProduct( $result->get_error_message(), 'post' );
		}
		$post_id = (int) $result;

		if ( $this->categories !== null ) {
			wp_set_object_terms( $post_id, $this->categories, PostTypes::CATEGORY );
		}
		if ( $this->tags !== null ) {
			wp_set_object_terms( $post_id, $this->tags, PostTypes::TAG );
		}
		if ( $this->featured_image !== null ) {
			$this->featured_image > 0 ? set_post_thumbnail( $post_id, $this->featured_image ) : delete_post_thumbnail( $post_id );
		}
		return $post_id;
	}

	/** @param array<array-key,mixed> $data */
	private static function text( array $data, string $key ): ?string {
		if ( ! array_key_exists( $key, $data ) ) {
			return null;
		}
		if ( ! is_string( $data[ $key ] ) ) {
			throw new InvalidProduct( __( 'This field must be text.', 'flexacommerce' ), $key );
		}
		return $data[ $key ];
	}

	private static function integer( mixed $value, string $key ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d{1,9}$/', $value ) === 1 ) ) {
			throw new InvalidProduct( __( 'This field must be a whole number.', 'flexacommerce' ), $key );
		}
		return (int) $value;
	}

	/**
	 * @param array<array-key,mixed> $data
	 * @return list<int>|null
	 */
	private static function terms( array $data, string $key, string $taxonomy ): ?array {
		if ( ! array_key_exists( $key, $data ) ) {
			return null;
		}
		if ( ! is_array( $data[ $key ] ) ) {
			throw new InvalidProduct( __( 'Send a list of term IDs.', 'flexacommerce' ), $key );
		}
		$ids = [];
		foreach ( $data[ $key ] as $value ) {
			$id = self::integer( $value, $key );
			if ( $id <= 0 || ! term_exists( $id, $taxonomy ) ) {
				/* translators: %d: term ID */
				throw new InvalidProduct( sprintf( __( 'Term %d does not exist.', 'flexacommerce' ), $id ), $key );
			}
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}

	/** @param array<array-key,mixed> $data */
	private static function image( array $data ): ?int {
		if ( ! array_key_exists( 'featured_image', $data ) ) {
			return null;
		}
		$id = $data['featured_image'] === null ? 0 : self::integer( $data['featured_image'], 'featured_image' );
		if ( $id < 0 || ( $id > 0 && ! wp_attachment_is_image( $id ) ) ) {
			throw new InvalidProduct( __( 'The featured image must be an image from the media library.', 'flexacommerce' ), 'featured_image' );
		}
		return $id;
	}
}
