<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\ValidationResult;

defined( 'ABSPATH' ) || exit;

/**
 * A payment gateway (doc 04 §3). Extend AbstractPaymentGateway to get safe
 * defaults for the optional parts.
 *
 * @api
 */
interface PaymentGateway {
	public const FEATURES = [ 'refunds', 'partial_refunds', 'webhooks', 'signed_returns', 'authorize_capture', 'tokenization' ];

	/** [a-z0-9_]{1,64}, the same id it was registered under. */
	public function id(): string;

	/** Admin label; the customer-facing title is a store setting. */
	public function title(): string;

	public function supports( string $feature ): bool;

	/** @return list<SettingField> */
	public function settings_fields(): array;

	/** @param array<string,mixed> $settings the stored values of settings_fields() */
	public function configure( array $settings ): void;

	public function is_available( CheckoutContext $checkout ): bool;

	public function checkout_fields( CheckoutContext $checkout ): CheckoutFields;

	/** @param array<string,mixed> $gateway_input */
	public function validate( CheckoutContext $checkout, array $gateway_input ): ValidationResult;

	public function process_payment( PaymentRequest $request ): PaymentResult;

	/** Null means "unknown, wait for the webhook". */
	public function verify_return( ReturnRequest $request ): ?PaymentEvent;

	/**
	 * @return list<PaymentEvent>
	 * @throws \Flexa\Commerce\Contracts\Exceptions\InvalidSignature
	 */
	public function parse_webhook( \WP_REST_Request $request ): array;

	public function refund( RefundRequest $request ): RefundResult;

	/**
	 * Strip anything sensitive before core stores or logs it.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function redact( array $data ): array;
}
