<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Core gateways whose money arrives outside the site (cash on delivery,
 * bank transfer). After their `pending` result the order moves to
 * `order_status()` and the admin records the payment by hand. Internal for
 * now; it becomes a contract once an extension needs it.
 */
interface OfflineGateway {
	/** `processing` or `on-hold`. */
	public function order_status(): string;

	public function enabled_by_default(): bool;
}
