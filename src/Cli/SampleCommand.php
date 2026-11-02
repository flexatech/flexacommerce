<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Setup\DemoSettings;
use Flexa\Commerce\Setup\SampleStore;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce sample install [--type=<physical|digital|both>] [--with=<parts>]
 *   wp flexacommerce sample remove
 *   wp flexacommerce sample status
 */
final class SampleCommand {
	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		WP_CLI::add_command( 'flexacommerce sample install', [ $instance, 'install' ] );
		WP_CLI::add_command( 'flexacommerce sample remove', [ $instance, 'remove' ] );
		WP_CLI::add_command( 'flexacommerce sample status', [ $instance, 'status' ] );
	}

	/**
	 * Adds the sample store: products with pictures, reviews and coupons, and the store settings around them.
	 *
	 * Products whose SKU the shop already uses are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : physical, digital or both.
	 * ---
	 * default: both
	 * options:
	 *   - physical
	 *   - digital
	 *   - both
	 * ---
	 *
	 * [--with=<parts>]
	 * : Comma-separated store settings to add: shipping, payments, tax, pages. "none" adds the products only.
	 * ---
	 * default: shipping,payments,pages
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function install( array $args, array $assoc_args ): void {
		unset( $args );
		$parts   = array_map( 'trim', explode( ',', $assoc_args['with'] ?? 'shipping,payments,pages' ) );
		$unknown = array_diff( $parts, DemoSettings::PARTS, [ 'none', '' ] );
		if ( $unknown !== [] ) {
			WP_CLI::error( sprintf( 'Unknown part: %s. Use shipping, payments, tax, pages or none.', implode( ', ', $unknown ) ) );
			return;
		}
		try {
			$counts = SampleStore::make()->install( $assoc_args['type'] ?? 'both', $parts );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		foreach ( $counts['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		WP_CLI::success(
			sprintf(
				'Added %d products (%d skipped), %d pictures, %d reviews and %d coupons.',
				$counts['products'],
				$counts['skipped'],
				$counts['images'],
				$counts['reviews'],
				$counts['coupons']
			)
		);
		$store = $counts['store'];
		if ( $store !== [] ) {
			WP_CLI::log(
				sprintf(
					'Store: %s, payment methods turned on: %s, tax rate: %s, %d pages, menu: %s.',
					$store['zone'] ? sprintf( 'a shipping zone with %d methods', $store['methods'] ) : 'no shipping zone',
					$store['gateways'] !== [] ? implode( ', ', $store['gateways'] ) : 'none',
					$store['tax'] ? 'yes' : 'no',
					$store['pages'],
					$store['menu'] ? 'yes' : 'no'
				)
			);
		}
	}

	/**
	 * Removes what the sample store added and puts back the store settings it changed. Categories and tags the shop's own products use stay, and so does a setting changed since the install.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function remove( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$store = SampleStore::make();
		if ( ! $store->status()['installed'] ) {
			WP_CLI::error( 'The sample store is not installed.' );
		}
		$counts = $store->remove();
		WP_CLI::success( sprintf( 'Removed %d products, %d pictures, %d coupons, %d terms, %d shipping zones, %d pages and %d menus.', $counts['products'], $counts['images'], $counts['coupons'], $counts['terms'], $counts['store']['zones'], $counts['store']['pages'], $counts['store']['menus'] ) );
	}

	/**
	 * Says whether the sample store is installed.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$status = SampleStore::make()->status();
		WP_CLI::log( $status['installed'] ? sprintf( 'installed (%s): %d products, %d coupons, settings: %s', $status['type'], $status['products'], $status['coupons'], $status['parts'] !== [] ? implode( ', ', $status['parts'] ) : 'none' ) : 'not installed' );
	}
}
