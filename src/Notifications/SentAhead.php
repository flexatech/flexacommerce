<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * An email that goes out some days before a date (ADR-075). The email
 * settings show a days field for it; 0 stored means these default days.
 *
 * @internal
 */
interface SentAhead {
	public function default_days(): int;
}
