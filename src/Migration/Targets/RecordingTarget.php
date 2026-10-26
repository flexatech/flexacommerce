<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/** Dry-run target (doc 05 §4.5): reports what would happen, writes nothing. */
final class RecordingTarget implements TargetAdapter {
	public function __construct( private readonly TargetAdapter $inner ) {}

	public function system(): string {
		return $this->inner->system();
	}

	public function write( Record $record, Mapping $map, Job $job ): WriteResult {
		unset( $job );
		$target = $map->target_id( $record->type, $record->source_id() );
		$action = $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED;
		return new WriteResult( $action, $target, [ Problem::info( 'dry_run', sprintf( 'Would be %s.', $action ) ) ] );
	}

	public function modified_at( string $entity, int $target_id ): ?string {
		return $this->inner->modified_at( $entity, $target_id );
	}

	public function finish_stage( string $stage, Job $job ): void {
		unset( $stage, $job );
	}

	public function delete( string $entity, int $target_id ): bool {
		unset( $entity, $target_id );
		return false;
	}

	public function preflight( Job $job, ?Record $settings ): array {
		return $this->inner->preflight( $job, $settings );
	}
}
