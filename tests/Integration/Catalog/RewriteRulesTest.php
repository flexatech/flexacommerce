<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Catalog;

use Flexa\Commerce\Catalog\PostTypes;

/**
 * The rewrite check replaces the old flush flag (doc 10 F1): it heals
 * missing rules and gives up after one flush when another plugin owns the URL.
 */
final class RewriteRulesTest extends \WP_UnitTestCase {
	private int $flushes = 0;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		// Core adds the archive rule only if permalinks are on at registration.
		PostTypes::instance()->register_types();
		flush_rewrite_rules( false );
		add_action( 'generate_rewrite_rules', [ $this, 'count_flush' ] );
	}

	public function count_flush(): void {
		++$this->flushes;
	}

	public function test_present_rule_needs_no_flush(): void {
		self::assertSame( 'index.php?post_type=' . PostTypes::PRODUCT, $this->stored_rule() );

		PostTypes::instance()->maybe_flush();

		self::assertSame( 0, $this->flushes );
	}

	public function test_missing_rule_is_restored_by_one_flush(): void {
		$this->remove_stored_rule();

		PostTypes::instance()->maybe_flush();

		self::assertSame( 1, $this->flushes );
		self::assertSame( 'index.php?post_type=' . PostTypes::PRODUCT, $this->stored_rule() );
		self::assertFalse( get_option( PostTypes::REWRITE_GUARD_OPTION ) );
	}

	public function test_url_owned_by_another_plugin_flushes_only_once(): void {
		add_filter(
			'rewrite_rules_array',
			static function ( array $rules ): array {
				return [ 'shop/?$' => 'index.php?pagename=other-plugin' ] + $rules;
			}
		);
		$this->remove_stored_rule();

		PostTypes::instance()->maybe_flush();
		PostTypes::instance()->maybe_flush();

		self::assertSame( 1, $this->flushes );
		self::assertSame( 'shop/?$', get_option( PostTypes::REWRITE_GUARD_OPTION ) );
	}

	public function test_admin_requests_do_not_flush(): void {
		$this->remove_stored_rule();
		set_current_screen( 'dashboard' );

		PostTypes::instance()->maybe_flush();

		self::assertSame( 0, $this->flushes );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		remove_action( 'generate_rewrite_rules', [ $this, 'count_flush' ] );
		parent::tear_down();
	}

	private function stored_rule(): ?string {
		$rules = (array) get_option( 'rewrite_rules' );
		$rule  = $rules['shop/?$'] ?? null;
		return is_string( $rule ) ? $rule : null;
	}

	private function remove_stored_rule(): void {
		$rules = (array) get_option( 'rewrite_rules' );
		unset( $rules['shop/?$'] );
		update_option( 'rewrite_rules', $rules );
	}
}
