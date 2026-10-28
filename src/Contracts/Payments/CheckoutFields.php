<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\SettingField;

defined( 'ABSPATH' ) || exit;

/**
 * What the checkout form shows under a gateway's radio button: a short
 * description, optional extra inputs (posted back as `gateway_input`) and an
 * optional script module URL with the data it needs (rendered as the
 * `data-fxc-config` JSON of the method).
 *
 * @api
 */
final class CheckoutFields {
	/**
	 * @param list<SettingField>  $fields
	 * @param array<string,mixed> $data   public values for the script module; never a secret
	 */
	public function __construct(
		public readonly string $description = '',
		public readonly array $fields = [],
		public readonly ?string $script_module = null,
		public readonly array $data = [],
	) {}
}
