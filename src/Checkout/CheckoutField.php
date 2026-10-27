<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * One input of a checkout form. Types: text, email, tel, country, state
 * (a select when `options` is set, else text), select, checkbox, and from
 * the checkout builder textarea, radio, date and number (ADR-031).
 *
 * @api
 */
final class CheckoutField {
	public const TYPES = [ 'text', 'email', 'tel', 'country', 'state', 'select', 'checkbox', 'textarea', 'radio', 'date', 'number' ];

	/**
	 * @param array<string,string> $options
	 * @param list<string>         $countries shown only when the address is in one of them; empty for every country
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $type = 'text',
		public readonly bool $required = false,
		public readonly string $autocomplete = '',
		public readonly array $options = [],
		public readonly int $max_length = 200,
		public readonly int $priority = 100,
		public readonly string $placeholder = '',
		public readonly string $help = '',
		public readonly string $width = 'full',
		public readonly array $countries = [],
	) {
		if ( preg_match( '/^[a-z0-9_]{1,64}$/', $id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid checkout field id "%s".', $id ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown checkout field type "%s".', $type ) );
		}
	}

	/** Whether the field asks for an answer when the address is in `$country`. */
	public function shown_in( string $country ): bool {
		return $this->countries === [] || in_array( $country, $this->countries, true );
	}

	public function required( bool $required = true ): self {
		return $this->copy( [ 'required' => $required ] );
	}

	/** @param array<string,mixed> $changes constructor arguments by name */
	public function copy( array $changes ): self {
		return new self( ...array_merge( get_object_vars( $this ), $changes ) );
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'           => $this->id,
			'label'        => $this->label,
			'type'         => $this->type,
			'required'     => $this->required,
			'autocomplete' => $this->autocomplete,
			'options'      => $this->options,
			'max_length'   => $this->max_length,
			'placeholder'  => $this->placeholder,
			'help'         => $this->help,
			'width'        => $this->width,
			'countries'    => $this->countries,
		];
	}
}
