<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

/** The email settings routes behind the Settings > Emails tab. */
final class AdminEmailsTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_the_list_has_every_email_with_its_settings(): void {
		$response = $this->send( 'GET', '/admin/emails' );
		self::assertSame( 200, $response->get_status() );
		$data   = $response->get_data();
		$emails = array_column( $data['emails'], null, 'id' );
		self::assertCount( 9, $emails );
		self::assertFalse( $emails['new_order']['to_customer'] );
		self::assertTrue( $emails['customer_completed_order']['to_customer'] );
		self::assertSame(
			[
				'enabled'    => true,
				'recipients' => '',
				'subject'    => '',
				'heading'    => '',
			],
			array_diff_key( $emails['new_order'], array_flip( [ 'id', 'title', 'to_customer' ] ) )
		);
		self::assertContains( '{order_number}', $data['placeholders'] );
	}

	public function test_a_partial_save_keeps_the_other_values(): void {
		$this->send( 'PATCH', '/admin/emails/new_order', [ 'subject' => 'New order {order_number}' ] );
		$saved = $this->send(
			'PATCH',
			'/admin/emails/new_order',
			[
				'enabled'    => false,
				'recipients' => 'a@example.com, b@example.com',
			]
		);
		self::assertSame( 200, $saved->get_status() );
		self::assertFalse( $saved->get_data()['enabled'] );
		self::assertSame( 'a@example.com, b@example.com', $saved->get_data()['recipients'] );
		self::assertSame( 'New order {order_number}', $saved->get_data()['subject'] );
	}

	public function test_bad_recipients_are_refused_and_customer_emails_take_none(): void {
		$bad = $this->send( 'PATCH', '/admin/emails/new_order', [ 'recipients' => 'a@example.com, nope' ] );
		self::assertSame( 400, $bad->get_status() );
		self::assertStringContainsString( 'nope', $bad->get_data()['message'] );
		self::assertSame( '', $this->send( 'GET', '/admin/emails' )->get_data()['emails'][0]['recipients'] );

		$customer = $this->send( 'PATCH', '/admin/emails/customer_completed_order', [ 'recipients' => 'a@example.com' ] );
		self::assertSame( 200, $customer->get_status() );
		self::assertSame( '', $customer->get_data()['recipients'] );

		self::assertSame( 404, $this->send( 'PATCH', '/admin/emails/nope', [ 'enabled' => true ] )->get_status() );
	}

	public function test_the_routes_need_the_settings_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/emails' )->get_status() );
		self::assertSame( 403, $this->send( 'PATCH', '/admin/emails/new_order', [ 'enabled' => false ] )->get_status() );
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
