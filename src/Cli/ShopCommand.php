<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Maintenance\Doctor;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Requirements;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce doctor [--format=<format>]
 *   wp flexacommerce status [--format=<format>]
 */
final class ShopCommand {
	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		WP_CLI::add_command( 'flexacommerce doctor', [ $instance, 'doctor' ] );
		WP_CLI::add_command( 'flexacommerce status', [ $instance, 'status' ] );
	}

	/**
	 * Runs read-only integrity checks. Exits with code 1 when any check fails.
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
	public function doctor( array $args, array $assoc_args ): void {
		unset( $args );
		$results = ( new Doctor( Plugin::instance()->services()->integrity() ) )->run();
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $results, [ 'id', 'status', 'label', 'detail' ] );

		$errors = array_filter( $results, static fn ( array $r ): bool => $r['status'] === 'error' );
		if ( $errors !== [] ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Shows environment checks and table status.
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
		$format = $assoc_args['format'] ?? 'table';
		$checks = array_map(
			static fn ( array $c ): array => [
				'check'  => $c['label'],
				'ok'     => $c['ok'] ? 'yes' : 'no',
				'detail' => $c['detail'],
			],
			Requirements::check()
		);
		\WP_CLI\Utils\format_items( $format, $checks, [ 'check', 'ok', 'detail' ] );
		\WP_CLI\Utils\format_items( $format, Schema::status(), [ 'name', 'exists', 'engine', 'rows' ] );
	}
}
