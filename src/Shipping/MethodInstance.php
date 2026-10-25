<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

defined( 'ABSPATH' ) || exit;

/** One shipping method added to a zone, with its own settings. */
final class MethodInstance {
	/** @param array<string,mixed> $settings */
	public function __construct(
		public readonly int $instance_id,
		public readonly string $type,
		public readonly bool $enabled = true,
		public readonly array $settings = [],
	) {}

	/** @param array<string,mixed> $row */
	public static function from_array( array $row ): self {
		return new self(
			max( 0, (int) ( $row['instance_id'] ?? 0 ) ),
			sanitize_key( (string) ( $row['type'] ?? '' ) ),
			(bool) ( $row['enabled'] ?? true ),
			is_array( $row['settings'] ?? null ) ? $row['settings'] : [],
		);
	}

	/** @return array{instance_id:int,type:string,enabled:bool,settings:array<string,mixed>} */
	public function to_array(): array {
		return [
			'instance_id' => $this->instance_id,
			'type'        => $this->type,
			'enabled'     => $this->enabled,
			'settings'    => $this->settings,
		];
	}
}
