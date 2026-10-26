<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Sources\SourceAdapter;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * The verify report (doc 05 §7.3): counts per stage, then a sample of
 * orders read again from the source and compared field by field with what
 * the target holds.
 */
final class Verifier {
	public const SAMPLE = 100;

	/**
	 * @param \Closure(int):(array{order_number:string,status:string,currency:string,total:int,tax_total:int,amount_refunded:int,product_lines:int}|null) $target_order
	 *        summary of a target order by target id
	 */
	public function __construct(
		private readonly IdMapRepository $ids,
		private readonly Clock $clock,
		private readonly \Closure $target_order,
	) {}

	/** @return array<string,mixed> */
	public function verify( Job $job, SourceAdapter $source ): array {
		$stages = [];
		foreach ( $job->stages() as $stage ) {
			$stages[ $stage ] = [
				'source' => $source->count( $stage, $job ),
				'mapped' => $this->ids->count( Job::entity( $stage ) ),
			] + ( $job->counters[ $stage ] ?? [] );
		}
		$mismatches = [];
		$checked    = 0;
		if ( in_array( 'orders', $job->stages(), true ) && ! $job->dry_run ) {
			foreach ( $this->ids->all( Record::ORDER, 0, self::SAMPLE ) as $row ) {
				$source_id = $row->id_on( $job->source() );
				$target_id = (int) $row->id_on( $job->target() );
				$record    = $source->read( 'orders', (int) $source_id, $job );
				$target    = ( $this->target_order )( $target_id );
				if ( $record === null ) {
					continue;
				}
				++$checked;
				if ( $target === null ) {
					$mismatches[] = self::mismatch( $source_id, $target_id, 'exists', true, false );
					continue;
				}
				foreach ( self::expected( $record ) as $field => $value ) {
					if ( $target[ $field ] !== $value ) {
						$mismatches[] = self::mismatch( $source_id, $target_id, $field, $value, $target[ $field ] );
					}
				}
			}
		}
		return [
			'stages'      => $stages,
			'checked'     => $checked,
			'mismatches'  => $mismatches,
			'verified_at' => $this->clock->db_now(),
		];
	}

	/**
	 * Fields of the canonical order the target must match, in minor units.
	 *
	 * @return array{currency:string,total:int,tax_total:int,amount_refunded:int,product_lines:int}
	 */
	public static function expected( Record $record ): array {
		$data     = $record->data;
		$currency = strtoupper( (string) ( $data['currency'] ?? '' ) );
		$exponent = Currency::exponent( $currency );
		$minor    = static fn ( mixed $value ): int => Decimal::to_minor( Decimal::clean( $value ) ?? '0', $currency, $exponent )[0];
		$refunded = 0;
		$lines    = 0;
		foreach ( is_array( $data['transactions'] ?? null ) ? $data['transactions'] : [] as $txn ) {
			if ( is_array( $txn ) && ( $txn['type'] ?? '' ) === 'refund' ) {
				$refunded += abs( $minor( is_array( $txn['amount'] ?? null ) ? ( $txn['amount']['amount'] ?? '0' ) : '0' ) );
			}
		}
		foreach ( is_array( $data['items'] ?? null ) ? $data['items'] : [] as $item ) {
			$lines += is_array( $item ) && ( $item['type'] ?? '' ) === 'product' ? 1 : 0;
		}
		return [
			'currency'        => $currency,
			'total'           => $minor( $data['totals']['total'] ?? '0' ),
			'tax_total'       => $minor( $data['totals']['tax'] ?? '0' ),
			'amount_refunded' => $refunded,
			'product_lines'   => $lines,
		];
	}

	/** @return array<string,mixed> */
	private static function mismatch( string $source_id, int $target_id, string $field, mixed $expected, mixed $actual ): array {
		return [
			'entity'    => Record::ORDER,
			'source_id' => $source_id,
			'target_id' => $target_id,
			'field'     => $field,
			'expected'  => $expected,
			'actual'    => $actual,
		];
	}
}
