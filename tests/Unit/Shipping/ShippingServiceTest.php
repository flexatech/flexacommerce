<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Shipping;

use Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\MethodRegistry;
use Flexa\Commerce\Shipping\ShippingService;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Tests\Unit\TestCase;

final class ShippingServiceTest extends TestCase {
	/** @var list<string> */
	private array $errors = [];

	public function test_zone_matching_by_country_state_and_postcode(): void {
		$us   = new Zone( 1, 'US', [ 'country:US' ] );
		$ca   = new Zone( 2, 'California', [ 'state:US:CA' ] );
		$la   = new Zone( 3, 'LA', [ 'country:US' ], [ '900*', '91000...91299' ] );
		$uk   = new Zone( 4, 'London', [ 'country:GB' ], [ 'SW1A 1AA' ] );
		$rest = new Zone( Zone::REST_OF_WORLD, 'Rest' );

		self::assertTrue( $us->matches( new Destination( 'US' ) ) );
		self::assertFalse( $us->matches( new Destination( 'CA' ) ) );
		self::assertTrue( $ca->matches( new Destination( 'us', 'ca' ) ) );
		self::assertFalse( $ca->matches( new Destination( 'US' ) ) );
		self::assertTrue( $la->matches( new Destination( 'US', '', '90012' ) ) );
		self::assertTrue( $la->matches( new Destination( 'US', '', '91100' ) ) );
		self::assertFalse( $la->matches( new Destination( 'US', '', '91300' ) ) );
		self::assertFalse( $la->matches( new Destination( 'US' ) ) );
		self::assertTrue( $uk->matches( new Destination( 'GB', '', 'sw1a1aa' ) ) );
		self::assertTrue( $rest->matches( new Destination( 'JP' ) ) );
	}

	public function test_first_matching_zone_wins_and_rest_of_world_is_last(): void {
		$service = $this->service(
			[
				new Zone( 0, 'Rest', [], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 2500 ] ) ] ),
				new Zone( 0, 'California', [ 'state:US:CA' ], [], [ new MethodInstance( 0, 'free_shipping' ) ] ),
				new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ),
			]
		);
		self::assertSame( 'California', $service->zone_for( new Destination( 'US', 'CA' ) )->name );
		self::assertSame( 'US', $service->zone_for( new Destination( 'US', 'NY' ) )->name );
		self::assertSame( 'Rest', $service->zone_for( new Destination( 'FR' ) )->name );
		self::assertSame( Zone::REST_OF_WORLD, $service->zones()->all()[2]->id );
	}

	public function test_rates_for_core_methods(): void {
		$service = $this->service(
			[
				new Zone(
					0,
					'US',
					[ 'country:US' ],
					[],
					[
						new MethodInstance(
							0,
							'flat_rate',
							true,
							[
								'cost'          => 500,
								'cost_per_item' => 100,
								'title'         => 'Standard',
							]
						),
						new MethodInstance( 0, 'free_shipping', true, [ 'min_amount' => 5000 ] ),
						new MethodInstance( 0, 'local_pickup', true, [ 'location' => '1 Main St' ] ),
						new MethodInstance( 0, 'flat_rate', false, [ 'cost' => 1 ] ),
					]
				),
			]
		);
		$rates   = $service->rates( $this->package( 'US', 3, 3000 ) );
		self::assertSame( [ 'flat_rate:1', 'local_pickup:3' ], array_map( static fn ( ShippingRate $r ): string => $r->id, $rates ) );
		self::assertSame( 800, $rates[0]->cost->amount );
		self::assertSame( 'Standard', $rates[0]->label );
		self::assertFalse( $rates[1]->needs_address );
		self::assertSame( [ '1 Main St' ], $rates[1]->meta );

		$rich = $service->rates( $this->package( 'US', 1, 5000 ) );
		self::assertSame( 'free_shipping:2', $rich[1]->id );
		self::assertTrue( $rich[1]->cost->is_zero() );
		self::assertNotNull( $service->find_rate( $this->package( 'US', 1, 5000 ), 'free_shipping:2' ) );
		self::assertNull( $service->find_rate( $this->package( 'US', 1, 3000 ), 'free_shipping:2' ) );
		self::assertSame( [], $service->rates( $this->package( 'FR', 1, 3000 ) ), 'rest of world has no methods' );
		self::assertSame( [], $service->rates( $this->package( '', 1, 3000 ) ) );
	}

	public function test_a_failing_method_is_logged_and_skipped(): void {
		$registry = new MethodRegistry();
		$registry->register( 'acme_broken', static fn (): ShippingMethod => new BrokenMethod() );
		$service = $this->service(
			[
				new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'acme_broken' ), new MethodInstance( 0, 'free_shipping' ), new MethodInstance( 0, 'gone_type' ) ] ),
			],
			$registry
		);
		$rates   = $service->rates( $this->package( 'US', 1, 1000 ) );
		self::assertSame( [ 'free_shipping:2' ], array_map( static fn ( ShippingRate $r ): string => $r->id, $rates ) );
		self::assertCount( 1, $this->errors );
	}

	public function test_registry_rejects_reserved_and_duplicate_types(): void {
		$registry = new MethodRegistry();
		$factory  = static fn (): ShippingMethod => new BrokenMethod();
		$registry->register( 'acme_broken', $factory );
		foreach ( [ 'flat_rate', 'fxc_x', 'acme_broken' ] as $type ) {
			try {
				$registry->register( $type, $factory );
				self::fail( 'Accepted ' . $type );
			} catch ( DuplicateRegistration $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		$registry->register( 'Bad Type', $factory );
	}

	public function test_saving_keeps_ids_and_never_reuses_them(): void {
		$repo  = new ZoneRepository();
		$saved = $repo->save( [ new Zone( 0, 'A', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate' ) ] ) ] );
		self::assertSame( 1, $saved[0]->id );
		self::assertSame( 1, $saved[0]->methods[0]->instance_id );

		$saved = $repo->save( [ new Zone( 1, 'A2', [ 'country:US' ], [], [ new MethodInstance( 1, 'flat_rate' ), new MethodInstance( 99, 'free_shipping' ) ] ) ] );
		self::assertSame( 'A2', $saved[0]->name );
		self::assertSame( [ 1, 2 ], array_map( static fn ( MethodInstance $m ): int => $m->instance_id, $saved[0]->methods ) );

		$saved = $repo->save( [ new Zone( 0, 'B', [ 'country:CA' ], [], [ new MethodInstance( 0, 'flat_rate' ) ] ) ] );
		self::assertSame( 2, $saved[0]->id, 'zone 1 was deleted; its id is not reused' );
		self::assertSame( 3, $saved[0]->methods[0]->instance_id );
	}

	/** @param list<Zone> $zones */
	private function service( array $zones, ?MethodRegistry $registry = null ): ShippingService {
		$repo = new ZoneRepository();
		$repo->save( $zones );
		$errors = &$this->errors;
		$log    = new class( $errors ) implements Logger {
			/** @param list<string> $errors */
			public function __construct( private array &$errors ) {}
			public function log( string $level, string $message, array $context = [] ): void {
				if ( $level === 'error' ) {
					$this->errors[] = $message;
				}
			}
			public function debug( string $message, array $context = [] ): void {}
			public function info( string $message, array $context = [] ): void {}
			public function notice( string $message, array $context = [] ): void {}
			public function warning( string $message, array $context = [] ): void {}
			public function error( string $message, array $context = [] ): void {
				$this->log( 'error', $message, $context );
			}
			public function critical( string $message, array $context = [] ): void {}
		};
		return new ShippingService( $repo, $registry ?? new MethodRegistry(), $log );
	}

	private function package( string $country, int $qty, int $total ): ShippingPackage {
		return new ShippingPackage(
			new Destination( $country ),
			[ new PackageLine( 7, $qty, 250, null, new Money( $total, 'USD' ) ) ],
			new Money( $total, 'USD' )
		);
	}
}

final class BrokenMethod implements ShippingMethod {
	public function type(): string {
		return 'acme_broken';
	}

	public function title(): string {
		return 'Broken';
	}

	public function settings_fields(): array {
		return [];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		throw new \RuntimeException( 'carrier down' );
	}
}
