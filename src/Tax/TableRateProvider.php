<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Contracts\Tax\TaxRateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The default provider: the store's own rate table. prefetch() loads every
 * class a request needs in one query, so rates() is then a memory read.
 */
final class TableRateProvider implements TaxRateProvider {
	/** @var array<string,array<string,list<TaxRate>>> location key => class => rates */
	private array $loaded = [];

	public function __construct( private readonly TaxRateRepository $repository ) {}

	/** @param list<string> $classes */
	public function prefetch( TaxLocation $location, array $classes ): void {
		$key     = $location->key();
		$missing = array_values( array_diff( array_unique( $classes ), array_keys( $this->loaded[ $key ] ?? [] ) ) );
		if ( $missing === [] ) {
			return;
		}
		foreach ( $this->repository->matching( $location, $missing ) as $class => $rates ) {
			$this->loaded[ $key ][ (string) $class ] = $rates;
		}
	}

	public function rates( TaxLocation $location, string $tax_class ): array {
		$key = $location->key();
		if ( ! isset( $this->loaded[ $key ][ $tax_class ] ) ) {
			$this->prefetch( $location, [ $tax_class ] );
		}
		return $this->loaded[ $key ][ $tax_class ] ?? [];
	}

	public function fallback_allowed(): bool {
		return true;
	}

	public function forget(): void {
		$this->loaded = [];
	}
}
