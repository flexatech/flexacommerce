<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * One option per email, `flexacommerce_email_{id}`, not autoloaded: it is
 * read only when that email is about to send. An empty subject, heading or
 * recipient list means the email's own default.
 */
final class EmailSettings {
	public const PREFIX = 'flexacommerce_email_';

	private const DEFAULTS = [
		'enabled'    => true,
		'recipients' => '',
		'subject'    => '',
		'heading'    => '',
	];

	/**
	 * Load the options of these emails with one query.
	 *
	 * @param list<string> $ids
	 */
	public function prime( array $ids ): void {
		if ( count( $ids ) > 1 ) {
			wp_prime_option_caches( array_map( static fn ( string $id ): string => self::PREFIX . $id, $ids ) );
		}
	}

	/** @return array{enabled:bool,recipients:string,subject:string,heading:string} */
	public function get( string $id ): array {
		$stored = get_option( self::PREFIX . $id, [] );
		return self::clean( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merges a partial update over the stored values.
	 *
	 * @param array<string,mixed> $partial
	 * @return array{enabled:bool,recipients:string,subject:string,heading:string}
	 */
	public function update( string $id, array $partial ): array {
		$values = self::clean( array_merge( $this->get( $id ), array_intersect_key( $partial, self::DEFAULTS ) ) );
		update_option( self::PREFIX . $id, $values, false );
		return $values;
	}

	/** @return list<string> valid addresses from a comma separated list */
	public static function addresses( string $list ): array {
		$out = [];
		foreach ( explode( ',', $list ) as $part ) {
			$email = sanitize_email( trim( $part ) );
			if ( $email !== '' && is_email( $email ) !== false ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<mixed> $values
	 * @return array{enabled:bool,recipients:string,subject:string,heading:string}
	 */
	private static function clean( array $values ): array {
		return [
			'enabled'    => (bool) ( $values['enabled'] ?? self::DEFAULTS['enabled'] ),
			'recipients' => implode( ', ', self::addresses( is_string( $values['recipients'] ?? null ) ? $values['recipients'] : '' ) ),
			'subject'    => is_string( $values['subject'] ?? null ) ? sanitize_text_field( $values['subject'] ) : '',
			'heading'    => is_string( $values['heading'] ?? null ) ? sanitize_text_field( $values['heading'] ) : '',
		];
	}
}
