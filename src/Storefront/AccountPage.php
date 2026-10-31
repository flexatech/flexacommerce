<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Checkout\CheckoutValidator;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderAccess;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\Account;
use Flexa\Commerce\Support\SameSite;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The account page (doc 02 §6.3): dashboard, orders, one order, the saved
 * addresses and the account details. Sub-pages are rewrite endpoints on
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
		if ( $user === 0 ) {
			return $view->login( self::url() ) . OrderLookup::instance()->render();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$saved = isset( $_GET[ self::SAVED ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::SAVED ] ) ) : '';

		$content = match ( $endpoint ) {
			'orders' => $view->orders( $user, max( 1, absint( $value ) ) ),
			'view-order' => $view->order( $this->order( absint( $value ), $user ) ),
			'downloads' => $view->downloads( $user ),
			'edit-address' => $view->addresses( $user, $value, $this->posted, $this->errors ),
			'edit-account' => $view->details( $user, $this->posted, $this->errors ),
			default => $view->dashboard( $user ),
		};
		return $view->page( $endpoint, $saved, $content );
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
