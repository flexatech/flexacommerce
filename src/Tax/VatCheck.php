<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * The result of checking one EU VAT number (ADR-086). `number` carries its
 * VIES prefix (EL for Greece); `country` is the ISO code (GR). Kept on the
 * order as `_fxcm_vat_check` so the merchant can show what was checked.
 */
final class VatCheck {
	/** VIES says the number is registered. */
	public const VALID = 'valid';
	/** The pattern is right; VIES was not asked (`vat_check` = format). */
	public const WELL_FORMED = 'well_formed';
	/** The pattern is right but VIES did not answer. */
	public const UNVERIFIED = 'unverified';
	/** VIES says the number is not registered. */
	public const INVALID = 'invalid';
	/** The number does not match its country's pattern. */
	public const MALFORMED = 'malformed';
	/** The prefix names another country than the billing address. */
	public const MISMATCH = 'mismatch';

	public const STATUSES = [ self::VALID, self::WELL_FORMED, self::UNVERIFIED, self::INVALID, self::MALFORMED, self::MISMATCH ];

	public function __construct(
		public readonly string $number,
		public readonly string $country,
		public readonly string $status,
		public readonly string $name = '',
		public readonly string $address = '',
		public readonly int $checked_at = 0,
	) {}

	/** The number can stand on the order without the buyer fixing it. */
	public function usable(): bool {
		return in_array( $this->status, [ self::VALID, self::WELL_FORMED, self::UNVERIFIED ], true );
	}

	/** A shopper-facing line for the checkout field. */
	public function message(): string {
		return match ( $this->status ) {
			self::VALID       => $this->name !== '' ? sprintf(
				/* translators: %s: the business name VIES has for the number */
				__( 'VAT number confirmed for %s.', 'flexacommerce' ),
				$this->name
			) : __( 'VAT number confirmed.', 'flexacommerce' ),
			self::WELL_FORMED => __( 'VAT number accepted.', 'flexacommerce' ),
			self::UNVERIFIED  => __( 'The EU VAT service did not answer, so this number could not be confirmed yet.', 'flexacommerce' ),
			self::INVALID     => __( 'This VAT number is not registered in the EU VAT database.', 'flexacommerce' ),
			self::MISMATCH    => __( 'This VAT number belongs to another country than the billing address.', 'flexacommerce' ),
			default           => __( 'This does not look like a VAT number for the billing country.', 'flexacommerce' ),
		};
	}

	/** @return array{number:string,country:string,status:string,name:string,address:string,checked_at:int} */
	public function to_array(): array {
		return [
			'number'     => $this->number,
			'country'    => $this->country,
			'status'     => $this->status,
			'name'       => $this->name,
			'address'    => $this->address,
			'checked_at' => $this->checked_at,
		];
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): ?self {
		$status = (string) ( $data['status'] ?? '' );
		if ( ! in_array( $status, self::STATUSES, true ) || ! is_string( $data['number'] ?? null ) ) {
			return null;
		}
		return new self(
			$data['number'],
			(string) ( $data['country'] ?? '' ),
			$status,
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['address'] ?? '' ),
			(int) ( $data['checked_at'] ?? 0 ),
		);
	}
}
