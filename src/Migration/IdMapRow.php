<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/** One fxc_id_map row. `remote_id` is the WooCommerce id, `local_id` the FlexaCommerce id. */
final class IdMapRow {
	public function __construct(
		public readonly int $id,
		public readonly string $entity,
		public readonly string $remote_id,
		public readonly int $local_id,
		public readonly string $origin,
		public readonly string $checksum,
		public readonly ?int $job_id,
		public readonly string $created_at,
		public readonly string $updated_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			entity: (string) $row['entity'],
			remote_id: (string) $row['remote_id'],
			local_id: (int) $row['local_id'],
			origin: (string) $row['origin'],
			checksum: (string) $row['checksum'],
			job_id: isset( $row['job_id'] ) ? (int) $row['job_id'] : null,
			created_at: (string) $row['created_at'],
			updated_at: (string) $row['updated_at'],
		);
	}

	/** The id on one side: 'wc' gives remote_id, 'fxc' gives local_id. */
	public function id_on( string $system ): string {
		return $system === 'wc' ? $this->remote_id : (string) $this->local_id;
	}
}
