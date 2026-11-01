<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Orders\Carriers;
use Flexa\Commerce\Orders\Shipment;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce shipment add <order-id> --carrier=<carrier> [--number=<number>] [--url=<url>] [--date=<date>] [--items=<items>] [--complete] [--notify]
 *   wp flexacommerce shipment list <order-id> [--format=<format>]
 *   wp flexacommerce shipment delete <order-id> <shipment-id>
 *   wp flexacommerce shipment carriers [--format=<format>]
 *
 * The same service as the order screen (ADR-067), so a package added
 * from here is checked, written to the history and emailed the same way.
 */
final class ShipmentCommand {
	public const FIELDS = [ 'id', 'carrier', 'number', 'url', 'items', 'shipped' ];

	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		foreach ( [ 'add', 'list', 'delete', 'carriers' ] as $command ) {
			WP_CLI::add_command( 'flexacommerce shipment ' . $command, [ $instance, $command === 'list' ? 'list_shipments' : $command ] );
		}
	}

	/**
	 * Records a package of an order with its tracking.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : The order.
	 *
	 * --carrier=<carrier>
	 * : A carrier key from `wp flexacommerce shipment carriers`, or the name of another carrier.
	 *
	 * [--number=<number>]
	 * : The tracking number.
	 *
	 * [--url=<url>]
	 * : The tracking link of a carrier that is not in the list. {number} in it is the tracking number.
	 *
	 * [--date=<date>]
	 * : The day the package left (Y-m-d, site time zone). Defaults to today.
	 *
	 * [--items=<items>]
	 * : Only some lines: order item id:quantity, comma-separated. Defaults to the whole order.
	 *
	 * [--complete]
	 * : Also complete a processing order.
	 *
	 * [--notify]
	 * : Email the customer the tracking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce shipment add 1042 --carrier=ups --number=1Z999AA10123456784 --complete --notify
	 *     wp flexacommerce shipment add 1042 --carrier="Local Courier" --url="https://courier.example/t/{number}" --number=AB12 --items=88:1
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>         $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function add( array $args, array $assoc_args ): void {
		$order_id = (int) ( $args[0] ?? 0 );
		$carrier  = is_string( $assoc_args['carrier'] ?? null ) ? trim( $assoc_args['carrier'] ) : '';
		if ( $carrier === '' ) {
			WP_CLI::error( 'Give --carrier: a key from `wp flexacommerce shipment carriers` or a carrier name.' );
		}
		$known = Carriers::get( $carrier ) !== null;
		$items = null;
		if ( is_string( $assoc_args['items'] ?? null ) && $assoc_args['items'] !== '' ) {
			$items = [];
			foreach ( explode( ',', $assoc_args['items'] ) as $pair ) {
				if ( preg_match( '/^\s*(\d+)\s*:\s*(\d+)\s*$/', $pair, $m ) !== 1 ) {
					WP_CLI::error( sprintf( 'Wrong --items part "%s". Use order item id:quantity, for example 88:2,89:1.', $pair ) );
				}
				$items[ (int) $m[1] ] = (int) $m[2];
			}
		}
		try {
			$shipment = Plugin::instance()->orders()->shipment_service()->add(
				$order_id,
				$known ? $carrier : '',
				$known ? '' : $carrier,
				is_string( $assoc_args['number'] ?? null ) ? $assoc_args['number'] : '',
				is_string( $assoc_args['url'] ?? null ) ? $assoc_args['url'] : '',
				$items,
				is_string( $assoc_args['date'] ?? null ) ? $assoc_args['date'] : '',
				! empty( $assoc_args['complete'] ),
				! empty( $assoc_args['notify'] ),
				new TransitionContext( 'cli', get_current_user_id() )
			);
			WP_CLI::success( sprintf( 'Added shipment %d to order %d.', $shipment->id, $order_id ) );
		} catch ( CommerceException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Lists the packages of an order.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : The order.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function list_shipments( array $args, array $assoc_args ): void {
		$order_id = (int) ( $args[0] ?? 0 );
		if ( Plugin::instance()->orders()->orders()->find( $order_id ) === null ) {
			WP_CLI::error( sprintf( 'Order not found: %d.', $order_id ) );
		}
		$rows   = array_map( [ self::class, 'row' ], Plugin::instance()->orders()->shipments()->for_order( $order_id ) );
		$format = $assoc_args['format'] ?? 'table';
		if ( $format === 'ids' ) {
			WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
	}

	/**
	 * Removes a package recorded by mistake. The order keeps its status and no email is sent.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : The order.
	 *
	 * <shipment-id>
	 * : The shipment, from `wp flexacommerce shipment list`.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		try {
			Plugin::instance()->orders()->shipment_service()->delete( (int) ( $args[0] ?? 0 ), (int) ( $args[1] ?? 0 ), new TransitionContext( 'cli', get_current_user_id() ) );
		} catch ( CommerceException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( 'Removed shipment %d.', (int) ( $args[1] ?? 0 ) ) );
	}

	/**
	 * Lists the carriers --carrier takes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function carriers( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = [];
		foreach ( Carriers::all() as $key => $carrier ) {
			$rows[] = [
				'key'  => $key,
				'name' => $carrier['name'],
				'url'  => $carrier['url'],
			];
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'key', 'name', 'url' ] );
	}

	/** @return array<string,int|string> */
	public static function row( Shipment $shipment ): array {
		$items = [];
		foreach ( $shipment->items ?? [] as $item_id => $qty ) {
			$items[] = $item_id . ':' . $qty;
		}
		return [
			'id'      => $shipment->id,
			'carrier' => $shipment->carrier_name,
			'number'  => $shipment->tracking_number,
			'url'     => $shipment->tracking_url,
			'items'   => $shipment->items === null ? 'all' : implode( ',', $items ),
			'shipped' => get_date_from_gmt( $shipment->shipped_at, 'Y-m-d H:i' ),
		];
	}
}
