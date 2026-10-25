<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

use Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingMethodRegistry;

defined( 'ABSPATH' ) || exit;

/** Shipping method types, built on first use (doc 04 §2). */
final class MethodRegistry implements ShippingMethodRegistry {
	public const CORE = [ 'flat_rate', 'free_shipping', 'local_pickup' ];

	/** @var array<string,callable():ShippingMethod> */
	private array $factories;

	/** @var array<string,ShippingMethod> */
	private array $built = [];

	public function __construct() {
		$this->factories = [
			'flat_rate'     => static fn (): ShippingMethod => new Methods\FlatRate(),
			'free_shipping' => static fn (): ShippingMethod => new Methods\FreeShipping(),
			'local_pickup'  => static fn (): ShippingMethod => new Methods\LocalPickup(),
		];
	}

	public function register( string $type, callable $factory ): void {
		if ( preg_match( '/^[a-z0-9_]{1,64}$/', $type ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid shipping method type "%s".', $type ) );
		}
		if ( in_array( $type, self::CORE, true ) || str_starts_with( $type, 'fxc_' ) ) {
			throw new DuplicateRegistration( sprintf( 'The shipping method type "%s" is reserved.', $type ) );
		}
		if ( isset( $this->factories[ $type ] ) ) {
			throw new DuplicateRegistration( sprintf( 'A shipping method with type "%s" is already registered.', $type ) );
		}
		$this->factories[ $type ] = $factory;
	}

	public function has( string $type ): bool {
		return isset( $this->factories[ $type ] );
	}

	public function get( string $type ): ?ShippingMethod {
		if ( isset( $this->built[ $type ] ) ) {
			return $this->built[ $type ];
		}
		if ( ! isset( $this->factories[ $type ] ) ) {
			return null;
		}
		$method = ( $this->factories[ $type ] )();
		if ( $method->type() !== $type ) {
			throw new \UnexpectedValueException( sprintf( 'The factory for shipping method "%s" returned another type.', $type ) );
		}
		$this->built[ $type ] = $method;

		return $method;
	}

	public function types(): array {
		return array_keys( $this->factories );
	}
}
