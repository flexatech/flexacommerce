<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxcm_stock_alerts (ADR-065): an address that asked to hear
 * when a product, or one variant of it, is back in stock. `variant_id` 0
 * means any variant. `due` is set when the stock may have come back, so
 * the cron looks at the row again. The token is the secret part of the
 * stop link.
 */
final class StockAlert {
	public const WAITING   = 'waiting';
	public const SENT      = 'sent';
	public const CANCELLED = 'cancelled';

	public const STATUSES = [ self::WAITING, self::SENT, self::CANCELLED ];

	public function __construct(
		public readonly int $id,
		public readonly int $product_id,
		public readonly int $variant_id,
		public readonly ?int $user_id,
		public readonly string $email,
		public readonly string $locale,
		public readonly string $status,
		public readonly string $token,
		public readonly string $created_at,
		public readonly ?string $sent_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			product_id: (int) ( $row['product_id'] ?? 0 ),
			variant_id: (int) ( $row['variant_id'] ?? 0 ),
			user_id: isset( $row['user_id'] ) && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
			email: (string) ( $row['email'] ?? '' ),
			locale: (string) ( $row['locale'] ?? '' ),
			status: (string) ( $row['status'] ?? self::WAITING ),
			token: (string) ( $row['token'] ?? '' ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			sent_at: isset( $row['sent_at'] ) ? (string) $row['sent_at'] : null,
		);
	}

	/** The value of the stop link: the id and the token. */
	public function key(): string {
		return $this->id . '.' . $this->token;
	}
}
