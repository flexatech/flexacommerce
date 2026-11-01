<?php
/**
 * Browser tests on e2e.bench only (tests/e2e). Never shipped.
 *
 * Each Playwright project sends `X-FXC-E2E-Theme`, so one store serves the
 * same catalog under Twenty Twenty-Five, Astra and Twenty Twenty-One without
 * switching the active theme between projects.
 */

declare(strict_types=1);

if ( ! defined( 'BENCH_HOST' ) || BENCH_HOST !== 'e2e.bench' ) {
	return;
}

$e2e_theme = (string) ( $_SERVER['HTTP_X_FXC_E2E_THEME'] ?? '' );
if ( in_array( $e2e_theme, [ 'twentytwentyfive', 'astra', 'twentytwentyone' ], true ) ) {
	add_filter( 'pre_option_template', static fn (): string => $e2e_theme );
	add_filter( 'pre_option_stylesheet', static fn (): string => $e2e_theme );
}

// The tests open many order pages from one address; bench-measure.php
// already turns off the per-IP checkout limit.
add_filter( 'flexacommerce.rate_limit.order_key_ip', static fn (): array => [ 'limit' => 0 ] );

// None of the three themes has the mini cart in its header; a store adds the
// block there. The tests need it on every page, so it opens the body instead.
add_action(
	'wp_body_open',
	static function (): void {
		echo '<div class="e2e-header">' . do_blocks( '<!-- wp:flexacommerce/mini-cart /-->' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block markup.
	}
);

// Cart reminders (ADR-063) wait for a quiet cart and the cron. A request with
// `X-FXC-E2E-Recovery: <email>` makes that address's carts two hours old,
// runs the cron with mail accepted unsent, and answers with the link key.
$e2e_recovery = sanitize_email( (string) ( $_SERVER['HTTP_X_FXC_E2E_RECOVERY'] ?? '' ) );
if ( $e2e_recovery !== '' ) {
	add_action(
		'init',
		static function () use ( $e2e_recovery ): void {
			global $wpdb;
			$sessions = \Flexa\Commerce\Database\Schema::table( 'sessions' );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = updated_at - INTERVAL 2 HOUR WHERE email = %s', $sessions, $e2e_recovery ) );
			add_filter( 'pre_wp_mail', '__return_true' );
			\Flexa\Commerce\Plugin::instance()->recovery()->run();
			$rows = \Flexa\Commerce\Plugin::instance()->recovery()->repository()->for_email( $e2e_recovery );
			$last = end( $rows );
			wp_send_json( [ 'key' => $last !== false ? $last->key() : null ] );
		},
		30
	);
}

// Back-in-stock emails (ADR-065) wait for a restock and the cron. A request
// with `X-FXC-E2E-Restock: <product id>` adds five to every variant of the
// product that has none, runs the cron with mail accepted unsent, and
// answers with who was emailed and the stop link key of each. The stock
// goes back to none after, so the next theme's run finds it sold out again.
$e2e_restock = absint( $_SERVER['HTTP_X_FXC_E2E_RESTOCK'] ?? 0 );
if ( $e2e_restock > 0 ) {
	add_action(
		'init',
		static function () use ( $e2e_restock ): void {
			$plugin  = \Flexa\Commerce\Plugin::instance();
			$product = $plugin->catalog()->products()->find( $e2e_restock );
			if ( $product === null ) {
				wp_send_json( [ 'sent' => [] ], 404 );
			}
			$restocked = [];
			foreach ( $product->sellable_rows() as $row ) {
				if ( $row->manage_stock && (int) $row->stock_quantity <= 0 ) {
					$plugin->cart()->stock()->adjust( $product, $row, 5, 'e2e' );
					$restocked[] = $row;
				}
			}
			$mails = [];
			add_filter(
				'pre_wp_mail',
				static function ( $result, array $atts ) use ( &$mails ) {
					$mails[] = (string) $atts['subject'];
					return true;
				},
				10,
				2
			);
			$plugin->stock_alerts()->run();
			foreach ( $restocked as $row ) {
				$plugin->cart()->stock()->adjust( $product, $row, -5, 'e2e' );
			}
			$sent = [];
			foreach ( $plugin->stock_alerts()->repository()->page( 'sent', $product->id, 1, 50 )['rows'] as $alert ) {
				$sent[] = [
					'email' => $alert->email,
					'key'   => $alert->key(),
				];
			}
			wp_send_json(
				[
					'sent'     => $sent,
					'subjects' => $mails,
				]
			);
		},
		30
	);
}
