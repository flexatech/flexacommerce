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
	 * @return array{ms:float,peak:int,queries:list<array{sql:string,caller:string}>,classes:int,own:list<string>,callbacks:int,assets:list<string>,autoload:array<string,int>,html:int,status:int|bool}
	 */
	public static function run( string $uri, bool $plugin ): array {
		$out = (string) tempnam( sys_get_temp_dir(), 'fxc-probe' );
		$cmd = array_merge(
			[ PHP_BINARY, '-d', 'display_errors=0', '-d', 'memory_limit=256M' ],
			self::opcache_flags(),
			[ __DIR__ . '/probe-request.php', $uri, $plugin ? 'on' : 'off', $out ]
		);

		$process = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
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
		/** @var array{ms:float,peak:int,queries:list<array{sql:string,caller:string}>,classes:int,own:list<string>,callbacks:int,assets:list<string>,autoload:array<string,int>,html:int,status:int|bool} $result */
		return $result;
	}

	/**
	 * One warm-up run per mode first: the first request after a change writes
	 * cron and theme transients, which would land in whichever mode ran first.
	 * Then the smaller of two runs per mode, which drops one-off allocator noise.
	 *
	 * @return array{on:array<string,mixed>,off:array<string,mixed>}
	 */
	public static function compare( string $uri ): array {
		self::run( $uri, true );
		self::run( $uri, false );
		$pick = static function ( array $a, array $b ): array {
			return $a['peak'] <= $b['peak'] ? $a : $b;
		};
		return [
			'on'  => $pick( self::run( $uri, true ), self::run( $uri, true ) ),
			'off' => $pick( self::run( $uri, false ), self::run( $uri, false ) ),
		];
	}

	public static function has_opcache(): bool {
		return extension_loaded( 'Zend OPcache' );
	}

	/** @return list<string> */
	private static function opcache_flags(): array {
		return self::has_opcache()
			? [ '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=0' ]
			: [];
	}
}
