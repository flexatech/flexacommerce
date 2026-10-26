<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/** Orders part of the composition root, built by Plugin on first use with the shared StockService. */
final class OrderServices {
	private ?OrderRepository $orders       = null;
	private ?HistoryRepository $history    = null;
	private ?OrderService $service         = null;
	private ?OrderReaderService $reader    = null;
	private ?CustomerRepository $customers = null;

	public function __construct(
		private readonly Services $services,
		private readonly StockService $stock,
		private readonly ?\Closure $release_coupons = null,
	) {}

	public function orders(): OrderRepository {
		return $this->orders ??= new OrderRepository( $this->services->wpdb() );
	}

	public function history(): HistoryRepository {
		return $this->history ??= new HistoryRepository( $this->services->wpdb() );
	}

	public function service(): OrderService {
		return $this->service ??= new OrderService(
			$this->orders(),
			$this->history(),
			$this->stock,
			$this->services->transaction(),
			$this->services->after_commit(),
			$this->services->clock(),
			new OrderNumber( $this->services->sequence() ),
			$this->release_coupons,
		);
	}

	public function customers(): CustomerRepository {
		return $this->customers ??= new CustomerRepository( $this->services->wpdb() );
	}

	public function reader(): OrderReaderService {
		return $this->reader ??= new OrderReaderService( $this->orders() );
	}
}
