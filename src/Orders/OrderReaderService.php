<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Orders\OrderReader;
use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/** The OrderReader extensions get from the facade (doc 04 §5.3). */
final class OrderReaderService implements OrderReader {
	public const RESERVED_PREFIX = '_fxc_';

	public function __construct( private readonly OrderRepository $orders ) {}

	public function find( int $order_id ): ?OrderView {
		return $this->orders->find( $order_id )?->view();
	}

	public function find_by_number( string $order_number ): ?OrderView {
		return $this->orders->find_by_number( $order_number )?->view();
	}

	public function get_meta( int $order_id, string $key ): ?string {
		return $this->orders->get_meta( $order_id, self::key( $key ) );
	}

	public function set_meta( int $order_id, string $key, string|array $value ): void {
		$stored = is_array( $value ) ? wp_json_encode( $value ) : $value;
		if ( ! is_string( $stored ) ) {
			throw new \InvalidArgumentException( 'The meta value cannot be encoded as JSON.' );
		}
		$this->orders->set_meta( $order_id, self::key( $key ), $stored );
	}

	public function delete_meta( int $order_id, string $key ): void {
		$this->orders->delete_meta( $order_id, self::key( $key ) );
	}

	private static function key( string $key ): string {
		if ( $key === '' || strlen( $key ) > 191 ) {
			throw new \InvalidArgumentException( 'A meta key must be 1 to 191 characters long.' );
		}
		if ( str_starts_with( $key, self::RESERVED_PREFIX ) ) {
			throw new \InvalidArgumentException( sprintf( 'Meta keys starting with "%s" are reserved.', self::RESERVED_PREFIX ) );
		}
		return $key;
	}
}
