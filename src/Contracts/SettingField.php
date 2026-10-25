<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * One setting a gateway or shipping method asks for. Core renders it on the
 * settings screen and validates the type. `secret` values are stored but
 * never sent back to the browser (doc 06 §2.10).
 *
 * @api
 */
final class SettingField {
	public const TYPES = [ 'text', 'textarea', 'secret', 'checkbox', 'select', 'number', 'money' ];

	/** @param array<string,string> $options value => label, for `select` */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly string $label,
		public readonly mixed $default = '',
		public readonly string $description = '',
		public readonly array $options = [],
	) {
		if ( preg_match( '/^[a-z0-9_]{1,64}$/', $id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid setting id "%s".', $id ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid setting type "%s".', $type ) );
		}
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'type'        => $this->type,
			'label'       => $this->label,
			'default'     => $this->type === 'secret' ? '' : $this->default,
			'description' => $this->description,
			'options'     => $this->options,
		];
	}
}
