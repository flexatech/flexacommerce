<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

defined( 'ABSPATH' ) || exit;

/** ISO 8601 UTC with `Z` in records, `Y-m-d H:i:s` UTC in the database. */
final class Dates {
	public static function from_db( ?string $value ): ?string {
		if ( $value === null || $value === '' || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date === false ? null : $date->format( 'Y-m-d\TH:i:s\Z' );
	}

	public static function from_timestamp( ?int $timestamp ): ?string {
		return $timestamp === null ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	public static function from_wc( ?\DateTimeInterface $date ): ?string {
		return $date === null ? null : gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() );
	}

	public static function to_db( ?string $iso ): ?string {
		$timestamp = self::to_timestamp( $iso );
		return $timestamp === null ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	public static function to_timestamp( ?string $iso ): ?int {
		if ( $iso === null || $iso === '' ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception ) {
			return null;
		}
	}

	public static function is_valid( mixed $value ): bool {
		return $value === null || ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) === 1 && self::to_timestamp( $value ) !== null );
	}

	/** Seconds added to an ISO date. */
	public static function shift( ?string $iso, int $seconds ): ?string {
		$timestamp = self::to_timestamp( $iso );
		return $timestamp === null ? null : self::from_timestamp( $timestamp + $seconds );
	}
}
