<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * The id map seen from one job: source ids in, target ids out. The table
 * always keeps the WooCommerce id as remote_id, so lookups flip with the
 * direction.
 */
final class Mapping {
	/** @var array<string,?IdMapRow> */
	private array $cache = [];

	public function __construct(
		private readonly IdMapRepository $map,
		private readonly string $direction,
		private readonly ?int $job_id,
		private readonly \Closure $now,
	) {}

	public function direction(): string {
		return $this->direction;
	}

	public function row( string $entity, int|string $source_id ): ?IdMapRow {
		$key = $entity . ':' . $source_id;
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = $this->direction === Direction::WC_TO_FXC
				? $this->map->by_remote( $entity, (string) $source_id )
				: $this->map->by_local( $entity, (int) $source_id );
		}
		return $this->cache[ $key ];
	}

	/** Target id for a source id, 0 when the entity was not imported. */
	public function target_id( string $entity, int|string $source_id ): int {
		$row = $this->row( $entity, $source_id );
		if ( $row === null ) {
			return 0;
		}
		return $this->direction === Direction::WC_TO_FXC ? $row->local_id : (int) $row->remote_id;
	}

	public function remember( string $entity, int|string $source_id, int $target_id, string $checksum = '' ): void {
		[ $remote, $local ] = $this->direction === Direction::WC_TO_FXC
			? [ (string) $source_id, $target_id ]
			: [ (string) $target_id, (int) $source_id ];
		$now                = ( $this->now )();
		$row                = $this->row( $entity, $source_id );
		if ( $row !== null ) {
			$this->map->update( $row->id, $remote, $local, $checksum, $this->job_id, $now );
		} else {
			$this->map->insert( $entity, $remote, $local, Direction::source( $this->direction ), $checksum, $this->job_id, $now );
		}
		unset( $this->cache[ $entity . ':' . $source_id ] );
	}

	public function forget( string $entity, int|string $source_id ): void {
		$row = $this->row( $entity, $source_id );
		if ( $row !== null ) {
			$this->map->delete( $row->id );
		}
		unset( $this->cache[ $entity . ':' . $source_id ] );
	}

	/** Drop cached rows, after a rolled back transaction or between batches. */
	public function reset(): void {
		$this->cache = [];
	}
}
