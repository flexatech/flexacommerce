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
		self::assertSame( $data['fields'], $data['default']['fields'] );
		self::assertSame( $data['page'], $data['default']['page'] );
		self::assertSame( [ 'billing', 'shipping', 'additional' ], array_column( $data['page']['main'], 'id' ) );
		self::assertSame( 'Email address', $data['labels']['email'] );
		self::assertContains(
			[
				'code' => 'US',
				'name' => 'United States',
			],
			$data['countries']
		);
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

	public function test_the_page_keeps_every_section_and_payment_last(): void {
		$page = static fn ( array $main, array $side, string $columns = 'two' ): array => [
			'page' => [
				'columns' => $columns,
				'main'    => array_map( static fn ( $id ): array => is_array( $id ) ? $id : [ 'id' => $id ], $main ),
				'side'    => array_map( static fn ( $id ): array => is_array( $id ) ? $id : [ 'id' => $id ], $side ),
			],
		];

		$moved = $this->send( 'PUT', '/admin/checkout-layout', $page( [ 'billing', 'summary', 'shipping' ], [ 'additional', 'shipping_method', 'payment' ] ) );
		self::assertSame( 200, $moved->get_status() );
		self::assertSame( [ 'additional', 'shipping_method', 'payment' ], array_column( $moved->get_data()['page']['side'], 'id' ) );

		$under = $this->send( 'PUT', '/admin/checkout-layout', $page( [ 'billing', 'shipping', 'additional' ], [ 'shipping_method', 'payment', 'summary' ] ) );
		self::assertSame( 400, $under->get_status(), 'nothing goes under Payment' );
		self::assertSame( 'page.side.1.id', $under->get_data()['data']['field'] );

		$content = $this->send(
			'PUT',
			'/admin/checkout-layout',
			$page(
				[ 'billing', 'shipping', 'additional' ],
				[
					'shipping_method',
					'summary',
					[
						'id'   => 'text_x',
						'type' => 'paragraph',
						'text' => 'Above the button',
					],
					'payment',
				]
			)
		);
		self::assertSame( 200, $content->get_status(), 'content above Payment is fine' );

		$missing = $this->send( 'PUT', '/admin/checkout-layout', $page( [ 'billing', 'shipping' ], [ 'shipping_method', 'summary', 'payment' ] ) );
		self::assertSame( 400, $missing->get_status() );
		self::assertSame( 'page', $missing->get_data()['data']['field'] );

		$one = $this->send( 'PUT', '/admin/checkout-layout', $page( [ 'billing', 'shipping', 'additional', 'shipping_method', 'summary' ], [ 'payment' ], 'one' ) );
		self::assertSame( 200, $one->get_status() );
		self::assertSame( [], $one->get_data()['page']['side'], 'one column keeps everything in order in main' );
		self::assertSame( 'payment', $one->get_data()['page']['main'][5]['id'] );

		$empty = $this->send(
			'PUT',
			'/admin/checkout-layout',
			$page(
				[
					[
						'id'   => 'text_y',
						'type' => 'heading',
						'text' => '<b></b>',
					],
					'billing',
					'shipping',
					'additional',
				],
				[ 'shipping_method', 'summary', 'payment' ]
			)
		);
		self::assertSame( 400, $empty->get_status() );
		self::assertSame( 'page.main.0.text', $empty->get_data()['data']['field'] );
	}

	public function test_preview_keeps_the_draft_for_its_author_and_answers_a_link(): void {
		$author  = get_current_user_id();
		$preview = $this->send( 'POST', '/admin/checkout-layout/preview', [ 'page' => [ 'columns' => 'one' ] ] );
		self::assertSame( 200, $preview->get_status() );
		self::assertStringContainsString( CheckoutLayout::PREVIEW_ARG . '=', $preview->get_data()['url'] );
		self::assertSame( 'one', CheckoutLayout::draft( $author )?->columns() );
		self::assertFalse( get_option( CheckoutLayout::OPTION ), 'nothing is saved' );
		self::assertSame( 400, $this->send( 'POST', '/admin/checkout-layout/preview', [ 'page' => [ 'columns' => 'three' ] ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $this->send( 'POST', '/admin/checkout-layout/preview', [] )->get_status() );
		delete_transient( 'flexacommerce_checkout_preview_' . $author );
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
