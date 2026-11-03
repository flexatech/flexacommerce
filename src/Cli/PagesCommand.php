<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Setup\Pages;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce pages status [--format=<format>]
 *   wp flexacommerce pages install [<page>...]
 *   wp flexacommerce pages assign <page> <id>
 *   wp flexacommerce pages shop [--filters=<side>]
 */
final class PagesCommand {
	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		WP_CLI::add_command( 'flexacommerce pages status', [ $instance, 'status' ] );
		WP_CLI::add_command( 'flexacommerce pages install', [ $instance, 'install' ] );
		WP_CLI::add_command( 'flexacommerce pages assign', [ $instance, 'assign' ] );
		WP_CLI::add_command( 'flexacommerce pages shop', [ $instance, 'shop' ] );
	}

	/**
	 * Lists the store pages and what is wrong with each.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = array_map(
			static fn ( array $row ): array => [
				'page'     => $row['key'],
				'required' => $row['required'] ? 'yes' : 'no',
				'id'       => $row['page']['id'] ?? '',
				'title'    => $row['page']['title'] ?? '',
				'status'   => $row['problem'] === '' ? 'ok' : $row['problem'],
				'url'      => $row['page']['view_url'] ?? '',
			],
			Pages::status()
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'page', 'required', 'id', 'title', 'status', 'url' ] );
	}

	/**
	 * Creates, restores, publishes or repairs store pages. A page already in use is never replaced.
	 *
	 * ## OPTIONS
	 *
	 * [<page>...]
	 * : cart, checkout, account, terms, wishlist or tracking. Without one, the cart, checkout and account pages that are missing or in the trash.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function install( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$unknown = array_diff( $args, Pages::KEYS );
		if ( $unknown !== [] ) {
			WP_CLI::error( sprintf( 'Unknown page: %s. Use %s.', implode( ', ', $unknown ), implode( ', ', Pages::KEYS ) ) );
		}
		$keys  = $args !== [] ? $args : array_filter( Pages::REQUIRED, static fn ( string $key ): bool => in_array( Pages::problem( $key ), [ 'missing', 'trash' ], true ) );
		$fixed = [];
		foreach ( $keys as $key ) {
			if ( Pages::problem( $key ) === '' ) {
				WP_CLI::log( sprintf( '%s: already works.', $key ) );
				continue;
			}
			if ( Pages::fix( $key ) ) {
				$fixed[] = $key;
			} else {
				WP_CLI::warning( sprintf( '%s: could not be fixed.', $key ) );
			}
		}
		WP_CLI::success( $fixed !== [] ? sprintf( 'Fixed: %s.', implode( ', ', $fixed ) ) : 'Nothing to do.' );
	}

	/**
	 * Uses an existing page for cart, checkout, account or terms. 0 for terms turns the terms checkbox off.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : cart, checkout, account or terms.
	 *
	 * <id>
	 * : The page ID.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function assign( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		[ $key, $id ] = $args + [ '', '' ];
		if ( ! in_array( $key, Pages::KEYS, true ) || ! ctype_digit( $id ) || ! Pages::assign( $key, (int) $id ) ) {
			WP_CLI::error( 'That page cannot be used there.' );
		}
		WP_CLI::success( sprintf( '%s page set to %d.', $key, (int) $id ) );
	}

	/**
	 * Shows the shop list and its filter sidebar, or moves the sidebar.
	 *
	 * ## OPTIONS
	 *
	 * [--filters=<side>]
	 * : Where the filters go.
	 * ---
	 * options:
	 *   - 'off'
	 *   - left
	 *   - right
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce pages shop --filters=left
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function shop( array $args, array $assoc_args ): void {
		unset( $args );
		if ( isset( $assoc_args['filters'] ) ) {
			if ( ! Pages::shop_filters( $assoc_args['filters'] ) ) {
				WP_CLI::error( 'Use --filters=off, left or right.' );
			}
			WP_CLI::success( sprintf( 'Shop filters: %s.', $assoc_args['filters'] ) );
		}
		$shop = Pages::shop();
		WP_CLI::log( sprintf( 'Shop: %s', $shop['view_url'] ) );
		WP_CLI::log( sprintf( 'Filters: %s', $shop['filters'] ) );
		$hidden = match ( $shop['hidden_by'] ) {
			'custom'  => 'The shop template was edited in the Site Editor, so it shows that copy. Reset it there, or add the Product filters block to it.',
			'theme'   => 'The theme has its own shop template, so the sidebar does not show. Add the Product filters block to it.',
			'classic' => 'The theme copy of flexacommerce/archive-product.php is older than 1.1.0 and has no sidebar. Update it from the plugin\'s templates folder.',
			default   => '',
		};
		if ( $hidden !== '' && $shop['filters'] !== 'off' ) {
			WP_CLI::warning( $hidden );
		}
		if ( $shop['page'] !== null ) {
			WP_CLI::warning( sprintf( 'Page %d "%s" has the same address as the shop, so shoppers see the product list instead.', $shop['page']['id'], $shop['page']['title'] ) );
		}
	}
}
