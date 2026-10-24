<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

use Flexa\Commerce\Database\Upgrades\UpgradeStep;

defined( 'ABSPATH' ) || exit;

/**
 * Table definitions (doc 03 §4) and the upgrade runner (doc 03 §6).
 *
 * `migrate()` runs dbDelta for every table, then each numbered upgrade step
 * above the stored version. dbDelta only adds; renames, drops and key type
 * changes belong in an UpgradeStep guarded by the INFORMATION_SCHEMA helpers.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema DDL on plugin-owned tables, no user input.
final class Schema {
	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'flexacommerce_db_version';
	public const OPTION_PREFIX     = 'flexacommerce_';

	private const LOCK_OPTION = 'flexacommerce_upgrade_lock';
	private const LOCK_TTL    = 300;

	/**
	 * Data steps keyed by the DB version they produce, in ascending order.
	 *
	 * @var array<int,class-string<UpgradeStep>>
	 */
	private const STEPS = [];

	/** Full table name for a short name such as `orders`. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'fxc_' . $name;
	}

	/** @return list<string> short names */
	public static function tables(): array {
		return array_keys( self::definitions() );
	}

	/** @return array<string,string> short name => CREATE TABLE statement */
	public static function definitions(): array {
		global $wpdb;
		$p       = $wpdb->prefix . 'fxc_';
		$charset = $wpdb->get_charset_collate();

		return [
			'variants'        => "CREATE TABLE {$p}variants (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				is_base tinyint(1) NOT NULL DEFAULT 0,
				combination_hash char(40) NOT NULL,
				attributes longtext NULL,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				menu_order int(11) NOT NULL DEFAULT 0,
				sku varchar(100) NULL,
				regular_price bigint(20) NULL,
				sale_price bigint(20) NULL,
				sale_from datetime NULL,
				sale_to datetime NULL,
				manage_stock tinyint(1) NOT NULL DEFAULT 0,
				stock_from_base tinyint(1) NOT NULL DEFAULT 0,
				stock_quantity int(11) NULL,
				backorders varchar(10) NOT NULL DEFAULT 'no',
				low_stock_threshold int(11) NULL,
				stock_status varchar(20) NOT NULL DEFAULT 'instock',
				weight decimal(12,3) NULL,
				length decimal(12,3) NULL,
				width decimal(12,3) NULL,
				height decimal(12,3) NULL,
				image_id bigint(20) unsigned NULL,
				tax_class varchar(50) NOT NULL DEFAULT '',
				description text NULL,
				meta longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY product_combination (product_id,combination_hash),
				UNIQUE KEY sku (sku),
				KEY product_order (product_id,menu_order),
				KEY stock_status (stock_status)
			) {$charset};",
			'orders'          => "CREATE TABLE {$p}orders (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_number varchar(40) NOT NULL,
				order_key varchar(64) NOT NULL,
				status varchar(20) NOT NULL,
				payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
				needs_attention tinyint(1) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL,
				currency_exponent tinyint(3) unsigned NOT NULL,
				prices_include_tax tinyint(1) NOT NULL DEFAULT 0,
				subtotal bigint(20) NOT NULL DEFAULT 0,
				discount_total bigint(20) NOT NULL DEFAULT 0,
				shipping_total bigint(20) NOT NULL DEFAULT 0,
				fee_total bigint(20) NOT NULL DEFAULT 0,
				tax_total bigint(20) NOT NULL DEFAULT 0,
				total bigint(20) NOT NULL DEFAULT 0,
				amount_paid bigint(20) NOT NULL DEFAULT 0,
				amount_refunded bigint(20) NOT NULL DEFAULT 0,
				customer_id bigint(20) unsigned NULL,
				customer_email varchar(190) NOT NULL DEFAULT '',
				customer_name varchar(200) NOT NULL DEFAULT '',
				customer_ip varchar(45) NOT NULL DEFAULT '',
				customer_note text NULL,
				payment_method varchar(64) NOT NULL DEFAULT '',
				payment_method_title varchar(190) NOT NULL DEFAULT '',
				checkout_token varchar(64) NULL,
				created_via varchar(32) NOT NULL DEFAULT 'checkout',
				locale varchar(20) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				paid_at datetime NULL,
				completed_at datetime NULL,
				cancelled_at datetime NULL,
				deleted_at datetime NULL,
				anonymized_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_number (order_number),
				UNIQUE KEY checkout_token (checkout_token),
				KEY status_created (status,created_at),
				KEY created_at (created_at),
				KEY customer_created (customer_id,created_at),
				KEY customer_email (customer_email),
				KEY payment_status (payment_status,created_at),
				KEY attention_created (needs_attention,created_at)
			) {$charset};",
			'order_items'     => "CREATE TABLE {$p}order_items (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				type varchar(16) NOT NULL,
				position smallint(5) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NULL,
				variant_id bigint(20) unsigned NULL,
				code varchar(100) NOT NULL DEFAULT '',
				name varchar(255) NOT NULL,
				sku varchar(100) NULL,
				attributes longtext NULL,
				quantity int(10) unsigned NOT NULL DEFAULT 1,
				unit_price bigint(20) NOT NULL DEFAULT 0,
				subtotal bigint(20) NOT NULL DEFAULT 0,
				discount_total bigint(20) NOT NULL DEFAULT 0,
				tax_total bigint(20) NOT NULL DEFAULT 0,
				total bigint(20) NOT NULL DEFAULT 0,
				stock_reduced_qty int(10) unsigned NOT NULL DEFAULT 0,
				refunded_qty int(10) unsigned NOT NULL DEFAULT 0,
				refunded_total bigint(20) NOT NULL DEFAULT 0,
				restocked_qty int(10) unsigned NOT NULL DEFAULT 0,
				meta longtext NULL,
				PRIMARY KEY  (id),
				KEY order_position (order_id,position),
				KEY variant_id (variant_id),
				KEY product_id (product_id)
			) {$charset};",
			'order_addresses' => "CREATE TABLE {$p}order_addresses (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				type varchar(10) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				company varchar(150) NOT NULL DEFAULT '',
				address_1 varchar(200) NOT NULL DEFAULT '',
				address_2 varchar(200) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(100) NOT NULL DEFAULT '',
				postcode varchar(20) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY order_type (order_id,type)
			) {$charset};",
			'order_history'   => "CREATE TABLE {$p}order_history (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				type varchar(16) NOT NULL,
				from_status varchar(20) NULL,
				to_status varchar(20) NULL,
				message text NULL,
				customer_visible tinyint(1) NOT NULL DEFAULT 0,
				actor_type varchar(16) NOT NULL DEFAULT 'system',
				actor_id bigint(20) unsigned NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_created (order_id,created_at)
			) {$charset};",
			'order_meta'      => "CREATE TABLE {$p}order_meta (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				meta_key varchar(191) NOT NULL,
				meta_value longtext NULL,
				PRIMARY KEY  (id),
				KEY order_key (order_id,meta_key),
				KEY meta_key_value (meta_key,meta_value(100))
			) {$charset};",
			'transactions'    => "CREATE TABLE {$p}transactions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NULL,
				type varchar(10) NOT NULL,
				status varchar(20) NOT NULL,
				method varchar(10) NOT NULL DEFAULT 'gateway',
				gateway varchar(64) NOT NULL,
				amount bigint(20) NOT NULL,
				currency char(3) NOT NULL,
				idempotency_key varchar(64) NOT NULL,
				gateway_reference varchar(191) NULL,
				failure_code varchar(64) NULL,
				failure_message varchar(255) NULL,
				refund_lines longtext NULL,
				data longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				UNIQUE KEY gateway_reference (gateway,gateway_reference),
				KEY order_type (order_id,type),
				KEY status_updated (status,updated_at)
			) {$charset};",
			'webhook_events'  => "CREATE TABLE {$p}webhook_events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				gateway varchar(64) NOT NULL,
				event_id varchar(191) NOT NULL,
				event_type varchar(100) NOT NULL DEFAULT '',
				transaction_id bigint(20) unsigned NULL,
				result varchar(20) NOT NULL DEFAULT 'received',
				received_at datetime NOT NULL,
				processed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY gateway_event (gateway,event_id),
				KEY received_at (received_at)
			) {$charset};",
			'sessions'        => "CREATE TABLE {$p}sessions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				user_id bigint(20) unsigned NULL,
				data longtext NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				UNIQUE KEY user_id (user_id),
				KEY expires_at (expires_at)
			) {$charset};",
			'sequences'       => "CREATE TABLE {$p}sequences (
				name varchar(32) NOT NULL,
				value bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (name)
			) {$charset};",
			'id_map'          => "CREATE TABLE {$p}id_map (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				remote_system varchar(32) NOT NULL,
				entity varchar(32) NOT NULL,
				remote_id varchar(64) NOT NULL,
				local_id bigint(20) unsigned NOT NULL,
				origin varchar(10) NOT NULL,
				checksum char(40) NOT NULL DEFAULT '',
				job_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY remote (remote_system,entity,remote_id),
				UNIQUE KEY local (remote_system,entity,local_id)
			) {$charset};",
			'migration_jobs'  => "CREATE TABLE {$p}migration_jobs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				direction varchar(16) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				dry_run tinyint(1) NOT NULL DEFAULT 0,
				format_version varchar(10) NOT NULL,
				options longtext NOT NULL,
				stage varchar(32) NOT NULL DEFAULT '',
				cursor_state longtext NULL,
				counters longtext NULL,
				lock_token char(36) NULL,
				locked_until datetime NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				report longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				started_at datetime NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};",
			'migration_log'   => "CREATE TABLE {$p}migration_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL,
				entity varchar(32) NOT NULL,
				source_id varchar(64) NOT NULL DEFAULT '',
				level varchar(10) NOT NULL,
				code varchar(64) NOT NULL,
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY job_level (job_id,level),
				KEY job_entity_source (job_id,entity,source_id)
			) {$charset};",
			'logs'            => "CREATE TABLE {$p}logs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level varchar(10) NOT NULL,
				channel varchar(64) NOT NULL,
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY channel_created (channel,created_at),
				KEY level_created (level,created_at)
			) {$charset};",
		];
	}

	/**
	 * Create or update every table, then run pending upgrade steps.
	 *
	 * @return list<string> the changes dbDelta reported (empty when the schema was current)
	 */
	public static function migrate(): array {
		if ( ! self::acquire_lock() ) {
			return [];
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$changes = [];
			foreach ( self::definitions() as $sql ) {
				foreach ( dbDelta( $sql ) as $message ) {
					$changes[] = (string) $message;
				}
			}

			self::seed();
			self::run_steps();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

			do_action( 'flexacommerce.schema.migrated', self::DB_VERSION, $changes );
			return $changes;
		} finally {
			self::release_lock();
		}
	}

	public static function maybe_upgrade(): void {
		if ( self::needs_upgrade() ) {
			self::migrate();
		}
	}

	public static function installed_version(): int {
		$stored = get_option( self::DB_VERSION_OPTION, '0' );
		return is_numeric( $stored ) ? (int) $stored : 0;
	}

	public static function needs_upgrade(): bool {
		return self::installed_version() < (int) self::DB_VERSION;
	}

	/** An older plugin copy running against a newer schema must not write orders. */
	public static function is_newer_than_code(): bool {
		return self::installed_version() > (int) self::DB_VERSION;
	}

	/**
	 * @return list<array{name:string,exists:bool,engine:string,rows:int}>
	 */
	public static function status(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'fxc_' ) . '%' ),
			ARRAY_A
		);

		$found = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$found[ (string) $row['Name'] ] = $row;
		}

		$out = [];
		foreach ( self::tables() as $short ) {
			$name  = self::table( $short );
			$row   = $found[ $name ] ?? null;
			$out[] = [
				'name'   => $name,
				'exists' => $row !== null,
				'engine' => $row !== null ? (string) ( $row['Engine'] ?? '' ) : '',
				'rows'   => $row !== null ? (int) ( $row['Rows'] ?? 0 ) : 0,
			];
		}
		return $out;
	}

	/** @return list<string> */
	public static function missing_tables(): array {
		return array_values( array_map( static fn ( array $t ): string => $t['name'], array_filter( self::status(), static fn ( array $t ): bool => ! $t['exists'] ) ) );
	}

	/** @return list<string> existing tables whose engine is not InnoDB */
	public static function non_innodb_tables(): array {
		$bad = array_filter( self::status(), static fn ( array $t ): bool => $t['exists'] && strcasecmp( $t['engine'], 'InnoDB' ) !== 0 );
		return array_values( array_map( static fn ( array $t ): string => $t['name'], $bad ) );
	}

	public static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);
		return (int) $found > 0;
	}

	public static function index_exists( string $table, string $index ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$table,
				$index
			)
		);
		return (int) $found > 0;
	}

	/**
	 * Core drops these with the rest of a deleted network site. It calls the
	 * filter while switched to that site, so the prefix is already right.
	 *
	 * @param array<int|string,string> $tables
	 * @return array<int|string,string>
	 */
	public static function site_tables_to_drop( array $tables ): array {
		foreach ( self::tables() as $short ) {
			$tables[ 'fxc_' . $short ] = self::table( $short );
		}
		return $tables;
	}

	/**
	 * Drop every table and delete every option of this site. Only the opt-in
	 * uninstall path calls this (Maintenance\Eraser).
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::tables() as $short ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $short ) ) );
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function seed(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO %i (name, value) VALUES (%s, 0)', self::table( 'sequences' ), 'order_number' )
		);
	}

	private static function run_steps(): void {
		$installed = self::installed_version();
		foreach ( self::steps() as $version => $class ) {
			if ( $version <= $installed || $version > (int) self::DB_VERSION ) {
				continue;
			}
			( new $class() )->run();
			update_option( self::DB_VERSION_OPTION, (string) $version, false );
		}
	}

	/** @return array<int,class-string<UpgradeStep>> */
	private static function steps(): array {
		return self::STEPS;
	}

	/** add_option is atomic on the unique option_name key, so only one request migrates. */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, (string) ( time() + self::LOCK_TTL ), '', false ) ) {
			return true;
		}
		$expires = get_option( self::LOCK_OPTION );
		if ( is_numeric( $expires ) && (int) $expires < time() ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, (string) ( time() + self::LOCK_TTL ), '', false );
		}
		return false;
	}

	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
