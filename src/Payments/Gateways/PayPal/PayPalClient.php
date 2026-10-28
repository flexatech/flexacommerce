<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\PayPal;

defined( 'ABSPATH' ) || exit;

/**
 * The PayPal REST API over the WordPress HTTP API. The OAuth access token
 * lives about nine hours; it is kept in a non-autoloaded option, tied to
 * the credentials that got it, and fetched again once when PayPal answers
 * 401. Writes carry `PayPal-Request-Id`: a retry after a timeout returns
 * the first answer and never captures twice.
 */
final class PayPalClient {
	public const SANDBOX = 'https://api-m.sandbox.paypal.com';

	public const LIVE = 'https://api-m.paypal.com';

	/** The cached tokens, per mode. Erased with the store's options. */
	public const TOKEN_OPTION = 'flexacommerce_paypal_token';

	public function __construct(
		private readonly string $client_id,
		private readonly string $secret,
		private readonly bool $live = false,
		private readonly int $timeout = 30,
	) {}

	public function base(): string {
		return $this->live ? self::LIVE : self::SANDBOX;
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 * @throws PayPalError
	 */
	public function get( string $path, array $query = [] ): array {
		return $this->send( 'GET', $path . ( $query !== [] ? '?' . http_build_query( $query ) : '' ), null, '' );
	}

	/**
	 * @param array<string,mixed>|string $body an array, or JSON already encoded
	 * @return array<string,mixed>
	 * @throws PayPalError
	 */
	public function post( string $path, array|string $body = [], string $request_id = '' ): array {
		return $this->send( 'POST', $path, is_string( $body ) ? $body : self::json( $body ), $request_id );
	}

	/**
	 * @param list<array<string,mixed>> $operations JSON Patch
	 * @return array<string,mixed>
	 * @throws PayPalError
	 */
	public function patch( string $path, array $operations ): array {
		return $this->send( 'PATCH', $path, (string) wp_json_encode( $operations ), '' );
	}

	/**
	 * A token for these credentials; checks them, so a save of wrong ones fails.
	 *
	 * @throws PayPalError 401 when PayPal does not know the client ID and secret
	 */
	public function token( bool $fresh = false ): string {
		$mode   = $this->live ? 'live' : 'test';
		$hash   = hash( 'sha256', $this->client_id . ':' . $this->secret );
		$stored = get_option( self::TOKEN_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$entry  = is_array( $stored[ $mode ] ?? null ) ? $stored[ $mode ] : [];
		if ( ! $fresh && ( $entry['hash'] ?? '' ) === $hash && (int) ( $entry['expires'] ?? 0 ) > time() + 60 && is_string( $entry['token'] ?? null ) ) {
			return $entry['token'];
		}
		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			[
				'headers' => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'User-Agent'    => self::agent(),
				],
				'body'    => 'grant_type=client_credentials',
				'timeout' => $this->timeout,
			]
		);
		$data     = self::answer( $response );
		$token    = is_string( $data['access_token'] ?? null ) ? $data['access_token'] : '';
		if ( $token === '' ) {
			throw new PayPalError( 'PayPal did not return an access token.' );
		}
		$stored[ $mode ] = [
			'hash'    => $hash,
			'token'   => $token,
			'expires' => time() + max( 60, (int) ( $data['expires_in'] ?? 3600 ) ),
		];
		update_option( self::TOKEN_OPTION, $stored, false );
		return $token;
	}

	/** @param array<string,mixed> $data */
	public static function json( array $data ): string {
		// Empty objects stay objects: PayPal wants `"paypal": {}`, not `[]`.
		return (string) wp_json_encode( $data === [] ? new \stdClass() : $data, JSON_UNESCAPED_SLASHES );
	}

	/** @return array<string,mixed> */
	private function send( string $method, string $path, ?string $body, string $request_id, bool $retry = true ): array {
		$headers = [
			'Authorization' => 'Bearer ' . $this->token(),
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
			'User-Agent'    => self::agent(),
		];
		if ( $request_id !== '' ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}
		$response = wp_remote_request(
			$this->base() . '/' . ltrim( $path, '/' ),
			[
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => $this->timeout,
			]
		);
		try {
			return self::answer( $response );
		} catch ( PayPalError $e ) {
			if ( $e->status === 401 && $retry ) {
				$this->token( true );
				return $this->send( $method, $path, $body, $request_id, false );
			}
			throw $e;
		}
	}

	/**
	 * @param array<string,mixed>|\WP_Error $response
	 * @return array<string,mixed>
	 */
	private static function answer( array|\WP_Error $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new PayPalError( $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : [];
		if ( $status >= 200 && $status < 300 ) {
			return $data;
		}
		$details = is_array( $data['details'] ?? null ) && is_array( $data['details'][0] ?? null ) ? $data['details'][0] : [];
		$message = '';
		foreach ( [ $details['description'] ?? null, $data['message'] ?? null, $data['error_description'] ?? null ] as $text ) {
			if ( is_string( $text ) && $text !== '' ) {
				$message = $text;
				break;
			}
		}
		throw new PayPalError( $message !== '' ? $message : sprintf( 'PayPal answered %d.', $status ), $status, $data );
	}

	private static function agent(): string {
		return 'FlexaCommerce/' . ( defined( 'FLEXACOMMERCE_VERSION' ) ? FLEXACOMMERCE_VERSION : 'dev' );
	}
}
