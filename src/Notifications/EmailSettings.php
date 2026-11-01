<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Invoices\InvoiceSettings;

defined( 'ABSPATH' ) || exit;

/**
 * One option per email, `flexacommerce_email_{id}`, not autoloaded: it is
 * read only when that email is about to send. An empty subject, heading or
 * recipient list means the email's own default, and so does 0 days (only
 * an email sent ahead of a date has days, ADR-075).
 */
final class EmailSettings {
	public const PREFIX = 'flexacommerce_email_';

	private const DEFAULTS = [
		'enabled'    => true,
		'recipients' => '',
		'subject'    => '',
		'heading'    => '',
		'days'       => 0,
	];

	/** Most days ahead an email may go out. */
	public const MAX_DAYS = 60;

	/**
	 * Load the options of these emails with one query.
	 *
	 * @param list<string> $ids
	 */
	public function prime( array $ids ): void {
		if ( $ids !== [] ) {
			// The invoice settings too: they say whether an email carries the invoice (ADR-060).
			wp_prime_option_caches( [ ...array_map( static fn ( string $id ): string => self::PREFIX . $id, $ids ), InvoiceSettings::OPTION ] );
		}
	}

	/** @return array{enabled:bool,recipients:string,subject:string,heading:string,days:int} */
	public function get( string $id ): array {
		$stored = get_option( self::PREFIX . $id, [] );
		return self::clean( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merges a partial update over the stored values.
	 *
	 * @param array<string,mixed> $partial
	 * @return array{enabled:bool,recipients:string,subject:string,heading:string,days:int}
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
	 * @return array{enabled:bool,recipients:string,subject:string,heading:string,days:int}
	 */
	private static function clean( array $values ): array {
		return [
			'enabled'    => (bool) ( $values['enabled'] ?? self::DEFAULTS['enabled'] ),
			'recipients' => implode( ', ', self::addresses( is_string( $values['recipients'] ?? null ) ? $values['recipients'] : '' ) ),
			'subject'    => is_string( $values['subject'] ?? null ) ? sanitize_text_field( $values['subject'] ) : '',
			'heading'    => is_string( $values['heading'] ?? null ) ? sanitize_text_field( $values['heading'] ) : '',
			'days'       => is_numeric( $values['days'] ?? null ) ? max( 0, min( self::MAX_DAYS, (int) $values['days'] ) ) : 0,
		];
	}
}
