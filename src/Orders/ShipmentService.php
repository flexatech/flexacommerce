<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Exceptions\InvalidOrderRequest;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Adds and removes the tracking of an order's packages (ADR-067). Adding
 * one can complete the order in the same transaction; the status change
 * then carries the shipment id, so the completed email stays quiet and
 * the shipped email speaks for both. `flexacommerce.order.shipped` fires
 * after commit, with `notify` saying whether the customer gets the email.
 */
final class ShipmentService {
	/** Statuses an order may ship in. */
	public const STATUSES = [ S::PROCESSING, S::ON_HOLD, S::COMPLETED ];

	public function __construct(
		private readonly ShipmentRepository $shipments,
		private readonly OrderRepository $orders,
		private readonly HistoryRepository $history,
		private readonly OrderService $service,
		private readonly Transaction $transaction,
		private readonly AfterCommit $after_commit,
		private readonly Clock $clock,
	) {}

	public function repository(): ShipmentRepository {
		return $this->shipments;
	}

	/** @return list<Shipment> */
	public function for_order( int $order_id ): array {
		return $this->shipments->for_order( $order_id );
	}

	/**
	 * Records a package. `carrier` is a key of Carriers or '' with a
	 * name (and maybe a link) typed in; `items` null is the whole order;
	 * `date` is the day it left, in the site's time zone, today when ''.
	 *
	 * @param array<int,int>|null $items order item id => quantity
	 * @throws InvalidOrderRequest
	 * @throws OrderNotFound
	 */
	public function add(
		int $order_id,
		string $carrier,
		string $carrier_name,
		string $number,
		string $url,
		?array $items,
		string $date,
		bool $complete,
		bool $notify,
		TransitionContext $ctx,
	): Shipment {
		$number = (string) preg_replace( '/\s+/', '', sanitize_text_field( $number ) );
		if ( strlen( $number ) > 100 ) {
			throw new InvalidOrderRequest( __( 'The tracking number is too long.', 'flexacommerce' ), 'tracking_number' );
		}
		$known = Carriers::get( sanitize_key( $carrier ) );
		if ( $known !== null ) {
			$carrier = sanitize_key( $carrier );
			$name    = $known['name'];
			$link    = Carriers::url( $carrier, $number );
		} else {
			$carrier = '';
			$name    = mb_substr( trim( sanitize_text_field( $carrier_name ) ), 0, 100 );
			$link    = self::link( $url, $number );
			if ( $name === '' ) {
				throw new InvalidOrderRequest( __( 'Choose a carrier or type its name.', 'flexacommerce' ), 'carrier' );
			}
		}
		$shipped = $this->shipped_at( $date );

		$id = $this->transaction->run(
			function () use ( $order_id, $carrier, $name, $number, $link, $items, $shipped, $complete, $notify, $ctx ): int {
				$order = $this->orders->find( $order_id, true ) ?? throw new OrderNotFound( __( 'Order not found.', 'flexacommerce' ) );
				if ( ! in_array( $order->status, self::STATUSES, true ) ) {
					throw new InvalidOrderRequest(
						sprintf(
							/* translators: %s: order status. */
							__( 'An order that is %s cannot be shipped.', 'flexacommerce' ),
							S::label( $order->status )
						),
						'status'
					);
				}
				$lines = self::items( $order, $items );
				$now   = $this->clock->db_now();
				$id    = $this->shipments->insert(
					[
						'order_id'        => $order_id,
						'carrier'         => $carrier,
						'carrier_name'    => $name,
						'tracking_number' => $number,
						'tracking_url'    => $link,
						'items'           => $lines,
						'shipped_at'      => $shipped ?? $now,
						'created_at'      => $now,
					]
				);
				$this->history->add(
					$order_id,
					'shipment',
					$number !== ''
						/* translators: 1: carrier, 2: tracking number. */
						? sprintf( __( 'Shipped with %1$s, tracking number %2$s.', 'flexacommerce' ), $name, $number )
						/* translators: %s: carrier. */
						: sprintf( __( 'Shipped with %s.', 'flexacommerce' ), $name ),
					$now,
					$ctx->actor_type,
					$ctx->actor_id,
					false,
					null,
					null,
					[ 'shipment_id' => $id ]
				);
				if ( $complete && $order->status !== S::COMPLETED ) {
					$this->service->transition(
						$order_id,
						S::COMPLETED,
						new TransitionContext( $ctx->actor_type, $ctx->actor_id, '', false, [ 'shipment' => $id ] )
					);
				}
				$this->after_commit->defer(
					function () use ( $order_id, $id, $notify ): void {
						$order    = $this->orders->find( $order_id );
						$shipment = $this->shipments->find( $id );
						if ( $order !== null && $shipment !== null ) {
							/**
							 * A package of the order was recorded (ADR-067).
							 *
							 * @param \Flexa\Commerce\Contracts\Orders\OrderView $view
							 * @param Shipment                                   $shipment
							 * @param array{notify:bool}                         $context
							 */
							do_action( 'flexacommerce.order.shipped', $order->view(), $shipment, [ 'notify' => $notify ] );
						}
					}
				);
				return $id;
			}
		);
		return $this->shipments->find( $id ) ?? throw new OrderNotFound( __( 'Shipment not found.', 'flexacommerce' ) );
	}

	/**
	 * Removes a package recorded by mistake. The order keeps its status.
	 *
	 * @throws OrderNotFound when the shipment is not on this order
	 */
	public function delete( int $order_id, int $shipment_id, TransitionContext $ctx ): void {
		$this->transaction->run(
			function () use ( $order_id, $shipment_id, $ctx ): void {
				$this->orders->find( $order_id, true ) ?? throw new OrderNotFound( __( 'Order not found.', 'flexacommerce' ) );
				$shipment = $this->shipments->find( $shipment_id );
				if ( $shipment === null || $shipment->order_id !== $order_id ) {
					throw new OrderNotFound( __( 'This shipment does not exist on this order.', 'flexacommerce' ) );
				}
				$this->shipments->delete( $shipment_id );
				$this->history->add(
					$order_id,
					'shipment',
					$shipment->tracking_number !== ''
						/* translators: 1: carrier, 2: tracking number. */
						? sprintf( __( 'Removed the %1$s shipment with tracking number %2$s.', 'flexacommerce' ), $shipment->carrier_name, $shipment->tracking_number )
						/* translators: %s: carrier. */
						: sprintf( __( 'Removed the %s shipment.', 'flexacommerce' ), $shipment->carrier_name ),
					$this->clock->db_now(),
					$ctx->actor_type,
					$ctx->actor_id
				);
			}
		);
	}

	/**
	 * The lines of the package, checked against the order. Every product
	 * line in full is the whole order, stored as null.
	 *
	 * @param array<int,int>|null $items
	 * @return array<int,int>|null
	 */
	private static function items( Order $order, ?array $items ): ?array {
		if ( $items === null ) {
			return null;
		}
		$lines = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$lines[ $item->id ] = $item->refundable_qty();
		}
		$out = [];
		foreach ( $items as $item_id => $qty ) {
			$qty = (int) $qty;
			if ( $qty <= 0 ) {
				continue;
			}
			if ( ! isset( $lines[ (int) $item_id ] ) ) {
				throw new InvalidOrderRequest( __( 'A product in the package is not on this order.', 'flexacommerce' ), 'items' );
			}
			if ( $qty > $lines[ (int) $item_id ] ) {
				throw new InvalidOrderRequest( __( 'The package holds more of a product than the order has.', 'flexacommerce' ), 'items' );
			}
			$out[ (int) $item_id ] = $qty;
		}
		if ( $out === [] ) {
			throw new InvalidOrderRequest( __( 'Choose at least one product for the package.', 'flexacommerce' ), 'items' );
		}
		$whole = array_filter( $lines );
		ksort( $whole );
		ksort( $out );
		return $out === $whole ? null : $out;
	}

	/** A typed-in tracking link: http(s) only; `{number}` in it is the tracking number. */
	private static function link( string $url, string $number ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		$url = str_replace( '{number}', rawurlencode( $number ), $url );
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( $url === '' || strlen( $url ) > 500 ) {
			throw new InvalidOrderRequest( __( 'The tracking link must be a web address that starts with http:// or https://.', 'flexacommerce' ), 'tracking_url' );
		}
		return $url;
	}

	/** UTC time for the day the package left; null is now. */
	private function shipped_at( string $date ): ?string {
		$date = trim( $date );
		if ( $date === '' ) {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( $day === false || $day->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidOrderRequest( __( 'The shipping date is not a valid date.', 'flexacommerce' ), 'shipped_at' );
		}
		$now   = $this->clock->now()->setTimezone( wp_timezone() );
		$today = $now->format( 'Y-m-d' );
		// The admin's own today can be the store's tomorrow when their clock is in a zone ahead.
		if ( $date > $now->modify( '+1 day' )->format( 'Y-m-d' ) ) {
			throw new InvalidOrderRequest( __( 'The shipping date cannot be in the future.', 'flexacommerce' ), 'shipped_at' );
		}
		if ( $date >= $today ) {
			return null;
		}
		return $day->setTime( 12, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
