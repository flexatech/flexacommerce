<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Option storage namespaced per extension (`flexacommerce_ext_{id}`, not
 * autoloaded). Values must be JSON-compatible.
 *
 * @api
 */
interface ExtensionSettings {
	public function get( string $key, mixed $default = null ): mixed;

	/** @return array<string,mixed> */
	public function all(): array;

	public function set( string $key, mixed $value ): void;

	public function delete( string $key ): void;
}
