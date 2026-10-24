<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The extension points every renderer shares (doc 04): an action before
 * and after the markup, then a filter over the whole HTML. Hooks only run
 * when something listens, so a plain page pays nothing for them.
 */
final class Component {
	/** @param array<string,mixed> $args */
	public static function wrap( string $component, callable $render, array $args ): string {
		$name = 'flexacommerce.render.' . $component;
		ob_start();
		if ( has_action( $name . '.before' ) ) {
			do_action( $name . '.before', $args );
		}
		$html  = (string) ob_get_clean();
		$html .= (string) $render();
		if ( has_action( $name . '.after' ) ) {
			ob_start();
			do_action( $name . '.after', $args );
			$html .= (string) ob_get_clean();
		}
		/**
		 * Filters the HTML of a storefront component.
		 *
		 * @param string              $html
		 * @param array<string,mixed> $args
		 */
		return has_filter( $name . '.html' ) ? (string) apply_filters( $name . '.html', $html, $args ) : $html;
	}

	/** @param array<string,string|int|float|bool|null> $attributes */
	public static function attributes( array $attributes ): string {
		$out = '';
		foreach ( $attributes as $name => $value ) {
			if ( $value === null || $value === false ) {
				continue;
			}
			$out .= $value === true ? ' ' . $name : sprintf( ' %s="%s"', $name, esc_attr( (string) $value ) );
		}
		return $out;
	}
}
