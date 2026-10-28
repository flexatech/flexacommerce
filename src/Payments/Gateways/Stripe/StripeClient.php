<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * The Stripe API over the WordPress HTTP API, with the API version pinned
 * so the answers keep the shape the gateway reads. POSTs carry an
 * idempotency key: a retry after a timeout returns the first answer and
 * never charges twice.
 */
final class StripeClient {
	public const API_VERSION = '2024-06-20';

	public const BASE = 'https://api.stripe.com';

	public function __construct(
		private readonly string $secret_key,
		private readonly string $base = self::BASE,
		private readonly int $timeout = 30,
	) {}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 * @throws StripeError
	 */
	public function get( string $path, array $params = [] ): array {
		return $this->send( 'GET', $path, $params, '' );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 * @throws StripeError
	 */
	public function post( string $path, array $params = [], string $idempotency_key = '' ): array {
		return $this->send( 'POST', $path, $params, $idempotency_key );
	}

	/**
	 * @return array<string,mixed>
	 * @throws StripeError
	 */
	public function delete( string $path ): array {
		return $this->send( 'DELETE', $path, [], '' );
	}

	/**
	 * Stripe's form encoding: nested keys as `a[b]`, lists as `a[0]`,
	 * booleans as `true` and `false`.
	 *
	 * @param array<array-key,mixed> $params
	 */
	public static function encode( array $params ): string {
		$walk = static function ( mixed $value ) use ( &$walk ): mixed {
			if ( is_bool( $value ) ) {
				return $value ? 'true' : 'false';
			}
			return is_array( $value ) ? array_map( $walk, $value ) : $value;
		};
		return http_build_query( (array) $walk( $params ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function send( string $method, string $path, array $params, string $idempotency_key ): array {
		$url     = rtrim( $this->base, '/' ) . '/v1/' . ltrim( $path, '/' );
		$query   = self::encode( $params );
		$headers = [
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
			'User-Agent'     => 'FlexaCommerce/' . ( defined( 'FLEXACOMMERCE_VERSION' ) ? FLEXACOMMERCE_VERSION : 'dev' ),
		];
		if ( $method === 'POST' ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			if ( $idempotency_key !== '' ) {
				$headers['Idempotency-Key'] = $idempotency_key;
			}
		} elseif ( $query !== '' ) {
			$url  .= '?' . $query;
			$query = '';
		}
		$response = wp_remote_request(
			$url,
			[
				'method'  => $method,
				'headers' => $headers,
				'body'    => $method === 'POST' ? $query : null,
				'timeout' => $this->timeout,
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new StripeError( $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : [];
		if ( $status >= 200 && $status < 300 ) {
			return $data;
		}
		$error   = is_array( $data['error'] ?? null ) ? $data['error'] : [];
		$message = is_string( $error['message'] ?? null ) && $error['message'] !== '' ? $error['message'] : sprintf( 'Stripe answered %d.', $status );
		throw new StripeError( $message, $status, $error );
	}
}
