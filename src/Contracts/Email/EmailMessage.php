<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Email;

use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/**
 * What an email renders: the body goes inside the store's email layout.
 * Recipients may be empty for an admin email; the settings fill them in.
 *
 * @api
 */
final class EmailMessage {
	/** @param list<string> $recipients */
	public function __construct(
		public readonly string $subject,
		public readonly string $heading,
		public readonly string $content_html,
		public readonly ?OrderView $order = null,
		public readonly array $recipients = [],
	) {}
}
