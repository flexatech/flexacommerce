<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only order lookups for extensions (doc 04 §5.3). Extension metadata
 * lives in fxc_order_meta; keys starting with `_fxc_` are reserved and
 * rejected with InvalidArgumentException.
 *
 * @api
 */
interface OrderReader {
	public function find( int $order_id ): ?OrderView;

	public function find_by_number( string $order_number ): ?OrderView;

	public function get_meta( int $order_id, string $key ): ?string;

	/** @param string|array<mixed> $value arrays are stored as JSON */
	public function set_meta( int $order_id, string $key, string|array $value ): void;

	public function delete_meta( int $order_id, string $key ): void;
}
