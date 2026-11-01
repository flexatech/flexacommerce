<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

defined( 'ABSPATH' ) || exit;

/** The shopper-facing text for a back-in-stock signup, shared by the form and the store route. */
final class SignupMessages {
	public const ADDED = 'added';

	public static function text( string $code ): string {
		return match ( $code ) {
			self::ADDED => __( 'Thanks. We will email you when it is back in stock.', 'flexacommerce' ),
			'off'       => __( 'Back-in-stock emails are turned off.', 'flexacommerce' ),
			'login'     => __( 'Log in to get an email when it is back in stock.', 'flexacommerce' ),
			'email'     => __( 'Enter a valid email address.', 'flexacommerce' ),
			'in_stock'  => __( 'Good news: this is in stock now.', 'flexacommerce' ),
			'limit'     => __( 'Too many signups from your connection. Please try again later.', 'flexacommerce' ),
			default     => __( 'This product cannot be followed.', 'flexacommerce' ),
		};
	}
}
