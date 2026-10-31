<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * Cell encoding shared by export and import, in the WooCommerce format:
 * lists are joined with ", " and a comma inside a value is written `\,`;
 * a cell that a spreadsheet would run as a formula gets a leading `'`.
 */
final class CsvValues {
	private const FORMULA = [ '=', '+', '-', '@', "\t", "\r" ];

	public static function strip_bom( string $text ): string {
		return str_starts_with( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
	}

	/** Guards a cell against formula injection when the file opens in a spreadsheet. */
	public static function escape( string $value ): string {
		return $value !== '' && in_array( $value[0], self::FORMULA, true ) ? "'" . $value : $value;
	}

	/** Removes the guard `escape()` adds. */
	public static function unescape( string $value ): string {
		return strlen( $value ) > 1 && $value[0] === "'" && in_array( $value[1], self::FORMULA, true ) ? substr( $value, 1 ) : $value;
	}

	/** @param list<string> $values */
	public static function join( array $values ): string {
		return implode( ', ', array_map( static fn ( string $value ): string => str_replace( ',', '\\,', $value ), $values ) );
	}

	/** @return list<string> trimmed, empty entries dropped */
	public static function split( string $value ): array {
		if ( trim( $value ) === '' ) {
			return [];
		}
		$out = [];
		foreach ( preg_split( '/(?<!\\\\),/', $value ) ?: [] as $part ) {
			$part = trim( str_replace( '\\,', ',', $part ) );
			if ( $part !== '' ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/** "1", "yes", "true" and "on" are true; anything else is false. */
	public static function bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'yes', 'true', 'on' ], true );
	}

	public static function flag( bool $value ): string {
		return $value ? '1' : '0';
	}

	/**
	 * A plain decimal string from a cell, or null when the cell is empty.
	 * A single comma with no dot is read as the decimal separator ("19,99");
	 * with both, the last one is ("1.299,50" and "1,299.50").
	 *
	 * @throws \InvalidArgumentException when the cell is not a number
	 */
	public static function decimal( string $value ): ?string {
		$value = str_replace( [ ' ', "\u{00A0}" ], '', trim( $value ) );
		if ( $value === '' ) {
			return null;
		}
		$comma = strrpos( $value, ',' );
		$dot   = strrpos( $value, '.' );
		if ( $comma !== false && $dot !== false ) {
			$value = $comma > $dot ? str_replace( [ '.', ',' ], [ '', '.' ], $value ) : str_replace( ',', '', $value );
		} elseif ( $comma !== false && substr_count( $value, ',' ) === 1 ) {
			$value = str_replace( ',', '.', $value );
		}
		$value = str_starts_with( $value, '.' ) ? '0' . $value : $value;
		if ( preg_match( '/^\d+(\.\d+)?$/', $value ) !== 1 ) {
			throw new \InvalidArgumentException( 'not a number' );
		}
		return $value;
	}

	/** A number for a cell, without trailing zeros. */
	public static function number( float $value, int $places = 6 ): string {
		$text = rtrim( rtrim( number_format( $value, $places, '.', '' ), '0' ), '.' );
		return $text === '-0' ? '0' : $text;
	}

	/**
	 * One CSV line, RFC 4180 quoting, no escape character.
	 *
	 * @param list<string> $cells
	 */
	public static function line( array $cells ): string {
		$out = [];
		foreach ( $cells as $cell ) {
			$out[] = preg_match( '/[",\r\n]/', $cell ) === 1 || ( $cell !== '' && ( $cell[0] === ' ' || str_ends_with( $cell, ' ' ) ) )
				? '"' . str_replace( '"', '""', $cell ) . '"'
				: $cell;
		}
		return implode( ',', $out ) . "\n";
	}
}
