<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderQuery;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Storefront\CheckoutPage;
use Flexa\Commerce\Storefront\OrderLookup;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Markup of the account page. Plain forms that work without scripts; every
 * form starts with an error summary that links to the fields.
 */
final class Account {
	public function __construct(
		private readonly CustomerProfile $profile,
		private readonly CheckoutService $checkout,
	) {}

	public function login( string $redirect ): string {
		return Component::wrap(
			'account-login',
			static function () use ( $redirect ): string {
				$html  = '<div class="fxc-account fxc-account--login"><h2>' . esc_html__( 'Sign in', 'flexacommerce' ) . '</h2>';
				$html .= wp_login_form(
					[
						'echo'     => false,
						'redirect' => $redirect,
						'form_id'  => 'fxc-login',
					]
				);
				$html .= '<p><a href="' . esc_url( wp_lostpassword_url( $redirect ) ) . '">' . esc_html__( 'Lost your password?', 'flexacommerce' ) . '</a></p>';
				if ( get_option( 'users_can_register' ) ) {
					$html .= '<p><a href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'Create an account', 'flexacommerce' ) . '</a></p>';
				}
				return $html . '</div>';
			},
			[]
		);
	}

	/**
	 * The order lookup form (ADR-057): order number and billing email. It
	 * posts to the page it is on; Plugin sends the post to OrderLookup.
	 *
	 * @param array<string,string> $posted
	 * @param array<string,string> $errors
	 */
	public static function lookup( array $posted, array $errors ): string {
		return Component::wrap(
			'order-lookup',
			static function () use ( $posted, $errors ): string {
				$html  = '<div class="fxc-account fxc-account--login fxc-order-lookup"><h2>' . esc_html__( 'Look up an order', 'flexacommerce' ) . '</h2>';
				$html .= '<form method="post" class="fxc-account__form" novalidate>';
				$html .= self::summary( $errors );
				$html .= '<p>' . esc_html__( 'Checked out without an account? Enter the order number from your confirmation email and the email address you used.', 'flexacommerce' ) . '</p>';
				$html .= self::input( OrderLookup::NUMBER, __( 'Order number', 'flexacommerce' ), $posted[ OrderLookup::NUMBER ] ?? '', 'text', 'off', true, $errors );
				$html .= self::input( OrderLookup::EMAIL, __( 'Email address', 'flexacommerce' ), $posted[ OrderLookup::EMAIL ] ?? '', 'email', 'email', true, $errors );
				$html .= '<input type="hidden" name="' . esc_attr( OrderLookup::ACTION ) . '" value="1">';
				return $html . '<p><button type="submit" class="fxc-button">' . esc_html__( 'Find order', 'flexacommerce' ) . '</button></p></form></div>';
			},
			[]
		);
	}

	/** An order opened with its key, for a visitor who is not its owner: no account navigation. */
	public function guest_order( ?Order $order ): string {
		$content = $this->order( $order );
		return Component::wrap(
			'account',
			static fn (): string => '<div class="fxc-account fxc-account--guest"><div class="fxc-account__content">' . $content
				. '<p><a href="' . esc_url( AccountPage::url() ) . '">' . esc_html__( 'Look up another order', 'flexacommerce' ) . '</a></p></div></div>',
			[ 'endpoint' => 'view-order' ]
		);
	}

	/** The navigation, a saved notice and the sub-page. */
	public function page( string $endpoint, string $saved, string $content ): string {
		$links  = [
			''             => __( 'Dashboard', 'flexacommerce' ),
			'orders'       => __( 'Orders', 'flexacommerce' ),
			'edit-address' => __( 'Addresses', 'flexacommerce' ),
			'edit-account' => __( 'Account details', 'flexacommerce' ),
		];
		$active = $endpoint === 'view-order' ? 'orders' : $endpoint;
		$nav    = '<nav class="fxc-account__nav" aria-label="' . esc_attr__( 'Account', 'flexacommerce' ) . '"><ul>';
		foreach ( $links as $slug => $label ) {
			$nav .= sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url( AccountPage::url( $slug ) ),
				$slug === $active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		$nav .= '<li><a href="' . esc_url( wp_logout_url( AccountPage::url() ) ) . '">' . esc_html__( 'Log out', 'flexacommerce' ) . '</a></li></ul></nav>';

		$notice = match ( $saved ) {
			'address' => __( 'Address saved.', 'flexacommerce' ),
			'account' => __( 'Account details saved.', 'flexacommerce' ),
			default => '',
		};
		$notice = $notice !== '' ? '<p class="fxc-notice" role="status">' . esc_html( $notice ) . '</p>' : '';

		return Component::wrap(
			'account',
			static fn (): string => '<div class="fxc-account">' . $nav . '<div class="fxc-account__content">' . $notice . $content . '</div></div>',
			[ 'endpoint' => $endpoint ]
		);
	}

	public function dashboard( int $user_id ): string {
		$user = get_userdata( $user_id );
		$name = $user instanceof \WP_User ? $user->display_name : '';
		return '<p>' . sprintf(
			/* translators: %s: the customer's display name. */
			esc_html__( 'Hello %s.', 'flexacommerce' ),
			'<strong>' . esc_html( $name ) . '</strong>'
		) . '</p><p>' . sprintf(
			/* translators: 1: link to the orders, 2: link to the addresses, 3: link to the account details. */
			esc_html__( 'Here you can see your %1$s, manage your %2$s and edit your %3$s.', 'flexacommerce' ),
			'<a href="' . esc_url( AccountPage::url( 'orders' ) ) . '">' . esc_html__( 'recent orders', 'flexacommerce' ) . '</a>',
			'<a href="' . esc_url( AccountPage::url( 'edit-address' ) ) . '">' . esc_html__( 'addresses', 'flexacommerce' ) . '</a>',
			'<a href="' . esc_url( AccountPage::url( 'edit-account' ) ) . '">' . esc_html__( 'password and account details', 'flexacommerce' ) . '</a>'
		) . '</p>';
	}

	public function orders( int $user_id, int $page ): string {
		$result = Plugin::instance()->orders()->orders()->search( new OrderQuery( customer_id: $user_id, page: $page, per_page: AccountPage::PER_PAGE ) );
		if ( $result['orders'] === [] ) {
			return '<p class="fxc-notice">' . esc_html__( 'No orders yet.', 'flexacommerce' ) . '</p>';
		}
		$html = '<table class="fxc-account__orders"><caption>' . esc_html__( 'Orders', 'flexacommerce' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Order', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Date', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Total', 'flexacommerce' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'flexacommerce' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $result['orders'] as $order ) {
			$view    = AccountPage::url( 'view-order', (string) $order->id );
			$actions = '<a href="' . esc_url( $view ) . '">' . sprintf(
				/* translators: %s: order number. */
				esc_html__( 'View order %s', 'flexacommerce' ),
				esc_html( $order->order_number )
			) . '</a>';
			if ( self::needs_payment( $order ) ) {
				$actions .= ' <a href="' . esc_url( $this->checkout->pages()->pay_url( $order ) ) . '">' . esc_html__( 'Pay', 'flexacommerce' ) . '</a>';
			}
			$html .= sprintf(
				'<tr><th scope="row"><a href="%1$s">%2$s</a></th><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( $view ),
				esc_html( $order->order_number ),
				esc_html( self::date( $order ) ),
				esc_html( OrderStatus::label( $order->status ) ),
				esc_html( $this->checkout->format( $order->total, $order->currency ) ),
				$actions
			);
		}
		$html .= '</tbody></table>';

		$pages = (int) ceil( $result['total'] / AccountPage::PER_PAGE );
		if ( $pages > 1 ) {
			$html .= '<nav class="fxc-account__pages" aria-label="' . esc_attr__( 'Order pages', 'flexacommerce' ) . '">';
			if ( $page > 1 ) {
				$html .= '<a rel="prev" href="' . esc_url( AccountPage::url( 'orders', (string) ( $page - 1 ) ) ) . '">' . esc_html__( 'Newer orders', 'flexacommerce' ) . '</a> ';
			}
			if ( $page < $pages ) {
				$html .= '<a rel="next" href="' . esc_url( AccountPage::url( 'orders', (string) ( $page + 1 ) ) ) . '">' . esc_html__( 'Older orders', 'flexacommerce' ) . '</a>';
			}
			$html .= '</nav>';
		}
		return $html;
	}

	/** One order of this customer; null when it is not theirs, so both look the same. */
	public function order( ?Order $order ): string {
		if ( $order === null ) {
			return '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'Order not found.', 'flexacommerce' ) . '</p>';
		}
		$html = '<p>' . sprintf(
			/* translators: 1: order number, 2: date, 3: status. */
			esc_html__( 'Order %1$s was placed on %2$s and is %3$s.', 'flexacommerce' ),
			'<strong>' . esc_html( $order->order_number ) . '</strong>',
			'<strong>' . esc_html( self::date( $order ) ) . '</strong>',
			'<strong>' . esc_html( OrderStatus::label( $order->status ) ) . '</strong>'
		) . '</p>';
		if ( self::needs_payment( $order ) ) {
			$html .= '<p><a class="fxc-button" href="' . esc_url( $this->checkout->pages()->pay_url( $order ) ) . '">' . esc_html__( 'Pay for this order', 'flexacommerce' ) . '</a></p>';
		}

		$notes = Plugin::instance()->orders()->history()->for_order( $order->id, true );
		if ( $notes !== [] ) {
			$html .= '<section class="fxc-account__updates"><h2>' . esc_html__( 'Order updates', 'flexacommerce' ) . '</h2><ol>';
			foreach ( $notes as $note ) {
				$html .= sprintf(
					'<li><time datetime="%1$s">%2$s</time> %3$s</li>',
					esc_attr( mysql2date( 'c', $note['created_at'] . ' UTC' ) ),
					esc_html( mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $note['created_at'] ) ) ),
					wpautop( esc_html( $note['message'] ) )
				);
			}
			$html .= '</ol></section>';
		}

		$html .= ( new OrderConfirmation( $this->checkout ) )->items( $order );
		$html .= OrderConfirmation::fields( $order );
		if ( $order->customer_note !== '' ) {
			$html .= '<section class="fxc-account__note"><h2>' . esc_html__( 'Note', 'flexacommerce' ) . '</h2>' . wpautop( esc_html( $order->customer_note ) ) . '</section>';
		}
		return $html . '<div class="fxc-order__addresses">'
			. OrderConfirmation::address( __( 'Billing address', 'flexacommerce' ), $order->billing )
			. OrderConfirmation::address( __( 'Shipping address', 'flexacommerce' ), $order->shipping )
			. '</div>';
	}

	/**
	 * Both addresses with an edit link, or the form of one.
	 *
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	public function addresses( int $user_id, string $type, array $posted, array $errors ): string {
		$titles = [
			'billing'  => __( 'Billing address', 'flexacommerce' ),
			'shipping' => __( 'Shipping address', 'flexacommerce' ),
		];
		if ( ! isset( $titles[ $type ] ) ) {
			$html = '<p>' . esc_html__( 'These addresses fill in the checkout form.', 'flexacommerce' ) . '</p><div class="fxc-order__addresses">';
			foreach ( $titles as $key => $title ) {
				$address = $this->profile->address( $user_id, $key );
				$html   .= $address !== null
					? OrderConfirmation::address( $title, $address )
					: '<section class="fxc-order__address"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html__( 'You have not set up this address yet.', 'flexacommerce' ) . '</p></section>';
				$html   .= '<p><a href="' . esc_url( AccountPage::url( 'edit-address', $key ) ) . '">' . sprintf(
					/* translators: %s: address title such as "Billing address". */
					esc_html__( 'Edit %s', 'flexacommerce' ),
					esc_html( strtolower( $title ) )
				) . '</a></p>';
			}
			return $html . '</div>';
		}

		$saved   = $this->profile->address( $user_id, $type );
		$values  = is_array( $posted[ $type ] ?? null ) ? $posted[ $type ] : ( $saved !== null ? $saved->to_array() : [] );
		$country = is_string( $values['country'] ?? null ) && $values['country'] !== '' ? strtoupper( $values['country'] ) : Settings::string( 'store_country' );
		$fields  = FieldSchema::for_address( $type, $country )->core()->to_array();

		$html  = '<form method="post" class="fxc-account__form" action="' . esc_url( AccountPage::url( 'edit-address', $type ) ) . '" novalidate>';
		$html .= self::summary( $errors );
		$html .= '<fieldset><legend>' . esc_html( $titles[ $type ] ) . '</legend>';
		$html .= CheckoutForm::fields( $type, $fields, $values, Countries::allowed(), $errors );
		$html .= '<p><button type="submit" name="' . esc_attr( CheckoutPage::REFRESH ) . '" value="1" formnovalidate>' . esc_html__( 'Update the fields for this country', 'flexacommerce' ) . '</button></p>';
		$html .= '</fieldset>';
		$html .= self::hidden( 'address' );
		return $html . '<p><button type="submit" class="fxc-button">' . esc_html__( 'Save address', 'flexacommerce' ) . '</button></p></form>';
	}

	/**
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	public function details( int $user_id, array $posted, array $errors ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}
		$value = static fn ( string $key, string $fallback ): string => is_string( $posted[ $key ] ?? null ) ? $posted[ $key ] : $fallback;

		$html  = '<form method="post" class="fxc-account__form" action="' . esc_url( AccountPage::url( 'edit-account' ) ) . '" novalidate>';
		$html .= self::summary( $errors );
		$html .= self::input( 'account_first_name', __( 'First name', 'flexacommerce' ), $value( 'account_first_name', $user->first_name ), 'text', 'given-name', false, $errors );
		$html .= self::input( 'account_last_name', __( 'Last name', 'flexacommerce' ), $value( 'account_last_name', $user->last_name ), 'text', 'family-name', false, $errors );
		$html .= self::input( 'account_display_name', __( 'Display name', 'flexacommerce' ), $value( 'account_display_name', $user->display_name ), 'text', 'nickname', true, $errors, __( 'This is how your name appears in reviews and in the account area.', 'flexacommerce' ) );
		$html .= self::input( 'account_email', __( 'Email address', 'flexacommerce' ), $value( 'account_email', $user->user_email ), 'email', 'email', true, $errors );
		$html .= '<fieldset><legend>' . esc_html__( 'Change password', 'flexacommerce' ) . '</legend>';
		$html .= self::input( 'password_current', __( 'Current password', 'flexacommerce' ), '', 'password', 'current-password', false, $errors, __( 'Needed to change your email address or password.', 'flexacommerce' ) );
		$html .= self::input( 'password_1', __( 'New password (leave blank to keep the current one)', 'flexacommerce' ), '', 'password', 'new-password', false, $errors );
		$html .= self::input( 'password_2', __( 'Confirm new password', 'flexacommerce' ), '', 'password', 'new-password', false, $errors );
		$html .= '</fieldset>';
		$html .= self::hidden( 'details' );
		return $html . '<p><button type="submit" class="fxc-button">' . esc_html__( 'Save changes', 'flexacommerce' ) . '</button></p></form>';
	}

	/** Pending orders that were not paid can be paid again from the account. */
	private static function needs_payment( Order $order ): bool {
		return $order->status === OrderStatus::PENDING && $order->total > 0 && $order->amount_paid === 0;
	}

	private static function date( Order $order ): string {
		return mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $order->created_at ) );
	}

	private static function hidden( string $action ): string {
		return sprintf(
			'<input type="hidden" name="%1$s" value="%2$s"><input type="hidden" name="%3$s" value="%4$s">',
			esc_attr( AccountPage::ACTION ),
			esc_attr( $action ),
			esc_attr( AccountPage::NONCE ),
			esc_attr( wp_create_nonce( AccountPage::ACTION . '_' . $action ) )
		);
	}

	/** @param array<string,string> $errors */
	private static function input( string $name, string $label, string $value, string $type, string $autocomplete, bool $required, array $errors, string $help = '' ): string {
		$id        = 'fxc-' . str_replace( '_', '-', $name );
		$described = [];
		if ( $help !== '' ) {
			$described[] = $id . '-help';
		}
		if ( isset( $errors[ $name ] ) ) {
			$described[] = $id . '-error';
		}
		return sprintf(
			'<p class="fxc-field"><label for="%1$s">%2$s%3$s</label><input%4$s>%5$s%6$s</p>',
			esc_attr( $id ),
			esc_html( $label ),
			$required ? ' <abbr class="fxc-required" title="' . esc_attr__( 'required', 'flexacommerce' ) . '">*</abbr>' : '',
			Component::attributes(
				[
					'type'             => $type,
					'id'               => $id,
					'name'             => $name,
					'value'            => $type === 'password' ? '' : $value,
					'autocomplete'     => $autocomplete,
					'required'         => $required,
					'aria-invalid'     => isset( $errors[ $name ] ) ? 'true' : null,
					'aria-describedby' => $described !== [] ? implode( ' ', $described ) : null,
				]
			),
			$help !== '' ? '<span class="fxc-field__help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</span>' : '',
			isset( $errors[ $name ] ) ? '<span class="fxc-field__error" id="' . esc_attr( $id ) . '-error">' . esc_html( $errors[ $name ] ) . '</span>' : ''
		);
	}

	/** @param array<string,string> $errors */
	private static function summary( array $errors ): string {
		if ( $errors === [] ) {
			return '';
		}
		$items = '';
		foreach ( $errors as $path => $message ) {
			$target = CheckoutForm::field_id( $path );
			if ( $target === '' && $path !== 'form' ) {
				$target = 'fxc-' . str_replace( '_', '-', $path );
			}
			$items .= $target !== ''
				? '<li><a href="#' . esc_attr( $target ) . '">' . esc_html( $message ) . '</a></li>'
				: '<li>' . esc_html( $message ) . '</li>';
		}
		return '<div class="fxc-notice fxc-notice--error fxc-account__errors" role="alert" tabindex="-1">'
			. '<p>' . esc_html__( 'Please fix the following:', 'flexacommerce' ) . '</p><ul>' . $items . '</ul></div>';
	}
}
