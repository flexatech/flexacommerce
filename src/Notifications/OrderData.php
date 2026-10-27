<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderFields;
use Flexa\Commerce\Orders\OrderRepository;
use Flexa\Commerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * What the core email templates need beyond the order view: links, the
 * payment instructions, the order locale and formatted amounts. The checkout
 * module is built only when a link or instructions are asked for.
 */
final class OrderData {
	/** @var array<int,?Order> */
	private array $orders = [];

	/** @param \Closure():CheckoutServices $checkout */
	public function __construct(
		private readonly OrderRepository $repository,
		private readonly MoneyFactory $money,
		private readonly \Closure $checkout,
	) {}

	public function money( Money $money ): string {
		return $this->money->format( $money );
	}

	/** @return list<string> the address as printed, one line each, without empty lines */
	public function address_lines( AddressView $address ): array {
		$states = Countries::states( $address->country );
		$region = trim( implode( ' ', array_filter( [ $address->city, $states[ $address->state ] ?? $address->state, $address->postcode ] ) ) );
		$lines  = [
			$address->name(),
			$address->company,
			$address->address_1,
			$address->address_2,
			$region,
			$address->country !== '' ? Countries::name( $address->country ) : '',
			$address->phone,
			$address->email,
		];
		return array_values( array_filter( array_map( 'trim', $lines ), static fn ( string $line ): bool => $line !== '' ) );
	}

	/**
	 * The answers to the merchant's own checkout fields that may go in emails (ADR-031).
	 *
	 * @return list<array{label:string,value:string}>
	 */
	public function answers( OrderView $view ): array {
		$out = [];
		foreach ( OrderFields::for_order( $this->repository, $view->id ) as $answer ) {
			if ( $answer['email'] ) {
				$out[] = [
					'label' => $answer['label'],
					'value' => $answer['value'],
				];
			}
		}
		return $out;
	}

	/** The order page with the order key, for the customer. */
	public function customer_url( OrderView $view ): string {
		$order = $this->order( $view->id );
		return $order !== null ? ( $this->checkout )()->pages()->confirmation_url( $order ) : '';
	}

	public function admin_url( OrderView $view ): string {
		return add_query_arg(
			[
				'page'  => 'flexacommerce-orders',
				'order' => $view->id,
			],
			admin_url( 'admin.php' )
		);
	}

	/** Bank details for an order that waits on a transfer; '' otherwise. */
	public function instructions( OrderView $view ): string {
		$order = $this->order( $view->id );
		return $order !== null ? ( $this->checkout )()->service()->instructions( $order ) : '';
	}

	/** The locale the customer placed the order in; '' when not recorded. */
	public function locale( int $order_id ): string {
		return $this->order( $order_id )->locale ?? '';
	}

	/** Called after each send so a later write in the same request is read fresh. */
	public function forget( int $order_id ): void {
		unset( $this->orders[ $order_id ] );
	}

	private function order( int $order_id ): ?Order {
		if ( ! array_key_exists( $order_id, $this->orders ) ) {
			$this->orders[ $order_id ] = $this->repository->find( $order_id );
		}
		return $this->orders[ $order_id ];
	}
}
