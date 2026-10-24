<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog part of the composition root. Support may not depend on Catalog
 * (doc 02 §3.2), so Plugin builds this next to Services.
 */
final class CatalogServices {
	private ?VariantRepository $variants     = null;
	private ?ProductRepository $products     = null;
	private ?AttributeRepository $attributes = null;
	private ?ProductService $product_service = null;
	private ?VariantMatcher $matcher         = null;

	public function __construct( private readonly Services $services ) {}

	public function variants(): VariantRepository {
		return $this->variants ??= new VariantRepository( $this->services->wpdb(), $this->services->clock() );
	}

	public function products(): ProductRepository {
		return $this->products ??= new ProductRepository( $this->variants() );
	}

	public function attributes(): AttributeRepository {
		return $this->attributes ??= new AttributeRepository();
	}

	public function matcher(): VariantMatcher {
		return $this->matcher ??= new VariantMatcher();
	}

	public function product_service(): ProductService {
		return $this->product_service ??= new ProductService(
			$this->products(),
			$this->variants(),
			$this->attributes(),
			$this->services->transaction(),
			$this->services->after_commit(),
			$this->services->clock(),
		);
	}
}
