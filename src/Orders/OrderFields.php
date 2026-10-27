<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * The answers to the merchant's own checkout fields on one order (ADR-031).
 * Each answer is in order meta under `_{type}_{id}`; `_fxc_fields` keeps the
 * list with the label and the shown value of the time of the order, so
 * renaming or deleting a field later does not change old orders.
 *
 * @phpstan-type Answer array{key:string,label:string,value:string,email:bool}
 */
final class OrderFields {
	public const META = '_fxc_fields';

	/** @return list<Answer> */
	public static function parse( ?string $raw ): array {
		$list = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$out  = [];
		foreach ( is_array( $list ) ? $list : [] as $row ) {
			if ( is_array( $row ) && is_string( $row['label'] ?? null ) && is_string( $row['value'] ?? null ) && $row['value'] !== '' ) {
				$out[] = [
					'key'   => is_string( $row['key'] ?? null ) ? $row['key'] : '',
					'label' => $row['label'],
					'value' => $row['value'],
					'email' => ( $row['email'] ?? true ) !== false,
				];
			}
		}
		return $out;
	}

	/** @return list<Answer> */
	public static function for_order( OrderRepository $orders, int $order_id ): array {
		return self::parse( $orders->get_meta( $order_id, self::META ) );
	}
}
