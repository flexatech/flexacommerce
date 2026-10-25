<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * One input of an address form. Types: text, email, tel, country, state
 * (a select when `options` is set, else text), select, checkbox.
 *
 * @api
 */
final class CheckoutField {
	public const TYPES = [ 'text', 'email', 'tel', 'country', 'state', 'select', 'checkbox' ];

	/** @param array<string,string> $options */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $type = 'text',
		public readonly bool $required = false,
		public readonly string $autocomplete = '',
		public readonly array $options = [],
		public readonly int $max_length = 200,
		public readonly int $priority = 100,
	) {
		if ( preg_match( '/^[a-z0-9_]{1,64}$/', $id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid checkout field id "%s".', $id ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown checkout field type "%s".', $type ) );
		}
	}

	public function required( bool $required = true ): self {
		return new self( $this->id, $this->label, $this->type, $required, $this->autocomplete, $this->options, $this->max_length, $this->priority );
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
		];
	}
}
