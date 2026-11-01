<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/** CSV cell writing shared by the product (ADR-059) and order (ADR-070) exports. */
final class Csv {
	private const FORMULA = [ '=', '+', '-', '@', "\t", "\r" ];

	/** Guards a cell against formula injection when the file opens in a spreadsheet. */
	public static function escape( string $value ): string {
		return $value !== '' && in_array( $value[0], self::FORMULA, true ) ? "'" . $value : $value;
	}

	/** Removes the guard `escape()` adds. */
	public static function unescape( string $value ): string {
		return strlen( $value ) > 1 && $value[0] === "'" && in_array( $value[1], self::FORMULA, true ) ? substr( $value, 1 ) : $value;
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
