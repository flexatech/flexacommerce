<?php
/**
 * Measurement mu-plugin for the benchmark stack (doc 10 §5.3). Never shipped.
 *
 * Writes one JSON line per request to /var/log/bench/php.jsonl, keyed by the
 * nginx request id. CPU time covers the request from this file on; the
 * part of the boot before mu-plugins load is the same for every store.
 */

declare(strict_types=1);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}

if ( defined( 'BENCH_HOST' ) && BENCH_HOST === 'core.bench' ) {
	add_filter( 'option_active_plugins', '__return_empty_array', PHP_INT_MAX );
}

// Mail servers stay out of the numbers; the email rendering cost stays in.
add_filter( 'pre_wp_mail', '__return_true' );
// Checkout runs with deferred order emails in both stores (doc 10 §4.2 P6);
// FlexaCommerce reads its `defer_emails` setting, set by prep-checkout.php.
add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );
// Every k6 request comes from one IP. FlexaCommerce limits checkouts per IP
// (doc 06 §2.9), WooCommerce's Store API rate limiting is off by default, so
// the bench turns ours off too. The per-session limit stays: each iteration
// is a new guest.
add_filter( 'flexacommerce.rate_limit.checkout_ip', static fn (): array => [ 'limit' => 0 ] );

$bench_rusage = getrusage();

register_shutdown_function(
	static function () use ( $bench_rusage ): void {
		$now = getrusage();
		$cpu = static fn ( array $r ): float => $r['ru_utime.tv_sec'] * 1e3 + $r['ru_utime.tv_usec'] / 1e3
			+ $r['ru_stime.tv_sec'] * 1e3 + $r['ru_stime.tv_usec'] / 1e3;

		$line = [
			'id'        => (string) ( $_SERVER['HTTP_X_REQUEST_ID'] ?? '' ),
			'run'       => (string) ( $_SERVER['HTTP_X_BENCH_RUN'] ?? '' ),
			'journey'   => (string) ( $_SERVER['HTTP_X_BENCH_JOURNEY'] ?? '' ),
			'ms'        => round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1e3, 3 ),
			'cpu_ms'    => round( $cpu( $now ) - $cpu( $bench_rusage ), 3 ),
			'queries'   => isset( $GLOBALS['wpdb'] ) ? (int) $GLOBALS['wpdb']->num_queries : 0,
			'peak'      => memory_get_peak_usage(),
			'peak_real' => memory_get_peak_usage( true ),
			'status'    => http_response_code(),
		];
		file_put_contents( '/var/log/bench/php.jsonl', json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
	}
);
