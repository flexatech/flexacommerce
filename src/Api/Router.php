<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api;

use Flexa\Commerce\Concerns\HasInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST endpoint in one place. Extensions add their own
 * endpoints by hooking `flexacommerce.rest.register_routes`.
 */
final class Router {
	use HasInstance;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		( new Admin\SettingsEndpoint() )->register_routes();
		( new Admin\StatusEndpoint() )->register_routes();
		( new Admin\LogsEndpoint() )->register_routes();

		do_action( 'flexacommerce.rest.register_routes' );
	}
}
