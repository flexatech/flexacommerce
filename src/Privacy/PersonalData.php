<?php

declare(strict_types=1);

namespace Flexa\Commerce\Privacy;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Cart\SessionRepository;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Orders\HistoryRepository;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderRepository;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * The store's part of the core personal data export and erasure (doc 06
 * §2.8). Orders are found by the customer email and, for a registered
 * address, by the user id. Erasure anonymizes orders in place; orders still
 * in progress are kept until they finish. The cart session of a user is
 * exported and then deleted.
 *
 * Each call returns the core page shape: `data` + `done` for an export,
 * `items_removed`, `items_retained`, `messages`, `done` for an erasure.
 */
final class PersonalData {
	public const PAGE = 10;

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly HistoryRepository $history,
		private readonly OrderService $service,
		private readonly SessionRepository $sessions,
		private readonly MoneyFactory $money,
	) {}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public function export_orders( string $email, int $page ): array {
		$ids  = $this->orders->ids_for_customer( $email, self::user_id( $email ), self::PAGE, ( max( 1, $page ) - 1 ) * self::PAGE );
		$data = [];
		foreach ( $ids as $id ) {
			$order = $this->orders->find( $id );
			if ( $order !== null ) {
				$data[] = [
					'group_id'          => 'flexacommerce-orders',
					'group_label'       => __( 'Orders', 'flexacommerce' ),
					'group_description' => __( 'Orders placed in the store, with the addresses given at checkout.', 'flexacommerce' ),
					'item_id'           => 'order-' . $order->id,
					'data'              => $this->order_fields( $order ),
				];
			}
		}
		return [
			'data' => $data,
			'done' => count( $ids ) < self::PAGE,
		];
	}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public function export_session( string $email ): array {
		$session = self::user_id( $email ) > 0 ? $this->sessions->find_by_user( self::user_id( $email ) ) : null;
		if ( $session === null ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$cart   = Cart::from_data( $session->data );
		$fields = [];
		$lines  = [];
		foreach ( $cart->lines as $line ) {
			$lines[] = sprintf( '%s × %d', get_the_title( $line->product_id ), $line->quantity );
		}
		if ( $lines !== [] ) {
			$fields[] = self::field( __( 'Cart items', 'flexacommerce' ), implode( ', ', $lines ) );
		}
		$destination = trim( implode( ' ', array_filter( [ $cart->destination['postcode'], $cart->destination['state'], $cart->destination['country'] ] ) ) );
		if ( $destination !== '' ) {
			$fields[] = self::field( __( 'Shipping estimate address', 'flexacommerce' ), $destination );
		}
		$fields[] = self::field( __( 'Last updated', 'flexacommerce' ), get_date_from_gmt( $session->updated_at ) );
		return [
			'data' => [
				[
					'group_id'          => 'flexacommerce-cart',
					'group_label'       => __( 'Cart', 'flexacommerce' ),
					'group_description' => __( 'The cart saved for your account.', 'flexacommerce' ),
					'item_id'           => 'cart-' . $session->id,
					'data'              => $fields,
				],
			],
			'done' => true,
		];
	}

	/**
	 * Anonymizes one batch of orders. Erased orders drop out of the query,
	 * so each call reads the next batch whatever the page number; the last
	 * call lists the orders that were kept.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase_orders( string $email ): array {
		$user_id = self::user_id( $email );
		$keep    = $this->kept_statuses();
		$batch   = $this->orders->unanonymized_for_customer( $email, $user_id, $keep, false, self::PAGE );
		$removed = false;
		foreach ( $batch as $row ) {
			$removed = $this->service->anonymize( $row['id'], new TransitionContext( 'admin', get_current_user_id() ) ) || $removed;
		}
		$done     = count( $batch ) < self::PAGE;
		$messages = [];
		$retained = false;
		if ( $done ) {
			foreach ( $this->orders->unanonymized_for_customer( $email, $user_id, $keep, true, 50 ) as $row ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: %s: order number. */
					__( 'Order #%s was kept because it is still in progress. Erase again once it is completed or cancelled.', 'flexacommerce' ),
					$row['order_number']
				);
			}
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase_session( string $email ): array {
		$session = self::user_id( $email ) > 0 ? $this->sessions->find_by_user( self::user_id( $email ) ) : null;
		if ( $session !== null ) {
			$this->sessions->delete( $session->id );
		}
		return [
			'items_removed'  => $session !== null,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Statuses whose orders the eraser keeps: the shop still has to ship or
	 * collect payment for them. Filter `flexacommerce.privacy.kept_statuses`.
	 *
	 * @return list<string>
	 */
	public function kept_statuses(): array {
		$statuses = apply_filters( 'flexacommerce.privacy.kept_statuses', [ OrderStatus::PENDING, OrderStatus::ON_HOLD, OrderStatus::PROCESSING ] );
		return array_values( array_intersect( OrderStatus::ALL, is_array( $statuses ) ? $statuses : [] ) );
	}

	/** @return list<array{name:string,value:string}> */
	private function order_fields( Order $order ): array {
		$items = [];
		foreach ( $order->items_of_type( 'product' ) as $item ) {
			$items[] = sprintf( '%s × %d', $item->name, $item->quantity );
		}
		$notes = [];
		foreach ( $this->history->for_order( $order->id, true ) as $row ) {
			if ( $row['message'] !== '' ) {
				$notes[] = $row['message'];
			}
		}
		$fields = [
			self::field( __( 'Order number', 'flexacommerce' ), $order->order_number ),
			self::field( __( 'Order date', 'flexacommerce' ), get_date_from_gmt( $order->created_at ) ),
			self::field( __( 'Status', 'flexacommerce' ), OrderStatus::label( $order->status ) ),
			self::field( __( 'Total', 'flexacommerce' ), $this->money->format( $order->money( $order->total ) ) ),
			self::field( __( 'Items', 'flexacommerce' ), implode( ', ', $items ) ),
			self::field( __( 'Payment method', 'flexacommerce' ), $order->payment_method_title ),
			self::field( __( 'Email address', 'flexacommerce' ), $order->customer_email ),
			self::field( __( 'IP address', 'flexacommerce' ), $order->customer_ip ),
			self::field( __( 'Order note', 'flexacommerce' ), $order->customer_note ),
		];
		foreach ( [ $order->billing, $order->shipping ] as $address ) {
			if ( $address !== null ) {
				$billing  = $address->type === 'billing';
				$fields[] = self::field( $billing ? __( 'Billing address', 'flexacommerce' ) : __( 'Shipping address', 'flexacommerce' ), self::address( $address ) );
				$fields[] = self::field( $billing ? __( 'Billing phone', 'flexacommerce' ) : __( 'Shipping phone', 'flexacommerce' ), $address->phone );
				$fields[] = self::field( $billing ? __( 'Billing email', 'flexacommerce' ) : __( 'Shipping email', 'flexacommerce' ), $address->email );
			}
		}
		$fields[] = self::field( __( 'Messages from the store', 'flexacommerce' ), implode( "\n", array_reverse( $notes ) ) );
		return array_values( array_filter( $fields, static fn ( array $field ): bool => $field['value'] !== '' ) );
	}

	private static function address( AddressView $address ): string {
		$parts = [
			$address->name(),
			$address->company,
			$address->address_1,
			$address->address_2,
			trim( $address->city . ' ' . $address->state . ' ' . $address->postcode ),
			$address->country !== '' ? Countries::name( $address->country ) : '',
		];
		return implode( ', ', array_filter( array_map( 'trim', $parts ), static fn ( string $part ): bool => $part !== '' ) );
	}

	/** @return array{name:string,value:string} */
	private static function field( string $name, string $value ): array {
		return [
			'name'  => $name,
			'value' => trim( $value ),
		];
	}

	private static function user_id( string $email ): int {
		$user = $email !== '' ? get_user_by( 'email', $email ) : false;
		return $user instanceof \WP_User ? $user->ID : 0;
	}
}
