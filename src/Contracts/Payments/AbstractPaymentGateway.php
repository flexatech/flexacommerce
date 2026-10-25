<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\ValidationResult;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults for the optional parts of PaymentGateway: no features, no
 * settings, always available, no extra input, returns wait for the webhook,
 * no webhooks, refunds refused, data stored as given.
 *
 * @api
 */
abstract class AbstractPaymentGateway implements PaymentGateway {
	/** @var list<string> */
	protected const SUPPORTS = [];

	/** @var array<string,mixed> */
	protected array $settings = [];

	public function supports( string $feature ): bool {
		return in_array( $feature, static::SUPPORTS, true );
	}

	public function settings_fields(): array {
		return [];
	}

	public function configure( array $settings ): void {
		$this->settings = $settings;
	}

	protected function setting( string $key, mixed $fallback = '' ): mixed {
		return $this->settings[ $key ] ?? $fallback;
	}

	public function is_available( CheckoutContext $checkout ): bool {
		unset( $checkout );
		return true;
	}

	public function checkout_fields( CheckoutContext $checkout ): CheckoutFields {
		unset( $checkout );
		return new CheckoutFields( (string) $this->setting( 'description' ) );
	}

	public function validate( CheckoutContext $checkout, array $gateway_input ): ValidationResult {
		unset( $checkout, $gateway_input );
		return new ValidationResult();
	}

	public function verify_return( ReturnRequest $request ): ?PaymentEvent {
		unset( $request );
		return null;
	}

	public function parse_webhook( \WP_REST_Request $request ): array {
		unset( $request );
		return [];
	}

	public function refund( RefundRequest $request ): RefundResult {
		unset( $request );
		return RefundResult::failed( 'not_supported', __( 'This payment method cannot refund online.', 'flexacommerce' ) );
	}

	public function redact( array $data ): array {
		return $data;
	}
}
