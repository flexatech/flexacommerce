<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Database\Integrity;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only health report shared by `wp flexacommerce doctor` and the
 * Status screen. Checks grow with each phase.
 */
final class Doctor {
	public function __construct( private readonly Integrity $integrity ) {}

	/**
	 * @return list<array{id:string,label:string,status:string,detail:string}>
	 */
	public function run(): array {
		$results = [];

		$missing   = Schema::missing_tables();
		$results[] = [
			'id'     => 'tables',
			'label'  => __( 'All tables present', 'flexacommerce' ),
			'status' => $missing === [] ? 'ok' : 'error',
			'detail' => implode( ', ', $missing ),
		];

		$non_innodb = Schema::non_innodb_tables();
		$results[]  = [
			'id'     => 'innodb',
			'label'  => __( 'Tables use InnoDB', 'flexacommerce' ),
			'status' => $non_innodb === [] ? 'ok' : 'error',
			'detail' => implode( ', ', $non_innodb ),
		];

		$results[] = [
			'id'     => 'schema_version',
			'label'  => __( 'Schema version matches the plugin', 'flexacommerce' ),
			'status' => Schema::needs_upgrade() || Schema::is_newer_than_code() ? 'warning' : 'ok',
			'detail' => sprintf( '%d / %s', Schema::installed_version(), Schema::DB_VERSION ),
		];

		if ( $missing === [] ) {
			foreach ( $this->integrity->orphans() as $id => $check ) {
				$results[] = [
					'id'     => $id,
					'label'  => $check['label'],
					'status' => $check['count'] === 0 ? 'ok' : 'warning',
					'detail' => (string) $check['count'],
				];
			}
		}

		/** @var list<array{id:string,label:string,status:string,detail:string}> */
		return apply_filters( 'flexacommerce.doctor.results', $results );
	}
}
