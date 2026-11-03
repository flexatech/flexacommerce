<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Notifications\Design\Designer;
use Flexa\Commerce\Notifications\Design\DesignTree;
use Flexa\Commerce\Notifications\Design\MailStyle;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The email designer (ADR-085): one email's design, the style all emails
 * share, a preview and a test send. A preview or test takes the design and
 * style being edited, so the merchant sees a change before saving it; a
 * `design` of null there means the default layout, and no `design` at all
 * means the saved one.
 */
final class EmailDesignEndpoint extends Endpoint {
	/** Most addresses one test goes to. */
	private const MAX_TEST_TO = 5;

	public function register_routes(): void {
		$id = '/admin/emails/(?P<id>[a-z0-9_]+)';
		register_rest_route(
			self::NAMESPACE,
			$id . '/design',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/preview',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'preview' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'order_id' => [
							'type'    => 'integer',
							'minimum' => 0,
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/test',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'test' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'to'       => [
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 1000,
						],
						'order_id' => [
							'type'    => 'integer',
							'minimum' => 0,
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/email-style',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'save_style' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = self::email( $request );
		if ( $email instanceof WP_Error ) {
			return $email;
		}
		$notifications = Plugin::instance()->notifications();
		$settings      = $notifications->settings();
		$emails        = [];
		foreach ( $notifications->emails() as $one ) {
			$emails[] = [
				'id'     => $one->id(),
				'title'  => $one->title(),
				'custom' => $settings->design( $one->id() ) !== null,
			];
		}
		return new WP_REST_Response(
			[
				'email'    => [
					'id'          => $email->id(),
					'title'       => $email->title(),
					'to_customer' => $email->to_customer(),
				],
				'design'   => $settings->design( $email->id() ),
				'starter'  => DesignTree::starter(),
				'style'    => $notifications->designer()->style()->get(),
				'defaults' => MailStyle::DEFAULTS,
				'fonts'    => array_keys( MailStyle::FONTS ),
				'tokens'   => Designer::TOKENS,
				'orders'   => $notifications->preview()->recent(),
				'emails'   => $emails,
				'test_to'  => (string) wp_get_current_user()->user_email,
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = self::email( $request );
		if ( $email instanceof WP_Error ) {
			return $email;
		}
		$body = (array) $request->get_json_params();
		if ( ! array_key_exists( 'design', $body ) ) {
			return new WP_Error( 'flexacommerce_design_missing', __( 'Send a design, or null to go back to the default.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		if ( $body['design'] !== null && DesignTree::clean( $body['design'] ) === null ) {
			return new WP_Error( 'flexacommerce_design_empty', __( 'Add at least one block to the email.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		$saved = Plugin::instance()->notifications()->settings()->set_design( $email->id(), $body['design'] );
		return new WP_REST_Response( [ 'design' => $saved ], 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save_style( WP_REST_Request $request ): WP_REST_Response {
		$body  = (array) $request->get_json_params();
		$style = Plugin::instance()->notifications()->designer()->style()->update( is_array( $body['style'] ?? null ) ? $body['style'] : [] );
		return new WP_REST_Response( [ 'style' => $style ], 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$built = $this->build( $request, true );
		return $built instanceof WP_Error ? $built : new WP_REST_Response( $built, 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$to = EmailSettings::addresses( (string) $request->get_param( 'to' ) );
		if ( $to === [] ) {
			return new WP_Error(
				'flexacommerce_invalid_recipients',
				__( 'Enter an email address to send the test to.', 'flexacommerce' ),
				[
					'status' => 400,
					'field'  => 'to',
				]
			);
		}
		$built = $this->build( $request, false );
		if ( $built instanceof WP_Error ) {
			return $built;
		}
		/* translators: %s: email subject. */
		$subject = sprintf( __( '[Test] %s', 'flexacommerce' ), $built['subject'] );
		if ( ! Plugin::instance()->notifications()->mailer()->send_test( array_slice( $to, 0, self::MAX_TEST_TO ), $subject, $built['html'] ) ) {
			return new WP_Error( 'flexacommerce_test_failed', __( 'The test email could not be sent. Check that this site can send email.', 'flexacommerce' ), [ 'status' => 500 ] );
		}
		return new WP_REST_Response( [ 'sent' => array_slice( $to, 0, self::MAX_TEST_TO ) ], 200 );
	}

	/**
	 * @param WP_REST_Request<array<string,mixed>> $request
	 * @return array{subject:string,html:string}|WP_Error
	 */
	private function build( WP_REST_Request $request, bool $marks ): array|WP_Error {
		$email = self::email( $request );
		if ( $email instanceof WP_Error ) {
			return $email;
		}
		$notifications = Plugin::instance()->notifications();
		$body          = (array) $request->get_json_params();
		$design        = array_key_exists( 'design', $body ) ? DesignTree::clean( $body['design'] ) : $notifications->settings()->design( $email->id() );
		$style         = $notifications->designer()->style()->get();
		if ( is_array( $body['style'] ?? null ) ) {
			$style = MailStyle::clean( array_merge( $style, array_intersect_key( $body['style'], MailStyle::DEFAULTS ) ) );
		}
		return $notifications->preview()->render( $email, $design, $style, (int) $request->get_param( 'order_id' ), $marks );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	private static function email( WP_REST_Request $request ): Email|WP_Error {
		$email = Plugin::instance()->notifications()->email( (string) $request->get_param( 'id' ) );
		return $email ?? new WP_Error( 'flexacommerce_email_not_found', __( 'Email not found.', 'flexacommerce' ), [ 'status' => 404 ] );
	}
}
