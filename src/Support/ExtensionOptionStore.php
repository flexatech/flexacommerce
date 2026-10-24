<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\ExtensionSettings;

defined( 'ABSPATH' ) || exit;

final class ExtensionOptionStore implements ExtensionSettings {
	private readonly string $option;

	public function __construct( string $extension_id ) {
		if ( preg_match( '/^[a-z0-9_]{1,64}$/', $extension_id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid extension id "%s".', $extension_id ) );
		}
		$this->option = 'flexacommerce_ext_' . $extension_id;
	}

	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public function all(): array {
		$stored = get_option( $this->option, [] );
		return is_array( $stored ) ? $stored : [];
	}

	public function set( string $key, mixed $value ): void {
		$all         = $this->all();
		$all[ $key ] = $value;
		update_option( $this->option, $all, false );
	}

	public function delete( string $key ): void {
		$all = $this->all();
		unset( $all[ $key ] );
		update_option( $this->option, $all, false );
	}
}
