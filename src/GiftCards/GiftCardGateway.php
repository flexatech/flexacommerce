<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

use Flexa\Commerce\Contracts\Payments\AbstractPaymentGateway;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\Payments\RefundRequest;
use Flexa\Commerce\Contracts\Payments\RefundResult;

defined( 'ABSPATH' ) || exit;

/**
 * The payments a gift card made (ADR-069). It never shows at checkout:
 * cards pay through the cart code box and GiftCardService::redeem(). It
 * exists so a refund of such a payment goes back on the card, whose id
 * starts the payment's reference.
 */
final class GiftCardGateway extends AbstractPaymentGateway {
	protected const SUPPORTS = [ 'refunds', 'partial_refunds' ];

	/** @param \Closure():GiftCardService $cards */
	public function __construct( private readonly \Closure $cards ) {}

	public function id(): string {
		return GiftCardService::GATEWAY;
	}

	public function title(): string {
		return __( 'Gift card', 'flexacommerce' );
	}

	public function is_available( CheckoutContext $checkout ): bool {
		unset( $checkout );
		return false;
	}

	public function process_payment( PaymentRequest $request ): PaymentResult {
		unset( $request );
		return PaymentResult::failed( 'not_supported', __( 'Enter the gift card code in the cart.', 'flexacommerce' ) );
	}

	public function refund( RefundRequest $request ): RefundResult {
		// `<card id>-<order id>`; payments made before that carry the card id alone.
		$card = (int) $request->payment_reference;
		if ( $card <= 0 ) {
			return RefundResult::failed( 'no_card', __( 'The gift card this order was paid with no longer exists.', 'flexacommerce' ) );
		}
		( $this->cards )()->credit( $card, $request->amount->amount, $request->refund_transaction_id );
		return RefundResult::succeeded( 'gift-card-' . $card . '-' . $request->refund_transaction_id );
	}
}
