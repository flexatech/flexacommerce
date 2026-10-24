<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Database\Schema;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce db upgrade
 *   wp flexacommerce db version
 */
final class DbCommand {
	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		WP_CLI::add_command( 'flexacommerce db upgrade', [ $instance, 'upgrade' ] );
		WP_CLI::add_command( 'flexacommerce db version', [ $instance, 'version' ] );
	}

	/**
	 * Creates missing tables and columns and runs pending upgrade steps.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function upgrade( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		if ( Schema::is_newer_than_code() ) {
			WP_CLI::error( 'The database schema is newer than this plugin version. Update the plugin first.' );
		}
		$changes = Schema::migrate();
		foreach ( $changes as $change ) {
			WP_CLI::log( $change );
		}
		WP_CLI::success( sprintf( 'Schema is at version %d (%d changes).', Schema::installed_version(), count( $changes ) ) );
	}

	/**
	 * Prints the installed and expected schema versions.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function version( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		WP_CLI::log( sprintf( 'installed: %d', Schema::installed_version() ) );
		WP_CLI::log( sprintf( 'code:      %s', Schema::DB_VERSION ) );
	}
}
