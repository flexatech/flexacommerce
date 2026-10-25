<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways;

use Flexa\Commerce\Contracts\Payments\AbstractPaymentGateway;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\OfflineGateway;

defined( 'ABSPATH' ) || exit;

/** Direct bank transfer: the order waits on hold with the bank details (doc 04 §3.3). */
final class BankTransfer extends AbstractPaymentGateway implements OfflineGateway {
	public function id(): string {
		return 'bacs';
	}

	public function title(): string {
		return __( 'Direct bank transfer', 'flexacommerce' );
	}

	public function settings_fields(): array {
		return [
			new SettingField( 'account_name', 'text', __( 'Account name', 'flexacommerce' ) ),
			new SettingField( 'bank_name', 'text', __( 'Bank', 'flexacommerce' ) ),
			new SettingField( 'account_number', 'text', __( 'Account number or IBAN', 'flexacommerce' ) ),
			new SettingField( 'sort_code', 'text', __( 'Sort code, routing number or BIC', 'flexacommerce' ) ),
			new SettingField( 'instructions', 'textarea', __( 'Instructions', 'flexacommerce' ), '', __( 'Shown on the order confirmation and in the email. Use {order_number} for the payment reference.', 'flexacommerce' ) ),
		];
	}

	public function process_payment( PaymentRequest $request ): PaymentResult {
		return PaymentResult::pending( null, $this->instructions( $request->order->number ) );
	}

	/** Plain text with the bank details and the order number as reference. */
	public function instructions( string $order_number ): string {
		$lines = [];
		$text  = trim( (string) $this->setting( 'instructions' ) );
		if ( $text !== '' ) {
			$lines[] = str_replace( '{order_number}', $order_number, $text );
		}
		$details = [
			__( 'Account name', 'flexacommerce' )   => (string) $this->setting( 'account_name' ),
			__( 'Bank', 'flexacommerce' )           => (string) $this->setting( 'bank_name' ),
			__( 'Account number', 'flexacommerce' ) => (string) $this->setting( 'account_number' ),
			__( 'Sort code', 'flexacommerce' )      => (string) $this->setting( 'sort_code' ),
			__( 'Reference', 'flexacommerce' )      => $order_number,
		];
		foreach ( $details as $label => $value ) {
			if ( $value !== '' ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		return implode( "\n", $lines );
	}

	public function order_status(): string {
		return OrderStatus::ON_HOLD;
	}

	public function enabled_by_default(): bool {
		return false;
	}
}
