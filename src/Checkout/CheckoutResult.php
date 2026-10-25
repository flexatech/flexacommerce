<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Orders\Order;

defined( 'ABSPATH' ) || exit;

/**
 * What the customer's browser does next. `duplicate` marks a resubmitted
 * checkout that returned the order the first submit created.
 */
final class CheckoutResult {
	public function __construct(
		public readonly Order $order,
		public readonly string $redirect,
		public readonly ?PaymentResult $payment = null,
		public readonly bool $duplicate = false,
	) {}

	/** @return array<string,mixed> */
	public function to_array(): array {
		$payment = null;
		if ( $this->payment !== null ) {
			$payment = [
				'result'       => $this->payment->type,
				'instructions' => $this->payment->instructions,
				'payload'      => $this->payment->type === PaymentResult::REQUIRES_ACTION ? $this->payment->payload : null,
				'message'      => $this->payment->type === PaymentResult::FAILED ? $this->payment->failure_message : '',
			];
		}
		return [
			'order_id'     => $this->order->id,
			'order_number' => $this->order->order_number,
			'order_key'    => $this->order->order_key,
			'status'       => $this->order->status,
			'redirect'     => $this->redirect,
			'payment'      => $payment,
			'duplicate'    => $this->duplicate,
		];
	}
}
