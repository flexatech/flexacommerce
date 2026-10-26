<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Job;

defined( 'ABSPATH' ) || exit;

/** Reads one store as canonical records, keyset by integer id (doc 05 §4.4). */
interface SourceAdapter {
	/** 'wc' or 'fxc', the system part of every record id. */
	public function system(): string;

	/** Highest id of a stage when the job starts; later entities wait for the next run. */
	public function upper_id( string $stage, Job $job ): int;

	/** @return list<int> ids after `$after`, up to `$upper`, ascending */
	public function ids( string $stage, int $after, int $upper, int $limit, Job $job ): array;

	/**
	 * One entity as a canonical record, null when it no longer exists.
	 *
	 * @throws \Flexa\Commerce\Migration\Canonical\Rejected When the entity cannot be expressed.
	 */
	public function read( string $stage, int $id, Job $job ): ?Record;

	/** A dependency by reference (`wc:term:product_cat:12`), null when unknown. */
	public function read_ref( string $ref, Job $job ): ?Record;

	public function count( string $stage, Job $job ): int;

	/** @return list<Problem> */
	public function preflight( Job $job ): array;
}
