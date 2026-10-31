<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Shape checks on a canonical record before it reaches a target (doc 05 §6).
 * Errors reject the entity; adapters handle the warnings they can repair.
 */
final class Validator {
	/** @return list<Problem> */
	public function validate( Record $record ): array {
		if ( Ref::parse( $record->id ) === null ) {
			return [ Problem::error( 'invalid_id', sprintf( 'Record id "%s" is not a reference.', $record->id ) ) ];
		}
		return match ( $record->type ) {
			Record::PRODUCT  => $this->product( $record->data ),
			Record::ORDER    => $this->order( $record->data ),
			Record::TAX_RATE => $this->tax_rate( $record->data ),
			Record::TERM, Record::ATTRIBUTE => $this->named( $record->data ),
			Record::SHIPPING => $this->shipping( $record->data ),
			Record::COUPON   => $this->coupon( $record->data ),
			Record::OPTION_SET => $this->option_set( $record->data ),
			Record::CHECKOUT_FIELDS => $this->checkout_fields( $record->data ),
			Record::REVIEW   => $this->review( $record->data ),
			Record::SETTINGS, Record::CUSTOMER => [],
			default          => [ Problem::error( 'unknown_type', sprintf( 'Unknown record type "%s".', $record->type ) ) ],
		};
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function named( array $data ): array {
		return is_string( $data['name'] ?? null ) && $data['name'] !== '' ? [] : [ Problem::error( 'missing_name', 'The record has no name.' ) ];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function shipping( array $data ): array {
		if ( ! is_array( $data['zones'] ?? null ) || ! is_array( $data['rest'] ?? null ) ) {
			return [ Problem::error( 'invalid_shipping', 'The shipping record has no zone list.' ) ];
		}
		$zones   = $data['zones'];
		$zones[] = $data['rest'];
		foreach ( $zones as $zone ) {
			if ( ! is_array( $zone ) || ! is_array( $zone['methods'] ?? null ) ) {
				return [ Problem::error( 'invalid_shipping', 'A shipping zone has no method list.' ) ];
			}
			foreach ( $zone['methods'] as $method ) {
				if ( ! is_array( $method ) || ! is_string( $method['type'] ?? null ) || ! is_string( $method['id'] ?? null ) ) {
					return [ Problem::error( 'invalid_shipping', 'A shipping method has no type or id.' ) ];
				}
				foreach ( [ 'cost', 'cost_per_item', 'min_amount' ] as $key ) {
					if ( isset( $method[ $key ] ) && ! Decimal::is_decimal( $method[ $key ] ) ) {
						return [ Problem::error( 'invalid_amount', sprintf( 'The shipping amount %s is not a decimal.', $key ) ) ];
					}
				}
			}
		}
		return [];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function option_set( array $data ): array {
		$problems = [];
		if ( ! is_array( $data['addons'] ?? null ) || ! array_is_list( $data['addons'] ) ) {
			$problems[] = Problem::error( 'invalid_addons', 'The option set has no list of add-ons.' );
		}
		if ( ! in_array( $data['scope'] ?? null, [ 'global', 'product' ], true ) ) {
			$problems[] = Problem::error( 'invalid_scope', 'The option set is neither global nor for products.' );
		}
		if ( ! Dates::is_valid( $data['modified_at'] ?? null ) ) {
			$problems[] = Problem::error( 'invalid_date', 'modified_at is not an ISO date.' );
		}
		return $problems;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function checkout_fields( array $data ): array {
		$sections = $data['sections'] ?? null;
		if ( ! is_array( $sections ) ) {
			return [ Problem::error( 'invalid_checkout_fields', 'The checkout fields record has no sections.' ) ];
		}
		foreach ( $sections as $fields ) {
			if ( $fields !== null && ( ! is_array( $fields ) || ! array_is_list( $fields ) ) ) {
				return [ Problem::error( 'invalid_checkout_fields', 'A section of the checkout fields record is not a list of fields.' ) ];
			}
		}
		return [];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function review( array $data ): array {
		$problems = [];
		if ( Ref::parse( is_string( $data['product'] ?? null ) ? $data['product'] : null ) === null ) {
			$problems[] = Problem::error( 'missing_product', 'The review has no product.' );
		}
		$rating = $data['rating'] ?? null;
		if ( $rating !== null && ( ! is_int( $rating ) || $rating < 1 || $rating > 5 ) ) {
			$problems[] = Problem::error( 'invalid_rating', 'The review rating is not 1 to 5.' );
		}
		if ( ! Dates::is_valid( $data['created_at'] ?? null ) ) {
			$problems[] = Problem::error( 'invalid_date', 'created_at is not an ISO date.' );
		}
		return $problems;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function coupon( array $data ): array {
		$problems = [];
		if ( ! is_string( $data['code'] ?? null ) || trim( $data['code'] ) === '' ) {
			$problems[] = Problem::error( 'missing_code', 'The coupon has no code.' );
		}
		foreach ( [ 'amount', 'min_subtotal', 'max_subtotal' ] as $key ) {
			if ( ( $key === 'amount' || isset( $data[ $key ] ) ) && ! Decimal::is_decimal( $data[ $key ] ?? null ) ) {
				$problems[] = Problem::error( 'invalid_amount', sprintf( 'The coupon %s is not a decimal.', $key ) );
			}
		}
		foreach ( [ 'starts_at', 'expires_at', 'modified_at' ] as $key ) {
			if ( ! Dates::is_valid( $data[ $key ] ?? null ) ) {
				$problems[] = Problem::error( 'invalid_date', sprintf( '%s is not an ISO date.', $key ) );
			}
		}
		return $problems;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function tax_rate( array $data ): array {
		return self::is_rate( $data['rate'] ?? null ) ? [] : [ Problem::error( 'invalid_rate', 'The tax rate is not a percent.' ) ];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function product( array $data ): array {
		$problems = [];
		if ( ! in_array( $data['kind'] ?? null, [ 'simple', 'variable' ], true ) ) {
			$problems[] = Problem::error( 'unsupported_type', 'Only simple and variable products are imported.' );
		}
		$rows = [ 'base' => is_array( $data['base'] ?? null ) ? $data['base'] : [] ];
		foreach ( is_array( $data['variants'] ?? null ) ? $data['variants'] : [] as $i => $variant ) {
			$rows[ 'variants.' . $i ] = is_array( $variant ) ? $variant : [];
		}
		foreach ( $rows as $path => $row ) {
			foreach ( [ 'regular_price', 'sale_price' ] as $key ) {
				if ( isset( $row[ $key ] ) && ! self::is_money( $row[ $key ] ) ) {
					$problems[] = Problem::error( 'invalid_money', sprintf( '%s.%s is not a money value.', $path, $key ) );
				}
			}
			if ( isset( $row['stock_quantity'] ) && ! is_int( $row['stock_quantity'] ) ) {
				$problems[] = Problem::error( 'fractional_quantity', sprintf( '%s.stock_quantity is not a whole number.', $path ) );
			}
			foreach ( [ 'sale_from', 'sale_to' ] as $key ) {
				if ( ! Dates::is_valid( $row[ $key ] ?? null ) ) {
					$problems[] = Problem::error( 'invalid_date', sprintf( '%s.%s is not an ISO date.', $path, $key ) );
				}
			}
		}
		return $problems;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<Problem>
	 */
	private function order( array $data ): array {
		$problems = [];
		$currency = is_string( $data['currency'] ?? null ) ? $data['currency'] : '';
		if ( ! Currency::is_valid( $currency ) ) {
			$problems[] = Problem::error( 'unknown_currency', sprintf( 'Unknown currency "%s".', $currency ) );
		}
		foreach ( [ 'created_at', 'modified_at', 'paid_at', 'completed_at' ] as $key ) {
			if ( ! Dates::is_valid( $data[ $key ] ?? null ) ) {
				$problems[] = Problem::error( 'invalid_date', sprintf( '%s is not an ISO date.', $key ) );
			}
		}
		$totals = is_array( $data['totals'] ?? null ) ? $data['totals'] : [];
		foreach ( [ 'subtotal', 'discount', 'shipping', 'fees', 'tax', 'total' ] as $key ) {
			if ( ! Decimal::is_decimal( $totals[ $key ] ?? null, true ) ) {
				$problems[] = Problem::error( 'invalid_total', sprintf( 'totals.%s is not a decimal.', $key ) );
			}
		}
		foreach ( is_array( $data['items'] ?? null ) ? $data['items'] : [] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ( $item['type'] ?? '' ) === 'product' ) {
				if ( ! is_int( $item['quantity'] ?? null ) || $item['quantity'] < 0 ) {
					$problems[] = Problem::error( 'fractional_quantity', sprintf( 'items.%d has a quantity that is not a whole number.', $i ) );
				}
				if ( str_starts_with( (string) ( $item['total'] ?? '' ), '-' ) ) {
					$problems[] = Problem::error( 'negative_total', sprintf( 'items.%d has a negative total.', $i ) );
				}
			}
			foreach ( [ 'subtotal', 'total', 'tax' ] as $key ) {
				if ( isset( $item[ $key ] ) && ! Decimal::is_decimal( $item[ $key ], true ) ) {
					$problems[] = Problem::error( 'invalid_total', sprintf( 'items.%d.%s is not a decimal.', $i, $key ) );
				}
			}
		}
		foreach ( is_array( $data['transactions'] ?? null ) ? $data['transactions'] : [] as $i => $transaction ) {
			if ( ! is_array( $transaction ) || ! self::is_money( $transaction['amount'] ?? null ) ) {
				$problems[] = Problem::error( 'invalid_money', sprintf( 'transactions.%d has no amount.', $i ) );
			}
		}
		return $problems;
	}

	public static function is_money( mixed $value ): bool {
		return is_array( $value )
			&& Decimal::is_decimal( $value['amount'] ?? null, true )
			&& is_string( $value['currency'] ?? null )
			&& Currency::is_valid( $value['currency'] );
	}

	public static function is_rate( mixed $value ): bool {
		return is_string( $value ) && preg_match( '/^\d{1,3}(\.\d+)?$/', $value ) === 1;
	}
}
