<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Database\Sequence;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Order numbers from fxc_sequences (doc 03 §2.7). Allocated outside the
 * checkout transaction, so a failed checkout leaves a gap; numbers are never
 * reused.
 */
final class OrderNumber {
	public const SEQUENCE = 'order_number';

	public function __construct( private readonly Sequence $sequence ) {}

	public function next(): string {
		$value     = $this->sequence->next( self::SEQUENCE );
		$formatted = Settings::string( 'order_number_prefix' ) . $value;
		$filtered  = apply_filters( 'flexacommerce.order.number', $formatted, $value );
		if ( ! is_string( $filtered ) || $filtered === '' || strlen( $filtered ) > 40 ) {
			_doing_it_wrong( 'flexacommerce.order.number', esc_html__( 'The filter must return a non-empty string of at most 40 characters.', 'flexacommerce' ), '0.1.0' );
			return $formatted;
		}
		return $filtered;
	}
}
