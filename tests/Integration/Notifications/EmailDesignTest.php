<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Notifications;

use Flexa\Commerce\Notifications\Design\DesignTree;
use Flexa\Commerce\Notifications\Design\MailStyle;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** The email designer (ADR-085): designs, the shared style, previews and test sends. */
final class EmailDesignTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		reset_phpmailer_instance();
	}

	/** Order writes commit mid-test, so the options are cleaned after the rollback and committed. */
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		foreach ( Plugin::instance()->notifications()->emails() as $email ) {
			delete_option( EmailSettings::PREFIX . $email->id() );
		}
		delete_option( MailStyle::OPTION );
		wp_cache_flush();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_cleaning_keeps_only_what_the_renderer_understands(): void {
		$clean = DesignTree::clean(
			[
				'blocks' => [
					[
						'id'    => 'a1',
						'type'  => 'text',
						'props' => [
							'text'  => 'Hi <strong>there</strong><script>alert(1)</script>',
							'align' => 'middle',
							'extra' => 'x',
						],
					],
					[
						'id'   => 'a1',
						'type' => 'message',
					],
					[ 'type' => 'message' ],
					[ 'type' => 'nope' ],
					'junk',
					[
						'id'    => 'Bad Id',
						'type'  => 'button',
						'props' => [
							'url'   => 'javascript:alert(1)',
							'color' => 'red',
						],
					],
					[
						'type'  => 'image',
						'props' => [
							'link'  => '{order_url}',
							'width' => 9000,
						],
					],
					[
						'type'  => 'spacer',
						'props' => [ 'height' => 1 ],
					],
				],
			]
		);
		self::assertNotNull( $clean );
		$blocks = $clean['blocks'];
		self::assertSame( [ 'text', 'message', 'button', 'image', 'spacer' ], array_column( $blocks, 'type' ) );
		self::assertSame( 'Hi <strong>there</strong>alert(1)', $blocks[0]['props']['text'] );
		self::assertSame(
			[
				'text'  => $blocks[0]['props']['text'],
				'align' => 'left',
			],
			$blocks[0]['props']
		);
		self::assertCount( 5, array_unique( array_column( $blocks, 'id' ) ), 'Repeated and bad ids get new ones.' );
		self::assertSame( '', $blocks[2]['props']['url'] );
		self::assertSame( '', $blocks[2]['props']['color'] );
		self::assertSame( '{order_url}', $blocks[3]['props']['link'] );
		self::assertSame( 600, $blocks[3]['props']['width'] );
		self::assertSame( 4, $blocks[4]['props']['height'] );

		self::assertNull( DesignTree::clean( [ 'blocks' => [] ] ) );
		self::assertNull( DesignTree::clean( 'x' ) );
		self::assertSame( [ 'logo', 'heading', 'message', 'footer' ], array_column( DesignTree::starter()['blocks'], 'type' ) );
	}

	public function test_a_sent_email_follows_its_design_and_the_shared_style(): void {
		$settings = Plugin::instance()->notifications()->settings();
		$settings->set_design(
			'customer_processing_order',
			[
				'blocks' => [
					DesignTree::block( 'b1', 'logo' ),
					DesignTree::block( 'b2', 'addresses' ),
					DesignTree::block( 'b3', 'message' ),
					DesignTree::block( 'b4', 'button', [ 'label' => 'Track order {order_number}' ] ),
					DesignTree::block( 'b5', 'image' ),
					DesignTree::block( 'b6', 'footer', [ 'text' => 'Sent to {customer_email}' ] ),
				],
			]
		);
		$settings->update( 'customer_processing_order', [ 'subject' => 'Thanks for order {order_number}' ] );
		self::assertNotNull( $settings->design( 'customer_processing_order' ), 'Saving the settings keeps the design.' );
		Plugin::instance()->notifications()->designer()->style()->update( [ 'brand' => '#7C3AED' ] );

		$order = $this->place( [ $this->simple( 1500 ) => 2 ], 0, null, S::PROCESSING );

		$sent = array_column( $this->sent(), null, 'to' );
		self::assertSame( 'Thanks for order ' . $order->order_number, $sent['ada@example.com']['subject'] );
		$body = $sent['ada@example.com']['body'];
		self::assertSame( 1, substr_count( $body, 'Billing address' ), 'The addresses move; they are not printed twice.' );
		self::assertLessThan( strpos( $body, 'Product ' ), strpos( $body, 'Billing address' ) );
		self::assertStringContainsString( 'Track order ' . $order->order_number, $body );
		self::assertStringContainsString( 'order-received=' . $order->id, $body );
		self::assertStringContainsString( 'background:#7c3aed', $body );
		self::assertStringContainsString( 'Sent to ada@example.com', $body );
		self::assertStringNotContainsString( 'fxc-part', $body );
		self::assertStringNotContainsString( 'data-fxc-block', $body );
		self::assertStringNotContainsString( 'Choose an image', $body, 'An empty image block is left out.' );

		$admin = $sent[ (string) get_option( 'admin_email' ) ]['body'];
		self::assertStringContainsString( 'Billing address', $admin, 'An email without a design keeps the default layout.' );
		self::assertStringNotContainsString( 'fxc-part', $admin );
		self::assertStringContainsString( 'background:#7c3aed', $admin, 'The style reaches every email.' );
	}

	public function test_the_design_routes_save_and_reset_a_design(): void {
		$show = $this->send( 'GET', '/admin/emails/customer_completed_order/design' );
		self::assertSame( 200, $show->get_status() );
		$data = $show->get_data();
		self::assertNull( $data['design'] );
		self::assertSame( DesignTree::starter(), $data['starter'] );
		self::assertSame( MailStyle::DEFAULTS, $data['style'] );
		self::assertContains( '{customer_first_name}', $data['tokens'] );
		self::assertCount( 13, $data['emails'] );

		$design = [ 'blocks' => [ DesignTree::block( 'b1', 'heading', [ 'text' => 'Done!' ] ), DesignTree::block( 'b2', 'message' ) ] ];
		$saved  = $this->send( 'PUT', '/admin/emails/customer_completed_order/design', [ 'design' => $design ] );
		self::assertSame( 200, $saved->get_status() );
		self::assertSame( $design, $saved->get_data()['design'] );
		$list = array_column( $this->send( 'GET', '/admin/emails' )->get_data()['emails'], null, 'id' );
		self::assertTrue( $list['customer_completed_order']['custom'] );
		self::assertFalse( $list['new_order']['custom'] );

		self::assertSame( 'flexacommerce_design_missing', $this->send( 'PUT', '/admin/emails/customer_completed_order/design', [] )->get_data()['code'] );
		self::assertSame( 'flexacommerce_design_empty', $this->send( 'PUT', '/admin/emails/customer_completed_order/design', [ 'design' => [ 'blocks' => [] ] ] )->get_data()['code'] );
		self::assertSame( 404, $this->send( 'GET', '/admin/emails/nope/design' )->get_status() );

		$reset = $this->send( 'PUT', '/admin/emails/customer_completed_order/design', [ 'design' => null ] );
		self::assertNull( $reset->get_data()['design'] );
		self::assertNull( Plugin::instance()->notifications()->settings()->design( 'customer_completed_order' ) );
	}

	public function test_a_preview_shows_unsaved_changes_with_a_sample_order(): void {
		$design  = [ 'blocks' => [ DesignTree::block( 'x1', 'text', [ 'text' => 'Hello {customer_first_name}' ] ), DesignTree::block( 'x2', 'order_details' ) ] ];
		$preview = $this->send(
			'POST',
			'/admin/emails/customer_processing_order/preview',
			[
				'design' => $design,
				'style'  => [ 'background' => '#fef3c7' ],
			]
		);
		self::assertSame( 200, $preview->get_status() );
		$html = $preview->get_data()['html'];
		self::assertStringContainsString( '1001', $preview->get_data()['subject'] );
		self::assertStringContainsString( 'data-fxc-block="x1"', $html );
		self::assertStringContainsString( 'Hello Alex', $html );
		self::assertStringContainsString( 'Linen shirt', $html );
		self::assertStringContainsString( '#fef3c7', $html );
		self::assertSame( MailStyle::DEFAULTS, Plugin::instance()->notifications()->designer()->style()->get(), 'A preview saves nothing.' );

		$default = $this->send( 'POST', '/admin/emails/customer_processing_order/preview', [ 'design' => null ] )->get_data()['html'];
		self::assertStringNotContainsString( 'data-fxc-block', $default );
		self::assertStringContainsString( 'Linen shirt', $default );

		foreach ( Plugin::instance()->notifications()->emails() as $email ) {
			self::assertSame( 200, $this->send( 'POST', '/admin/emails/' . $email->id() . '/preview', [ 'design' => DesignTree::starter() ] )->get_status(), $email->id() );
		}
	}

	public function test_a_test_send_goes_to_the_given_addresses(): void {
		$sent = $this->send(
			'POST',
			'/admin/emails/customer_processing_order/test',
			[
				'to'     => 'qa@example.com, nope',
				'design' => DesignTree::starter(),
			]
		);
		self::assertSame( 200, $sent->get_status() );
		self::assertSame( [ 'qa@example.com' ], $sent->get_data()['sent'] );
		$mail = $this->sent();
		self::assertCount( 1, $mail );
		self::assertStringStartsWith( '[Test] ', $mail[0]['subject'] );
		self::assertStringNotContainsString( 'data-fxc-block', $mail[0]['body'] );

		$bad = $this->send( 'POST', '/admin/emails/customer_processing_order/test', [ 'to' => 'nope' ] );
		self::assertSame( 'flexacommerce_invalid_recipients', $bad->get_data()['code'] );
	}

	public function test_the_style_is_cleaned_and_merged(): void {
		$saved = $this->send(
			'PUT',
			'/admin/email-style',
			[
				'style' => [
					'brand'      => 'red',
					'text'       => '#333333',
					'logo_url'   => 'https://example.com/logo.png',
					'logo_width' => 9999,
					'font'       => 'comic',
					'footer'     => "1 Main St\n<b>Town</b>",
				],
			]
		);
		self::assertSame( 200, $saved->get_status() );
		$style = $saved->get_data()['style'];
		self::assertSame( MailStyle::DEFAULTS['brand'], $style['brand'] );
		self::assertSame( '#333333', $style['text'] );
		self::assertSame( 600, $style['logo_width'] );
		self::assertSame( 'system', $style['font'] );
		self::assertSame( "1 Main St\nTown", $style['footer'] );

		$again = $this->send( 'PUT', '/admin/email-style', [ 'style' => [ 'font' => 'georgia' ] ] )->get_data()['style'];
		self::assertSame( '#333333', $again['text'], 'A partial save keeps the rest.' );
		self::assertSame( 'georgia', $again['font'] );
		self::assertArrayNotHasKey( MailStyle::OPTION, wp_load_alloptions() );
	}

	public function test_the_routes_need_the_settings_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/emails/new_order/design' )->get_status() );
		self::assertSame( 403, $this->send( 'PUT', '/admin/emails/new_order/design', [ 'design' => null ] )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/emails/new_order/preview', [] )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/emails/new_order/test', [ 'to' => 'qa@example.com' ] )->get_status() );
		self::assertSame( 403, $this->send( 'PUT', '/admin/email-style', [ 'style' => [] ] )->get_status() );
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

	/** @return list<array{to:string,subject:string,body:string}> */
	private function sent(): array {
		$out = [];
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$out[] = [
				'to'      => (string) $mail['to'][0][0],
				'subject' => (string) $mail['subject'],
				'body'    => quoted_printable_decode( (string) $mail['body'] ),
			];
		}
		return $out;
	}
}
