<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Payments\TransactionView;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxc_transactions: a single attempt to move money (doc 02 §7.7).
 * A retry is a new row; a refund is a row of type refund.
 */
final class Transaction {
	public const PAYMENT = 'payment';
	public const REFUND  = 'refund';

	public const PENDING         = 'pending';
	public const REQUIRES_ACTION = 'requires_action';
	public const AUTHORIZED      = 'authorized';
	public const SUCCEEDED       = 'succeeded';
	public const FAILED          = 'failed';
	public const CANCELLED       = 'cancelled';

	public const TERMINAL = [ self::SUCCEEDED, self::FAILED, self::CANCELLED ];

	public const GATEWAY = 'gateway';
	public const MANUAL  = 'manual';

	/** Payment moves of the diagram in doc 02 §7.7; refunds go pending to succeeded or failed. */
	private const MOVES = [
		self::PENDING         => [ self::REQUIRES_ACTION, self::AUTHORIZED, self::SUCCEEDED, self::FAILED, self::CANCELLED ],
		self::REQUIRES_ACTION => [ self::SUCCEEDED, self::FAILED, self::CANCELLED ],
		self::AUTHORIZED      => [ self::SUCCEEDED, self::CANCELLED ],
	];

	/**
	 * @param list<array{item_id:int,qty:int,amount:int,restock:bool}> $refund_lines
	 * @param array<string,mixed>                                       $data
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly ?int $parent_id,
		public readonly string $type,
		public readonly string $status,
		public readonly string $method,
		public readonly string $gateway,
		public readonly int $amount,
		public readonly string $currency,
		public readonly string $idempotency_key,
		public readonly ?string $gateway_reference,
		public readonly ?string $failure_code,
		public readonly ?string $failure_message,
		public readonly array $refund_lines,
		public readonly array $data,
		public readonly string $created_at,
		public readonly ?string $completed_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$lines = json_decode( (string) ( $row['refund_lines'] ?? '' ), true );
		$data  = json_decode( (string) ( $row['data'] ?? '' ), true );
		return new self(
			id: (int) $row['id'],
			order_id: (int) $row['order_id'],
			parent_id: isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			type: (string) $row['type'],
			status: (string) $row['status'],
			method: (string) $row['method'],
			gateway: (string) $row['gateway'],
			amount: (int) $row['amount'],
			currency: (string) $row['currency'],
			idempotency_key: (string) $row['idempotency_key'],
			gateway_reference: isset( $row['gateway_reference'] ) ? (string) $row['gateway_reference'] : null,
			failure_code: isset( $row['failure_code'] ) ? (string) $row['failure_code'] : null,
			failure_message: isset( $row['failure_message'] ) ? (string) $row['failure_message'] : null,
			refund_lines: is_array( $lines ) ? self::lines( $lines ) : [],
			data: is_array( $data ) ? $data : [],
			created_at: (string) $row['created_at'],
			completed_at: isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
		);
	}

	public function is_terminal(): bool {
		return in_array( $this->status, self::TERMINAL, true );
	}

	public function can_move_to( string $status ): bool {
		if ( $this->type === self::REFUND ) {
			return $this->status === self::PENDING && in_array( $status, [ self::SUCCEEDED, self::FAILED ], true );
		}
		return in_array( $status, self::MOVES[ $this->status ] ?? [], true );
	}

	public function money(): Money {
		return new Money( $this->amount, $this->currency );
	}

	public function view(): TransactionView {
		return new TransactionView(
			id: $this->id,
			order_id: $this->order_id,
			parent_id: $this->parent_id,
			type: $this->type,
			status: $this->status,
			method: $this->method,
			gateway: $this->gateway,
			amount: $this->money(),
			gateway_reference: $this->gateway_reference,
			created_at: $this->created_at,
			completed_at: $this->completed_at,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'id'                => $this->id,
			'parent_id'         => $this->parent_id,
			'type'              => $this->type,
			'status'            => $this->status,
			'method'            => $this->method,
			'gateway'           => $this->gateway,
			'amount'            => $this->money(),
			'gateway_reference' => $this->gateway_reference,
			'failure_code'      => $this->failure_code,
			'failure_message'   => $this->failure_message,
			'refund_lines'      => $this->refund_lines,
			'created_at'        => $this->created_at,
			'completed_at'      => $this->completed_at,
		];
	}

	/**
	 * @param array<mixed> $rows
	 * @return list<array{item_id:int,qty:int,amount:int,restock:bool}>
	 */
	private static function lines( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = [
					'item_id' => (int) ( $row['item_id'] ?? 0 ),
					'qty'     => (int) ( $row['qty'] ?? 0 ),
					'amount'  => (int) ( $row['amount'] ?? 0 ),
					'restock' => (bool) ( $row['restock'] ?? false ),
				];
			}
		}
		return $out;
	}
}
