<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

defined( 'ABSPATH' ) || exit;

/** A gift card code that exists but cannot pay: disabled, expired, spent or in another currency. The message is for the shopper. */
final class GiftCardUnusable extends \RuntimeException {}
