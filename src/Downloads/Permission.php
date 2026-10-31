<?php

declare(strict_types=1);

namespace Flexa\Commerce\Downloads;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxc_downloads (ADR-058): the right to download one file of one
 * order line. `downloads_remaining` and `access_expires` are null for no
 * limit. The token is the secret part of the download link.
 */
final class Permission {
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly int $order_item_id,
		public readonly int $product_id,
		public readonly int $variant_id,
		public readonly string $file_id,
		public readonly ?int $customer_id,
		public readonly string $email,
		public readonly string $token,
		public readonly ?int $downloads_remaining,
		public readonly ?string $access_expires,
		public readonly int $download_count,
		public readonly string $granted_at,
		public readonly ?string $last_download_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			order_id: (int) $row['order_id'],
			order_item_id: (int) $row['order_item_id'],
			product_id: (int) $row['product_id'],
			variant_id: (int) $row['variant_id'],
			file_id: (string) $row['file_id'],
			customer_id: isset( $row['customer_id'] ) && (int) $row['customer_id'] > 0 ? (int) $row['customer_id'] : null,
			email: (string) ( $row['email'] ?? '' ),
			token: (string) $row['token'],
			downloads_remaining: isset( $row['downloads_remaining'] ) ? (int) $row['downloads_remaining'] : null,
			access_expires: isset( $row['access_expires'] ) ? (string) $row['access_expires'] : null,
			download_count: (int) ( $row['download_count'] ?? 0 ),
			granted_at: (string) ( $row['granted_at'] ?? '' ),
			last_download_at: isset( $row['last_download_at'] ) ? (string) $row['last_download_at'] : null,
		);
	}

	/** `$now` is a UTC `Y-m-d H:i:s` string. */
	public function is_expired( string $now ): bool {
		return $this->access_expires !== null && $this->access_expires <= $now;
	}

	public function is_used_up(): bool {
		return $this->downloads_remaining !== null && $this->downloads_remaining <= 0;
	}

	/** @return array<string,mixed> JS-friendly shape for the admin REST API. */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'order_item_id'       => $this->order_item_id,
			'product_id'          => $this->product_id,
			'variant_id'          => $this->variant_id,
			'file_id'             => $this->file_id,
			'downloads_remaining' => $this->downloads_remaining,
			'access_expires'      => $this->access_expires,
			'download_count'      => $this->download_count,
			'granted_at'          => $this->granted_at,
			'last_download_at'    => $this->last_download_at,
		];
	}
}
