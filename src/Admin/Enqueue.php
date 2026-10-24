<?php

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the Vite manifest and enqueues the built admin app on our screens
 * only. In dev mode (FLEXACOMMERCE_DEV, or a `client/.dev` marker file) it
 * loads from the Vite dev server with HMR.
 */
final class Enqueue {
	use HasInstance;

	private const HANDLE = 'flexacommerce-admin';
	private const ENTRY  = 'admin/main.tsx';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	public function enqueue_admin( string $hook_suffix ): void {
		$page = Menu::instance()->page_for( $hook_suffix );
		if ( $page === '' ) {
			return;
		}

		$dev_server = __DIR__ . '/dev-server.php';
		if ( $this->is_dev_mode() && is_readable( $dev_server ) ) {
			require_once $dev_server;
			\Flexa\Commerce\Admin\flexacommerce_enqueue_dev_server( self::HANDLE, self::ENTRY );
		} elseif ( ! $this->enqueue_prod() ) {
			return;
		}

		wp_set_script_translations( self::HANDLE, FLEXACOMMERCE_TEXT_DOMAIN, FLEXACOMMERCE_PATH . 'i18n/languages' );

		$currency = Settings::string( 'currency' );
		wp_localize_script(
			self::HANDLE,
			'flexaCommerce',
			[
				'restUrl'   => esc_url_raw( rest_url( FLEXACOMMERCE_REST_NAMESPACE . '/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'version'   => FLEXACOMMERCE_VERSION,
				'pluginUrl' => esc_url_raw( FLEXACOMMERCE_URL ),
				'adminUrl'  => esc_url_raw( admin_url( 'admin.php' ) ),
				'page'      => $page,
				'locale'    => determine_locale(),
				'theme'     => $this->detect_admin_theme(),
				'currency'  => [
					'code'              => $currency,
					'symbol'            => Currency::symbol( $currency ),
					'exponent'          => Currency::exponent( $currency ),
					'position'          => Settings::string( 'currency_position' ),
					'decimalSeparator'  => Settings::string( 'decimal_separator' ),
					'thousandSeparator' => Settings::string( 'thousand_separator' ),
				],
			]
		);
	}

	private function detect_admin_theme(): string {
		$scheme = (string) get_user_option( 'admin_color' );
		return in_array( $scheme, [ 'midnight', 'ectoplasm', 'ocean', 'coffee' ], true ) ? 'dark' : 'light';
	}

	private function is_dev_mode(): bool {
		if ( defined( 'FLEXACOMMERCE_DEV' ) && constant( 'FLEXACOMMERCE_DEV' ) ) {
			return true;
		}
		return file_exists( FLEXACOMMERCE_PATH . 'client/.dev' );
	}

	private function enqueue_prod(): bool {
		$manifest_path = FLEXACOMMERCE_PATH . 'assets/dist/.vite/manifest.json';
		if ( ! is_readable( $manifest_path ) ) {
			return false;
		}
		$raw      = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$manifest = $raw === false ? null : json_decode( $raw, true );
		if ( ! is_array( $manifest ) || ! isset( $manifest[ self::ENTRY ]['file'] ) ) {
			return false;
		}

		$dist_url = FLEXACOMMERCE_URL . 'assets/dist/';
		foreach ( $this->collect_css( $manifest, self::ENTRY ) as $i => $css ) {
			wp_enqueue_style( self::HANDLE . '-css-' . $i, $dist_url . $css, [], FLEXACOMMERCE_VERSION );
		}

		wp_enqueue_script( self::HANDLE, $dist_url . (string) $manifest[ self::ENTRY ]['file'], [ 'wp-i18n' ], FLEXACOMMERCE_VERSION, true );
		add_filter(
			'script_loader_tag',
			static fn ( string $tag, string $handle ): string => $handle === self::HANDLE
				? str_replace( '<script ', '<script type="module" ', $tag )
				: $tag,
			10,
			2
		);
		return true;
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @param array<string,true>  $seen
	 * @return list<string>
	 */
	private function collect_css( array $manifest, string $key, array &$seen = [] ): array {
		if ( isset( $seen[ $key ] ) || ! isset( $manifest[ $key ] ) || ! is_array( $manifest[ $key ] ) ) {
			return [];
		}
		$seen[ $key ] = true;

		$item = $manifest[ $key ];
		$css  = isset( $item['css'] ) && is_array( $item['css'] ) ? array_map( 'strval', $item['css'] ) : [];
		foreach ( isset( $item['imports'] ) && is_array( $item['imports'] ) ? $item['imports'] : [] as $import ) {
			$css = array_merge( $css, $this->collect_css( $manifest, (string) $import, $seen ) );
		}
		return array_values( array_unique( $css ) );
	}
}
