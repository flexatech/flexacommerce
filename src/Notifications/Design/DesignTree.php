<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Design;

defined( 'ABSPATH' ) || exit;

/**
 * The blocks an email design is made of, top to bottom (ADR-085), and the
 * cleaning every saved or previewed design goes through. A design is
 * `{blocks: list<{id, type, props}>}`; unknown types and props are dropped,
 * so the renderer can trust what it reads.
 *
 * @phpstan-type Block array{id:string,type:string,props:array<string,string|int>}
 * @phpstan-type Design array{blocks:list<Block>}
 */
final class DesignTree {
	public const MAX_BLOCKS = 60;

	public const MAX_TEXT = 5000;

	/** Blocks that print one of the email template parts. */
	public const PARTS = [
		'order_details' => 'order-details',
		'addresses'     => 'addresses',
		'downloads'     => 'downloads',
		'shipments'     => 'shipments',
	];

	/** Blocks a design may hold only once. */
	public const SINGLE = [ 'message', 'order_details', 'addresses', 'downloads', 'shipments' ];

	private const ALIGN = [ 'left', 'center', 'right' ];

	/**
	 * Each type with its props and their defaults. The type of the default
	 * is the type of the prop.
	 */
	public const TYPES = [
		'logo'          => [
			'align' => 'left',
			'width' => 0,
		],
		'heading'       => [
			'text'  => '{heading}',
			'size'  => 'medium',
			'align' => 'left',
		],
		'text'          => [
			'text'  => '',
			'align' => 'left',
		],
		'button'        => [
			'label' => '',
			'url'   => '{order_url}',
			'align' => 'left',
			'color' => '',
		],
		'image'         => [
			'id'    => 0,
			'url'   => '',
			'alt'   => '',
			'width' => 0,
			'align' => 'center',
			'link'  => '',
		],
		'divider'       => [],
		'spacer'        => [ 'height' => 24 ],
		'message'       => [],
		'order_details' => [],
		'addresses'     => [],
		'downloads'     => [],
		'shipments'     => [],
		'footer'        => [ 'text' => '' ],
	];

	/**
	 * What "Customize" starts from: the email as it looks today.
	 *
	 * @return Design
	 */
	public static function starter(): array {
		return [
			'blocks' => [
				self::block( 'b1', 'logo' ),
				self::block( 'b2', 'heading' ),
				self::block( 'b3', 'message' ),
				self::block( 'b4', 'footer' ),
			],
		];
	}

	/**
	 * A design with only what the renderer understands; null when nothing is left.
	 *
	 * @return Design|null
	 */
	public static function clean( mixed $design ): ?array {
		$list = is_array( $design ) && is_array( $design['blocks'] ?? null ) ? $design['blocks'] : null;
		if ( $list === null ) {
			return null;
		}
		$blocks = [];
		$ids    = [];
		$seen   = [];
		foreach ( $list as $raw ) {
			if ( count( $blocks ) >= self::MAX_BLOCKS ) {
				break;
			}
			if ( ! is_array( $raw ) || ! is_string( $raw['type'] ?? null ) || ! isset( self::TYPES[ $raw['type'] ] ) ) {
				continue;
			}
			$type = $raw['type'];
			if ( in_array( $type, self::SINGLE, true ) ) {
				if ( isset( $seen[ $type ] ) ) {
					continue;
				}
				$seen[ $type ] = true;
			}
			$id = is_string( $raw['id'] ?? null ) && preg_match( '/^[a-z0-9]{1,16}$/', $raw['id'] ) === 1 ? $raw['id'] : '';
			if ( $id === '' || isset( $ids[ $id ] ) ) {
				$id = self::free_id( $ids );
			}
			$ids[ $id ] = true;
			$blocks[]   = [
				'id'    => $id,
				'type'  => $type,
				'props' => self::props( $type, is_array( $raw['props'] ?? null ) ? $raw['props'] : [] ),
			];
		}
		return $blocks === [] ? null : [ 'blocks' => $blocks ];
	}

	/**
	 * @param array<string,string|int> $props
	 * @return Block
	 */
	public static function block( string $id, string $type, array $props = [] ): array {
		return [
			'id'    => $id,
			'type'  => $type,
			'props' => self::props( $type, $props ),
		];
	}

	/** @param array<string,true> $taken */
	private static function free_id( array $taken ): string {
		$n = count( $taken ) + 1;
		while ( isset( $taken[ 'b' . $n ] ) ) {
			++$n;
		}
		return 'b' . $n;
	}

	/**
	 * @param array<mixed> $raw
	 * @return array<string,string|int>
	 */
	private static function props( string $type, array $raw ): array {
		$out = [];
		foreach ( self::TYPES[ $type ] as $key => $default ) {
			$value       = $raw[ $key ] ?? $default;
			$out[ $key ] = is_int( $default ) ? self::number( $key, $value, $default ) : self::text( $key, $value, $default );
		}
		return $out;
	}

	private static function number( string $key, mixed $value, int $default ): int {
		$n = is_numeric( $value ) ? (int) $value : $default;
		return match ( $key ) {
			'height' => max( 4, min( 120, $n ) ),
			'width'  => max( 0, min( 600, $n ) ),
			default  => max( 0, $n ),
		};
	}

	private static function text( string $key, mixed $value, string $default ): string {
		$value = is_scalar( $value ) ? (string) $value : $default;
		switch ( $key ) {
			case 'align':
				return in_array( $value, self::ALIGN, true ) ? $value : $default;
			case 'size':
				return in_array( $value, [ 'small', 'medium', 'large' ], true ) ? $value : $default;
			case 'color':
				$hex = sanitize_hex_color( $value );
				return is_string( $hex ) ? strtolower( $hex ) : '';
			case 'url':
			case 'link':
				return self::url( $value );
			case 'text':
				return mb_substr( wp_kses( $value, Designer::ALLOWED_HTML ), 0, self::MAX_TEXT );
			default:
				return mb_substr( sanitize_text_field( $value ), 0, 300 );
		}
	}

	/** A link, or a link made of tokens such as {order_url} that the send fills in. */
	private static function url( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( preg_match( '/^\{[a-z_]+\}$/', $value ) === 1 ) {
			return $value;
		}
		return esc_url_raw( $value, [ 'http', 'https', 'mailto', 'tel' ] );
	}
}
