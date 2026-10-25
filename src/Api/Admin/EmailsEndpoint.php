<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Order email settings (doc 02 §7.9): on or off, extra recipients for the
 * store emails, and a subject and heading that replace the defaults. An
 * empty value means the email's own default.
 */
final class EmailsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/emails',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/emails/(?P<id>[a-z0-9_]+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'id'         => [
							'type'     => 'string',
							'required' => true,
						],
						'enabled'    => [ 'type' => 'boolean' ],
						'recipients' => [
							'type'      => 'string',
							'maxLength' => 1000,
						],
						'subject'    => [
							'type'      => 'string',
							'maxLength' => 255,
						],
						'heading'    => [
							'type'      => 'string',
							'maxLength' => 255,
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$out = [];
		foreach ( Plugin::instance()->notifications()->emails() as $email ) {
			$out[] = $this->email_data( $email );
		}
		return new WP_REST_Response(
			[
				'emails'       => $out,
				'admin_email'  => (string) get_option( 'admin_email' ),
				'placeholders' => [ '{site_title}', '{order_number}', '{order_date}' ],
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = Plugin::instance()->notifications()->email( (string) $request->get_param( 'id' ) );
		if ( $email === null ) {
			return new WP_Error( 'flexacommerce_email_not_found', __( 'Email not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		$partial = [];
		foreach ( [ 'enabled', 'recipients', 'subject', 'heading' ] as $key ) {
			if ( $request->has_param( $key ) ) {
				$partial[ $key ] = $request->get_param( $key );
			}
		}
		if ( isset( $partial['recipients'] ) ) {
			if ( $email->to_customer() ) {
				unset( $partial['recipients'] );
			} else {
				$invalid = self::invalid_addresses( (string) $partial['recipients'] );
				if ( $invalid !== [] ) {
					return new WP_Error(
						'flexacommerce_invalid_recipients',
						/* translators: %s: comma separated addresses. */
						sprintf( __( 'These are not valid email addresses: %s', 'flexacommerce' ), implode( ', ', $invalid ) ),
						[
							'status' => 400,
							'field'  => 'recipients',
						]
					);
				}
			}
		}
		Plugin::instance()->notifications()->settings()->update( $email->id(), $partial );
		return new WP_REST_Response( $this->email_data( $email ), 200 );
	}

	/** @return array<string,mixed> */
	private function email_data( Email $email ): array {
		return [
			'id'          => $email->id(),
			'title'       => $email->title(),
			'to_customer' => $email->to_customer(),
		] + Plugin::instance()->notifications()->settings()->get( $email->id() );
	}

	/** @return list<string> */
	private static function invalid_addresses( string $list ): array {
		$out = [];
		foreach ( explode( ',', $list ) as $part ) {
			$part = trim( $part );
			if ( $part !== '' && EmailSettings::addresses( $part ) === [] ) {
				$out[] = $part;
			}
		}
		return $out;
	}
}
