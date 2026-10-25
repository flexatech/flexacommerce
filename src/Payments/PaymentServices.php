<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/** Lazy container for the Payments module. */
final class PaymentServices {
	private ?GatewayRegistryService $gateways    = null;
	private ?TransactionRepository $transactions = null;
	private ?WebhookEventRepository $webhooks    = null;
	private ?PaymentService $service             = null;

	public function __construct(
		private readonly Services $services,
		/** @var \Closure():OrderService built only when a payment is taken, not to register a gateway */
		private readonly \Closure $orders,
	) {}

	public function gateways(): GatewayRegistryService {
		return $this->gateways ??= new GatewayRegistryService( new GatewaySettings() );
	}

	public function transactions(): TransactionRepository {
		return $this->transactions ??= new TransactionRepository( $this->services->wpdb() );
	}

	public function webhooks(): WebhookEventRepository {
		return $this->webhooks ??= new WebhookEventRepository( $this->services->wpdb() );
	}

	public function service(): PaymentService {
		return $this->service ??= new PaymentService(
			$this->gateways(),
			$this->transactions(),
			$this->webhooks(),
			( $this->orders )(),
			$this->services->transaction(),
			$this->services->clock(),
			$this->services->logger( 'payments' ),
			$this->services->redactor(),
		);
	}
}
