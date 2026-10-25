<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/** Lazy container for the Shipping module. */
final class ShippingServices {
	private ?MethodRegistry $methods  = null;
	private ?ShippingService $service = null;

	public function __construct( private readonly Services $services ) {}

	public function methods(): MethodRegistry {
		return $this->methods ??= new MethodRegistry();
	}

	public function service(): ShippingService {
		return $this->service ??= new ShippingService( new ZoneRepository(), $this->methods(), $this->services->logger( 'shipping' ) );
	}
}
