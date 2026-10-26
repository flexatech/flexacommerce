<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Contracts\Exceptions\InvalidAttribute;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

/** Swatches (ADR-022): what an attribute and its values keep, and the radio group the product page draws. */
final class SwatchesTest extends CartTestCase {
	/** @var array<string,mixed> */
	private array $settings = [];

	public function set_up(): void {
		parent::set_up();
		$this->settings = Settings::all();
	}

	public function tear_down(): void {
		update_option( Settings::OPTION, $this->settings, true );
		parent::tear_down();
	}

	public function test_an_attribute_keeps_its_swatch_and_a_value_its_look(): void {
		[ $color, [ $red, $blue ] ] = $this->attribute( 'Color', 'Red', 'Blue' );
		$repository                 = $this->attributes();
		$repository->set_swatch( $color, AttributeRepository::COLOR );
		$repository->set_look( $red, ' #FF0000 ', null );

		$row = $this->row( $color );
		self::assertSame( 'color', $row['swatch'] );
		self::assertSame( [ '#ff0000', '' ], array_column( $row['values'], 'color' ) );
		self::assertSame( [ 0, 0 ], array_column( $row['values'], 'image_id' ) );

		$repository->set_look( $red, '', null );
		$repository->set_swatch( $color, AttributeRepository::DROPDOWN );
		$row = $this->row( $color );
		self::assertSame( '', $row['swatch'] );
		self::assertSame( '', $row['values'][0]['color'] );
		self::assertSame( '', get_term_meta( $color, AttributeRepository::SWATCH_META, true ), 'the dropdown keeps no meta' );

		foreach (
			[
				'a bad colour'        => static fn () => $repository->set_look( $blue, 'blue', null ),
				'a look on attribute' => static fn () => $repository->set_look( $color, '#000000', null ),
				'a swatch on a value' => static fn () => $repository->set_swatch( $blue, AttributeRepository::COLOR ),
				'an unknown swatch'   => static fn () => $repository->set_swatch( $color, 'slider' ),
				'a post for an image' => fn () => $repository->set_look( $blue, null, $this->product() ),
			] as $case => $call
		) {
			try {
				$call();
				self::fail( $case . ' is refused' );
			} catch ( InvalidAttribute $e ) {
				self::assertNotSame( '', $e->getMessage(), $case );
			}
		}
	}

	public function test_a_value_keeps_an_image_from_the_library(): void {
		[ , [ $red ] ] = $this->attribute( 'Pattern', 'Stripes' );
		$image         = self::factory()->attachment->create_object( 'fxc-swatch.jpg', 0, [ 'post_mime_type' => 'image/jpeg' ] );
		$this->track_post( $image );

		$this->attributes()->set_look( $red, null, $image );
		self::assertSame( $image, AttributeRepository::look( $red )['image_id'] );
		$this->attributes()->set_look( $red, null, 0 );
		self::assertSame( 0, AttributeRepository::look( $red )['image_id'] );
	}

	public function test_the_route_sets_only_what_it_is_sent(): void {
		[ $color, [ $red ] ] = $this->attribute( 'Color', 'Red' );
		$server              = $this->server();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->patch( $server, $color, [ 'swatch' => 'color' ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = $this->patch( $server, $color, [ 'swatch' => 'color' ] );
		self::assertSame( 200, $response->get_status() );
		$row = $this->find( $response->get_data()['items'], $color );
		self::assertSame( 'color', $row['swatch'] );
		self::assertStringStartsWith( 'Color', $row['name'], 'the name is left as it was' );

		$response = $this->patch( $server, $red, [ 'color' => '#00ff00' ] );
		self::assertSame( '#00ff00', $this->find( $response->get_data()['items'], $color )['values'][0]['color'] );

		self::assertSame( 400, $this->patch( $server, $color, [ 'swatch' => 'slider' ] )->get_status() );
		self::assertSame( 400, $this->patch( $server, $red, [ 'color' => 'green' ] )->get_status() );
	}

	public function test_the_product_page_draws_swatches_as_radio_buttons(): void {
		[ $color, [ $red, $blue ] ] = $this->attribute( 'Color', 'Red', 'Blue' );
		$this->attributes()->set_swatch( $color, AttributeRepository::COLOR );
		$this->attributes()->set_look( $red, '#ff0000', null );
		$id = $this->product();
		$this->save(
			$id,
			[
				'type'       => 'variable',
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => [ $red, $blue ],
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
		$this->save(
			$id,
			[
				'variations' => array_map(
					static fn ( $v ): array => [
						'id'            => $v->id,
						'regular_price' => 1500,
					],
					$product->variations
				),
			]
		);

		$html = $this->form( $id );
		self::assertMatchesRegularExpression( '/<fieldset class="fxc-add-to-cart__option fxc-swatches fxc-swatches--color fxc-swatches--round"/', $html );
		self::assertMatchesRegularExpression( '/<input class="fxc-swatch__input" type="radio" name="attributes\[[^\]]+\]" value="' . $red . '" required/', $html );
		self::assertStringContainsString( 'style="--fxc-swatch-color:#ff0000"', $html );
		self::assertStringContainsString( '<label class="fxc-swatch fxc-swatch--button"><input class="fxc-swatch__input" type="radio"', $html, 'a value without a colour is a button' );
		self::assertStringContainsString( 'data-wp-text="state.chosenLabel"', $html );
		self::assertStringContainsString( '<select', $html, 'the custom Size stays a dropdown' );

		Settings::update(
			[
				'swatch_custom'      => 'button',
				'swatch_unavailable' => 'hide',
				'swatch_show_choice' => false,
				'swatch_size'        => 500,
			]
		);
		$html = $this->form( $id );
		self::assertStringNotContainsString( '<select', $html );
		self::assertStringContainsString( 'fxc-swatches--button', $html );
		self::assertStringContainsString( 'fxc-swatches--hide-unavailable', $html );
		self::assertStringContainsString( '--fxc-swatch-size:96px', $html, 'the size is capped' );
		self::assertStringNotContainsString( 'state.chosenLabel', $html );
	}

	private function attributes(): AttributeRepository {
		return Plugin::instance()->catalog()->attributes();
	}

	/** @return array<string,mixed> */
	private function row( int $id ): array {
		return $this->find( $this->attributes()->all(), $id );
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	private function find( array $rows, int $id ): array {
		foreach ( $rows as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		self::fail( 'attribute ' . $id . ' is listed' );
	}

	private function form( int $id ): string {
		return do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
	}

	private function server(): \WP_REST_Server {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		return $wp_rest_server;
	}

	/** @param array<string,mixed> $body */
	private function patch( \WP_REST_Server $server, int $id, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'PATCH', '/flexacommerce/v1/admin/attributes/' . $id );
		$request->set_body_params( $body );
		return $server->dispatch( $request );
	}
}
