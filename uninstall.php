<?php
/**
 * Runs when the plugin is deleted. Data is removed only on sites where the
 * merchant opted in (Settings > remove data on uninstall).
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Same autoloader as flexacommerce.php, which never loads vendor/.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Flexa\Commerce\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

\Flexa\Commerce\Maintenance\Uninstaller::run();
