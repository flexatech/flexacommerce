<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\ImportRepository;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * FlexaCommerce as the target of a WC→FXC job (doc 05 §5.3, §5.4). Each
 * record and its id map rows commit in one transaction (doc 05 §8.1).
 */
final class FxcTarget implements TargetAdapter {
	public function __construct(
		private readonly Transaction $transaction,
		private readonly FxcStoreWriter $store,
		private readonly FxcCatalogWriter $catalog,
		private readonly FxcOrderWriter $orders,
		private readonly ImportRepository $repository,
		private readonly FxcShippingWriter $shipping,
		private readonly FxcCouponWriter $coupons,
	) {}

	public function system(): string {
		return 'fxc';
	}

	public function write( Record $record, Mapping $map, Job $job ): WriteResult {
		return $this->transaction->run(
			fn (): WriteResult => match ( $record->type ) {
				Record::SETTINGS  => $this->store->settings( $record, $map ),
				Record::TAX_RATE  => $this->store->tax_rate( $record, $map ),
				Record::SHIPPING  => $this->shipping->shipping( $record, $map ),
				Record::CUSTOMER  => $this->store->customer( $record, $map ),
				Record::ATTRIBUTE => $this->catalog->attribute( $record, $map ),
				Record::TERM      => $this->catalog->term( $record, $map ),
				Record::PRODUCT   => $this->catalog->product( $record, $map, $job ),
				Record::ORDER     => $this->orders->order( $record, $map, $job ),
				Record::COUPON    => $this->coupons->coupon( $record, $map ),
				default           => throw new Rejected( 'unsupported_entity', sprintf( 'FlexaCommerce cannot import "%s" records.', $record->type ) ),
			}
		);
	}

	public function modified_at( string $entity, int $target_id ): ?string {
		return match ( $entity ) {
			Record::PRODUCT => $this->catalog->modified_at( $target_id ),
			Record::ORDER   => $this->orders->modified_at( $target_id ),
			Record::COUPON  => $this->coupons->modified_at( $target_id ),
			default         => null,
		};
	}

	public function finish_stage( string $stage, Job $job ): void {
		// Either stage can run last, and orders can be imported without their coupons.
		if ( ( $stage === 'coupons' || $stage === 'orders' ) && ! $job->dry_run ) {
			$this->coupons->backfill_uses();
		}
	}

	public function delete( string $entity, int $target_id ): bool {
		return $this->transaction->run(
			fn (): bool => match ( $entity ) {
				Record::ORDER => $this->orders->delete( $target_id ),
				Record::COUPON => $this->coupons->delete( $target_id ),
				Record::PRODUCT, Record::TERM, Record::ATTRIBUTE => $this->catalog->delete( $entity, $target_id ),
				default => false,
			}
		);
	}

	public function preflight( Job $job, ?Record $settings ): array {
		$data     = $settings->data ?? [];
		$problems = [];
		$currency = strtoupper( (string) ( $data['currency'] ?? '' ) );
		if ( $currency !== '' && in_array( 'orders', $job->stages(), true ) ) {
			$other = array_diff_key( $this->repository->order_currencies(), [ $currency => true ] );
			if ( $other !== [] ) {
				$problems[] = Problem::error(
					'currency_conflict',
					sprintf( 'FlexaCommerce already has orders in %1$s; the source store uses %2$s.', implode( ', ', array_keys( $other ) ), $currency ),
					[ 'currencies' => $other ]
				);
			}
		}
		// Settings imported in this job replace the target ones, so the flags only differ when that stage is left out.
		if ( ! in_array( 'settings', $job->stages(), true ) ) {
			if ( isset( $data['prices_include_tax'] ) && (bool) $data['prices_include_tax'] !== Settings::bool( 'prices_include_tax' ) ) {
				$problems[] = Problem::warning( 'prices_include_tax', 'The two stores disagree on whether prices include tax. Imported orders keep their own flag; new orders follow the FlexaCommerce setting.' );
			}
			if ( ! empty( $data['tax_enabled'] ) && ! Settings::bool( 'tax_enabled' ) ) {
				$problems[] = Problem::warning( 'tax_disabled', 'Taxes are on in the source and off in FlexaCommerce. Rates are imported; taxes stay off until you enable them.' );
			}
		}
		return $problems;
	}
}
