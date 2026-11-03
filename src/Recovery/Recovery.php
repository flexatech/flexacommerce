<?php

declare(strict_types=1);

namespace Flexa\Commerce\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxcm_recoveries (ADR-063): a cart a known shopper left, as it
 * was when the cron last looked. `cart` is the stored cart (lines and
 * coupons) and what the email lists. `reminder` is the next reminder to
 * send, 0 to 2, or 3 when all have had their turn. The token is the
 * secret part of the recovery and unsubscribe links.
 *
 * @phpstan-type Snapshot array{lines:list<array<string,mixed>>,coupons:list<string>,items:list<array{name:string,quantity:int,total:?int,image:string}>}
 */
final class Recovery {
	public const OPEN      = 'open';
	public const RECOVERED = 'recovered';
	public const LOST      = 'lost';

	public const STATUSES = [ self::OPEN, self::RECOVERED, self::LOST ];

	/** @param Snapshot $cart */
	public function __construct(
		public readonly int $id,
		public readonly ?int $session_id,
		public readonly ?int $user_id,
		public readonly string $email,
		public readonly array $cart,
		public readonly int $total,
		public readonly string $currency,
		public readonly int $item_count,
		public readonly string $status,
		public readonly string $token,
		public readonly int $reminder,
		public readonly int $emails_sent,
		public readonly ?string $next_send_at,
		public readonly string $last_activity_at,
		public readonly ?string $clicked_at,
		public readonly string $coupon_code,
		public readonly ?int $order_id,
		public readonly ?string $recovered_at,
		public readonly ?string $unsubscribed_at,
		public readonly string $created_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$cart = json_decode( (string) ( $row['cart'] ?? '' ), true );
		$cart = is_array( $cart ) ? $cart : [];
		return new self(
			id: (int) $row['id'],
			session_id: isset( $row['session_id'] ) && (int) $row['session_id'] > 0 ? (int) $row['session_id'] : null,
			user_id: isset( $row['user_id'] ) && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
			email: (string) ( $row['email'] ?? '' ),
			cart: self::snapshot( $cart ),
			total: (int) ( $row['total'] ?? 0 ),
			currency: (string) ( $row['currency'] ?? '' ),
			item_count: (int) ( $row['item_count'] ?? 0 ),
			status: (string) ( $row['status'] ?? self::OPEN ),
			token: (string) ( $row['token'] ?? '' ),
			reminder: (int) ( $row['reminder'] ?? 0 ),
			emails_sent: (int) ( $row['emails_sent'] ?? 0 ),
			next_send_at: isset( $row['next_send_at'] ) ? (string) $row['next_send_at'] : null,
			last_activity_at: (string) ( $row['last_activity_at'] ?? '' ),
			clicked_at: isset( $row['clicked_at'] ) ? (string) $row['clicked_at'] : null,
			coupon_code: (string) ( $row['coupon_code'] ?? '' ),
			order_id: isset( $row['order_id'] ) && (int) $row['order_id'] > 0 ? (int) $row['order_id'] : null,
			recovered_at: isset( $row['recovered_at'] ) ? (string) $row['recovered_at'] : null,
			unsubscribed_at: isset( $row['unsubscribed_at'] ) ? (string) $row['unsubscribed_at'] : null,
			created_at: (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * @param array<mixed> $cart
	 * @return Snapshot
	 */
	public static function snapshot( array $cart ): array {
		$items = [];
		foreach ( is_array( $cart['items'] ?? null ) ? $cart['items'] : [] as $item ) {
			if ( is_array( $item ) ) {
				$items[] = [
					'name'     => (string) ( $item['name'] ?? '' ),
					'quantity' => (int) ( $item['quantity'] ?? 1 ),
					'total'    => isset( $item['total'] ) ? (int) $item['total'] : null,
					'image'    => (string) ( $item['image'] ?? '' ),
				];
			}
		}
		return [
			'lines'   => array_values( array_filter( is_array( $cart['lines'] ?? null ) ? $cart['lines'] : [], 'is_array' ) ),
			'coupons' => array_values( array_filter( is_array( $cart['coupons'] ?? null ) ? $cart['coupons'] : [], 'is_string' ) ),
			'items'   => $items,
		];
	}

	/** Whether a reminder went out or the shopper came back through a link. */
	public function was_reached(): bool {
		return $this->emails_sent > 0 || $this->clicked_at !== null;
	}

	/** The link value: id and token. */
	public function key(): string {
		return $this->id . '.' . $this->token;
	}
}
