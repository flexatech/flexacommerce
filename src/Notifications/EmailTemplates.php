<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Email templates live in `templates/emails/`; a theme overrides one by
 * copying it to `{theme}/flexacommerce/emails/` with the same path (doc 02
 * §7.9). Templates read their values from `$args`, as load_template() does.
 */
final class EmailTemplates {
	public const THEME_DIR = 'flexacommerce/emails/';

	/** @param array<string,mixed> $args */
	public function render( string $name, array $args ): string {
		$file = $this->locate( $name );
		if ( $file === '' ) {
			return '';
		}
		ob_start();
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the template reads $args.
		( static function ( string $__file, array $args ): void {
			include $__file;
		} )( $file, $args );
		return (string) ob_get_clean();
	}

	/** The theme copy when there is one, else ours; '' for an unknown name. */
	public function locate( string $name ): string {
		if ( preg_match( '#^[a-z0-9-]+(/[a-z0-9-]+)?$#', $name ) !== 1 ) {
			return '';
		}
		$theme = locate_template( self::THEME_DIR . $name . '.php' );
		if ( $theme !== '' ) {
			return $theme;
		}
		$own = FLEXACOMMERCE_PATH . 'templates/emails/' . $name . '.php';
		return is_readable( $own ) ? $own : '';
	}
}
