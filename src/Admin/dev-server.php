<?php
/**
 * Vite dev-server asset enqueue - local development only.
 *
 * EXCLUDED from production builds via .distignore. Required on demand by
 * Admin\Enqueue when dev mode is active. Because it never ships, the dev-only
 * wp_enqueue_script() calls below (which omit `$ver` on purpose to keep the
 * Vite module URL query-free) never reach a distributed install or Plugin
 * Check.
 */

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

defined( 'ABSPATH' ) || exit;

function flexacommerce_dev_server_url(): string {
	if ( defined( 'FLEXACOMMERCE_DEV_SERVER' ) && is_string( constant( 'FLEXACOMMERCE_DEV_SERVER' ) ) ) {
		return rtrim( constant( 'FLEXACOMMERCE_DEV_SERVER' ), '/' );
	}
	return 'http://localhost:5181';
}

function flexacommerce_enqueue_dev_server( string $handle, string $entry ): void {
	$base = flexacommerce_dev_server_url();

	// No `?ver=` query on dev-server URLs: Vite's esbuild plugin derives the
	// loader from the file extension, and `main.tsx?ver=1.0.0` defeats that
	// ("Invalid loader value" on every request).
	wp_enqueue_script( $handle . '-client', $base . '/@vite/client', [], null, false );
	wp_enqueue_script(
		$handle,
		$base . '/' . $entry,
		[ $handle . '-client', 'wp-i18n' ],
		null,
		true
	);

	add_filter(
		'script_loader_tag',
		static function ( string $tag, string $h ) use ( $handle, $base ): string {
			if ( $h === $handle . '-client' ) {
				$tag         = str_replace( '<script ', '<script type="module" ', $tag );
				$refresh_url = esc_url( $base . '/@react-refresh' );
				$preamble    =
					'<script type="module">' .
					'import RefreshRuntime from "' . $refresh_url . '";' .
					'RefreshRuntime.injectIntoGlobalHook(window);' .
					'window.$RefreshReg$ = () => {};' .
					'window.$RefreshSig$ = () => (type) => type;' .
					'window.__vite_plugin_react_preamble_installed__ = true;' .
					'</script>';
				return $tag . $preamble;
			}
			if ( $h === $handle ) {
				return str_replace( '<script ', '<script type="module" ', $tag );
			}
			return $tag;
		},
		10,
		2
	);
}
