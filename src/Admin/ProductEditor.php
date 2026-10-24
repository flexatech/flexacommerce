<?php

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Product data metabox (a React root inside the block editor) and the
 * duplicate row action on the product list.
 *
 * `flexacommerce.admin.product_editor` picks who edits products: "post"
 * (default) is this metabox in the WordPress post editor; "app" turns the
 * metabox off for a standalone editor that saves the whole product through
 * `admin/products` (doc 08 ADR-019). The filter is read when the screen is
 * built, so it can depend on the current user.
 */
final class ProductEditor {
	use HasInstance;

	public const HANDLE           = 'flexacommerce-product-editor';
	public const ENTRY            = 'admin/product-editor.tsx';
	public const ROOT_ID          = 'flexacommerce-product-root';
	public const DUPLICATE_ACTION = 'flexacommerce_duplicate_product';

	public function register(): void {
		add_action( 'add_meta_boxes_' . PostTypes::PRODUCT, [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_action( 'admin_post_' . self::DUPLICATE_ACTION, [ $this, 'handle_duplicate' ] );
	}

	/** @return 'post'|'app' */
	public static function mode(): string {
		$mode = apply_filters( 'flexacommerce.admin.product_editor', 'post' );
		return $mode === 'app' ? 'app' : 'post';
	}

	public function add_meta_box(): void {
		if ( self::mode() !== 'post' ) {
			return;
		}
		add_meta_box(
			'flexacommerce-product-data',
			__( 'Product data', 'flexacommerce' ),
			[ $this, 'render' ],
			PostTypes::PRODUCT,
			'normal',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		printf(
			'<div id="%s" class="flexacommerce-product-ui" data-product-id="%d"><p>%s</p></div>',
			esc_attr( self::ROOT_ID ),
			(int) $post->ID,
			esc_html__( 'Loading product data…', 'flexacommerce' )
		);
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen === null || $screen->post_type !== PostTypes::PRODUCT || self::mode() !== 'post' ) {
			return;
		}
		$post = get_post();

		wp_enqueue_media();
		wp_enqueue_script( 'wp-data' );
		Enqueue::instance()->enqueue_entry(
			self::HANDLE,
			self::ENTRY,
			'product-editor',
			[
				'productId'          => $post instanceof \WP_Post ? (int) $post->ID : 0,
				'canManageTerms'     => Capabilities::can_manage_product_terms(),
				'attributesAdminUrl' => esc_url_raw( admin_url( 'admin.php?page=' . Menu::SLUG . '-attributes' ) ),
			]
		);
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== PostTypes::PRODUCT || ! Capabilities::can_edit_products() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DUPLICATE_ACTION . '&post=' . $post->ID ),
			self::DUPLICATE_ACTION . '_' . $post->ID
		);

		$actions['flexacommerce_duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			/* translators: %s: product title */
			esc_attr( sprintf( __( 'Duplicate “%s”', 'flexacommerce' ), get_the_title( $post ) ) ),
			esc_html__( 'Duplicate', 'flexacommerce' )
		);
		return $actions;
	}

	public function handle_duplicate(): void {
		$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::DUPLICATE_ACTION . '_' . $id );

		if ( get_post_type( $id ) !== PostTypes::PRODUCT || ! Capabilities::can_edit_products() || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate this product.', 'flexacommerce' ), '', [ 'response' => 403 ] );
		}

		try {
			$copy_id = Plugin::instance()->catalog()->product_service()->duplicate( $id );
		} catch ( CommerceException $e ) {
			wp_die( esc_html( $e->getMessage() ), '', [ 'response' => 400 ] );
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $copy_id ) );
		exit;
	}
}
