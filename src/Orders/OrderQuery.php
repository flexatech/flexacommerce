<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Admin list filters. Every filter maps to an indexed column; `search`
 * matches the order number prefix or the exact customer email.
 */
final class OrderQuery {
	public const ORDER_BY = [ 'created_at', 'total', 'id' ];

	public function __construct(
		public readonly ?string $status = null,
		public readonly ?string $payment_status = null,
		public readonly bool $needs_attention = false,
		public readonly ?int $customer_id = null,
		public readonly string $search = '',
		public readonly int $page = 1,
		public readonly int $per_page = 20,
		public readonly string $orderby = 'created_at',
		public readonly string $order = 'desc',
	) {}

	/** @param array<string,mixed> $params */
	public static function from_params( array $params ): self {
		$status  = isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : '';
		$payment = isset( $params['payment_status'] ) ? sanitize_key( (string) $params['payment_status'] ) : '';
		$orderby = isset( $params['orderby'] ) ? sanitize_key( (string) $params['orderby'] ) : 'created_at';
		return new self(
			status: in_array( $status, OrderStatus::ALL, true ) ? $status : null,
			payment_status: in_array( $payment, OrderStatus::PAYMENT_STATUSES, true ) ? $payment : null,
			needs_attention: ! empty( $params['needs_attention'] ),
			customer_id: isset( $params['customer_id'] ) && (int) $params['customer_id'] > 0 ? (int) $params['customer_id'] : null,
			search: isset( $params['search'] ) ? trim( sanitize_text_field( (string) $params['search'] ) ) : '',
			page: max( 1, (int) ( $params['page'] ?? 1 ) ),
			per_page: min( 100, max( 1, (int) ( $params['per_page'] ?? 20 ) ) ),
			orderby: in_array( $orderby, self::ORDER_BY, true ) ? $orderby : 'created_at',
			order: strtolower( (string) ( $params['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc',
		);
	}

	/** @return array{0:string,1:list<string|int>} SQL condition and its placeholders' values */
	public function where(): array {
		$sql  = [ 'deleted_at IS NULL' ];
		$args = [];
		if ( $this->status !== null ) {
			$sql[]  = 'status = %s';
			$args[] = $this->status;
		}
		if ( $this->payment_status !== null ) {
			$sql[]  = 'payment_status = %s';
			$args[] = $this->payment_status;
		}
		if ( $this->needs_attention ) {
			$sql[] = 'needs_attention = 1';
		}
		if ( $this->customer_id !== null ) {
			$sql[]  = 'customer_id = %d';
			$args[] = $this->customer_id;
		}
		if ( $this->search !== '' ) {
			if ( is_email( $this->search ) ) {
				$sql[]  = 'customer_email = %s';
				$args[] = strtolower( $this->search );
			} else {
				global $wpdb;
				$sql[]  = 'order_number LIKE %s';
				$args[] = $wpdb->esc_like( ltrim( $this->search, '#' ) ) . '%';
			}
		}
		return [ implode( ' AND ', $sql ), $args ];
	}

	public function order_by(): string {
		$direction = $this->order === 'asc' ? 'ASC' : 'DESC';
		return "{$this->orderby} {$direction}, id {$direction}";
	}
}
