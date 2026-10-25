<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Exceptions\IncompatibleApi;
use Flexa\Commerce\Contracts\Extensions;
use Flexa\Commerce\Contracts\ExtensionSettings;
use Flexa\Commerce\Contracts\Logger as LoggerContract;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\OrderReader;
use Flexa\Commerce\Contracts\Payments\GatewayRegistry;
use Flexa\Commerce\Contracts\Shipping\ShippingMethodRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The modules arrive as closures from `Plugin`, so Support does not depend
 * on them and a listener that only reads settings builds no order or
 * payment service (doc 10 F2).
 */
final class ExtensionsFacade implements Extensions {
	/** @var array<string,ExtensionSettings> */
	private array $settings = [];

	/**
	 * @param \Closure():GatewayRegistry        $gateways
	 * @param \Closure():ShippingMethodRegistry $shipping
	 * @param \Closure():OrderReader            $orders
	 */
	public function __construct(
		private readonly Services $services,
		private readonly \Closure $gateways,
		private readonly \Closure $shipping,
		private readonly \Closure $orders,
	) {}

	public function api_version(): string {
		return self::API_VERSION;
	}

	public function require_api( string $min ): void {
		if ( version_compare( self::API_VERSION, $min, '<' ) ) {
			throw new IncompatibleApi( $min, self::API_VERSION );
		}
	}

	public function payment_gateways(): GatewayRegistry {
		return ( $this->gateways )();
	}

	public function shipping_methods(): ShippingMethodRegistry {
		return ( $this->shipping )();
	}

	public function orders(): OrderReader {
		return ( $this->orders )();
	}

	public function money(): MoneyFactory {
		return $this->services->money();
	}

	public function settings( string $extension_id ): ExtensionSettings {
		return $this->settings[ $extension_id ] ??= new ExtensionOptionStore( $extension_id );
	}

	public function logger( string $channel ): LoggerContract {
		return $this->services->logger( $channel );
	}
}
