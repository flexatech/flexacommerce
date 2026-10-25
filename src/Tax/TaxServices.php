<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/** Lazy container for the Tax module. With taxes off only service() is built. */
final class TaxServices {
	private ?TaxService $service           = null;
	private ?TaxRateRepository $repository = null;
	private ?TaxPresets $presets           = null;

	public function __construct( private readonly Services $services ) {}

	public function repository(): TaxRateRepository {
		return $this->repository ??= new TaxRateRepository( $this->services->wpdb(), $this->services->clock(), $this->services->transaction() );
	}

	public function service(): TaxService {
		return $this->service ??= new TaxService(
			fn (): TaxRateRepository => $this->repository(),
			fn (): Logger => $this->services->logger( 'tax' ),
		);
	}

	public function presets(): TaxPresets {
		return $this->presets ??= new TaxPresets( $this->service() );
	}
}
