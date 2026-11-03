<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Design;

defined( 'ABSPATH' ) || exit;

/**
 * The look every store email shares (ADR-085): logo, colors, font and
 * footer. One option, not autoloaded, primed with the email options before
 * a send. The defaults are the colors the layout always had, so a store
 * that never opens the designer sends the same email as before.
 *
 * @phpstan-type Style array{logo_id:int,logo_url:string,logo_width:int,brand:string,button_text:string,background:string,body:string,text:string,font:string,footer:string}
 */
final class MailStyle {
	public const OPTION = 'flexacommerce_mail_style';

	/** The button color the templates were written with; the brand color replaces it. */
	public const TEMPLATE_BUTTON = 'background:#18181b;color:#ffffff;';

	public const DEFAULTS = [
		'logo_id'     => 0,
		'logo_url'    => '',
		'logo_width'  => 160,
		'brand'       => '#18181b',
		'button_text' => '#ffffff',
		'background'  => '#f4f4f5',
		'body'        => '#ffffff',
		'text'        => '#18181b',
		'font'        => 'system',
		'footer'      => '',
	];

	public const FONTS = [
		'system'  => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
		'arial'   => 'Arial,Helvetica,sans-serif',
		'verdana' => 'Verdana,Geneva,sans-serif',
		'georgia' => "Georgia,'Times New Roman',serif",
		'times'   => "'Times New Roman',Times,serif",
		'courier' => "'Courier New',Courier,monospace",
	];

	/** @return Style */
	public function get(): array {
		$stored = get_option( self::OPTION, [] );
		return self::clean( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merges a partial update over the stored style.
	 *
	 * @param array<string,mixed> $partial
	 * @return Style
	 */
	public function update( array $partial ): array {
		$values = self::clean( array_merge( $this->get(), array_intersect_key( $partial, self::DEFAULTS ) ) );
		update_option( self::OPTION, $values, false );
		return $values;
	}

	/**
	 * @param array<mixed> $values
	 * @return Style
	 */
	public static function clean( array $values ): array {
		$color   = static function ( string $key ) use ( $values ): string {
			$hex = is_string( $values[ $key ] ?? null ) ? sanitize_hex_color( $values[ $key ] ) : null;
			return is_string( $hex ) && $hex !== '' ? strtolower( $hex ) : self::DEFAULTS[ $key ];
		};
		$font    = is_string( $values['font'] ?? null ) && isset( self::FONTS[ $values['font'] ] ) ? $values['font'] : 'system';
		$logo_id = is_numeric( $values['logo_id'] ?? null ) ? max( 0, (int) $values['logo_id'] ) : 0;
		$url     = is_string( $values['logo_url'] ?? null ) ? esc_url_raw( $values['logo_url'], [ 'http', 'https' ] ) : '';
		$width   = is_numeric( $values['logo_width'] ?? null ) ? (int) $values['logo_width'] : self::DEFAULTS['logo_width'];
		return [
			'logo_id'     => $url !== '' ? $logo_id : 0,
			'logo_url'    => $url,
			'logo_width'  => max( 40, min( 600, $width ) ),
			'brand'       => $color( 'brand' ),
			'button_text' => $color( 'button_text' ),
			'background'  => $color( 'background' ),
			'body'        => $color( 'body' ),
			'text'        => $color( 'text' ),
			'font'        => $font,
			'footer'      => is_string( $values['footer'] ?? null ) ? mb_substr( sanitize_textarea_field( $values['footer'] ), 0, 1000 ) : '',
		];
	}

	/** @param Style $style */
	public static function font_stack( array $style ): string {
		return self::FONTS[ $style['font'] ] ?? self::FONTS['system'];
	}

	/**
	 * Gives the buttons of the email templates the brand colors.
	 *
	 * @param Style $style
	 */
	public static function recolor( string $html, array $style ): string {
		$colors = 'background:' . $style['brand'] . ';color:' . $style['button_text'] . ';';
		return $colors === self::TEMPLATE_BUTTON ? $html : str_replace( self::TEMPLATE_BUTTON, $colors, $html );
	}
}
