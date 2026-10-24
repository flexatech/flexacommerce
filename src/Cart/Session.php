<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/** One row of fxc_sessions (doc 03 §4.9). */
final class Session {
	/** @param array<string,mixed> $data */
	public function __construct(
		public readonly int $id,
		public readonly string $token_hash,
		public readonly ?int $user_id,
		public readonly array $data,
		public readonly int $version,
		public readonly string $expires_at,
		public readonly string $created_at = '',
		public readonly string $updated_at = '',
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$data = [];
		if ( isset( $row['data'] ) && is_string( $row['data'] ) && $row['data'] !== '' ) {
			try {
				$decoded = json_decode( $row['data'], true, 16, JSON_THROW_ON_ERROR );
				$data    = is_array( $decoded ) ? $decoded : [];
			} catch ( \JsonException ) {
				$data = [];
			}
		}
		return new self(
			id: (int) $row['id'],
			token_hash: (string) $row['token_hash'],
			user_id: isset( $row['user_id'] ) && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
			data: $data,
			version: (int) $row['version'],
			expires_at: (string) $row['expires_at'],
			created_at: (string) ( $row['created_at'] ?? '' ),
			updated_at: (string) ( $row['updated_at'] ?? '' ),
		);
	}

	public function is_expired( string $now ): bool {
		return $this->expires_at <= $now;
	}

	/** @param array<string,mixed> $data */
	public function with_data( array $data, ?string $expires_at = null, ?int $user_id = null ): self {
		return new self( $this->id, $this->token_hash, $user_id ?? $this->user_id, $data, $this->version + 1, $expires_at ?? $this->expires_at, $this->created_at, $this->updated_at );
	}
}
