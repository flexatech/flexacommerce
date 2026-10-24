<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Support;

/**
 * Records every statement. `fail_next` makes the next matching query return
 * false with the given MySQL errno, the way wpdb reports a failed query.
 */
final class FakeWpdb extends \wpdb {
	/** @var list<string> */
	public array $queries = [];

	private const MESSAGES = [
		1213 => 'Deadlock found when trying to get lock; try restarting transaction',
		1205 => 'Lock wait timeout exceeded; try restarting transaction',
		1062 => "Duplicate entry '1' for key 'PRIMARY'",
	];

	/** @var list<array{pattern:string,errno:int}> */
	private array $failures = [];

	private int $errno = 0;

	/** @var array<string,mixed> */
	public array $vars = [];

	public function fail_next( string $pattern, int $errno ): void {
		$this->failures[] = [
			'pattern' => $pattern,
			'errno'   => $errno,
		];
	}

	public function query( string $query ): int|bool {
		$this->queries[] = $query;
		foreach ( $this->failures as $i => $failure ) {
			if ( str_contains( $query, $failure['pattern'] ) ) {
				unset( $this->failures[ $i ] );
				$this->failures   = array_values( $this->failures );
				$this->errno      = $failure['errno'];
				$this->last_error = self::MESSAGES[ $failure['errno'] ] ?? 'Simulated error ' . $failure['errno'];
				return false;
			}
		}
		$this->errno      = 0;
		$this->last_error = '';
		return 1;
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		foreach ( $this->vars as $pattern => $value ) {
			if ( str_contains( $query, $pattern ) ) {
				return $value;
			}
		}
		return null;
	}

	/** @var list<array{table:string,data:array<string,mixed>}> */
	public array $inserted = [];

	/**
	 * @param array<string,mixed> $data
	 * @param list<string>|string|null $format
	 */
	public function insert( string $table, array $data, array|string|null $format = null ): int|false {
		unset( $format );
		$this->inserted[] = [
			'table' => $table,
			'data'  => $data,
		];
		return 1;
	}

	public function errno(): int {
		return $this->errno;
	}
}
