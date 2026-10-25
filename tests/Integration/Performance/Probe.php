<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Performance;

/**
 * Runs probe-request.php in a fresh PHP process, so boot cost is inside the measured
 * window (doc 10 F3). OPcache is enabled when the extension is available,
 * so compiled code is not counted as request memory, as in production.
 */
final class Probe {
	/**
	 * @param array<string,mixed> $options see probe-request.php
	 * @return array{ms:float,peak:int,queries:list<array{sql:string,caller:string}>,classes:int,own:list<string>,callbacks:int,assets:list<string>,autoload:array<string,int>,html:int,status:int|bool,state:int,nocache:bool,headers:array<string,string>,rest:mixed}
	 */
	public static function run( string $uri, bool $plugin, array $options = [] ): array {
		$out = (string) tempnam( sys_get_temp_dir(), 'fxc-probe' );
		$cmd = array_merge(
			[ PHP_BINARY, '-d', 'display_errors=0', '-d', 'memory_limit=256M' ],
			self::opcache_flags(),
			[ __DIR__ . '/probe-request.php', $uri, $plugin ? 'on' : 'off', $out, (string) json_encode( (object) $options ) ]
		);

		$process = proc_open(
			$cmd,
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start the probe process.' );
		}
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$raw = (string) file_get_contents( $out );
		unlink( $out );
		$result = json_decode( $raw, true );
		if ( ! is_array( $result ) ) {
			throw new \RuntimeException( "Probe for {$uri} produced no result. stderr: {$stderr}" );
		}
		/** @var array{ms:float,peak:int,queries:list<array{sql:string,caller:string}>,classes:int,own:list<string>,callbacks:int,assets:list<string>,autoload:array<string,int>,html:int,status:int|bool,state:int,nocache:bool,headers:array<string,string>,rest:mixed} $result */
		return $result;
	}

	/**
	 * One warm-up run per mode first: the first request after a change writes
	 * cron and theme transients, which would land in whichever mode ran first.
	 * Then the smaller of two runs per mode, which drops one-off allocator noise.
	 *
	 * @param array<string,mixed> $options see probe-request.php
	 * @return array{on:array<string,mixed>,off:array<string,mixed>}
	 */
	public static function compare( string $uri, array $options = [] ): array {
		self::run( $uri, true, $options );
		self::run( $uri, false, $options );
		$pick = static function ( array $a, array $b ): array {
			return $a['peak'] <= $b['peak'] ? $a : $b;
		};
		return [
			'on'  => $pick( self::run( $uri, true, $options ), self::run( $uri, true, $options ) ),
			'off' => $pick( self::run( $uri, false, $options ), self::run( $uri, false, $options ) ),
		];
	}

	public static function has_opcache(): bool {
		return extension_loaded( 'Zend OPcache' );
	}

	/** @return list<string> */
	private static function opcache_flags(): array {
		if ( ! self::has_opcache() ) {
			return [];
		}
		// Each probe is a new process, so without a file cache every file is
		// compiled again and the compiler's memory lands in the peak; a code
		// change then reads as request memory. The warm-up run in compare()
		// fills the file cache, as a warm OPcache would be in production.
		$cache = sys_get_temp_dir() . '/fxc-probe-opcache';
		if ( ! is_dir( $cache ) ) {
			mkdir( $cache, 0700, true );
		}
		return [ '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=0', '-d', 'opcache.file_cache=' . $cache, '-d', 'opcache.validate_timestamps=1' ];
	}
}
