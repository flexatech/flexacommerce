<?php
/**
 * Summarizes a bench.sh run directory (doc 10 §5.3, §6.5). Writes
 * summary.json and summary.md next to the raw data.
 *
 *   php tools/bench/summarize.php tools/bench/out/runs/<stamp>
 */

declare(strict_types=1);

$dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( ! is_dir( $dir ) ) {
	fwrite( STDERR, "Usage: php summarize.php <run dir>\n" );
	exit( 2 );
}

/** @return list<array<string,mixed>> */
function jsonl( string $file ): array {
	$rows = [];
	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
		$row = json_decode( $line, true );
		if ( is_array( $row ) ) {
			$rows[] = $row;
		}
	}
	return $rows;
}

/** Nearest-rank percentile. @param list<float|int> $values */
function pct( array $values, float $p ): float {
	if ( $values === [] ) {
		return NAN;
	}
	sort( $values );
	return (float) $values[ max( 0, (int) ceil( $p / 100 * count( $values ) ) - 1 ) ];
}

/** @param list<float|int> $values */
function median( array $values ): float {
	return pct( $values, 50 );
}

/** @return array<string,int> */
function status_file( string $file ): array {
	$out = [];
	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
		$parts = preg_split( '/\s+/', trim( $line ) );
		if ( count( $parts ) === 2 ) {
			$out[ $parts[0] ] = (int) $parts[1];
		}
	}
	return $out;
}

/** @return list<int> */
function rss_file( string $file ): array {
	return array_map( 'intval', file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] );
}

// One entry per <scenario>.<store>.<run> directory.
$runs = [];
foreach ( glob( $dir . '/*.*.*', GLOB_ONLYDIR ) ?: [] as $run_dir ) {
	[ $scenario, $store, $run ] = explode( '.', basename( $run_dir ) );
	$label = basename( $run_dir );

	$php = [];
	foreach ( jsonl( $run_dir . '/php.jsonl' ) as $row ) {
		if ( $row['run'] === $label ) {
			$php[ $row['id'] ] = $row;
		}
	}
	$upstream = [];
	$php_ms   = [];
	$queries  = [];
	$peak     = [];
	$cpu      = [];
	$errors   = 0;
	$requests = 0;
	foreach ( jsonl( $run_dir . '/nginx.jsonl' ) as $row ) {
		if ( $row['run'] !== $label ) {
			continue;
		}
		++$requests;
		// 201 is the success status of an add-to-cart request.
		if ( (int) $row['status'] < 200 || (int) $row['status'] > 201 ) {
			++$errors;
		}
		$upstream[] = (float) $row['upstream'] * 1000;
		$entry      = $php[ $row['id'] ] ?? null;
		if ( $entry !== null ) {
			$php_ms[]  = (float) $entry['ms'];
			$queries[] = (int) $entry['queries'];
			$peak[]    = (int) $entry['peak'];
			$cpu[]     = (float) $entry['cpu_ms'];
		}
	}

	$before = status_file( $run_dir . '/mysql-before.txt' );
	$after  = status_file( $run_dir . '/mysql-after.txt' );
	$rows   = isset( $before['Innodb_rows_read'], $after['Innodb_rows_read'] ) ? $after['Innodb_rows_read'] - $before['Innodb_rows_read'] : null;
	$rss    = rss_file( $run_dir . '/rss-after.txt' );

	$runs[ $scenario ][ $store ][ (int) $run ] = [
		'requests'      => $requests,
		'joined'        => count( $php_ms ),
		'error_rate'    => $requests > 0 ? (float) $errors / $requests : 1.0,
		'p50'           => pct( $upstream, 50 ),
		'p95'           => pct( $upstream, 95 ),
		'p99'           => pct( $upstream, 99 ),
		'php_p50'       => pct( $php_ms, 50 ),
		'php_p95'       => pct( $php_ms, 95 ),
		'queries'       => median( $queries ),
		'peak_kb'       => median( $peak ) / 1024,
		'cpu_ms'        => median( $cpu ),
		'rows_per_req'  => $rows !== null && $requests > 0 ? $rows / $requests : null,
		'rss_kb_median' => $rss !== [] ? median( $rss ) : null,
		'rss_kb_max'    => $rss !== [] ? max( $rss ) : null,
		'html_bytes'    => is_file( $run_dir . '/page.html' ) ? filesize( $run_dir . '/page.html' ) : null,
	];
}
ksort( $runs );

$summary = [];
foreach ( $runs as $scenario => $stores ) {
	foreach ( $stores as $store => $per_run ) {
		ksort( $per_run );
		$col = static fn ( string $key ): array => array_values( array_filter( array_column( $per_run, $key ), static fn ( $v ): bool => $v !== null ) );
		$p95 = $col( 'p95' );
		$mid = median( $p95 );
		$out = [ 'runs' => count( $per_run ) ];
		foreach ( [ 'p50', 'p95', 'p99', 'php_p50', 'php_p95', 'queries', 'peak_kb', 'cpu_ms', 'rows_per_req', 'rss_kb_median', 'rss_kb_max', 'html_bytes' ] as $key ) {
			$out[ $key ] = $col( $key ) !== [] ? median( $col( $key ) ) : null;
		}
		$out['error_rate'] = (float) max( $col( 'error_rate' ) );
		$out['spread']     = $mid > 0 ? ( max( $p95 ) - min( $p95 ) ) / $mid : null;
		$out['valid']      = $out['spread'] !== null && $out['spread'] <= 0.10 && $out['error_rate'] === 0.0
			&& min( $col( 'requests' ) ) === max( $col( 'joined' ) );
		$out['per_run']    = $per_run;
		$summary[ $scenario ][ $store ] = $out;
	}
}

file_put_contents( $dir . '/summary.json', json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

$fmt = static fn ( $v, int $d = 1 ): string => $v === null || ( is_float( $v ) && is_nan( $v ) ) ? 'n/a' : number_format( (float) $v, $d, '.', '' );
$md  = "| Scenario | Store | p50 ms | p95 ms | p99 ms | PHP p50 / p95 ms | Spread | Errors | Queries | Peak KB | CPU ms | Rows/req | FPM RSS KB (median / max) | HTML bytes | Valid |\n";
$md .= "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";
foreach ( $summary as $scenario => $stores ) {
	foreach ( [ 'core', 'fxc', 'wc' ] as $store ) {
		if ( ! isset( $stores[ $store ] ) ) {
			continue;
		}
		$s   = $stores[ $store ];
		$md .= sprintf(
			"| %s | %s | %s | %s | %s | %s / %s | %s %% | %s %% | %s | %s | %s | %s | %s / %s | %s | %s |\n",
			$scenario,
			$store,
			$fmt( $s['p50'] ),
			$fmt( $s['p95'] ),
			$fmt( $s['p99'] ),
			$fmt( $s['php_p50'] ),
			$fmt( $s['php_p95'] ),
			$fmt( $s['spread'] * 100 ),
			$fmt( $s['error_rate'] * 100 ),
			$fmt( $s['queries'], 0 ),
			$fmt( $s['peak_kb'], 0 ),
			$fmt( $s['cpu_ms'] ),
			$fmt( $s['rows_per_req'], 0 ),
			$fmt( $s['rss_kb_median'], 0 ),
			$fmt( $s['rss_kb_max'], 0 ),
			$fmt( $s['html_bytes'], 0 ),
			$s['valid'] ? 'yes' : 'no'
		);
	}
}
file_put_contents( $dir . '/summary.md', $md );
echo $md;
