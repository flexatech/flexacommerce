<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Environment checks. The result is stored in a non-autoloaded option at
 * activation and on schema changes, and shown as a notice on our screens.
 */
final class Requirements {
	use HasInstance;

	public const OPTION  = 'flexacommerce_health';
	public const MIN_PHP = '8.2';
	public const MIN_WP  = '6.9';

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'flexacommerce.schema.migrated', [ self::class, 'store' ] );
	}

	/**
	 * @return list<array{id:string,label:string,ok:bool,detail:string}>
	 */
	public static function check(): array {
		global $wp_version, $wpdb;

		$non_innodb = Schema::non_innodb_tables();
		$db_server  = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';

		return [
			[
				'id'     => 'php',
				'label'  => __( 'PHP version', 'flexacommerce' ),
				'ok'     => version_compare( PHP_VERSION, self::MIN_PHP, '>=' ),
				'detail' => PHP_VERSION,
			],
			[
				'id'     => 'wordpress',
				'label'  => __( 'WordPress version', 'flexacommerce' ),
				'ok'     => version_compare( (string) $wp_version, self::MIN_WP, '>=' ),
				'detail' => (string) $wp_version,
			],
			[
				'id'     => 'innodb',
				'label'  => __( 'InnoDB tables', 'flexacommerce' ),
				'ok'     => $non_innodb === [],
				'detail' => $non_innodb === [] ? $db_server : implode( ', ', $non_innodb ),
			],
			[
				'id'     => 'schema',
				'label'  => __( 'Database schema', 'flexacommerce' ),
				'ok'     => ! Schema::is_newer_than_code() && Schema::missing_tables() === [],
				'detail' => sprintf( '%d / %s', Schema::installed_version(), Schema::DB_VERSION ),
			],
		];
	}

	public static function store(): void {
		$failed = array_values( array_filter( self::check(), static fn ( array $c ): bool => ! $c['ok'] ) );
		update_option(
			self::OPTION,
			[
				'checked_at' => time(),
				'failed'     => $failed,
			],
			false
		);
	}

	public function notice(): void {
		if ( ! Capabilities::can_manage() || ! $this->on_our_screen() ) {
			return;
		}
		$health = get_option( self::OPTION, [] );
		$failed = is_array( $health ) && isset( $health['failed'] ) && is_array( $health['failed'] ) ? $health['failed'] : [];
		if ( $failed === [] ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'FlexaCommerce found a problem with this site:', 'flexacommerce' ) . '</strong></p><ul>';
		foreach ( $failed as $item ) {
			printf(
				'<li>%s: %s</li>',
				esc_html( (string) ( $item['label'] ?? '' ) ),
				esc_html( (string) ( $item['detail'] ?? '' ) )
			);
		}
		echo '</ul></div>';
	}

	private function on_our_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen === null ) {
			return false;
		}
		return $screen->id === 'plugins' || str_contains( $screen->id, 'flexacommerce' );
	}
}
