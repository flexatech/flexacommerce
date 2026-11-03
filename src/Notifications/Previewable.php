<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/**
 * An email the designer can show for any order (ADR-085), with made-up
 * values for what the real trigger would pass (a refund, a reason).
 */
interface Previewable {
	public function preview( OrderView $order ): EmailMessage;
}
