<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * An express gateway whose buttons pay on the provider's own sheet, which
 * needs an order there before it opens (PayPal, ADR-054). The store keeps
 * that order in step with its quote while the shopper picks an address
 * and a shipping rate, then reads the contact from it when the order is
 * placed. Internal for now, like ExpressGateway.
 */
interface ExpressSession {
	/**
	 * Opens the provider's order for a quote from CheckoutService::express_quote().
	 *
	 * @param array<string,mixed> $quote
	 * @return string the session id the buttons hand back
	 * @throws \RuntimeException the provider refused or did not answer
	 */
	public function open_express( array $quote ): string;

	/**
	 * Brings the provider's order to a new quote.
	 *
	 * @param array<string,mixed> $quote
	 * @throws \RuntimeException
	 */
	public function update_express( string $session, array $quote ): void;

	/**
	 * Who pays and where it ships, as checkout fields, once the shopper
	 * approved: `billing`, `shipping` (null without one) and `shipping_rate`.
	 *
	 * @return array{billing:array<string,string>,shipping:array<string,string>|null,shipping_rate:string}
	 * @throws \RuntimeException
	 */
	public function express_contact( string $session ): array;
}
