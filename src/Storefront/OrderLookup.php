<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\Account;
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Support\SameSite;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Order lookup for guests (ADR-057): the order number and the billing email
 * lead to the order's page on the account page, with the order key in the
 * URL. The form has no nonce, so it keeps working on cached pages; it only
 * reads, the origin must be this site and failures are rate limited per IP
 * (doc 06 §2.9). A wrong number and a wrong email get the same message.
 */
final class OrderLookup {
	use HasInstance;

	/** Literal in Plugin::on_template_redirect(), which must not load this class on other pages. */
	public const ACTION = 'fxc_order_lookup';
	public const NUMBER = 'order_number';
	public const EMAIL  = 'order_email';

	public const LIMIT = 'order_lookup_ip';

	/** @var array<string,string> */
	private array $posted = [];

	/** @var array<string,string> */
	private array $errors = [];

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only lookup; same-site check and a failure limit instead (see the class comment).
		if ( ! isset( $_POST[ self::ACTION ] ) ) {
			return;
		}
		$this->posted = [
			self::NUMBER => isset( $_POST[ self::NUMBER ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NUMBER ] ) ) : '',
			self::EMAIL  => isset( $_POST[ self::EMAIL ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::EMAIL ] ) ) : '',
		];
		// phpcs:enable
		if ( ! SameSite::current() ) {
			$this->errors = [ 'form' => __( 'Your session has expired. Please try again.', 'flexacommerce' ) ];
			return;
		}
		$number = ltrim( trim( $this->posted[ self::NUMBER ] ), '#' );
		$email  = sanitize_email( $this->posted[ self::EMAIL ] );
		if ( $number === '' ) {
			$this->errors[ self::NUMBER ] = __( 'Enter the order number from your confirmation email.', 'flexacommerce' );
		}
		if ( $email === '' || ! is_email( $email ) ) {
			$this->errors[ self::EMAIL ] = __( 'Enter the email address used for the order.', 'flexacommerce' );
		}
		if ( $this->errors !== [] ) {
			return;
		}

		$limiter = new RateLimiter();
		$ip      = ClientIp::get();
		try {
			$limiter->check( self::LIMIT, $ip );
		} catch ( RateLimited ) {
			$this->errors = [ 'form' => __( 'Too many lookups from your connection. Please try again later.', 'flexacommerce' ) ];
			return;
		}
		$order = Plugin::instance()->orders()->orders()->find_by_number( $number );
		if ( $order === null || ! hash_equals( strtolower( $order->customer_email ), strtolower( $email ) ) ) {
			$limiter->count( self::LIMIT, $ip );
			$this->errors = [ 'form' => __( 'No order matches that order number and email address.', 'flexacommerce' ) ];
			return;
		}
		/** Fires when a visitor found an order with its number and email, before the redirect to it. */
		do_action( 'flexacommerce.order.looked_up', $order );
		wp_safe_redirect( self::url( $order ), 303 );
		exit;
	}

	/**
	 * Where a found order is shown: its page on the account page, or the
	 * order confirmation when the store has no account page.
	 */
	public static function url( Order $order ): string {
		if ( Settings::int( 'account_page_id' ) <= 0 ) {
			return Plugin::instance()->checkout()->service()->pages()->confirmation_url( $order );
		}
		return add_query_arg( 'key', $order->order_key, AccountPage::url( 'view-order', (string) $order->id ) );
	}

	/** The form, with the values and errors of the last post. */
	public function render(): string {
		return Account::lookup( $this->posted, $this->errors );
	}

	/** @return array<string,string> the errors of the last post, for tests */
	public function errors(): array {
		return $this->errors;
	}
}
