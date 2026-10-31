<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Badges\Badge;
use Flexa\Commerce\Badges\BadgeMatcher;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Reviews\Reviews;
use Flexa\Commerce\Storefront\Render\AddToCartForm;
use Flexa\Commerce\Storefront\Render\BadgeOverlay;
use Flexa\Commerce\Storefront\Render\Breadcrumbs;
use Flexa\Commerce\Storefront\Render\CartTable;
use Flexa\Commerce\Storefront\Render\Gallery;
use Flexa\Commerce\Storefront\Render\MiniCart;
use Flexa\Commerce\Storefront\Render\PricingBlocks;
use Flexa\Commerce\Storefront\Render\ProductDetails;
use Flexa\Commerce\Storefront\Render\ProductPrice;
use Flexa\Commerce\Storefront\Render\ProductReviews;
use Flexa\Commerce\Storefront\Render\RelatedProducts;
use Flexa\Commerce\Storefront\Render\ShopControls;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points of the storefront (doc 02 §6.3): blocks, block templates,
 * the classic-theme fallback and shortcodes all end in the same renderers.
 * Plugin::boot() only adds cheap gates; this class loads the first time a
 * request actually shows commerce content (P1).
 */
final class Storefront {
	use HasInstance;

	public const BLOCKS = [
		'product-price',
		'product-add-to-cart',
		'cart',
		'mini-cart',
		'checkout',
		'account',
		'order-confirmation',
		'product-gallery',
		'product-sku',
		'product-stock',
		'product-attributes',
		'breadcrumbs',
		'catalog-sorting',
		'product-filters',
		'related-products',
		'quantity-prices',
		'discount-badge',
		'offer-countdown',
		'discount-nudge',
		'product-rating',
		'product-reviews',
		'order-lookup',
	];

	/** The pricing shortcodes and the blocks they show (ADR-050). */
	public const PRICING_SHORTCODES = [
		'flexacommerce_quantity_prices' => 'quantity-prices',
		'flexacommerce_discount_badge'  => 'discount-badge',
		'flexacommerce_countdown'       => 'offer-countdown',
		'flexacommerce_discount_nudge'  => 'discount-nudge',
	];

	private bool $blocks = false;

	private ?ProductPrice $price = null;

	private ?AddToCartForm $form = null;

	private ?CartTable $table = null;

	private ?MiniCart $mini = null;

	private ?ProductDetails $details = null;

	private ?Gallery $gallery = null;

	private ?ShopControls $controls = null;

	private ?RelatedProducts $related = null;

	private ?BadgeOverlay $badges = null;

	private ?PricingBlocks $pricing = null;

	private ?ProductReviews $reviews = null;

	public function register_blocks(): void {
		if ( $this->blocks ) {
			return;
		}
		$this->blocks = true;
		Assets::register();
		wp_register_script(
			'flexacommerce-blocks-editor',
			FLEXACOMMERCE_URL . 'assets/frontend/blocks-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
			FLEXACOMMERCE_VERSION,
			true
		);
		wp_set_script_translations( 'flexacommerce-blocks-editor', 'flexacommerce' );
		add_filter( 'query_loop_block_query_vars', [ ShopQuery::instance(), 'loop_vars' ] );
		wp_register_block_types_from_metadata_collection( FLEXACOMMERCE_PATH . 'blocks', FLEXACOMMERCE_PATH . 'blocks/blocks-manifest.php' );
	}

	/**
	 * Block themes get plugin templates. Classic themes get the PHP
	 * templates in `templates/` (a theme copy wins, see TemplateLoader), or,
	 * when a site turns those off, price and form in the content.
	 */
	public function on_template_redirect(): void {
		if ( wp_is_block_theme() ) {
			$this->register_templates();
			return;
		}
		/**
		 * Whether classic themes use the plugin's page templates.
		 *
		 * @param bool $use false keeps the theme's own templates and adds price and form to the content.
		 */
		if ( (bool) apply_filters( 'flexacommerce.classic_templates', true ) ) {
			add_filter( 'template_include', [ TemplateLoader::class, 'include' ] );
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

	/**
	 * One piece of a classic template: the markup of one of our blocks, by
	 * name, for the current product or `$product_id`; `card` is the price
	 * and button of a product in a list, `badges` the badges over its image.
	 */
	public function part( string $name, int $product_id = 0 ): string {
		if ( $name === 'card' || $name === 'badges' ) {
			$product = $this->product( $product_id > 0 ? $product_id : (int) get_the_ID() );
			if ( $product === null ) {
				return '';
			}
			return $name === 'card' ? $this->card( $product ) : $this->badges()->html( $product, Badge::LOOP );
		}
		if ( ! in_array( $name, self::BLOCKS, true ) ) {
			return '';
		}
		return $this->block_html( $name, $product_id > 0 ? [ 'productId' => $product_id ] : [], null );
	}

	/** A featured image block of a product in a list, with its badges. */
	public function with_badges( string $html, int $product_id ): string {
		$product = $this->product( $product_id );
		return $product === null ? $html : $this->badges()->inside( $html, $product, is_singular( PostTypes::PRODUCT ) && get_queried_object_id() === $product_id ? Badge::SINGLE : Badge::LOOP );
	}

	/** @param array<string,mixed> $attributes */
	public function render_block( string $name, array $attributes, ?\WP_Block $block ): string {
		return self::with_wrapper( $this->block_html( $name, $attributes, $block ), $block );
	}

	/** @param array<string,mixed> $attributes */
	private function block_html( string $name, array $attributes, ?\WP_Block $block ): string {
		$user = get_current_user_id();
		switch ( $name ) {
			case 'cart':
				return $this->cart_html();
			case 'mini-cart':
				return $this->mini()->html();
			case 'checkout':
				return CheckoutPage::instance()->render();
			case 'account':
				return AccountPage::instance()->render();
			case 'order-confirmation':
				return CheckoutPage::instance()->render_confirmation();
			case 'order-lookup':
				return OrderLookup::instance()->render();
			case 'breadcrumbs':
				return ( new Breadcrumbs() )->html();
			case 'catalog-sorting':
				return $this->controls()->sorting();
			case 'product-filters':
				$slugs = is_array( $attributes['attributeSlugs'] ?? null ) ? array_values( array_filter( $attributes['attributeSlugs'], 'is_string' ) ) : [];
				return $this->controls()->filters(
					(bool) ( $attributes['showCategories'] ?? true ),
					$slugs,
					(bool) ( $attributes['showPrice'] ?? true ),
					(bool) ( $attributes['showStock'] ?? true ),
					(bool) ( $attributes['showCounts'] ?? true )
				);
			case 'discount-nudge':
				return $this->pricing()->nudge( $user );
			case 'offer-countdown':
				// A rule's countdown needs no product.
				if ( (int) ( $attributes['ruleId'] ?? 0 ) > 0 ) {
					return $this->pricing()->countdown( (int) $attributes['ruleId'], null, $user );
				}
		}
		$product = $this->product( $this->product_id( $attributes, $block ) );
		if ( $product === null ) {
			return '';
		}
		switch ( $name ) {
			case 'product-price':
				return $this->price()->html( $product, $user );
			case 'product-gallery':
				return $this->gallery()->html( $product );
			case 'product-sku':
				return $this->details()->sku( $product );
			case 'product-stock':
				return $this->details()->stock( $product );
			case 'product-attributes':
				return $this->details()->attributes( $product );
			case 'related-products':
				return $this->related()->html( $product, (int) ( $attributes['count'] ?? 4 ) );
			case 'quantity-prices':
				return $this->pricing()->quantity_prices( $product, $user );
			case 'discount-badge':
				return $this->pricing()->discount_badge( $product, $user );
			case 'offer-countdown':
				return $this->pricing()->countdown( 0, $product, $user );
			case 'product-rating':
				return $this->reviews()->rating( $product, ! self::in_list( $attributes, $block ) );
			case 'product-reviews':
				return $this->reviews()->html( $product );
		}
		return $this->form()->html( $product, self::in_list( $attributes, $block ) ? AddToCartForm::LOOP : AddToCartForm::SINGLE, $user );
	}

	/**
	 * Puts the block supports (wrapper class, alignment, colors, font size)
	 * on the first element of the markup, so a block needs no extra wrapper.
	 */
	private static function with_wrapper( string $html, ?\WP_Block $block ): string {
		if ( $html === '' || $block === null || \WP_Block_Supports::$block_to_render === null ) {
			return $html;
		}
		$wrapper = new \WP_HTML_Tag_Processor( '<div ' . get_block_wrapper_attributes() . '>' );
		$markup  = new \WP_HTML_Tag_Processor( $html );
		if ( ! $wrapper->next_tag() || ! $markup->next_tag() ) {
			return $html;
		}
		foreach ( explode( ' ', (string) $wrapper->get_attribute( 'class' ) ) as $class ) {
			if ( $class !== '' ) {
				$markup->add_class( $class );
			}
		}
		$style = $wrapper->get_attribute( 'style' );
		if ( is_string( $style ) && $style !== '' ) {
			$own = $markup->get_attribute( 'style' );
			$markup->set_attribute( 'style', ( is_string( $own ) && $own !== '' ? rtrim( $own, '; ' ) . ';' : '' ) . $style );
		}
		$id = $wrapper->get_attribute( 'id' );
		if ( is_string( $id ) && $id !== '' && $markup->get_attribute( 'id' ) === null ) {
			$markup->set_attribute( 'id', $id );
		}
		return $markup->get_updated_html();
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_cart( array|string $atts = [] ): string {
		unset( $atts );
		return $this->cart_html();
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_checkout( array|string $atts = [] ): string {
		unset( $atts );
		return CheckoutPage::instance()->render();
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_account( array|string $atts = [] ): string {
		unset( $atts );
		return AccountPage::instance()->render();
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_order_confirmation( array|string $atts = [] ): string {
		unset( $atts );
		return CheckoutPage::instance()->render_confirmation();
	}

	/** @param array<string,mixed>|string $atts */
	public function shortcode_order_tracking( array|string $atts = [] ): string {
		unset( $atts );
		return OrderLookup::instance()->render();
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

	/**
	 * `[flexacommerce_quantity_prices id="12"]`, `[flexacommerce_discount_badge id="12"]`,
	 * `[flexacommerce_countdown rule="3"]` or `[flexacommerce_countdown id="12"]`, and
	 * `[flexacommerce_discount_nudge]` (ADR-050). Without an id they show the current product.
	 *
	 * @param array<string,mixed>|string $atts
	 */
	public function shortcode_pricing( string $tag, array|string $atts = [] ): string {
		$name = self::PRICING_SHORTCODES[ $tag ] ?? null;
		if ( $name === null ) {
			return '';
		}
		$atts       = shortcode_atts(
			[
				'id'   => 0,
				'rule' => 0,
			],
			is_array( $atts ) ? $atts : [],
			$tag
		);
		$attributes = [
			'productId' => absint( $atts['id'] ),
			'ruleId'    => absint( $atts['rule'] ),
		];
		return $this->block_html( $name, $attributes, null );
	}

	private function cart_html(): string {
		$cart = Plugin::instance()->cart();
		return $this->table()->html( $cart->cart()->state( get_current_user_id() ) );
	}

	/**
	 * Whether a block shows a product of a list. The Post Template passes
	 * its inner blocks only `postId` and `postType` (WordPress 6.3+), so a
	 * missing `queryId` proves nothing: a block that takes its product from
	 * the context is in a list unless that product is the page being viewed.
	 * A block with its own `productId` is a buy box wherever it stands.
	 *
	 * @param array<string,mixed> $attributes
	 */
	private static function in_list( array $attributes, ?\WP_Block $block ): bool {
		if ( $block === null || (int) ( $attributes['productId'] ?? 0 ) > 0 ) {
			return false;
		}
		if ( isset( $block->context['queryId'] ) ) {
			return true;
		}
		return isset( $block->context['postId'] ) && ! ( is_singular() && (int) $block->context['postId'] === get_queried_object_id() );
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
		return $this->form ??= new AddToCartForm(
			Plugin::instance()->cart()->purchase_options(),
			Plugin::instance()->cart()->pricing(),
			static fn (): ProductOptions => Plugin::instance()->product_options()
		);
	}

	private function table(): CartTable {
		$cart                 = Plugin::instance()->cart();
		return $this->table ??= new CartTable( $cart->presenter(), $cart->cart(), $cart->pricing(), static fn (): ProductOptions => Plugin::instance()->product_options() );
	}

	private function mini(): MiniCart {
		return $this->mini ??= new MiniCart();
	}

	private function details(): ProductDetails {
		return $this->details ??= new ProductDetails( Plugin::instance()->cart()->stock() );
	}

	private function gallery(): Gallery {
		return $this->gallery ??= new Gallery( $this->badges() );
	}

	private function controls(): ShopControls {
		return $this->controls ??= new ShopControls();
	}

	private function related(): RelatedProducts {
		return $this->related ??= new RelatedProducts(
			Plugin::instance()->catalog()->products(),
			fn ( int $id ): ?Product => $this->product( $id ),
			fn ( Product $product ): string => $this->card( $product ),
			fn ( Product $product ): string => $this->badges()->html( $product, Badge::LOOP )
		);
	}

	private function pricing(): PricingBlocks {
		$cart                   = Plugin::instance()->cart();
		return $this->pricing ??= new PricingBlocks( $cart->pricing(), $cart->cart(), $cart->presenter() );
	}

	private function reviews(): ProductReviews {
		if ( $this->reviews === null ) {
			// The form asks comments_open(), which the review hooks answer.
			Reviews::instance()->register();
			$this->reviews = new ProductReviews( Reviews::instance() );
		}
		return $this->reviews;
	}

	private function badges(): BadgeOverlay {
		return $this->badges ??= new BadgeOverlay( new BadgeMatcher( Plugin::instance()->badges() ) );
	}

	/** Stars, price and the list add-to-cart button. */
	private function card( Product $product ): string {
		return $this->reviews()->rating( $product, false ) . $this->price()->html( $product, get_current_user_id() ) . $this->form()->html( $product, AddToCartForm::LOOP, get_current_user_id() );
	}
}
