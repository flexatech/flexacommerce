<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\AddToCartForm;
use Flexa\Commerce\Storefront\Render\CartTable;
use Flexa\Commerce\Storefront\Render\MiniCart;
use Flexa\Commerce\Storefront\Render\ProductPrice;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points of the storefront (doc 02 §6.3): blocks, block templates,
 * the classic-theme fallback and shortcodes all end in the same renderers.
 * Plugin::boot() only adds cheap gates; this class loads the first time a
 * request actually shows commerce content (P1).
 */
final class Storefront {
	use HasInstance;

	public const BLOCKS = [ 'product-price', 'product-add-to-cart', 'cart', 'mini-cart' ];

	private bool $blocks = false;

	private ?ProductPrice $price = null;

	private ?AddToCartForm $form = null;

	private ?CartTable $table = null;

	private ?MiniCart $mini = null;

	public function register_blocks(): void {
		if ( $this->blocks ) {
			return;
		}
		$this->blocks = true;
		Assets::register();
		wp_register_script(
			'flexacommerce-blocks-editor',
			FLEXACOMMERCE_URL . 'assets/frontend/blocks-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ],
			FLEXACOMMERCE_VERSION,
			true
		);
		wp_set_script_translations( 'flexacommerce-blocks-editor', 'flexacommerce' );
		wp_register_block_types_from_metadata_collection( FLEXACOMMERCE_PATH . 'blocks', FLEXACOMMERCE_PATH . 'blocks/blocks-manifest.php' );
	}

	/** Block themes get plugin templates; classic themes get the content filter. */
	public function on_template_redirect(): void {
		if ( wp_is_block_theme() ) {
			$this->register_templates();
			return;
		}
		add_filter( 'the_content', [ $this, 'classic_content' ], 20 );
	}

	public function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		$templates = [
			'single-' . PostTypes::PRODUCT    => [ __( 'Single product', 'flexacommerce' ), __( 'Shows one product with its price and add-to-cart form.', 'flexacommerce' ), Templates::single() ],
			'archive-' . PostTypes::PRODUCT   => [ __( 'Shop', 'flexacommerce' ), __( 'Lists products.', 'flexacommerce' ), Templates::archive() ],
			'taxonomy-' . PostTypes::CATEGORY => [ __( 'Product category', 'flexacommerce' ), __( 'Lists the products in a category.', 'flexacommerce' ), Templates::archive() ],
			'taxonomy-' . PostTypes::TAG      => [ __( 'Product tag', 'flexacommerce' ), __( 'Lists the products with a tag.', 'flexacommerce' ), Templates::archive() ],
		];
		$registry  = \WP_Block_Templates_Registry::get_instance();
		foreach ( $templates as $slug => [ $title, $description, $content ] ) {
			$name = 'flexacommerce//' . $slug;
			if ( $registry->is_registered( $name ) ) {
				continue;
			}
			register_block_template(
				$name,
				[
					'title'       => $title,
					'description' => $description,
					'content'     => $content,
				]
			);
		}
	}

	/** Classic themes: price and form above the description of a product, price and button in lists. */
	public function classic_content( string $content ): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::PRODUCT || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$product = $this->product( $post->ID );
		if ( $product === null ) {
			return $content;
		}
		$mode = is_singular( PostTypes::PRODUCT ) ? AddToCartForm::SINGLE : AddToCartForm::LOOP;
		$top  = $this->price()->html( $product, get_current_user_id() ) . $this->form()->html( $product, $mode, get_current_user_id() );
		return $mode === AddToCartForm::SINGLE ? $top . $content : $content . $top;
	}

	/** @param array<string,mixed> $attributes */
	public function render_block( string $name, array $attributes, ?\WP_Block $block ): string {
		$user = get_current_user_id();
		switch ( $name ) {
			case 'cart':
				return $this->cart_html();
			case 'mini-cart':
				return $this->mini()->html();
		}
		$product = $this->product( $this->product_id( $attributes, $block ) );
		if ( $product === null ) {
			return '';
		}
		if ( $name === 'product-price' ) {
			return $this->price()->html( $product, $user );
		}
		$mode = $block !== null && isset( $block->context['queryId'] ) ? AddToCartForm::LOOP : AddToCartForm::SINGLE;
		return $this->form()->html( $product, $mode, $user );
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_cart( array|string $atts = [] ): string {
		unset( $atts );
		return $this->cart_html();
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_add_to_cart( array|string $atts = [] ): string {
		$atts    = shortcode_atts(
			[
				'id'         => 0,
				'show_price' => 'yes',
			],
			is_array( $atts ) ? $atts : [],
			'flexacommerce_add_to_cart'
		);
		$id      = absint( $atts['id'] ) > 0 ? absint( $atts['id'] ) : (int) get_the_ID();
		$product = $this->product( $id );
		if ( $product === null ) {
			return '';
		}
		$price = $atts['show_price'] !== 'no' ? $this->price()->html( $product, get_current_user_id() ) : '';
		return $price . $this->form()->html( $product, AddToCartForm::SINGLE, get_current_user_id() );
	}

	private function cart_html(): string {
		$cart = Plugin::instance()->cart();
		return $this->table()->html( $cart->cart()->state( get_current_user_id() ) );
	}

	/** @param array<string,mixed> $attributes */
	private function product_id( array $attributes, ?\WP_Block $block ): int {
		$id = isset( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;
		if ( $id > 0 ) {
			return $id;
		}
		if ( $block !== null && isset( $block->context['postId'] ) ) {
			return (int) $block->context['postId'];
		}
		return (int) get_the_ID();
	}

	private function product( int $id ): ?Product {
		if ( $id <= 0 || get_post_type( $id ) !== PostTypes::PRODUCT ) {
			return null;
		}
		$product = Plugin::instance()->catalog()->products()->find( $id );
		if ( $product === null || ( $product->status !== 'publish' && ! current_user_can( 'edit_post', $id ) ) ) {
			return null;
		}
		return $product;
	}

	private function price(): ProductPrice {
		$cart                 = Plugin::instance()->cart();
		return $this->price ??= new ProductPrice( $cart->pricing(), $cart->cart() );
	}

	private function form(): AddToCartForm {
		return $this->form ??= new AddToCartForm( Plugin::instance()->cart()->purchase_options() );
	}

	private function table(): CartTable {
		$cart                 = Plugin::instance()->cart();
		return $this->table ??= new CartTable( $cart->presenter(), $cart->cart(), $cart->pricing() );
	}

	private function mini(): MiniCart {
		return $this->mini ??= new MiniCart();
	}
}
