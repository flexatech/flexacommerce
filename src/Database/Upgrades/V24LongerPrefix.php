<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database\Upgrades;

defined( 'ABSPATH' ) || exit;

/**
 * DB_VERSION 24: the prefix grows from `fxc_` to `fxcm_` (ADR-087). Schema
 * renames the tables before dbDelta through `rename_tables()`, so dbDelta
 * finds them and does not create empty ones. `run()` then moves the keys
 * stored in WordPress tables: the post type, taxonomies and comment type,
 * meta keys, the gift card gateway id, roles and capabilities. Transients
 * under the old prefix are dropped (they are caches, queues and limits that
 * rebuild) and the rewrite rules are flushed for the new query vars. The
 * old and new keys are written out, as in V23PrefixedKeys.
 */
final class V24LongerPrefix implements UpgradeStep {
	private const OLD = 'fxc_';
	private const NEW = 'fxcm_';

	private const POST_TYPE  = [ 'fxc_product' => 'fxcm_product' ];
	private const TAXONOMIES = [
		'fxc_product_cat' => 'fxcm_product_cat',
		'fxc_product_tag' => 'fxcm_product_tag',
		'fxc_attribute'   => 'fxcm_attribute',
	];
	private const COMMENT    = [ 'fxc_review' => 'fxcm_review' ];
	private const GATEWAY    = [ 'fxc_gift_card' => 'fxcm_gift_card' ];
	private const ROLE       = [ 'fxc_store_manager' => 'fxcm_store_manager' ];

	/**
	 * Rename `{prefix}fxc_<name>` to `{prefix}fxcm_<name>` where the old table
	 * exists and the new one does not.
	 *
	 * @param list<string> $names short table names
	 */
	public static function rename_tables( array $names ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off upgrade on the plugin's own tables.
		foreach ( $names as $name ) {
			$old = $wpdb->prefix . self::OLD . $name;
			$new = $wpdb->prefix . self::NEW . $name;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) !== $old ) {
				continue;
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) ) === $new ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) );
		}
		// phpcs:enable
	}

	public function run(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off upgrade; the object cache is flushed below.
		foreach ( self::POST_TYPE as $old => $new ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET post_type = %s WHERE post_type = %s', $wpdb->posts, $new, $old ) );
		}
		foreach ( self::TAXONOMIES + self::POST_TYPE as $old => $new ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET taxonomy = %s WHERE taxonomy = %s', $wpdb->term_taxonomy, $new, $old ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, $new, '_menu_item_object', $old ) );
		}
		foreach ( self::COMMENT as $old => $new ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET comment_type = %s WHERE comment_type = %s', $wpdb->comments, $new, $old ) );
		}

		// Meta keys `_fxc_*` in core and plugin meta tables.
		$tables = [ $wpdb->postmeta, $wpdb->termmeta, $wpdb->commentmeta, $wpdb->usermeta, $wpdb->prefix . self::NEW . 'order_meta' ];
		foreach ( $tables as $table ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
					$table,
					'_' . self::NEW,
					strlen( '_' . self::OLD ) + 1,
					$wpdb->esc_like( '_' . self::OLD ) . '%'
				)
			);
		}
		// User meta without the underscore: addresses, and the screen options core keys by post type.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s',
				$wpdb->usermeta,
				self::OLD,
				self::NEW,
				'%' . $wpdb->esc_like( self::OLD ) . '%'
			)
		);

		foreach ( self::GATEWAY as $old => $new ) {
			foreach ( [ 'orders', 'subscriptions' ] as $table ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET payment_method = %s WHERE payment_method = %s', $wpdb->prefix . self::NEW . $table, $new, $old ) );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET gateway = %s WHERE gateway = %s', $wpdb->prefix . self::NEW . 'transactions', $new, $old ) );
		}

		foreach ( [ '_transient_', '_transient_timeout_' ] as $kind ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $kind . self::OLD ) . '%' ) );
		}
		// phpcs:enable

		$this->roles();
		delete_option( 'rewrite_rules' );
		wp_cache_flush();
	}

	/** Capabilities move on every role, and the store manager role moves with its users. */
	private function roles(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $role->capabilities as $cap => $grant ) {
				if ( ! str_contains( (string) $cap, '_' . self::OLD ) ) {
					continue;
				}
				$role->add_cap( str_replace( '_' . self::OLD, '_' . self::NEW, (string) $cap ), (bool) $grant );
				$role->remove_cap( (string) $cap );
			}
		}
		foreach ( self::ROLE as $old => $new ) {
			$role = get_role( $old );
			if ( $role === null ) {
				continue;
			}
			if ( get_role( $new ) === null ) {
				add_role( $new, __( 'Store Manager', 'flexacommerce' ), $role->capabilities );
			}
			foreach ( get_users( [ 'role' => $old ] ) as $user ) {
				$user->add_role( $new );
				$user->remove_role( $old );
			}
			remove_role( $old );
		}
	}
}
