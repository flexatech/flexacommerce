<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Invoices\InvoiceSettings;
use Flexa\Commerce\Notifications\Design\DesignTree;
use Flexa\Commerce\Notifications\Design\MailStyle;

defined( 'ABSPATH' ) || exit;

/**
 * One option per email, `flexacommerce_email_{id}`, not autoloaded: it is
 * read only when that email is about to send. An empty subject, heading or
 * recipient list means the email's own default, and so does 0 days (only
 * an email sent ahead of a date has days, ADR-075). The same option keeps
 * the email's design under `design` (ADR-085), so a send reads it with
 * the settings it already loads.
 *
 * @phpstan-import-type Design from DesignTree
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
			// The invoice settings too: they say whether an email carries the invoice (ADR-060); and the email style (ADR-085).
			wp_prime_option_caches( [ ...array_map( static fn ( string $id ): string => self::PREFIX . $id, $ids ), InvoiceSettings::OPTION, MailStyle::OPTION ] );
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
		$design = $this->design( $id );
		update_option( self::PREFIX . $id, $design !== null ? $values + [ 'design' => $design ] : $values, false );
		return $values;
	}

	/** @return Design|null the saved design; null when the email uses the default layout */
	public function design( string $id ): ?array {
		$stored = get_option( self::PREFIX . $id, [] );
		return is_array( $stored ) ? DesignTree::clean( $stored['design'] ?? null ) : null;
	}

	/**
	 * Saves a design, or removes it with null.
	 *
	 * @return Design|null what was saved
	 */
	public function set_design( string $id, mixed $design ): ?array {
		$clean  = $design !== null ? DesignTree::clean( $design ) : null;
		$values = $this->get( $id );
		update_option( self::PREFIX . $id, $clean !== null ? $values + [ 'design' => $clean ] : $values, false );
		return $clean;
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
