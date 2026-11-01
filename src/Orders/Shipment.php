<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * One package sent for an order (ADR-067): the carrier, the tracking
 * number and its link, and which lines it holds. `items` null means the
 * whole order; otherwise order item id => quantity.
 */
final class Shipment {
	/** @param array<int,int>|null $items */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $carrier,
		public readonly string $carrier_name,
		public readonly string $tracking_number,
		public readonly string $tracking_url,
		public readonly ?array $items,
		public readonly string $shipped_at,
		public readonly string $created_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$items = null;
		if ( is_string( $row['items'] ?? null ) && $row['items'] !== '' ) {
			$decoded = json_decode( $row['items'], true );
			if ( is_array( $decoded ) ) {
				$items = [];
				foreach ( $decoded as $item_id => $qty ) {
					if ( (int) $item_id > 0 && (int) $qty > 0 ) {
						$items[ (int) $item_id ] = (int) $qty;
					}
				}
			}
		}
		return new self(
			id: (int) $row['id'],
			order_id: (int) $row['order_id'],
			carrier: (string) ( $row['carrier'] ?? '' ),
			carrier_name: (string) ( $row['carrier_name'] ?? '' ),
			tracking_number: (string) ( $row['tracking_number'] ?? '' ),
			tracking_url: (string) ( $row['tracking_url'] ?? '' ),
			items: $items,
			shipped_at: (string) $row['shipped_at'],
			created_at: (string) ( $row['created_at'] ?? $row['shipped_at'] ),
		);
	}

	/** @return array{id:int,order_id:int,carrier:string,carrier_name:string,tracking_number:string,tracking_url:string,items:?array<int,int>,shipped_at:string} */
	public function to_array(): array {
		return [
			'id'              => $this->id,
			'order_id'        => $this->order_id,
			'carrier'         => $this->carrier,
			'carrier_name'    => $this->carrier_name,
			'tracking_number' => $this->tracking_number,
			'tracking_url'    => $this->tracking_url,
			'items'           => $this->items,
			'shipped_at'      => $this->shipped_at,
		];
	}
}
