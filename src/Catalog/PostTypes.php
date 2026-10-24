<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Support\Capabilities;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the product post type and its taxonomies on every request
 * (doc 02 §9). Rewrite rules are flushed once, on the first `init` after
 * activation or after a permalink base changes.
 */
final class PostTypes {
	use HasInstance;

	public const PRODUCT   = 'fxc_product';
	public const CATEGORY  = 'fxc_product_cat';
	public const TAG       = 'fxc_product_tag';
	public const ATTRIBUTE = 'fxc_attribute';

	public const FLUSH_OPTION = 'flexacommerce_flush_rewrite';

	private const BASE_SETTINGS = [ 'product_base', 'shop_base', 'category_base', 'tag_base' ];

	public function register(): void {
		add_action( 'init', [ $this, 'register_types' ], 5 );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_action( 'flexacommerce.settings.updated', [ $this, 'on_settings_updated' ], 10, 2 );
	}

	public static function request_flush(): void {
		update_option( self::FLUSH_OPTION, 1, false );
	}

	public function maybe_flush(): void {
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * @param array<string,mixed> $new
	 * @param array<string,mixed> $old
	 */
	public function on_settings_updated( array $new, array $old ): void {
		foreach ( self::BASE_SETTINGS as $key ) {
			if ( ( $new[ $key ] ?? '' ) !== ( $old[ $key ] ?? '' ) ) {
				self::request_flush();
				return;
			}
		}
	}

	/**
	 * Permalink bases. An empty setting means "automatic": `product`/`shop`,
	 * or `shop-item`/`shop` while WooCommerce is active so the two plugins
	 * never claim the same URLs.
	 *
	 * @return array{product:string,shop:string,category:string,tag:string}
	 */
	public static function bases(): array {
		$woo  = class_exists( 'WooCommerce' );
		$pick = static function ( string $key, string $default ): string {
			$value = Settings::string( $key );
			return $value !== '' ? $value : $default;
		};
		return [
			'product'  => $pick( 'product_base', $woo ? 'shop-item' : 'product' ),
			'shop'     => $pick( 'shop_base', 'shop' ),
			'category' => $pick( 'category_base', $woo ? 'shop-category' : 'product-category' ),
			'tag'      => $pick( 'tag_base', $woo ? 'shop-tag' : 'product-tag' ),
		];
	}

	public function register_types(): void {
		$bases = self::bases();

		register_post_type(
			self::PRODUCT,
			[
				'labels'          => [
					'name'               => __( 'Products', 'flexacommerce' ),
					'singular_name'      => __( 'Product', 'flexacommerce' ),
					'add_new'            => __( 'Add product', 'flexacommerce' ),
					'add_new_item'       => __( 'Add new product', 'flexacommerce' ),
					'edit_item'          => __( 'Edit product', 'flexacommerce' ),
					'new_item'           => __( 'New product', 'flexacommerce' ),
					'view_item'          => __( 'View product', 'flexacommerce' ),
					'view_items'         => __( 'View products', 'flexacommerce' ),
					'search_items'       => __( 'Search products', 'flexacommerce' ),
					'not_found'          => __( 'No products found.', 'flexacommerce' ),
					'not_found_in_trash' => __( 'No products found in the trash.', 'flexacommerce' ),
					'all_items'          => __( 'Products', 'flexacommerce' ),
					'menu_name'          => __( 'Products', 'flexacommerce' ),
				],
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => 'flexacommerce',
				'show_in_rest'    => true,
				'has_archive'     => $bases['shop'],
				'rewrite'         => [
					'slug'       => $bases['product'],
					'with_front' => false,
				],
				'capability_type' => [ 'fxc_product', 'fxc_products' ],
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions', 'author' ],
				'taxonomies'      => [ self::CATEGORY, self::TAG ],
			]
		);

		$term_caps = [
			'manage_terms' => Capabilities::MANAGE_PRODUCT_TERMS,
			'edit_terms'   => Capabilities::EDIT_PRODUCT_TERMS,
			'delete_terms' => Capabilities::DELETE_PRODUCT_TERMS,
			'assign_terms' => Capabilities::ASSIGN_PRODUCT_TERMS,
		];

		register_taxonomy(
			self::CATEGORY,
			self::PRODUCT,
			[
				'labels'            => [
					'name'          => __( 'Product categories', 'flexacommerce' ),
					'singular_name' => __( 'Category', 'flexacommerce' ),
					'menu_name'     => __( 'Categories', 'flexacommerce' ),
					'add_new_item'  => __( 'Add new category', 'flexacommerce' ),
					'edit_item'     => __( 'Edit category', 'flexacommerce' ),
					'search_items'  => __( 'Search categories', 'flexacommerce' ),
				],
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [
					'slug'         => $bases['category'],
					'with_front'   => false,
					'hierarchical' => true,
				],
				'capabilities'      => $term_caps,
			]
		);

		register_taxonomy(
			self::TAG,
			self::PRODUCT,
			[
				'labels'            => [
					'name'          => __( 'Product tags', 'flexacommerce' ),
					'singular_name' => __( 'Tag', 'flexacommerce' ),
					'menu_name'     => __( 'Tags', 'flexacommerce' ),
					'add_new_item'  => __( 'Add new tag', 'flexacommerce' ),
					'edit_item'     => __( 'Edit tag', 'flexacommerce' ),
					'search_items'  => __( 'Search tags', 'flexacommerce' ),
				],
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [
					'slug'       => $bases['tag'],
					'with_front' => false,
				],
				'capabilities'      => $term_caps,
			]
		);

		// Attributes are edited on their own React screen (ADR-006).
		register_taxonomy(
			self::ATTRIBUTE,
			self::PRODUCT,
			[
				'labels'       => [
					'name'          => __( 'Attributes', 'flexacommerce' ),
					'singular_name' => __( 'Attribute', 'flexacommerce' ),
				],
				'hierarchical' => true,
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => true,
				'rewrite'      => false,
				'query_var'    => false,
				'capabilities' => $term_caps,
			]
		);
	}
}
