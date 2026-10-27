<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\ListSwatches;
use Flexa\Commerce\Storefront\Storefront;
use Flexa\Commerce\Storefront\Templates;
use Flexa\Commerce\Support\Settings;

/** Swatches in product lists (ADR-038): links per value, stock, the "+N" link and the page they open. */
final class ListSwatchesTest extends CartTestCase {
	/** @var array<string,mixed> */
	private array $settings = [];

	public function set_up(): void {
		parent::set_up();
		$this->settings = Settings::all();
	}

	public function tear_down(): void {
		$_GET = [];
		update_option( Settings::OPTION, $this->settings, true );
		parent::tear_down();
	}

	public function test_a_card_links_each_value_to_the_product(): void {
		[ $id, $key, [ $red, $blue, $green ] ] = $this->shirt();

		$html = Storefront::instance()->part( 'card', $id );
		self::assertStringContainsString( 'class="fxc-swatches fxc-swatches--list fxc-swatches--round"', $html );
		self::assertSame( 3, substr_count( $html, 'class="fxc-swatch fxc-swatch--' ) );
		self::assertStringContainsString( 'style="--fxc-swatch-color:#ff0000"', $html );
		self::assertStringContainsString( '--fxc-swatch-size:24px', $html, 'Smaller than on the product page.' );
		$link = esc_url( add_query_arg( ListSwatches::PICK . '[' . rawurlencode( $key ) . ']', (string) $red, (string) get_permalink( $id ) ) );
		self::assertStringContainsString( 'href="' . $link . '" rel="nofollow"', $html );
		self::assertMatchesRegularExpression( '/class="fxc-swatch fxc-swatch--button is-unavailable" href="[^"]+' . $green . '"/', $html, 'Green has no variation in stock.' );
		self::assertStringContainsString( 'Green (out of stock)', wp_strip_all_tags( $html ) );
		self::assertDoesNotMatchRegularExpression( '/is-unavailable" href="[^"]+' . $blue . '"/', $html );
		self::assertStringContainsString( '>Choose options</a>', $html, 'The button stays.' );
		self::assertStringNotContainsString( '<input', $html, 'No form fields in a list.' );

		Settings::update(
			[
				'swatch_unavailable' => 'hide',
				'swatch_list'        => 1,
			]
		);
		$html = Storefront::instance()->part( 'card', $id );
		self::assertSame( 1, substr_count( $html, 'class="fxc-swatch fxc-swatch--' ) );
		self::assertStringContainsString( 'aria-label="1 more choice">+1</a>', $html, 'Green is left out, Blue is behind the link.' );

		Settings::update( [ 'swatch_list' => 0 ] );
		self::assertStringNotContainsString( 'fxc-swatches', Storefront::instance()->part( 'card', $id ) );
	}

	public function test_a_block_theme_list_shows_list_cards(): void {
		[ $id ] = $this->shirt();
		$this->go_to( (string) get_post_type_archive_link( PostTypes::PRODUCT ) );
		$html = do_blocks( Templates::product_query( '{"queryId":3,"query":{"inherit":false,"perPage":50,"postType":"' . PostTypes::PRODUCT . '"}}' ) );
		self::assertStringContainsString( 'fxc-swatches--list', $html, 'The Post Template gives its blocks no queryId.' );
		self::assertStringContainsString( 'fxc-add-to-cart--loop', $html );
		self::assertStringNotContainsString( 'fxc-add-to-cart--single', $html );

		$this->go_to( (string) get_permalink( $id ) );
		$html = do_blocks( '<!-- wp:flexacommerce/product-add-to-cart /-->' );
		self::assertStringContainsString( 'fxc-add-to-cart--single', $html, 'The product of the page gets its full form.' );
		self::assertStringContainsString( 'fxc-add-to-cart--single', do_blocks( '<!-- wp:flexacommerce/product-add-to-cart {"productId":' . $id . '} /-->' ) );
	}

	public function test_a_product_without_swatches_shows_none(): void {
		[ $id ] = $this->sized();
		self::assertStringNotContainsString( 'fxc-swatches', Storefront::instance()->part( 'card', $id ), 'Size is a dropdown.' );
	}

	public function test_the_product_page_starts_from_the_picked_value(): void {
		[ $id, $key, [ $red, $blue ] ] = $this->shirt();

		$_GET[ ListSwatches::PICK ] = [
			$key     => (string) $blue,
			'l:size' => 'nope',
		];
		$html                       = $this->form( $id );
		self::assertMatchesRegularExpression( '/value="' . $blue . '" checked required/', $html );
		self::assertStringNotContainsString( 'selected', $html, 'A value the product lacks is ignored.' );
		self::assertStringNotContainsString( 'actions.refresh', $html, 'Half a choice waits for the rest.' );
		self::assertStringContainsString( '"selection":{"' . $key . '":"' . $blue . '"}', $html );

		$_GET[ ListSwatches::PICK ] = [
			$key     => (string) $red,
			'l:size' => 'm',
		];
		$html                       = $this->form( $id );
		self::assertStringContainsString( '<option value="m" selected', $html );
		self::assertStringContainsString( 'data-wp-init="actions.refresh"', $html, 'A full choice shows its price at once.' );
		self::assertStringContainsString( '>Add to cart<', $html );
	}

	/**
	 * A shirt in Red, Blue and Green (a colour swatch) and S or M; no Green
	 * one is in stock.
	 *
	 * @return array{int,string,list<int>} product id, colour key, value ids
	 */
	private function shirt(): array {
		[ $color, $values ] = $this->attribute( 'Color', 'Red', 'Blue', 'Green' );
		$attributes         = Plugin::instance()->catalog()->attributes();
		$attributes->set_swatch( $color, AttributeRepository::COLOR );
		$attributes->set_look( $values[0], '#ff0000', null );
		$attributes->set_look( $values[1], '#0000ff', null );
		$id = $this->product();
		$this->save(
			$id,
			[
				'type'       => 'variable',
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => $values,
						'variation'         => true,
					],
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S', 'M' ],
						'variation' => true,
					],
				],
			]
		);
		$product = $this->service()->generate_variants( $id )->product;
		$rows    = [];
		$key     = '';
		foreach ( $product->variations as $variation ) {
			foreach ( $variation->combination->to_array() as $name => $value ) {
				$key = $name !== 'l:size' ? $name : $key;
			}
			$rows[] = [
				'id'             => $variation->id,
				'regular_price'  => 1500,
				'manage_stock'   => true,
				'stock_quantity' => $variation->combination->get( $key ) === (string) $values[2] ? 0 : 3,
			];
		}
		$this->save( $id, [ 'variations' => $rows ] );
		return [ $id, $key, $values ];
	}

	private function form( int $id ): string {
		return do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
	}
}
