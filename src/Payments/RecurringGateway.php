<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;

defined( 'ABSPATH' ) || exit;

/**
 * A core gateway that charges subscription renewals by itself (ADR-074).
 * It saves the payment method when an order with a subscription is paid
 * (PaymentRequest::$order says so), charges it later with nobody at the
 * screen, and lets the customer save another one. Internal for now, like
 * ExpressGateway.
 */
interface RecurringGateway extends PaymentGateway {
	/** The query arg setup_url() adds to `$return_url` with the reference for finish_setup(). */
	public const SETUP_ARG = 'fxc_setup';

	/** The method a paid payment saved for later, read from the provider; null if it saved none. */
	public function saved_method( string $reference ): ?SavedMethod;

	/** Charges a saved method off-session. A bank asking the customer to confirm is a failure. */
	public function charge_saved( PaymentRequest $request, SavedMethod $method ): PaymentResult;

	/**
	 * The provider's page where the customer saves a new method; `$return_url`
	 * gets the reference in SETUP_ARG to pass to finish_setup(). `$tag` names what the
	 * method is for, and finish_setup() checks it.
	 *
	 * @param string $customer the provider's customer to save it under, '' for a new one
	 */
	public function setup_url( string $customer, string $email, string $name, int $user_id, string $tag, string $return_url, string $cancel_url ): string;

	/** The method saved on that page, read back with the secret key; null unless it belongs to `$tag`. */
	public function finish_setup( string $reference, string $tag ): ?SavedMethod;
}
