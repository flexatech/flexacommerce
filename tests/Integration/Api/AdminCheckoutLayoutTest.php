<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Database\Upgrades\V8CheckoutLayout;
use Flexa\Commerce\Orders\OrderFields;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** The checkout builder route, the settings it took over, and the answers it collects (ADR-031). */
final class AdminCheckoutLayoutTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_the_builder_reads_saves_and_resets_the_layout(): void {
		$shown = $this->send( 'GET', '/admin/checkout-layout' );
		self::assertSame( 200, $shown->get_status() );
		$data = $shown->get_data();
		self::assertSame( $data['fields'], $data['default'] );
		self::assertSame( 'Email address', $data['labels']['email'] );
		self::assertSame( [ 'billing', 'shipping', 'additional' ], array_keys( $data['fields'] ) );

		$saved = $this->send(
			'PUT',
			'/admin/checkout-layout',
			[
				'fields' => [
					'billing' => [
						[
							'id'     => 'phone',
							'answer' => 'required',
						],
						[
							'id'    => 'vat',
							'type'  => 'text',
							'label' => 'VAT number',
						],
					],
				],
			]
		);
		self::assertSame( 200, $saved->get_status() );
		self::assertSame( [ 'phone', 'vat', 'email' ], array_slice( array_column( $saved->get_data()['fields']['billing'], 'id' ), 0, 3 ) );
		self::assertIsArray( get_option( CheckoutLayout::OPTION ) );
		self::assertFalse( wp_load_alloptions()[ CheckoutLayout::OPTION ] ?? false, 'only the checkout loads it' );

		$refused = $this->send(
			'PUT',
			'/admin/checkout-layout',
			[
				'fields' => [
					'billing' => [
						[
							'id'     => 'email',
							'answer' => 'hidden',
						],
					],
				],
			]
		);
		self::assertSame( 400, $refused->get_status() );
		self::assertSame( 'flexacommerce_invalid_checkout_layout', $refused->get_data()['code'] );
		self::assertSame( 'billing.0.answer', $refused->get_data()['data']['field'] );
		self::assertSame( 'required', CheckoutLayout::current()->entry( 'billing', 'phone' )['answer'] ?? '', 'the saved layout stays' );

		$reset = $this->send( 'DELETE', '/admin/checkout-layout' );
		self::assertSame( 200, $reset->get_status() );
		self::assertFalse( get_option( CheckoutLayout::OPTION ) );
		self::assertTrue( CheckoutLayout::current()->is_default() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/checkout-layout' )->get_status() );
	}

	public function test_the_upgrade_keeps_the_choices_made_in_settings(): void {
		$stored = get_option( Settings::OPTION, [] );
		update_option(
			Settings::OPTION,
			array_merge(
				is_array( $stored ) ? $stored : [],
				[
					'checkout_company'   => 'required',
					'checkout_phone'     => 'optional',
					'checkout_address_2' => 'hidden',
				]
			)
		);
		( new V8CheckoutLayout() )->run();

		$after = get_option( Settings::OPTION );
		self::assertIsArray( $after );
		self::assertArrayNotHasKey( 'checkout_company', $after );
		$layout = CheckoutLayout::current();
		self::assertSame( 'required', $layout->entry( 'billing', 'company' )['answer'] ?? '' );
		self::assertSame( 'hidden', $layout->entry( 'shipping', 'address_2' )['answer'] ?? '' );
		self::assertSame( 'optional', $layout->entry( 'billing', 'phone' )['answer'] ?? '' );
		self::assertSame( 'company', $layout->section( 'billing' )[3]['id'], 'the order stays' );

		delete_option( CheckoutLayout::OPTION );
		update_option( Settings::OPTION, array_merge( $after, [ 'checkout_company' => 'hidden' ] ) );
		( new V8CheckoutLayout() )->run();
		self::assertFalse( get_option( CheckoutLayout::OPTION ), 'nothing to keep when the choices were the defaults' );
		self::assertArrayNotHasKey( 'checkout_company', (array) get_option( Settings::OPTION ) );
	}

	public function test_answers_show_in_the_admin_and_leave_with_the_personal_data(): void {
		$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, OrderStatus::PROCESSING );
		$repo  = Plugin::instance()->orders()->orders();
		$repo->set_meta( $order->id, '_additional_gift', 'Happy birthday' );
		$repo->set_meta(
			$order->id,
			OrderFields::META,
			(string) wp_json_encode(
				[
					[
						'key'   => '_additional_gift',
						'label' => 'Gift message',
						'value' => 'Happy birthday',
						'email' => true,
					],
				]
			)
		);

		$detail = $this->send( 'GET', '/admin/orders/' . $order->id )->get_data();
		self::assertSame(
			[
				[
					'label' => 'Gift message',
					'value' => 'Happy birthday',
				],
			],
			$detail['fields']
		);

		Privacy::instance()->register();
		$export = Privacy::instance()->export_orders( 'ada@example.com', 1 )['data'][0]['data'];
		self::assertContains(
			[
				'name'  => 'Gift message',
				'value' => 'Happy birthday',
			],
			$export
		);

		$this->orders()->transition( $order->id, OrderStatus::COMPLETED, TransitionContext::admin() );
		Privacy::instance()->erase_orders( 'ada@example.com', 1 );
		self::assertNull( $repo->get_meta( $order->id, '_additional_gift' ) );
		self::assertSame( [], OrderFields::for_order( $repo, $order->id ) );
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
