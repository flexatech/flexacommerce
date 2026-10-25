<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\SettingField;

defined( 'ABSPATH' ) || exit;

/**
 * What the checkout form shows under a gateway's radio button: a short
 * description, optional extra inputs (posted back as `gateway_input`) and an
 * optional script module handle the gateway registered itself.
 *
 * @api
 */
final class CheckoutFields {
	/** @param list<SettingField> $fields */
	public function __construct(
		public readonly string $description = '',
		public readonly array $fields = [],
		public readonly ?string $script_module = null,
	) {}
}
