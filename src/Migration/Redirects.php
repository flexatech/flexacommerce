<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Migration\Canonical\Record;

defined( 'ABSPATH' ) || exit;

/**
 * 301s for WooCommerce URLs that cannot stay identical after cutover
 * (doc 05 §9.2 step 7): `?post_type=product&p=ID` and the other query
 * forms through the id map, and paths under a WooCommerce base that
 * FlexaCommerce could not adopt through the slug. It only answers requests
 * WordPress could not route, so an identical URL never pays for it.
 */
final class Redirects {
	public function __construct( private readonly IdMapRepository $ids ) {}

	/** Front-end hook, added by Plugin::boot() once step 7 of the cutover ran. */
	public static function handle(): void {
		if ( function_exists( 'WC' ) ) {
			return;
		}
		if ( ! is_404() && ! isset( $_GET['post_type'], $_GET['p'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			return;
		}
		$path   = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$target = ( new self( \Flexa\Commerce\Plugin::instance()->migration()->ids() ) )->resolve(
			wp_unslash( $_GET ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			is_string( $path ) ? $path : '',
			is_404()
		);
		if ( $target !== null ) {
			wp_safe_redirect( $target, 301, 'FlexaCommerce' );
			exit;
		}
	}

	/**
	 * Where an old URL lives now, or null.
	 *
	 * @param array<mixed> $query the request's query string
	 * @param string       $path  the request path, with the home path
	 */
	public function resolve( array $query, string $path, bool $is_404 ): ?string {
		$string = static fn ( string $key ): string => is_string( $query[ $key ] ?? null ) ? sanitize_title( $query[ $key ] ) : '';
		if ( ( $query['post_type'] ?? null ) === 'product' && is_numeric( $query['p'] ?? null ) ) {
			return $this->product_by_wc_id( (int) $query['p'] );
		}
		if ( ! $is_404 ) {
			return null;
		}
		if ( is_numeric( $query['p'] ?? null ) ) {
			return $this->product_by_wc_id( (int) $query['p'] );
		}
		if ( $string( 'product' ) !== '' ) {
			return self::product_by_slug( $string( 'product' ) );
		}
		if ( $string( 'product_cat' ) !== '' ) {
			return self::term_by_slug( $string( 'product_cat' ), PostTypes::CATEGORY );
		}
		if ( $string( 'product_tag' ) !== '' ) {
			return self::term_by_slug( $string( 'product_tag' ), PostTypes::TAG );
		}
		return $this->by_path( $path );
	}

	/** A path under an old base: its last segment is the slug, identical in both plugins. */
	private function by_path( string $path ): ?string {
		$home     = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$relative = trim( str_starts_with( $path, $home ) ? substr( $path, strlen( $home ) ) : $path, '/' );
		if ( $relative === '' ) {
			return null;
		}
		$bases = Cutover::state()['bases'] ?? [];
		$bases = is_array( $bases ) ? $bases : [];
		$parts = explode( '/', $relative );
		$slug  = sanitize_title( (string) end( $parts ) );
		foreach ( [
			'category' => PostTypes::CATEGORY,
			'tag'      => PostTypes::TAG,
			'product'  => null,
		] as $key => $taxonomy ) {
			$plan = is_array( $bases[ $key ] ?? null ) ? $bases[ $key ] : null;
			if ( $plan === null || ! empty( $plan['identical'] ) ) {
				continue;
			}
			$prefix = self::static_prefix( (string) $plan['wc'] );
			if ( $prefix === '' || ! str_starts_with( $relative . '/', $prefix . '/' ) || $relative === $prefix ) {
				continue;
			}
			$url = $taxonomy === null ? self::product_by_slug( $slug ) : self::term_by_slug( $slug, $taxonomy );
			if ( $url !== null ) {
				return $url;
			}
		}
		$shop = is_array( $bases['shop'] ?? null ) ? $bases['shop'] : null;
		if ( $shop !== null && empty( $shop['identical'] ) && $relative === trim( (string) $shop['wc'], '/' ) ) {
			$link = get_post_type_archive_link( PostTypes::PRODUCT );
			return is_string( $link ) ? $link : null;
		}
		return null;
	}

	/** A variation id redirects to its product. */
	private function product_by_wc_id( int $wc_id ): ?string {
		if ( $wc_id <= 0 ) {
			return null;
		}
		if ( get_post_type( $wc_id ) === 'product_variation' ) {
			$wc_id = (int) wp_get_post_parent_id( $wc_id );
		}
		$row = $this->ids->by_remote( Record::PRODUCT, (string) $wc_id );
		return $row !== null ? self::product_link( $row->local_id ) : null;
	}

	private static function product_by_slug( string $slug ): ?string {
		$post = get_page_by_path( $slug, OBJECT, PostTypes::PRODUCT );
		return $post instanceof \WP_Post ? self::product_link( $post->ID ) : null;
	}

	private static function product_link( int $id ): ?string {
		if ( get_post_type( $id ) !== PostTypes::PRODUCT || get_post_status( $id ) !== 'publish' ) {
			return null;
		}
		$link = get_permalink( $id );
		return is_string( $link ) ? $link : null;
	}

	private static function term_by_slug( string $slug, string $taxonomy ): ?string {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$link = get_term_link( $term );
		return is_string( $link ) ? $link : null;
	}

	/** `shop/%product_cat%` → `shop`. */
	private static function static_prefix( string $base ): string {
		$parts = [];
		foreach ( explode( '/', trim( $base, '/' ) ) as $part ) {
			if ( $part === '' || str_contains( $part, '%' ) ) {
				break;
			}
			$parts[] = $part;
		}
		return implode( '/', $parts );
	}
}
