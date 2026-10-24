<?php

declare(strict_types=1);

namespace Flexa\Commerce\Inventory;

use Flexa\Commerce\Catalog\Variant;

defined( 'ABSPATH' ) || exit;

/**
 * What can be sold of one stock owner right now. `quantity` is null when
 * stock is not managed.
 */
final class Availability {
	public function __construct(
		public readonly string $status,
		public readonly ?int $quantity,
		public readonly bool $backorders,
	) {}

	/** @param array{manage_stock:bool,stock_quantity:?int,backorders:string,stock_status:string} $row */
	public static function from_stock_row( array $row ): self {
		if ( ! $row['manage_stock'] ) {
			return new self( in_array( $row['stock_status'], Variant::STOCK_STATUSES, true ) ? $row['stock_status'] : Variant::INSTOCK, null, false );
		}
		$quantity   = (int) $row['stock_quantity'];
		$backorders = $row['backorders'] !== 'no';
		$status     = $quantity > 0 ? Variant::INSTOCK : ( $backorders ? Variant::ONBACKORDER : Variant::OUTOFSTOCK );
		return new self( $status, $quantity, $backorders );
	}

	public static function of( Variant $owner ): self {
		return self::from_stock_row(
			[
				'manage_stock'   => $owner->manage_stock,
				'stock_quantity' => $owner->stock_quantity,
				'backorders'     => $owner->backorders,
				'stock_status'   => $owner->stock_status,
			]
		);
	}

	public function is_in_stock(): bool {
		return $this->status !== Variant::OUTOFSTOCK;
	}

	public function allows( int $quantity ): bool {
		if ( $quantity <= 0 ) {
			return true;
		}
		if ( $this->status === Variant::OUTOFSTOCK ) {
			return false;
		}
		return $this->quantity === null || $this->backorders || $this->quantity >= $quantity;
	}

	/** Largest quantity that can be sold, null for no limit. */
	public function max_quantity(): ?int {
		if ( $this->status === Variant::OUTOFSTOCK ) {
			return 0;
		}
		if ( $this->quantity === null || $this->backorders ) {
			return null;
		}
		return max( 0, $this->quantity );
	}

	/** @return array{status:string,quantity:?int,backorders:bool} */
	public function to_array(): array {
		return [
			'status'     => $this->status,
			'quantity'   => $this->quantity,
			'backorders' => $this->backorders,
		];
	}
}
