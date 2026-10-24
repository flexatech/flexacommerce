<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Catalog;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Plugin;

/**
 * ProductService runs its own START TRANSACTION, which commits the test
 * transaction of WP_UnitTestCase. Data written here therefore survives the
 * test, so every product and term is removed in tear_down and SKUs carry a
 * random suffix.
 */
abstract class CatalogTestCase extends \WP_UnitTestCase {
	/** @var list<int> */
	private array $posts = [];

	/** @var list<array{int,string}> */
	private array $terms = [];

	protected string $run;

	public function set_up(): void {
		parent::set_up();
		$this->run = strtoupper( wp_generate_password( 6, false ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->terms as [ $id, $taxonomy ] ) {
			wp_delete_term( $id, $taxonomy );
		}
		$this->posts = [];
		$this->terms = [];
		parent::tear_down();
	}

	protected function service(): ProductService {
		return Plugin::instance()->catalog()->product_service();
	}

	protected function product( string $title = 'Test product' ): int {
		$id            = (int) wp_insert_post(
			[
				'post_type'   => PostTypes::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		$this->posts[] = $id;
		return $id;
	}

	protected function track_post( int $id ): void {
		$this->posts[] = $id;
	}

	protected function track_term( int $id ): void {
		$this->terms[] = [ $id, PostTypes::ATTRIBUTE ];
	}

	protected function attribute( string $name, string ...$values ): array {
		$repository = Plugin::instance()->catalog()->attributes();
		$id         = $repository->create_attribute( $name . ' ' . $this->run );
		$ids        = [];
		foreach ( $values as $value ) {
			$ids[]         = $repository->create_value( $id, $value );
			$this->terms[] = [ end( $ids ), PostTypes::ATTRIBUTE ];
		}
		$this->terms[] = [ $id, PostTypes::ATTRIBUTE ];
		return [ $id, $ids ];
	}

	protected function sku( string $label ): string {
		return $label . '-' . $this->run;
	}

	/** @param array<string,mixed> $data */
	protected function save( int $id, array $data ): \Flexa\Commerce\Catalog\Product {
		return $this->service()->save( $id, ProductInput::from_array( $data ) );
	}

	/**
	 * A variable product with a local "Size" attribute.
	 *
	 * @param list<string> $sizes
	 * @param array<string,mixed> $extra
	 */
	protected function variable_product( array $sizes, array $extra = [] ): int {
		$id = $this->product();
		$this->save(
			$id,
			$extra + [
				'type'       => 'variable',
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => $sizes,
						'variation' => true,
					],
				],
			]
		);
		return $id;
	}
}
