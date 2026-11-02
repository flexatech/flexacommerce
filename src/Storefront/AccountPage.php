<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Checkout\CheckoutValidator;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderAccess;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\RecurringGateway;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\Account;
use Flexa\Commerce\Storefront\Render\Component;
use Flexa\Commerce\Subscriptions\Snapshot;
use Flexa\Commerce\Subscriptions\Subscription;
use Flexa\Commerce\Subscriptions\SwitchQuote;
use Flexa\Commerce\Support\SameSite;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Wishlist\WishlistCookies;
use Flexa\Commerce\Wishlist\WishlistRefused;
use Flexa\Commerce\Wishlist\WishlistService;

defined( 'ABSPATH' ) || exit;

/**
 * The account page (doc 02 §6.3): dashboard, orders, one order, the saved
 * addresses, the account details, the store credit (ADR-072) and the
 * subscriptions (ADR-073). Sub-pages are rewrite endpoints on
 * the page (PostTypes::ACCOUNT_ENDPOINTS). Forms post to the page itself:
 * template_redirect checks the nonce and the origin, saves, and redirects
 * with a notice; on errors the page renders again with the posted values.
 * Guests get the sign-in form and the order lookup, and see one order when
 * the link carries its key (ADR-057).
 */
final class AccountPage {
	use HasInstance;

	public const ACTION = 'fxc_account_action';
	public const NONCE  = 'fxc_account_nonce';
	public const SAVED  = 'fxc_saved';

	public const PER_PAGE = 10;

	/** @var array<string,mixed> */
	private array $posted = [];

	/** @var array<string,string> */
	private array $errors = [];

	private ?CustomerProfile $profile = null;

	/** A subscription change (ADR-076) or plan switch (ADR-077) priced for the customer to confirm. */
	private Snapshot|SwitchQuote|null $preview = null;

	/**
	 * The account page, or one of its sub-pages. With plain permalinks the
	 * endpoint is a query argument.
	 */
	public static function url( string $endpoint = '', string $value = '' ): string {
		$page = Settings::int( 'account_page_id' );
		$base = $page > 0 ? get_permalink( $page ) : false;
		$base = is_string( $base ) ? $base : home_url( '/' );
		if ( $endpoint === '' ) {
			return $base;
		}
		$var = array_search( $endpoint, PostTypes::ACCOUNT_ENDPOINTS, true );
		if ( $var === false ) {
			return $base;
		}
		if ( get_option( 'permalink_structure' ) === '' || str_contains( $base, '?' ) ) {
			return add_query_arg( $var, $value !== '' ? $value : '1', $base );
		}
		return user_trailingslashit( trailingslashit( $base ) . $endpoint . ( $value !== '' ? '/' . rawurlencode( $value ) : '' ) );
	}

	/**
	 * The endpoint this request asks for and its value: `['orders', '2']`,
	 * or `['', '']` for the dashboard.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function current(): array {
		global $wp;
		$vars = $wp instanceof \WP ? $wp->query_vars : [];
		foreach ( PostTypes::ACCOUNT_ENDPOINTS as $var => $slug ) {
			if ( isset( $vars[ $var ] ) ) {
				return [ $slug, sanitize_text_field( (string) $vars[ $var ] ) ];
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- routing only.
			if ( isset( $_GET[ $var ] ) ) {
				return [ $slug, sanitize_text_field( wp_unslash( (string) $_GET[ $var ] ) ) ];
			}
			// phpcs:enable
		}
		return [ '', '' ];
	}

	public function handle(): void {
		nocache_headers();
		// A guest wishlist moves into the account before the page prints, while cookies can still be set (ADR-066).
		if ( is_user_logged_in() && isset( $_COOKIE[ WishlistCookies::TOKEN ] ) && WishlistService::enabled() ) {
			// A signed-in customer is never refused: only a new guest list is.
			Plugin::instance()->wishlist()->current( get_current_user_id() );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the payment provider confirms the reference, and it must name this subscription (ADR-074).
		if ( is_user_logged_in() && isset( $_GET[ RecurringGateway::SETUP_ARG ] ) ) {
			$this->finish_card( get_current_user_id(), sanitize_text_field( wp_unslash( (string) $_GET[ RecurringGateway::SETUP_ARG ] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified() checks the nonce before anything changes.
		if ( ! is_user_logged_in() || ! isset( $_POST[ self::ACTION ] ) ) {
			return;
		}
		$this->posted = $this->posted();
		$action       = sanitize_key( (string) ( $this->posted[ self::ACTION ] ?? '' ) );
		// phpcs:enable
		if ( ! $this->verified( $action ) ) {
			return;
		}
		$user = get_current_user_id();
		if ( $action === 'address' ) {
			$this->save_address( $user );
		} elseif ( $action === 'details' ) {
			$this->save_details( $user );
		} elseif ( $action === 'subscription' ) {
			$this->change_subscription( $user );
		}
	}

	/** What the account block or shortcode shows for this request. */
	public function render(): string {
		$view                 = new Account( $this->profile(), Plugin::instance()->checkout()->service() );
		$user                 = get_current_user_id();
		[ $endpoint, $value ] = self::current();
		$key                  = self::key();
		if ( $endpoint === 'view-order' && $key !== '' ) {
			$order = Plugin::instance()->checkout()->service()->viewable_order( absint( $value ), $key, $user );
			if ( $user === 0 || $order === null || ! OrderAccess::is_owner( $order, $user ) ) {
				return $view->guest_order( $order );
			}
		}
		// Plain permalinks link the own list as `?fxc_wishlist=1`.
		if ( $endpoint === 'wishlist' && $value !== '' && $value !== '1' ) {
			return $this->shared_wishlist( $value );
		}
		if ( $user === 0 ) {
			// A guest's own wishlist lives in a cookie, so it shows without signing in (ADR-066).
			return $endpoint === 'wishlist' ? $this->wishlist( 0 ) : $view->login( self::url() ) . OrderLookup::instance()->render();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$saved = isset( $_GET[ self::SAVED ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::SAVED ] ) ) : '';

		$content = match ( $endpoint ) {
			'orders' => $view->orders( $user, max( 1, absint( $value ) ) ),
			'view-order' => $view->order( $this->order( absint( $value ), $user ) ),
			'downloads' => $view->downloads( $user ),
			'edit-address' => $view->addresses( $user, $value, $this->posted, $this->errors ),
			'edit-account' => $view->details( $user, $this->posted, $this->errors ),
			'wishlist' => $this->wishlist_content( $user ),
			'store-credit' => $view->credit( $user ),
			'subscriptions' => $view->subscriptions( $user ),
			'view-subscription' => $view->subscription( Plugin::instance()->subscriptions()->owned( absint( $value ), $user ), $this->errors, $this->posted, $this->preview ),
			default => $view->dashboard( $user ),
		};
		return $view->page( $endpoint, $saved, $content );
	}

	/**
	 * The visitor's wishlist outside the account navigation: the guest view
	 * of the endpoint and the `[flexacommerce_wishlist]` shortcode.
	 */
	public function wishlist( int $user ): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- read by page cache plugins.
		}
		$html = '<div class="fxc-account fxc-account--guest"><div class="fxc-account__content"><h2>' . esc_html__( 'Wishlist', 'flexacommerce' ) . '</h2>' . $this->wishlist_content( $user );
		if ( $user === 0 && WishlistService::enabled() ) {
			$html .= '<p>' . sprintf(
				/* translators: %s: link to the sign-in form. */
				esc_html__( '%s to keep your wishlist on every device.', 'flexacommerce' ),
				'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Sign in', 'flexacommerce' ) . '</a>'
			) . '</p>';
		}
		return Component::wrap( 'account', static fn (): string => $html . '</div></div>', [ 'endpoint' => 'wishlist' ] );
	}

	private function wishlist_content( int $user ): string {
		if ( ! WishlistService::enabled() ) {
			return '<p class="fxc-notice">' . esc_html( WishlistRefused::text( 'off' ) ) . '</p>';
		}
		try {
			$list = Plugin::instance()->wishlist()->current( $user );
		} catch ( WishlistRefused ) {
			$list = null;
		}
		$service = Plugin::instance()->wishlist();
		return Storefront::instance()->wishlist_table()->html( $list !== null ? $service->items( $list ) : [], true, $list->share_key ?? '', $user );
	}

	/** A wishlist opened from its share link: read-only, no owner shown. */
	private function shared_wishlist( string $key ): string {
		$list = WishlistService::enabled() ? Plugin::instance()->wishlist()->shared( $key ) : null;
		$body = $list === null
			? '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'This wishlist link does not work any more.', 'flexacommerce' ) . '</p>'
			: Storefront::instance()->wishlist_table()->html( Plugin::instance()->wishlist()->items( $list ), false, '', get_current_user_id() );
		return Component::wrap(
			'account',
			static fn (): string => '<div class="fxc-account fxc-account--guest"><div class="fxc-account__content"><h2>' . esc_html__( 'Shared wishlist', 'flexacommerce' ) . '</h2>' . $body . '</div></div>',
			[ 'endpoint' => 'wishlist' ]
		);
	}

	/** The order key of a guest order link (ADR-057). */
	private static function key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the credential; CheckoutService checks it with a failure limit.
		return isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	}

	private function order( int $order_id, int $user ): ?Order {
		$order = $order_id > 0 ? Plugin::instance()->orders()->orders()->find( $order_id ) : null;
		return $order !== null && OrderAccess::is_owner( $order, $user ) ? $order : null;
	}

	private function save_address( int $user ): void {
		[ , $type ] = self::current();
		if ( ! in_array( $type, CustomerProfile::TYPES, true ) ) {
			return;
		}
		$input = [];
		foreach ( is_array( $this->posted[ $type ] ?? null ) ? $this->posted[ $type ] : [] as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$input[ $key ] = (string) $value;
			}
		}
		if ( isset( $this->posted[ CheckoutPage::REFRESH ] ) ) {
			// Country changed without scripts: show the fields of the new country.
			return;
		}
		[ $address, $errors ] = ( new CheckoutValidator() )->single_address( $type, $input );
		if ( $errors !== [] ) {
			$this->errors = $errors;
			return;
		}
		$this->profile()->save( $user, $address );
		self::redirect( add_query_arg( self::SAVED, 'address', self::url( 'edit-address' ) ) );
	}

	private function save_details( int $user ): void {
		$account = get_userdata( $user );
		if ( ! $account instanceof \WP_User ) {
			return;
		}
		$text     = fn ( string $key ): string => sanitize_text_field( (string) ( is_scalar( $this->posted[ $key ] ?? null ) ? $this->posted[ $key ] : '' ) );
		$raw      = fn ( string $key ): string => is_string( $this->posted[ $key ] ?? null ) ? $this->posted[ $key ] : '';
		$first    = $text( 'account_first_name' );
		$last     = $text( 'account_last_name' );
		$display  = $text( 'account_display_name' );
		$email    = sanitize_email( $text( 'account_email' ) );
		$current  = $raw( 'password_current' );
		$password = $raw( 'password_1' );
		$confirm  = $raw( 'password_2' );

		$errors = [];
		if ( $display === '' ) {
			$errors['account_display_name'] = __( 'Enter a display name.', 'flexacommerce' );
		}
		if ( $email === '' || ! is_email( $email ) ) {
			$errors['account_email'] = __( 'Enter a valid email address.', 'flexacommerce' );
		} elseif ( strtolower( $email ) !== strtolower( $account->user_email ) && email_exists( $email ) !== false ) {
			$errors['account_email'] = __( 'This email address is already registered.', 'flexacommerce' );
		}
		if ( $password !== '' && $password !== $confirm ) {
			$errors['password_2'] = __( 'The new passwords do not match.', 'flexacommerce' );
		}
		$sensitive = $password !== '' || strtolower( $email ) !== strtolower( $account->user_email );
		if ( $sensitive && ( $current === '' || ! wp_check_password( $current, $account->user_pass, $user ) ) ) {
			$errors['password_current'] = __( 'Enter your current password to change your email or password.', 'flexacommerce' );
		}
		if ( $errors !== [] ) {
			$this->errors = $errors;
			return;
		}

		$data = [
			'ID'           => $user,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $display,
			'user_email'   => $email,
		];
		if ( $password !== '' ) {
			$data['user_pass'] = $password;
		}
		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			$this->errors = [ 'account_email' => $result->get_error_message() ];
			return;
		}
		if ( $password !== '' ) {
			// wp_update_user() logs the user out of every session; keep this one.
			wp_set_auth_cookie( $user, false, is_ssl() );
		}
		/** Fires after a customer saved their account details on the account page. */
		do_action( 'flexacommerce.customer.details_saved', $user );
		self::redirect( add_query_arg( self::SAVED, 'account', self::url( 'edit-account' ) ) );
	}

	/** Back from the payment provider's page with a new method for a subscription (ADR-074). */
	private function finish_card( int $user, string $reference ): void {
		[ $endpoint, $value ] = self::current();
		$service              = Plugin::instance()->subscriptions();
		$row                  = $endpoint === 'view-subscription' ? $service->owned( absint( $value ), $user ) : null;
		if ( $row === null ) {
			return;
		}
		try {
			$result = $service->finish_update( $row, $reference, new TransitionContext( 'customer', $user ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->errors = [ 'form' => $e->getMessage() ];
			return;
		}
		$saved = match ( true ) {
			$result === null => 'subscription_card',
			$result->type === PaymentResult::FAILED => 'subscription_card_failed',
			default => 'subscription_card_paid',
		};
		self::redirect( add_query_arg( self::SAVED, $saved, self::url( 'view-subscription', (string) $row->id ) ) );
	}

	/**
	 * Cancel or reactivate a subscription of the account (ADR-073), go save
	 * a new payment method (ADR-074), pause or resume it, change its
	 * quantities or address (ADR-076), or switch it to other variations
	 * (ADR-077).
	 */
	private function change_subscription( int $user ): void {
		$service = Plugin::instance()->subscriptions();
		$id      = absint( is_scalar( $this->posted['subscription_id'] ?? null ) ? $this->posted['subscription_id'] : 0 );
		$do      = sanitize_key( is_scalar( $this->posted['subscription_do'] ?? null ) ? (string) $this->posted['subscription_do'] : '' );
		$row     = $service->owned( $id, $user );
		if ( $row === null ) {
			$this->errors = [ 'form' => __( 'Subscription not found.', 'flexacommerce' ) ];
			return;
		}
		$ctx = new TransitionContext( 'customer', $user );
		try {
			if ( $do === 'change' ) {
				if ( ! $this->change_items( $row, $ctx ) ) {
					return;
				}
			} elseif ( $do === 'switch' ) {
				$order = $this->switch_plan( $row, $ctx );
				if ( $order === false ) {
					return;
				}
				if ( $order !== null && $order->status === OrderStatus::PENDING && $order->balance_due() > 0 ) {
					// Nothing saved to charge, or the charge failed: the shopper pays on the order's pay page.
					self::redirect( Plugin::instance()->checkout()->pages()->pay_url( $order ) );
				}
				$do = $order === null ? 'switch' : 'switch_paid';
			} elseif ( $do === 'pause' ) {
				$service->pause( $id, $ctx );
			} elseif ( $do === 'resume' ) {
				$order = $service->resume( $id, $ctx );
				$do    = $order === null ? 'resume' : ( $service->unpaid_renewal( $service->find( $id ) ?? $row ) !== null ? 'resume_due' : 'resume_paid' );
			} elseif ( $do === 'reactivate' ) {
				$service->reactivate( $id, $ctx );
			} elseif ( $do === 'cancel' ) {
				$service->cancel( $id, true, $ctx );
			} elseif ( $do === 'card' ) {
				// The provider's own page, on its own host.
				wp_redirect( $service->update_url( $row, self::url( 'view-subscription', (string) $id ) ), 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the gateway's hosted page.
				exit;
			} else {
				return;
			}
		} catch ( \InvalidArgumentException $e ) {
			$this->errors = [ 'form' => $e->getMessage() ];
			return;
		}
		self::redirect( add_query_arg( self::SAVED, 'subscription_' . $do, self::url( 'view-subscription', (string) $id ) ) );
	}

	/**
	 * New quantities or a new address, priced again. A change that moves
	 * the price is shown first and saved when the customer confirms the
	 * same total; one that does not is saved at once. Returns whether it
	 * was saved.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function change_items( Subscription $row, TransitionContext $ctx ): bool {
		$quantities = [];
		foreach ( is_array( $this->posted['subscription_qty'] ?? null ) ? $this->posted['subscription_qty'] : [] as $index => $quantity ) {
			if ( is_int( $index ) && is_scalar( $quantity ) ) {
				$quantities[ $index ] = min( 9999, absint( $quantity ) );
			}
		}
		$address = null;
		if ( ! empty( $this->posted['subscription_address'] ) ) {
			if ( isset( $this->posted[ CheckoutPage::REFRESH ] ) ) {
				// Country changed without scripts: show the fields of the new country.
				return false;
			}
			$type  = $row->shipping !== null ? 'shipping' : 'billing';
			$input = [];
			foreach ( is_array( $this->posted[ $type ] ?? null ) ? $this->posted[ $type ] : [] as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) ) {
					$input[ $key ] = (string) $value;
				}
			}
			[ $address, $errors ] = ( new CheckoutValidator() )->single_address( $type, $input );
			if ( $errors !== [] ) {
				$this->errors = $errors;
				return false;
			}
		}
		if ( $quantities === [] && ! $address instanceof AddressView ) {
			return false;
		}
		$service  = Plugin::instance()->subscriptions();
		$snapshot = $service->quote_change( $row, $quantities, $address );
		$expected = is_scalar( $this->posted['subscription_total'] ?? null ) ? (string) $this->posted['subscription_total'] : '';
		$confirm  = ! empty( $this->posted['subscription_confirm'] ) && $expected === (string) $snapshot->total;
		if ( ! $confirm && $snapshot->total !== $row->total ) {
			$this->preview = $snapshot;
			return false;
		}
		$service->apply_change( $row, $snapshot, $address, $ctx );
		return true;
	}

	/**
	 * Other variations for some lines, priced with what switching costs
	 * now (ADR-077). It is shown first and made when the customer confirms
	 * the same price and charge. Returns false while nothing is made, else
	 * the switch order, or null when nothing was owed.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function switch_plan( Subscription $row, TransitionContext $ctx ): Order|false|null {
		$switch = [];
		foreach ( is_array( $this->posted['subscription_variant'] ?? null ) ? $this->posted['subscription_variant'] : [] as $index => $variant ) {
			$variant = is_scalar( $variant ) ? absint( $variant ) : 0;
			if ( is_int( $index ) && $variant > 0 && $variant !== (int) ( $row->snapshot->items[ $index ]->variant_id ?? 0 ) ) {
				$switch[ $index ] = $variant;
			}
		}
		if ( $switch === [] ) {
			$this->errors = [ 'form' => __( 'Choose an option to switch to.', 'flexacommerce' ) ];
			return false;
		}
		$service  = Plugin::instance()->subscriptions();
		$quote    = $service->quote_switch( $row, $switch );
		$expected = is_scalar( $this->posted['subscription_quote'] ?? null ) ? (string) $this->posted['subscription_quote'] : '';
		if ( empty( $this->posted['subscription_confirm'] ) || $expected !== $quote->snapshot->total . ':' . $quote->charge ) {
			$this->preview = $quote;
			return false;
		}
		return $service->apply_switch( $row, $quote, $ctx );
	}

	private function verified( string $action ): bool {
		$nonce = is_string( $this->posted[ self::NONCE ] ?? null ) ? $this->posted[ self::NONCE ] : '';
		if ( SameSite::current() && wp_verify_nonce( $nonce, self::ACTION . '_' . $action ) !== false ) {
			return true;
		}
		$this->errors = [ 'form' => __( 'Your session has expired. Please try again.', 'flexacommerce' ) ];
		return false;
	}

	/**
	 * The raw submission; each field is cleaned where it is used, and the
	 * renderer escapes.
	 *
	 * @return array<string,mixed>
	 */
	private function posted(): array {
		$out = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see the method comment; the nonce is checked next.
		foreach ( wp_unslash( $_POST ) as $key => $value ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private function profile(): CustomerProfile {
		return $this->profile ??= new CustomerProfile();
	}

	private static function redirect( string $url ): never {
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/** @return array<string,string> the errors of the last post, for tests */
	public function errors(): array {
		return $this->errors;
	}
}
