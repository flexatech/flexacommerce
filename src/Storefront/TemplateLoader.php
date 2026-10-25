<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Classic theme page templates (doc 02 §6.3). `templates/single-product.php`
 * and `templates/archive-product.php` render the product page and the shop,
 * category and tag lists. A theme overrides one by copying it to
 * `{theme}/flexacommerce/` with the same name, as with WooCommerce. Each
 * template carries an `@version`; a theme copy older than ours is reported
 * on the FlexaCommerce screens.
 */
final class TemplateLoader {
	public const THEME_DIR = 'flexacommerce/';

	public const SINGLE  = 'single-product.php';
	public const ARCHIVE = 'archive-product.php';

	/** The `template_include` filter for classic themes. */
	public static function include( string $template ): string {
		if ( is_singular( PostTypes::PRODUCT ) ) {
			$name = self::SINGLE;
		} elseif ( is_post_type_archive( PostTypes::PRODUCT ) || is_tax( [ PostTypes::CATEGORY, PostTypes::TAG ] ) ) {
			$name = self::ARCHIVE;
		} else {
			return $template;
		}
		/**
		 * Filters the file of a FlexaCommerce page template.
		 *
		 * @param string $file the theme copy or ours
		 * @param string $name `single-product.php` or `archive-product.php`
		 */
		$file = (string) apply_filters( 'flexacommerce.template', self::locate( $name ), $name );
		if ( $file === '' || ! is_readable( $file ) ) {
			return $template;
		}
		Assets::use_style();
		return $file;
	}

	/**
	 * The markup the templates open and close around the page content.
	 * Most classic themes expect `#primary` and `#main` from the template;
	 * Twenty Twenty-One opens them in its header already.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function wrapper(): array {
		$wrapper = get_template() === 'twentytwentyone'
			? [ '<div class="fxc-classic">', '</div>' ]
			: [ '<div id="primary" class="content-area fxc-classic"><main id="main" class="site-main">', '</main></div>' ];
		/**
		 * Filters the opening and closing markup around a classic template.
		 *
		 * @param array{0:string,1:string} $wrapper
		 */
		return self::pair( apply_filters( 'flexacommerce.template_wrapper', $wrapper ) );
	}

	/** @return array{0:string,1:string} */
	private static function pair( mixed $wrapper ): array {
		return is_array( $wrapper ) && is_string( $wrapper[0] ?? null ) && is_string( $wrapper[1] ?? null ) ? [ $wrapper[0], $wrapper[1] ] : [ '', '' ];
	}

	/** The theme copy (child theme first) when there is one, else ours. */
	public static function locate( string $name ): string {
		$theme = locate_template( self::THEME_DIR . $name );
		return $theme !== '' ? $theme : FLEXACOMMERCE_PATH . 'templates/' . $name;
	}

	/**
	 * Theme copies of our templates, emails included, whose `@version` is
	 * older than ours or missing. A child theme copy hides the parent's.
	 *
	 * @return list<array{file:string,theme:string,plugin:string}>
	 */
	public static function outdated(): array {
		$out  = [];
		$seen = [];
		foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $root ) {
			$dir = trailingslashit( $root ) . self::THEME_DIR;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php' ) {
					continue;
				}
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) );
				$own      = FLEXACOMMERCE_PATH . 'templates/' . $relative;
				if ( isset( $seen[ $relative ] ) || ! is_readable( $own ) ) {
					continue;
				}
				$seen[ $relative ] = true;
				$plugin            = self::version( $own );
				$theme             = self::version( $file->getPathname() );
				if ( $plugin !== '' && ( $theme === '' || version_compare( $theme, $plugin, '<' ) ) ) {
					$out[] = [
						'file'   => self::THEME_DIR . $relative,
						'theme'  => $theme,
						'plugin' => $plugin,
					];
				}
			}
		}
		usort( $out, static fn ( array $a, array $b ): int => strcmp( $a['file'], $b['file'] ) );
		return $out;
	}

	/** The `@version` tag in the file's first 8 KB, as WooCommerce reads it. */
	public static function version( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local template file.
		$head = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 8192 ) : '';
		return preg_match( '/^[ \t\/*#]*@version[ \t:]+([0-9][0-9A-Za-z.\-]*)/m', $head, $match ) === 1 ? $match[1] : '';
	}

	/** `admin_notices`: outdated theme copies, on our screens and the Themes screen. */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen === null || ! current_user_can( 'manage_options' ) || ( ! str_contains( $screen->id, 'flexacommerce' ) && $screen->id !== 'themes' ) ) {
			return;
		}
		$outdated = self::outdated();
		if ( $outdated === [] ) {
			return;
		}
		$items = '';
		foreach ( $outdated as $row ) {
			$items .= '<li><code>' . esc_html( $row['file'] ) . '</code> ' . esc_html(
				sprintf(
					/* translators: 1: version in the theme copy, 2: current template version */
					__( 'version %1$s, current %2$s', 'flexacommerce' ),
					$row['theme'] !== '' ? $row['theme'] : __( 'unknown', 'flexacommerce' ),
					$row['plugin']
				)
			) . '</li>';
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s</p><ul>%2$s</ul></div>',
			esc_html__( 'Your theme has copies of FlexaCommerce templates that are older than the plugin\'s. Compare them with the files in the plugin\'s templates folder and update them.', 'flexacommerce' ),
			wp_kses(
				$items,
				[
					'li'   => [],
					'code' => [],
				]
			)
		);
	}
}
