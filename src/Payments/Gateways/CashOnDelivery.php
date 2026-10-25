<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways;

use Flexa\Commerce\Contracts\Payments\AbstractPaymentGateway;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\OfflineGateway;

defined( 'ABSPATH' ) || exit;

/** Paid on delivery: the order goes to processing and the admin records the payment (doc 04 §3.3). */
final class CashOnDelivery extends AbstractPaymentGateway implements OfflineGateway {
	public function id(): string {
		return 'cod';
	}

	public function title(): string {
		return __( 'Cash on delivery', 'flexacommerce' );
	}

	public function settings_fields(): array {
		return [
			new SettingField( 'methods', 'text', __( 'Only for these shipping methods', 'flexacommerce' ), '', __( 'Comma-separated method types, such as flat_rate,local_pickup. Empty allows every method.', 'flexacommerce' ) ),
		];
	}

	public function is_available( CheckoutContext $checkout ): bool {
		$methods = array_filter( array_map( 'trim', explode( ',', (string) $this->setting( 'methods' ) ) ) );
		if ( $methods === [] ) {
			return true;
		}
		return $checkout->needs_shipping && in_array( (string) $checkout->shipping_method(), $methods, true );
	}

	public function process_payment( PaymentRequest $request ): PaymentResult {
		unset( $request );
		return PaymentResult::pending();
	}

	public function order_status(): string {
		return OrderStatus::PROCESSING;
	}

	public function enabled_by_default(): bool {
		return true;
	}
}
