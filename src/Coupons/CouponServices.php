<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons part of the composition root. Plugin builds it the first time a
 * cart holds a code, a checkout redeems one, an order with a discount is
 * cancelled or the Coupons screen is opened; other requests never load it.
 */
final class CouponServices {
	private ?CouponRepository $repository = null;
	private ?CouponService $service       = null;
	private ?CouponEditor $editor         = null;

	public function __construct(
		private readonly Services $services,
		private readonly PricingService $pricing,
	) {}

	public function repository(): CouponRepository {
		return $this->repository ??= new CouponRepository( $this->services->wpdb() );
	}

	public function service(): CouponService {
		return $this->service ??= new CouponService( $this->repository(), $this->pricing, $this->services->clock() );
	}

	public function editor(): CouponEditor {
		return $this->editor ??= new CouponEditor( $this->repository(), $this->services->clock() );
	}
}
