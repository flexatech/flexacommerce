<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Catalog\CatalogServices;
use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing, stock and cart part of the composition root, built by Plugin
 * next to CatalogServices. Tests pass their own token store. `$tax`
 * resolves the tax service for catalog display prices; Cart never calls it.
 */
final class CartServices {
	private ?PricingService $pricing  = null;
	private ?StockService $stock      = null;
	private ?SessionStore $sessions   = null;
	private ?SessionRepository $rows  = null;
	private ?CartService $cart        = null;
	private ?SessionNonce $nonce      = null;
	private ?TokenStore $token_store  = null;
	private ?PurchaseOptions $options = null;
	private ?CartPresenter $presenter = null;

	public function __construct(
		private readonly Services $services,
		private readonly CatalogServices $catalog,
		?TokenStore $tokens = null,
		private readonly ?\Closure $tax = null,
	) {
		$this->token_store = $tokens;
	}

	public function pricing(): PricingService {
		return $this->pricing ??= new PricingService( $this->services->money(), $this->services->clock(), $this->tax );
	}

	public function stock(): StockService {
		return $this->stock ??= new StockService(
			$this->catalog->variants(),
			$this->catalog->products(),
			$this->services->transaction(),
			$this->services->after_commit(),
			$this->services->logger( 'stock' ),
			$this->services->clock(),
		);
	}

	public function tokens(): TokenStore {
		return $this->token_store ??= new CookieTokenStore();
	}

	public function sessions(): SessionStore {
		return $this->sessions ??= new SessionStore(
			$this->session_rows(),
			$this->tokens(),
			$this->services->clock(),
		);
	}

	/** The session table itself, for the privacy tools. */
	public function session_rows(): SessionRepository {
		return $this->rows ??= new SessionRepository( $this->services->wpdb() );
	}

	public function nonce(): SessionNonce {
		return $this->nonce ??= new SessionNonce( wp_salt( 'nonce' ) );
	}

	public function cart(): CartService {
		return $this->cart ??= new CartService(
			$this->sessions(),
			$this->catalog->products(),
			$this->catalog->matcher(),
			$this->pricing(),
			$this->stock(),
			$this->services->clock(),
		);
	}

	public function purchase_options(): PurchaseOptions {
		return $this->options ??= new PurchaseOptions( $this->cart(), $this->pricing(), $this->stock() );
	}

	public function presenter(): CartPresenter {
		return $this->presenter ??= new CartPresenter( $this->cart(), $this->pricing() );
	}
}
