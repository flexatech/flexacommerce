<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Job;

defined( 'ABSPATH' ) || exit;

/** WooCommerce as the source of a WC→FXC job (doc 05 §5.3, §5.4). */
final class WooSource implements SourceAdapter {
	/** WooCommerce versions the readers are tested against. */
	public const TESTED_FROM = '8.2';
	public const TESTED_TO   = '10.9';

	/** Statuses with a direct FXC equivalent (doc 05 §5.6). */
	public const CORE_STATUSES = [ 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft', 'trash', 'auto-draft', 'draft' ];

	private const SUPPORTED_TYPES = [ 'simple', 'variable' ];

	public function __construct(
		private readonly WooEnumerationRepository $enumeration,
		private readonly WooStoreReader $store,
		private readonly WooCatalogReader $catalog,
		private readonly WooOrderReader $orders,
		private readonly WooShippingReader $shipping,
	) {}

	public static function available(): bool {
		return class_exists( \WooCommerce::class ) && function_exists( 'wc_get_order' );
	}

	public function system(): string {
		return 'wc';
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
			default      => null,
		};
	}

	public function read_ref( string $ref, Job $job ): ?Record {
		$parsed = Ref::parse( $ref );
		if ( $parsed === null || $parsed->system !== 'wc' || $parsed->int() <= 0 ) {
			return null;
		}
		$stage = Job::stage_of( $parsed->entity );
		return $stage === null ? null : $this->read( $stage, $parsed->int(), $job );
	}

	public function count( string $stage, Job $job ): int {
		return $this->enumeration->count( $stage, self::range( $job ) );
	}

	public function preflight( Job $job ): array {
		if ( ! self::available() ) {
			return [ Problem::error( 'woocommerce_inactive', 'WooCommerce is not active.' ) ];
		}
		$problems = [];
		$version  = (string) get_option( 'woocommerce_version', '0' );
		if ( version_compare( $version, self::TESTED_FROM, '<' ) || version_compare( $version, self::TESTED_TO . '.999', '>' ) ) {
			$problems[] = Problem::warning(
				'woocommerce_version',
				sprintf( 'WooCommerce %s is outside the tested range %s to %s.', $version, self::TESTED_FROM, self::TESTED_TO ),
				[ 'version' => $version ]
			);
		}

		$hpos       = WooEnumerationRepository::hpos();
		$sync       = WooEnumerationRepository::sync_enabled();
		$problems[] = Problem::info(
			'storage_mode',
			sprintf( 'Order storage: %s.', $hpos ? ( $sync ? 'HPOS with sync' : 'HPOS' ) : ( $sync ? 'posts with sync' : 'posts' ) ),
			[
				'hpos' => $hpos,
				'sync' => $sync,
			]
		);
		$pending    = $this->enumeration->orders_pending_sync();
		if ( $pending !== null && $pending > 0 ) {
			$problems[] = Problem::warning( 'orders_pending_sync', sprintf( '%d orders are waiting for WooCommerce to sync them. Let WooCommerce finish syncing first.', $pending ), [ 'orders' => $pending ] );
		}

		$stages = $job->stages();
		if ( in_array( 'products', $stages, true ) ) {
			foreach ( $this->enumeration->product_types() as $type => $n ) {
				if ( ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
					$problems[] = Problem::warning(
						'unsupported_type',
						sprintf( '%1$d products of type "%2$s" will not be imported.', $n, $type ),
						[
							'type'     => $type,
							'products' => $n,
						]
					);
				}
			}
			$duplicates = $this->enumeration->duplicate_skus();
			if ( $duplicates !== [] ) {
				$problems[] = Problem::warning(
					'duplicate_sku',
					sprintf( '%d SKUs are shared by more than one product or variation.', count( $duplicates ) ),
					[ 'skus' => $duplicates ]
				);
			}
		}

		if ( in_array( 'orders', $stages, true ) ) {
			$custom = array_diff_key( $this->enumeration->order_statuses(), array_flip( self::CORE_STATUSES ) );
			$mapped = (array) $job->option( 'status_map' );
			foreach ( $custom as $status => $n ) {
				$slug       = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
				$problems[] = Problem::warning(
					'custom_status',
					isset( $mapped[ $slug ] )
						? sprintf( '%1$d orders have the custom status "%2$s", mapped to "%3$s".', $n, $slug, (string) $mapped[ $slug ] )
						: sprintf( '%1$d orders have the custom status "%2$s" and will be imported as on-hold.', $n, $slug ),
					[
						'status' => $slug,
						'orders' => $n,
					]
				);
			}
			$currencies = $this->enumeration->order_currencies();
			if ( count( $currencies ) > 1 ) {
				$problems[] = Problem::warning( 'multi_currency', sprintf( 'Orders use %d currencies.', count( $currencies ) ), [ 'currencies' => $currencies ] );
			}
			foreach ( $this->enumeration->sample_orders( 20 ) as $id ) {
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order && (string) $order->get_order_number() !== (string) $id ) {
					$problems[] = Problem::info( 'custom_order_numbers', 'Order numbers differ from order ids, probably from a sequential number plugin. The numbers are kept.' );
					break;
				}
			}
		}

		$zones = $this->enumeration->shipping_zones();
		if ( $zones > 0 && ! in_array( 'shipping', $stages, true ) ) {
			$problems[] = Problem::info( 'shipping_zones', sprintf( '%d shipping zones are left out because the shipping stage is off. Set up shipping in FlexaCommerce after the import.', $zones ), [ 'zones' => $zones ] );
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
