<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Setup\SampleStore;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce sample install
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
	 * Adds the sample store: products of every type with pictures, reviews and coupons.
	 *
	 * Products whose SKU the shop already uses are skipped.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function install( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		try {
			$counts = SampleStore::make()->install();
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
	}

	/**
	 * Removes what the sample store added. Categories and tags the shop's own products use stay.
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
		WP_CLI::success( sprintf( 'Removed %d products, %d pictures, %d coupons and %d terms.', $counts['products'], $counts['images'], $counts['coupons'], $counts['terms'] ) );
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
		WP_CLI::log( $status['installed'] ? sprintf( 'installed: %d products, %d coupons', $status['products'], $status['coupons'] ) : 'not installed' );
	}
}
