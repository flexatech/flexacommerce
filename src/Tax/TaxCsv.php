<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Tax rate CSV in WooCommerce's column order (doc 02 §7.12.9), so a file
 * exported from either plugin imports into the other. The Taxes screen
 * reads the file in the browser and sends it in batches of BATCH rows.
 */
final class TaxCsv {
	public const HEADER = [ 'Country code', 'State code', 'Postcode / ZIP', 'City', 'Rate %', 'Tax name', 'Priority', 'Compound', 'Shipping', 'Tax class' ];

	public const BATCH = 500;

	/**
	 * Validate one batch. Rows are the CSV cells, without the header.
	 *
	 * @param list<mixed>  $rows
	 * @param list<string> $classes known class slugs ('' is Standard)
	 * @param string|null  $only    a class every row must belong to (replace mode)
	 * @param int          $first   file line number of the first row, for messages
	 * @return array{rows:list<RateRow>,errors:list<array{line:int,message:string}>}
	 */
	public static function parse( array $rows, array $classes, ?string $only, int $first, int $order = 0 ): array {
		$out    = [];
		$errors = [];
		foreach ( $rows as $index => $cells ) {
			$line = $first + $index;
			if ( ! is_array( $cells ) || count( $cells ) !== count( self::HEADER ) ) {
				$errors[] = [
					'line'    => $line,
					'message' => sprintf( /* translators: %d: number of columns. */ __( 'The row must have %d columns.', 'flexacommerce' ), count( self::HEADER ) ),
				];
				continue;
			}
			$cells = array_map( static fn ( mixed $c ): string => is_scalar( $c ) ? trim( (string) $c ) : '', array_values( $cells ) );
			$class = sanitize_title( $cells[9] );
			if ( $only !== null && $class !== $only ) {
				$errors[] = [
					'line'    => $line,
					'message' => __( 'The row belongs to another tax class than the one being replaced.', 'flexacommerce' ),
				];
				continue;
			}
			if ( ! in_array( $class, $classes, true ) ) {
				$errors[] = [
					'line'    => $line,
					/* translators: %s: tax class slug. */
					'message' => sprintf( __( 'Unknown tax class "%s". Add the class first.', 'flexacommerce' ), $cells[9] ),
				];
				continue;
			}
			try {
				$out[] = RateRow::from_input(
					[
						'country'    => $cells[0],
						'state'      => $cells[1],
						'postcodes'  => $cells[2],
						'cities'     => $cells[3],
						'rate'       => $cells[4],
						'name'       => $cells[5],
						'priority'   => $cells[6] === '' ? 1 : $cells[6],
						'compound'   => $cells[7],
						'shipping'   => $cells[8] === '' ? '1' : $cells[8],
						'tax_class'  => $class,
						'rate_order' => $order + $index,
						'source'     => 'import',
					]
				);
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = [
					'line'    => $line,
					'message' => $e->getMessage(),
				];
			}
		}
		return [
			'rows'   => $out,
			'errors' => $errors,
		];
	}

	/** @param list<RateRow> $rows */
	public static function export( array $rows ): string {
		$lines = [ self::line( self::HEADER ) ];
		foreach ( $rows as $row ) {
			$lines[] = self::line(
				[
					$row->country,
					$row->state,
					implode( ';', $row->postcodes ),
					implode( ';', $row->cities ),
					self::rate_text( $row->rate ),
					$row->name,
					(string) $row->priority,
					$row->compound ? '1' : '0',
					$row->shipping ? '1' : '0',
					$row->tax_class,
				]
			);
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** Four decimals, as WooCommerce writes them: 72500 -> "7.2500". */
	private static function rate_text( int $rate ): string {
		return intdiv( $rate, 10000 ) . '.' . str_pad( (string) ( $rate % 10000 ), 4, '0', STR_PAD_LEFT );
	}

	/** @param list<string> $cells */
	private static function line( array $cells ): string {
		return implode(
			',',
			array_map(
				static function ( string $cell ): string {
					// A leading =, +, - or @ would run as a formula in a spreadsheet.
					if ( $cell !== '' && strpbrk( $cell[0], '=+-@' ) !== false ) {
						$cell = "'" . $cell;
					}
					return '"' . str_replace( '"', '""', $cell ) . '"';
				},
				$cells
			)
		);
	}
}
