<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Orders\OrderStatus;

defined( 'ABSPATH' ) || exit;

/** FlexaCommerce as the source of an FXC→WC job, the way back (doc 05 §2, §5.5). */
final class FxcSource implements SourceAdapter {
	public function __construct(
		private readonly FxcEnumerationRepository $enumeration,
		private readonly FxcStoreReader $store,
		private readonly FxcCatalogReader $catalog,
		private readonly FxcOrderReader $orders,
		private readonly FxcShippingReader $shipping,
		private readonly FxcCouponReader $coupons,
	) {}

	public function system(): string {
		return 'fxc';
	}

	public function upper_id( string $stage, Job $job ): int {
		return $this->enumeration->upper_id( $stage );
	}

	public function ids( string $stage, int $after, int $upper, int $limit, Job $job ): array {
		return $this->enumeration->ids( $stage, $after, $upper, $limit, self::range( $job ) );
	}

	public function read( string $stage, int $id, Job $job ): ?Record {
		return match ( $stage ) {
			'settings'   => $this->store->settings(),
			'taxes'      => $this->store->tax_rate( $id ),
			'shipping'   => $this->shipping->shipping(),
			'attributes' => $this->catalog->attribute( $id ),
			'terms'      => $this->catalog->term( $id ),
			'products'   => $this->catalog->product( $id ),
			'customers'  => $this->store->customer( $id ),
			'orders'     => $this->orders->order( $id ),
			'coupons'    => $this->coupons->coupon( $id ),
			default      => null,
		};
	}

	public function read_ref( string $ref, Job $job ): ?Record {
		$parsed = Ref::parse( $ref );
		if ( $parsed === null || $parsed->system !== 'fxc' || $parsed->int() <= 0 ) {
			return null;
		}
		$stage = Job::stage_of( $parsed->entity );
		return $stage === null ? null : $this->read( $stage, $parsed->int(), $job );
	}

	public function count( string $stage, Job $job ): int {
		return $this->enumeration->count( $stage, self::range( $job ) );
	}

	public function preflight( Job $job ): array {
		$problems = [];
		if ( in_array( 'orders', $job->stages(), true ) ) {
			$statuses   = $this->enumeration->order_statuses();
			$problems[] = Problem::info( 'order_statuses', sprintf( '%d orders to copy back.', array_sum( $statuses ) ), [ 'statuses' => $statuses ] );
			foreach ( array_diff_key( $statuses, array_flip( OrderStatus::ALL ) ) as $status => $n ) {
				$problems[] = Problem::warning(
					'custom_status',
					sprintf( '%1$d orders have the status "%2$s", which WooCommerce may not know.', $n, $status ),
					[
						'status' => $status,
						'orders' => $n,
					]
				);
			}
			$currencies = $this->enumeration->order_currencies();
			if ( count( $currencies ) > 1 ) {
				$problems[] = Problem::warning( 'multi_currency', sprintf( 'Orders use %d currencies.', count( $currencies ) ), [ 'currencies' => $currencies ] );
			}
		}
		return $problems;
	}

	/** @return array{from:?string,to:?string} */
	private static function range( Job $job ): array {
		$from = $job->option( 'orders_from' );
		$to   = $job->option( 'orders_to' );
		return [
			'from' => is_string( $from ) ? Dates::to_db( $from ) : null,
			'to'   => is_string( $to ) ? Dates::to_db( $to ) : null,
		];
	}
}
