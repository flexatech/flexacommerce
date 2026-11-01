<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxc_gift_card_activity (ADR-069): every change to a balance.
 * `amount` is signed: issued and credited rows add, redeemed rows take
 * off, adjusted rows go either way. `transaction_id` is the payment a
 * redeem made, or the refund a credit came from.
 */
final class GiftCardActivity {
	public const ISSUED   = 'issued';
	public const REDEEMED = 'redeemed';
	public const CREDITED = 'credited';
	public const ADJUSTED = 'adjusted';

	public function __construct(
		public readonly int $id,
		public readonly int $gift_card_id,
		public readonly string $type,
		public readonly int $amount,
		public readonly int $balance_after,
		public readonly ?int $order_id,
		public readonly ?int $transaction_id,
		public readonly string $note,
		public readonly ?int $user_id,
		public readonly string $created_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$id = static fn ( mixed $v ): ?int => $v !== null && (int) $v > 0 ? (int) $v : null;
		return new self(
			id: (int) $row['id'],
			gift_card_id: (int) ( $row['gift_card_id'] ?? 0 ),
			type: (string) ( $row['type'] ?? '' ),
			amount: (int) ( $row['amount'] ?? 0 ),
			balance_after: (int) ( $row['balance_after'] ?? 0 ),
			order_id: $id( $row['order_id'] ?? null ),
			transaction_id: $id( $row['transaction_id'] ?? null ),
			note: (string) ( $row['note'] ?? '' ),
			user_id: $id( $row['user_id'] ?? null ),
			created_at: (string) ( $row['created_at'] ?? '' ),
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'             => $this->id,
			'type'           => $this->type,
			'amount'         => $this->amount,
			'balance_after'  => $this->balance_after,
			'order_id'       => $this->order_id,
			'transaction_id' => $this->transaction_id,
			'note'           => $this->note,
			'user_id'        => $this->user_id,
			'created_at'     => $this->created_at,
		];
	}
}
