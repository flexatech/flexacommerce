<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\GatewayRegistry;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Gateways by id, built on first use and configured from their option
 * (doc 04 §2). Core ids and the `fxc_` prefix are reserved.
 */
final class GatewayRegistryService implements GatewayRegistry {
	public const CORE = [ 'cod', 'bacs', 'stripe' ];

	/** @var array<string,callable():PaymentGateway> */
	private array $factories = [];

	/** @var array<string,PaymentGateway> */
	private array $built = [];

	public function __construct( private readonly GatewaySettings $settings ) {
		$this->factories['cod']    = static fn (): PaymentGateway => new Gateways\CashOnDelivery();
		$this->factories['bacs']   = static fn (): PaymentGateway => new Gateways\BankTransfer();
		$this->factories['stripe'] = static fn (): PaymentGateway => new Gateways\Stripe\StripeGateway();
	}

	public function register( string $id, callable $factory ): void {
		if ( preg_match( '/^[a-z0-9_]{1,64}$/', $id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid gateway id "%s".', $id ) );
		}
		if ( in_array( $id, self::CORE, true ) || str_starts_with( $id, 'fxc_' ) ) {
			throw new DuplicateRegistration( sprintf( 'The gateway id "%s" is reserved.', $id ) );
		}
		if ( isset( $this->factories[ $id ] ) ) {
			throw new DuplicateRegistration( sprintf( 'A gateway with id "%s" is already registered.', $id ) );
		}
		$this->factories[ $id ] = $factory;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	public function get( string $id ): ?PaymentGateway {
		if ( isset( $this->built[ $id ] ) ) {
			return $this->built[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			return null;
		}
		$gateway = ( $this->factories[ $id ] )();
		if ( $gateway->id() !== $id ) {
			throw new \UnexpectedValueException( sprintf( 'The factory for gateway "%s" returned another gateway.', $id ) );
		}
		$gateway->configure( $this->settings->values( $gateway ) );
		$this->built[ $id ] = $gateway;

		return $gateway;
	}

	public function ids(): array {
		return array_keys( $this->factories );
	}

	public function settings(): GatewaySettings {
		return $this->settings;
	}

	/** Title shown to the customer: the merchant's setting, else the gateway's. */
	public function title( PaymentGateway $gateway ): string {
		$title = (string) ( $this->settings->values( $gateway )['title'] ?? '' );
		return $title !== '' ? $title : $gateway->title();
	}

	/**
	 * Enabled gateways that accept this checkout, in registration order. A
	 * gateway that throws is left out, so one broken extension does not
	 * break checkout.
	 *
	 * @return array<string,PaymentGateway>
	 */
	public function available( CheckoutContext $checkout ): array {
		$out = [];
		$this->settings->prime( $this->ids() );
		foreach ( $this->ids() as $id ) {
			try {
				$gateway = $this->get( $id );
				if ( $gateway !== null && $this->settings->enabled( $gateway ) && $gateway->is_available( $checkout ) ) {
					$out[ $id ] = $gateway;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $out;
	}
}
