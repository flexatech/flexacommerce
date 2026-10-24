<?php
/**
 * Plugin Name:       FlexaCommerce
 * Description:       A commerce engine for WordPress: products with variants, cart, checkout and orders in dedicated tables.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            FlexaTech
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flexacommerce
 * Domain Path:       /i18n/languages
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'FlexaCommerce requires PHP 8.2 or higher. The plugin has been disabled.', 'flexacommerce' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'FLEXACOMMERCE_VERSION', '0.1.0' );
define( 'FLEXACOMMERCE_FILE', __FILE__ );
define( 'FLEXACOMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLEXACOMMERCE_URL', plugin_dir_url( __FILE__ ) );
define( 'FLEXACOMMERCE_BASENAME', plugin_basename( __FILE__ ) );
define( 'FLEXACOMMERCE_REST_NAMESPACE', 'flexacommerce/v1' );
define( 'FLEXACOMMERCE_TEXT_DOMAIN', 'flexacommerce' );

if ( file_exists( FLEXACOMMERCE_PATH . 'vendor/autoload.php' ) ) {
	require_once FLEXACOMMERCE_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'Flexa\Commerce\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = FLEXACOMMERCE_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

register_activation_hook( __FILE__, [ \Flexa\Commerce\Setup\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Flexa\Commerce\Setup\Deactivator::class, 'deactivate' ] );

add_action( 'before_woocommerce_init', [ \Flexa\Commerce\Integrations\WooCommerce\Compatibility::class, 'declare' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\Flexa\Commerce\Plugin::instance()->boot();
	}
);

/**
 * Entry point for extensions that load after `flexacommerce.loaded` has fired.
 */
function flexacommerce(): \Flexa\Commerce\Plugin {
	return \Flexa\Commerce\Plugin::instance();
}
