<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * What one import has learned so far, carried between batches: which
 * product each ID and SKU of the file became, which SKUs the file already
 * used, and (in a check) the attributes and terms it would create. A
 * product id of 0 means the row was skipped; a negative id stands for a
 * product a check would create.
 */
final class ImportContext {
	/**
	 * @param array<string,int>  $ids     file ID => product id
	 * @param array<string,int>  $skus    lower-case SKU => product id
	 * @param array<string,int>  $lines   lower-case SKU => line that used it first
	 * @param array<string,true> $created what a check would create, already noted
	 */
	public function __construct(
		public array $ids = [],
		public array $skus = [],
		public array $lines = [],
		public array $created = [],
		public int $next_pseudo = -1,
	) {}

	/** @param array<array-key,mixed> $data */
	public static function from_array( array $data ): self {
		$map     = static function ( mixed $value ): array {
			$out = [];
			foreach ( is_array( $value ) ? $value : [] as $key => $id ) {
				$out[ (string) $key ] = (int) $id;
			}
			return $out;
		};
		$created = [];
		foreach ( is_array( $data['created'] ?? null ) ? $data['created'] : [] as $key => $unused ) {
			$created[ (string) $key ] = true;
		}
		return new self(
			$map( $data['ids'] ?? null ),
			$map( $data['skus'] ?? null ),
			$map( $data['lines'] ?? null ),
			$created,
			min( -1, (int) ( $data['next_pseudo'] ?? -1 ) ),
		);
	}

	/** @return array{ids:array<string,int>,skus:array<string,int>,lines:array<string,int>,created:array<string,true>,next_pseudo:int} */
	public function to_array(): array {
		return [
			'ids'         => $this->ids,
			'skus'        => $this->skus,
			'lines'       => $this->lines,
			'created'     => $this->created,
			'next_pseudo' => $this->next_pseudo,
		];
	}

	public function pseudo(): int {
		return $this->next_pseudo--;
	}

	/** True the first time a check notes that it would create `$what`. */
	public function note( string $what ): bool {
		if ( isset( $this->created[ $what ] ) ) {
			return false;
		}
		$this->created[ $what ] = true;
		return true;
	}

	public function remember( string $file_id, ?string $sku, int $product_id ): void {
		if ( $file_id !== '' ) {
			$this->ids[ $file_id ] = $product_id;
		}
		if ( $sku !== null && $sku !== '' ) {
			$this->skus[ mb_strtolower( $sku ) ] = $product_id;
		}
	}
}
