<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the module dependency table in doc 02 §3.2. Each file's imports
 * and fully qualified references to Flexa\Commerce\* are checked against the
 * rules of the module it lives in. Rules for modules that do not exist yet
 * are listed so they apply the day the module lands.
 */
final class DependencyRulesTest extends TestCase {
	private const ROOT = 'Flexa\\Commerce\\';

	/** Modules every module may use. */
	private const SHARED = [ 'Contracts', 'Concerns' ];

	/**
	 * Allow-lists: the module may use only these (plus itself and SHARED).
	 * `Plugin` is the root class in the top namespace.
	 *
	 * @var array<string,list<string>>
	 */
	private const ALLOW = [
		'Contracts' => [],
		'Concerns'  => [],
		'Support'   => [ 'Database' ],
		'Database'  => [ 'Support' ],
		'Catalog'   => [ 'Support', 'Database' ],
		'Orders'    => [ 'Support', 'Database', 'Inventory' ],
		'Inventory' => [ 'Catalog', 'Database', 'Support' ],
		'Pricing'   => [ 'Catalog', 'Tax', 'Support' ],
		'Shipping'  => [ 'Support' ],
		'Tax'       => [ 'Support', 'Database' ],
		'Payments'  => [ 'Orders', 'Database', 'Support' ],
		'Cart'      => [ 'Catalog', 'Pricing', 'Inventory', 'Shipping', 'Coupons', 'Options', 'Support', 'Database' ],
		// Coupons reads cart lines (CartItem) to price a discount; Cart asks
		// Coupons for the discount. CartItem is a plain value, so the cycle
		// is only in types.
		'Coupons'   => [ 'Cart', 'Catalog', 'Pricing', 'Support', 'Database' ],
		'Options'   => [ 'Catalog', 'Inventory', 'Support' ],
		'Customers' => [ 'Support' ],
		'Checkout'  => [ 'Cart', 'Coupons', 'Pricing', 'Inventory', 'Shipping', 'Tax', 'Orders', 'Payments', 'Customers', 'Support', 'Database' ],
	];

	/**
	 * Deny-lists for the outer layers, which may use any service module.
	 *
	 * @var array<string,list<string>>
	 */
	private const DENY = [
		'Api'         => [ 'Admin', 'Cli', 'Storefront' ],
		'Admin'       => [ 'Api', 'Cli', 'Storefront' ],
		'Cli'         => [ 'Api', 'Admin', 'Storefront' ],
		'Storefront'  => [ 'Api', 'Admin', 'Cli' ],
		'Setup'       => [ 'Api', 'Admin', 'Cli', 'Storefront' ],
		'Maintenance' => [ 'Api', 'Admin', 'Cli', 'Storefront' ],
		'Migration'   => [ 'Checkout', 'Cart', 'Notifications', 'Api', 'Admin', 'Storefront' ],
		'Privacy'     => [ 'Api', 'Admin', 'Cli', 'Storefront', 'Notifications', 'Migration' ],
	];

	/**
	 * Single classes a module may use although their module is denied. The
	 * checkout layout is the builder's stored settings, not the checkout
	 * flow, and the migration imports fields into it (ADR-036).
	 *
	 * @var array<string,list<string>>
	 */
	private const EXCEPT = [
		'Migration' => [ 'Checkout\\CheckoutLayout' ],
	];

	public function test_modules_respect_the_dependency_table(): void {
		$violations = [];
		foreach ( self::files() as $file ) {
			$module = self::module_of( $file );
			if ( $module === null ) {
				continue;
			}
			$code = (string) file_get_contents( $file );
			foreach ( self::EXCEPT[ $module ] ?? [] as $class ) {
				$code = (string) preg_replace( '/\\\\?' . preg_quote( self::ROOT . $class, '/' ) . '\\b/', '', $code );
			}
			foreach ( self::references( $code ) as $target ) {
				if ( ! self::allowed( $module, $target ) ) {
					$violations[] = sprintf( '%s (%s) uses %s', self::relative( $file ), $module, $target );
				}
			}
		}
		self::assertSame( [], $violations, "Dependency rule violations (doc 02 §3.2):\n" . implode( "\n", $violations ) );
	}

	public function test_sql_lives_only_in_the_data_layer(): void {
		$offenders = [];
		foreach ( self::files() as $file ) {
			$rel = self::relative( $file );
			if ( str_starts_with( $rel, 'Database/' ) || str_ends_with( $rel, 'Repository.php' ) ) {
				continue;
			}
			$code = (string) file_get_contents( $file );
			if ( preg_match( '/\$wpdb\s*->\s*(query|get_var|get_row|get_col|get_results|insert|update|delete|replace)\s*\(/', $code ) === 1 ) {
				$offenders[] = $rel;
			}
		}
		self::assertSame( [], $offenders, 'Direct $wpdb queries outside Database/ or *Repository.php' );
	}

	public function test_rule_engine_rejects_a_known_bad_reference(): void {
		self::assertFalse( self::allowed( 'Contracts', 'Support' ) );
		self::assertFalse( self::allowed( 'Support', 'Plugin' ) );
		self::assertFalse( self::allowed( 'Api', 'Admin' ) );
		self::assertTrue( self::allowed( 'Api', 'Support' ) );
		self::assertTrue( self::allowed( 'Support', 'Database' ) );
	}

	private static function allowed( string $module, string $target ): bool {
		if ( $target === $module || in_array( $target, self::SHARED, true ) ) {
			return true;
		}
		if ( isset( self::ALLOW[ $module ] ) ) {
			return in_array( $target, self::ALLOW[ $module ], true );
		}
		if ( isset( self::DENY[ $module ] ) ) {
			return ! in_array( $target, self::DENY[ $module ], true );
		}
		return true; // Plugin root and modules without a rule yet.
	}

	/** @return list<string> top-level module names this file references */
	private static function references( string $code ): array {
		$found  = [];
		$quoted = preg_quote( self::ROOT, '/' );
		if ( preg_match_all( '/\\\\?' . $quoted . '([A-Za-z_]+)(?:\\\\|\b)/', $code, $m ) > 0 ) {
			foreach ( $m[1] as $name ) {
				$found[ $name ] = true;
			}
		}
		unset( $found['Tests'] );
		return array_keys( $found );
	}

	private static function module_of( string $file ): ?string {
		$rel = self::relative( $file );
		if ( ! str_contains( $rel, '/' ) ) {
			return null; // Plugin.php
		}
		return strstr( $rel, '/', true ) ?: null;
	}

	/** @return list<string> */
	private static function files(): array {
		$out = [];
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::src(), \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $info ) {
			if ( $info instanceof \SplFileInfo && $info->getExtension() === 'php' ) {
				$out[] = $info->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	private static function relative( string $file ): string {
		return substr( $file, strlen( self::src() ) + 1 );
	}

	private static function src(): string {
		return dirname( __DIR__, 3 ) . '/src';
	}
}
