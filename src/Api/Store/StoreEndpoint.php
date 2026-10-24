<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\SameSite;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Base of the public store routes (doc 06 §2). Reads are open. Writes pass
 * three checks in the permission callback: a JSON body, a same-site Origin
 * (or Referer), and the `X-FXC-Nonce` header. Every response carries a
 * fresh nonce so the storefront script can keep going after the cart
 * session changes.
 */
abstract class StoreEndpoint extends Endpoint {
	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function read_permission( WP_REST_Request $request ): bool {
		unset( $request );
		return true;
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function write_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( $request->get_method() !== 'DELETE' && ! $request->is_json_content_type() ) {
			return new WP_Error( 'flexacommerce_unsupported_media_type', __( 'Send the request body as JSON.', 'flexacommerce' ), [ 'status' => 415 ] );
		}
		if ( ! self::same_origin( $request ) ) {
			return new WP_Error( 'flexacommerce_bad_origin', __( 'This request came from another site.', 'flexacommerce' ), [ 'status' => 403 ] );
		}
		$nonce = (string) $request->get_header( 'x_fxc_nonce' );
		if ( ! self::nonce()->verify( $nonce, get_current_user_id(), wp_get_session_token(), self::session_token() ) ) {
			return new WP_Error(
				'flexacommerce_invalid_nonce',
				__( 'Your session has expired. Please try again.', 'flexacommerce' ),
				[
					'status' => 403,
					'nonce'  => self::current_nonce(),
				]
			);
		}
		return true;
	}

	/**
	 * Adds the nonce header. The nonce is bound to the cart session, so it is
	 * computed after the handler ran and possibly created the session.
	 *
	 * @param array<string,mixed> $data
	 */
	protected static function respond( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( SessionNonce::HEADER, self::current_nonce() );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	protected static function current_nonce(): string {
		return self::nonce()->create( get_current_user_id(), wp_get_session_token(), self::session_token() );
	}

	private static function nonce(): SessionNonce {
		return Plugin::instance()->cart()->nonce();
	}

	private static function session_token(): ?string {
		return Plugin::instance()->cart()->sessions()->token();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	private static function same_origin( WP_REST_Request $request ): bool {
		return SameSite::check( (string) $request->get_header( 'origin' ), (string) $request->get_header( 'referer' ) );
	}
}
