<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

defined( 'ABSPATH' ) || exit;

/** sha1 of the record body with object keys sorted, so key order never changes it. */
final class Checksum {
	/** @param array<array-key,mixed> $data */
	public static function of( array $data ): string {
		return sha1( (string) wp_json_encode( self::sorted( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sorted( $item );
		}
		return $value;
	}
}
