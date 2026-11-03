<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxcm_gift_cards (ADR-069). The code is stored normalized:
 * upper case, no spaces or dashes. Amounts are in minor units of
 * `currency`. A card bought in an order keeps the order and item ids; a
 * card the merchant made has none. `deliver_at` holds the email back until
 * that moment, `delivered_at` says when it went out. A card with a
 * `user_id` is that customer's store credit (ADR-072): it never expires,
 * its code is never shown or accepted in the code box, and it pays on its
 * own for the customer's carts.
 */
final class GiftCard {
	public const ACTIVE   = 'active';
	public const DISABLED = 'disabled';

	public const STATUSES = [ self::ACTIVE, self::DISABLED ];

	/** No 0/O, 1/I/L, 5/S or 8/B: a code read aloud or typed from paper stays right. */
	public const ALPHABET = '234679ACDEFGHJKMNPQRTUVWXYZ';

	public const LENGTH = 16;

	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly int $initial_amount,
		public readonly int $balance,
		public readonly string $currency,
		public readonly string $status,
		public readonly string $recipient_email,
		public readonly string $recipient_name,
		public readonly string $sender_name,
		public readonly string $message,
		public readonly ?int $order_id,
		public readonly ?int $order_item_id,
		public readonly ?string $deliver_at,
		public readonly ?string $delivered_at,
		public readonly ?string $expires_at,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?int $user_id = null,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$id = static fn ( mixed $v ): ?int => $v !== null && (int) $v > 0 ? (int) $v : null;
		$at = static fn ( mixed $v ): ?string => $v !== null && $v !== '' ? (string) $v : null;
		return new self(
			id: (int) $row['id'],
			code: (string) ( $row['code'] ?? '' ),
			initial_amount: (int) ( $row['initial_amount'] ?? 0 ),
			balance: (int) ( $row['balance'] ?? 0 ),
			currency: (string) ( $row['currency'] ?? '' ),
			status: in_array( $row['status'] ?? null, self::STATUSES, true ) ? (string) $row['status'] : self::ACTIVE,
			recipient_email: (string) ( $row['recipient_email'] ?? '' ),
			recipient_name: (string) ( $row['recipient_name'] ?? '' ),
			sender_name: (string) ( $row['sender_name'] ?? '' ),
			message: (string) ( $row['message'] ?? '' ),
			order_id: $id( $row['order_id'] ?? null ),
			order_item_id: $id( $row['order_item_id'] ?? null ),
			deliver_at: $at( $row['deliver_at'] ?? null ),
			delivered_at: $at( $row['delivered_at'] ?? null ),
			expires_at: $at( $row['expires_at'] ?? null ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			updated_at: (string) ( $row['updated_at'] ?? '' ),
			user_id: $id( $row['user_id'] ?? null ),
		);
	}

	/** Upper case without spaces and dashes, as stored. */
	public static function normalize( string $code ): string {
		return strtoupper( (string) preg_replace( '/[\s\-]+/', '', trim( $code ) ) );
	}

	/** Whether a normalized code has the shape of a gift card code, so a wrong one is not looked up as a coupon. */
	public static function looks_like( string $normalized ): bool {
		return strlen( $normalized ) === self::LENGTH && strspn( $normalized, self::ALPHABET ) === self::LENGTH;
	}

	public static function generate(): string {
		$max  = strlen( self::ALPHABET ) - 1;
		$code = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $code;
	}

	/** XXXX-XXXX-XXXX-XXXX, as printed in the email. */
	public static function format( string $normalized ): string {
		return implode( '-', str_split( $normalized, 4 ) );
	}

	/** The last four characters, as the cart and the order show them. */
	public static function mask( string $normalized ): string {
		return '••••' . substr( $normalized, -4 );
	}

	/** Whether this is a customer's store credit (ADR-072). */
	public function is_credit(): bool {
		return $this->user_id !== null;
	}

	public function is_expired( string $now ): bool {
		return $this->expires_at !== null && $this->expires_at <= $now;
	}

	/** Active, not expired and with money left. */
	public function is_usable( string $now ): bool {
		return $this->status === self::ACTIVE && ! $this->is_expired( $now ) && $this->balance > 0;
	}

	/** Why the card cannot pay now, for the shopper; null when it can. */
	public function problem( string $now, string $currency ): ?string {
		if ( $this->status !== self::ACTIVE ) {
			return __( 'This gift card is disabled.', 'flexacommerce' );
		}
		if ( $this->is_expired( $now ) ) {
			return __( 'This gift card has expired.', 'flexacommerce' );
		}
		if ( $this->balance <= 0 ) {
			return __( 'This gift card has no balance left.', 'flexacommerce' );
		}
		if ( strtoupper( $this->currency ) !== strtoupper( $currency ) ) {
			return __( 'This gift card is in another currency.', 'flexacommerce' );
		}
		return null;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'              => $this->id,
			'code'            => self::format( $this->code ),
			'masked'          => self::mask( $this->code ),
			'initial_amount'  => $this->initial_amount,
			'balance'         => $this->balance,
			'currency'        => $this->currency,
			'status'          => $this->status,
			'recipient_email' => $this->recipient_email,
			'recipient_name'  => $this->recipient_name,
			'sender_name'     => $this->sender_name,
			'message'         => $this->message,
			'order_id'        => $this->order_id,
			'order_item_id'   => $this->order_item_id,
			'deliver_at'      => $this->deliver_at,
			'delivered_at'    => $this->delivered_at,
			'expires_at'      => $this->expires_at,
			'created_at'      => $this->created_at,
			'updated_at'      => $this->updated_at,
			'user_id'         => $this->user_id,
		];
	}
}
