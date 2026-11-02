<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\GiftCards\GiftCardActivity;
use Flexa\Commerce\GiftCards\GiftCardService;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderQuery;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Storefront\CheckoutPage;
use Flexa\Commerce\Storefront\OrderLookup;
use Flexa\Commerce\Subscriptions\Snapshot;
use Flexa\Commerce\Subscriptions\Subscription;
use Flexa\Commerce\Subscriptions\SubscriptionService;
use Flexa\Commerce\Subscriptions\SubscriptionStatus;
use Flexa\Commerce\Subscriptions\SwitchQuote;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Wishlist\WishlistService;

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
		$links = [
			''             => __( 'Dashboard', 'flexacommerce' ),
			'orders'       => __( 'Orders', 'flexacommerce' ),
			'downloads'    => __( 'Downloads', 'flexacommerce' ),
			'edit-address' => __( 'Addresses', 'flexacommerce' ),
			'edit-account' => __( 'Account details', 'flexacommerce' ),
		];
		if ( WishlistService::enabled() ) {
			$links = array_slice( $links, 0, 3, true ) + [ 'wishlist' => __( 'Wishlist', 'flexacommerce' ) ] + $links;
		}
		// Only customers who have store credit see the page (ADR-072).
		if ( (int) get_user_meta( get_current_user_id(), GiftCardService::CREDIT_META, true ) > 0 || $endpoint === 'store-credit' ) {
			$links = array_slice( $links, 0, 3, true ) + [ 'store-credit' => __( 'Store credit', 'flexacommerce' ) ] + $links;
		}
		// Only customers who ever subscribed see the page (ADR-073).
		if ( SubscriptionService::has_any( get_current_user_id() ) || in_array( $endpoint, [ 'subscriptions', 'view-subscription' ], true ) ) {
			$links = array_slice( $links, 0, 2, true ) + [ 'subscriptions' => __( 'Subscriptions', 'flexacommerce' ) ] + $links;
		}
		$active = match ( $endpoint ) {
			'view-order' => 'orders',
			'view-subscription' => 'subscriptions',
			default => $endpoint,
		};
		$nav = '<nav class="fxc-account__nav" aria-label="' . esc_attr__( 'Account', 'flexacommerce' ) . '"><ul>';
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
			'subscription_cancel' => __( 'Your subscription is cancelled. It ends when the period you paid for does.', 'flexacommerce' ),
			'subscription_reactivate' => __( 'Your subscription is active again.', 'flexacommerce' ),
			'subscription_card' => __( 'Payment method saved. Renewals are charged to it.', 'flexacommerce' ),
			'subscription_card_paid' => __( 'Payment method saved, and the renewal is paid.', 'flexacommerce' ),
			'subscription_card_failed' => __( 'Payment method saved, but the renewal payment did not go through. Pay it with the button below.', 'flexacommerce' ),
			'subscription_change' => __( 'Subscription updated. The next renewal bills the change.', 'flexacommerce' ),
			'subscription_switch' => __( 'Plan switched. The next renewal bills the new plan.', 'flexacommerce' ),
			'subscription_switch_paid' => __( 'Plan switched and paid. The next renewal bills the new plan.', 'flexacommerce' ),
			'subscription_pause' => __( 'Your subscription is paused. Nothing is charged or sent until you resume it.', 'flexacommerce' ),
			'subscription_resume' => __( 'Your subscription is active again.', 'flexacommerce' ),
			'subscription_resume_paid' => __( 'Your subscription is active again, and the renewal is paid.', 'flexacommerce' ),
			'subscription_resume_due' => __( 'Your subscription is active again. Pay the renewal with the button below.', 'flexacommerce' ),
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
		$user   = get_userdata( $user_id );
		$name   = $user instanceof \WP_User ? $user->display_name : '';
		$card   = Plugin::instance()->gift_cards()->credit_account( $user_id );
		$credit = $card !== null && $card->balance > 0 ? '<p class="fxc-account__credit-balance">' . sprintf(
			/* translators: %s: store credit balance, linked to the store credit page. */
			esc_html__( 'You have %s in store credit. It is used at checkout.', 'flexacommerce' ),
			'<a href="' . esc_url( AccountPage::url( 'store-credit' ) ) . '">' . esc_html( $this->checkout->format( $card->balance, $card->currency ) ) . '</a>'
		) . '</p>' : '';
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
		) . '</p>' . $credit;
	}

	/**
	 * The store credit balance and what changed it (ADR-072). The credit
	 * pays at checkout by itself, so the page has no code to copy.
	 */
	public function credit( int $user_id ): string {
		$card = Plugin::instance()->gift_cards()->credit_account( $user_id );
		if ( $card === null ) {
			return '<p class="fxc-notice">' . esc_html__( 'You have no store credit.', 'flexacommerce' ) . '</p>';
		}
		$html = '<p class="fxc-account__credit-balance">' . sprintf(
			/* translators: %s: store credit balance. */
			esc_html__( 'Balance: %s', 'flexacommerce' ),
			'<strong>' . esc_html( $this->checkout->format( $card->balance, $card->currency ) ) . '</strong>'
		) . '</p><p>' . esc_html__( 'Your store credit pays at checkout before you choose a payment method. It never expires. You can choose not to use it for an order.', 'flexacommerce' ) . '</p>';

		$rows = '';
		foreach ( Plugin::instance()->gift_cards()->repository()->activity( $card->id, 100 ) as $row ) {
			if ( $row->type === GiftCardActivity::ISSUED && $row->amount === 0 ) {
				continue;
			}
			$order = $row->order_id !== null ? Plugin::instance()->orders()->orders()->find( $row->order_id ) : null;
			$link  = $order !== null && (int) $order->customer_id === $user_id
				? '<a href="' . esc_url( AccountPage::url( 'view-order', (string) $order->id ) ) . '">' . esc_html( $order->order_number ) . '</a>'
				: '';
			$what  = match ( $row->type ) {
				/* translators: %s: order number */
				GiftCardActivity::REDEEMED => $link !== '' ? sprintf( esc_html__( 'Paid for order %s', 'flexacommerce' ), $link ) : esc_html__( 'Paid for an order', 'flexacommerce' ),
				/* translators: %s: order number */
				GiftCardActivity::CREDITED => $link !== '' ? sprintf( esc_html__( 'Refund for order %s', 'flexacommerce' ), $link ) : esc_html__( 'Refund', 'flexacommerce' ),
				default => $row->amount >= 0 ? esc_html__( 'Added by the store', 'flexacommerce' ) : esc_html__( 'Taken off by the store', 'flexacommerce' ),
			};
			if ( $row->type === GiftCardActivity::ADJUSTED && $row->note !== '' ) {
				$what .= '<br><span class="fxc-account__credit-note">' . esc_html( $row->note ) . '</span>';
			}
			$rows .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $row->created_at ) ) ),
				$what,
				esc_html( ( $row->amount > 0 ? '+' : ( $row->amount < 0 ? '−' : '' ) ) . $this->checkout->format( abs( $row->amount ), $card->currency ) ),
				esc_html( $this->checkout->format( $row->balance_after, $card->currency ) )
			);
		}
		if ( $rows === '' ) {
			return $html;
		}
		return $html . '<table class="fxc-account__orders fxc-account__credit"><caption>' . esc_html__( 'History', 'flexacommerce' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Date', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'What', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Amount', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Balance', 'flexacommerce' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/** The account's subscriptions, newest first (ADR-073). */
	public function subscriptions( int $user_id ): string {
		$service = Plugin::instance()->subscriptions();
		$rows    = $service->repository()->for_customer( $user_id );
		if ( $rows === [] ) {
			return '<p class="fxc-notice">' . esc_html__( 'You have no subscriptions.', 'flexacommerce' ) . '</p>';
		}
		$html = '<table class="fxc-account__orders fxc-account__subscriptions"><caption>' . esc_html__( 'Subscriptions', 'flexacommerce' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Subscription', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Next payment', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Price', 'flexacommerce' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= sprintf(
				'<tr><th scope="row"><a href="%1$s">%2$s</a></th><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_url( AccountPage::url( 'view-subscription', (string) $row->id ) ),
				esc_html( '#' . $row->id . ' ' . $row->title() ),
				esc_html( SubscriptionStatus::label( $row->status ) ),
				esc_html( $row->next_payment_at !== null ? self::day( $row->next_payment_at ) : '–' ),
				esc_html( $service->price_text( $row ) )
			);
		}
		return $html . '</tbody></table>';
	}

	/**
	 * One subscription: its terms, the orders it made, and what the
	 * shopper can do with it.
	 *
	 * @param array<string,string> $errors
	 * @param array<string,mixed>  $posted  the last post, to fill the forms again
	 * @param Snapshot|SwitchQuote|null $preview a change (ADR-076) or plan switch (ADR-077) priced for the customer to confirm
	 */
	public function subscription( ?Subscription $row, array $errors = [], array $posted = [], Snapshot|SwitchQuote|null $preview = null ): string {
		if ( $row === null ) {
			return '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'Subscription not found.', 'flexacommerce' ) . '</p>';
		}
		$service = Plugin::instance()->subscriptions();
		$html    = self::summary( $errors ) . '<h2>' . esc_html( '#' . $row->id . ' ' . $row->title() ) . '</h2><dl class="fxc-account__terms">';
		$terms   = [
			__( 'Status', 'flexacommerce' ) => $row->is_paused() ? __( 'Paused', 'flexacommerce' ) : SubscriptionStatus::label( $row->status ),
			__( 'Price', 'flexacommerce' )  => $service->price_text( $row ),
		];
		if ( $row->start_at !== null ) {
			$terms[ __( 'Started', 'flexacommerce' ) ] = self::day( $row->start_at );
		}
		if ( $row->trial_end_at !== null && $row->in_trial( gmdate( 'Y-m-d H:i:s' ) ) ) {
			$terms[ __( 'Free trial until', 'flexacommerce' ) ] = self::day( $row->trial_end_at );
		}
		if ( $row->next_payment_at !== null && ! $row->is_paused() ) {
			$terms[ __( 'Next payment', 'flexacommerce' ) ] = self::day( $row->next_payment_at );
		}
		if ( $row->end_at !== null ) {
			$terms[ $row->is_ended() ? __( 'Ended', 'flexacommerce' ) : __( 'Ends', 'flexacommerce' ) ] = self::day( $row->end_at );
		}
		if ( $row->plan->length > 0 ) {
			/* translators: 1: payments made, 2: payments in the plan. */
			$terms[ __( 'Payments', 'flexacommerce' ) ] = sprintf( __( '%1$d of %2$d', 'flexacommerce' ), $row->billed, $row->plan->length );
		}
		$method = $row->payment_label !== '' ? $row->payment_label : $row->payment_method_title;
		if ( $method !== '' && ! $row->is_ended() ) {
			$terms[ __( 'Payment method', 'flexacommerce' ) ] = $row->is_automatic()
				/* translators: %s: a payment method, like "Visa ending in 4242". */
				? sprintf( __( '%s, charged automatically', 'flexacommerce' ), $method )
				: $method;
		}
		foreach ( $terms as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		$html .= '</dl>';

		if ( $row->billing_held ) {
			// Imported from WooCommerce Subscriptions, which still bills it until the store starts billing here (ADR-079).
			$html .= '<p class="fxc-notice" role="status">' . esc_html__( 'This subscription is moving to our new store system. You can see it here, and changes open once the move is finished. Nothing about your plan or payments changes.', 'flexacommerce' ) . '</p>';
		}
		$unpaid = $row->status === SubscriptionStatus::ON_HOLD ? $service->unpaid_renewal( $row ) : null;
		if ( $unpaid !== null ) {
			$wait = $row->retry_at !== null
				/* translators: %s: date of the next try. */
				? sprintf( __( 'The automatic payment for your renewal failed. We will try again on %s, or you can pay now or change the payment method.', 'flexacommerce' ), self::day( $row->retry_at ) )
				: __( 'Your renewal is waiting for payment. The subscription continues once it is paid.', 'flexacommerce' );
			$html .= '<p class="fxc-notice" role="status">' . esc_html( $wait ) . '</p>'
				. '<p><a class="fxc-button" href="' . esc_url( $this->checkout->pages()->pay_url( $unpaid ) ) . '">' . esc_html__( 'Pay renewal', 'flexacommerce' ) . '</a></p>';
		}
		$switching = $service->pending_switch( $row );
		if ( $switching !== null ) {
			$html .= '<p class="fxc-notice" role="status">' . esc_html__( 'Your plan switch is waiting for payment. It applies once it is paid.', 'flexacommerce' ) . '</p>'
				. '<p><a class="fxc-button" href="' . esc_url( $this->checkout->pages()->pay_url( $switching ) ) . '">' . esc_html__( 'Pay for the switch', 'flexacommerce' ) . '</a></p>';
		}
		if ( $preview instanceof SwitchQuote ) {
			$html .= $this->switch_preview( $row, $preview, $posted );
		} elseif ( $preview !== null ) {
			$html .= $this->preview( $row, $preview, $posted );
		}
		if ( $row->is_paused() ) {
			$html .= '<form method="post" class="fxc-account__subscription-action">' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'resume' )
				. '<p class="fxc-notice" role="status">' . esc_html__( 'Your subscription is paused. Nothing is charged or sent until you resume it.', 'flexacommerce' ) . '</p>'
				. ( $row->next_payment_at !== null && $row->next_payment_at <= gmdate( 'Y-m-d H:i:s' )
					? '<p>' . esc_html__( 'The payment day passed while it was paused, so resuming renews it today.', 'flexacommerce' ) . '</p>'
					: '' )
				. '<p><button type="submit" class="fxc-button">' . esc_html__( 'Resume subscription', 'flexacommerce' ) . '</button></p></form>';
		}
		if ( $service->can_change( $row ) && $row->snapshot !== null ) {
			$html .= $this->items_form( $row, $row->snapshot, $posted );
		}
		if ( $service->can_switch( $row ) && $row->snapshot !== null ) {
			$html .= $this->switch_form( $row, $row->snapshot, $posted );
		}
		if ( $service->can_update_method( $row ) ) {
			$html .= '<form method="post" class="fxc-account__subscription-action">' . self::hidden( 'subscription' )
				. '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $row->id ) . '">'
				. '<input type="hidden" name="subscription_do" value="card">'
				. '<p><button type="submit" class="fxc-button fxc-button--secondary">'
				. ( $row->is_automatic() ? esc_html__( 'Change payment method', 'flexacommerce' ) : esc_html__( 'Pay renewals automatically', 'flexacommerce' ) )
				. '</button></p></form>';
		}
		$do = $row->billing_held ? null : match ( true ) {
			$row->status === SubscriptionStatus::PENDING_CANCEL => [ 'reactivate', __( 'Keep my subscription', 'flexacommerce' ) ],
			in_array( $row->status, [ SubscriptionStatus::ACTIVE, SubscriptionStatus::ON_HOLD ], true ) => [ 'cancel', __( 'Cancel subscription', 'flexacommerce' ) ],
			default => null,
		};
		if ( $do !== null ) {
			$html .= '<form method="post" class="fxc-account__subscription-action">' . self::hidden( 'subscription' )
				. '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $row->id ) . '">'
				. '<input type="hidden" name="subscription_do" value="' . esc_attr( $do[0] ) . '">';
			if ( $do[0] === 'cancel' && ( $row->status === SubscriptionStatus::ACTIVE || $row->is_paused() ) && $row->next_payment_at !== null ) {
				$html .= '<p>' . sprintf(
					/* translators: %s: date the paid period ends. */
					esc_html__( 'If you cancel, nothing more is charged and the subscription ends on %s.', 'flexacommerce' ),
					esc_html( self::day( $row->next_payment_at ) )
				) . '</p>';
			}
			$html .= '<p><button type="submit" class="fxc-button">' . esc_html( $do[1] ) . '</button></p></form>';
		}
		if ( $service->can_pause( $row ) ) {
			$html .= '<form method="post" class="fxc-account__subscription-action">' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'pause' )
				. '<p>' . esc_html__( 'Away for a while? Pause the subscription: nothing is charged or sent until you resume it.', 'flexacommerce' ) . '</p>'
				. '<p><button type="submit" class="fxc-button fxc-button--secondary">' . esc_html__( 'Pause subscription', 'flexacommerce' ) . '</button></p></form>';
		}

		$orders = '';
		foreach ( array_reverse( $service->repository()->orders( $row->id ) ) as $link ) {
			$order = Plugin::instance()->orders()->orders()->find( $link['order_id'] );
			if ( $order === null ) {
				continue;
			}
			$orders .= sprintf(
				'<tr><th scope="row"><a href="%1$s">%2$s</a></th><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( AccountPage::url( 'view-order', (string) $order->id ) ),
				esc_html( $order->order_number ),
				esc_html(
					match ( $link['relation'] ) {
						'parent' => __( 'First order', 'flexacommerce' ),
						'switch' => __( 'Plan switch', 'flexacommerce' ),
						default => __( 'Renewal', 'flexacommerce' ),
					}
				),
				esc_html( self::date( $order ) ),
				esc_html( OrderStatus::label( $order->status ) ),
				esc_html( $this->checkout->format( $order->total, $order->currency ) )
			);
		}
		if ( $orders !== '' ) {
			$html .= '<table class="fxc-account__orders"><caption>' . esc_html__( 'Related orders', 'flexacommerce' ) . '</caption><thead><tr>'
				. '<th scope="col">' . esc_html__( 'Order', 'flexacommerce' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Type', 'flexacommerce' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Date', 'flexacommerce' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Status', 'flexacommerce' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Total', 'flexacommerce' ) . '</th>'
				. '</tr></thead><tbody>' . $orders . '</tbody></table>';
		}
		$html .= '<div class="fxc-order__addresses">'
			. OrderConfirmation::address( __( 'Billing address', 'flexacommerce' ), $row->billing )
			. OrderConfirmation::address( __( 'Shipping address', 'flexacommerce' ), $row->shipping )
			. '</div>';
		if ( $service->can_change( $row ) && ( $row->shipping ?? $row->billing ) !== null ) {
			$html .= $this->address_form( $row, $posted, $errors );
		}
		return $html;
	}

	/**
	 * The quantities of the renewals, one field per product line; a line
	 * can be set to 0 while another stays (ADR-076).
	 *
	 * @param array<string,mixed> $posted
	 */
	private function items_form( Subscription $row, Snapshot $snapshot, array $posted ): string {
		$lines  = self::lines( $snapshot );
		$values = is_array( $posted['subscription_qty'] ?? null ) ? $posted['subscription_qty'] : [];
		$rows   = '';
		foreach ( $lines as $index => $item ) {
			$id    = 'fxc-subscription-qty-' . $index;
			$value = is_scalar( $values[ $index ] ?? null ) ? (string) $values[ $index ] : (string) $item->quantity;
			$rows .= sprintf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s</td><td><input type="number" class="fxc-input fxc-account__qty" id="%1$s" name="subscription_qty[%4$d]" value="%5$s" min="%6$d" max="9999" step="1" inputmode="numeric"></td></tr>',
				esc_attr( $id ),
				esc_html( $item->name ),
				esc_html( $this->checkout->format( $item->unit_price, $row->currency ) ),
				$index,
				esc_attr( $value ),
				count( $lines ) > 1 ? 0 : 1
			);
		}
		return '<form method="post" class="fxc-account__subscription-items">' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'change' )
			. '<table class="fxc-account__orders"><caption>' . esc_html__( 'Items in each renewal', 'flexacommerce' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Item', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Price', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Quantity', 'flexacommerce' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<p>' . esc_html__( 'Changes apply from the next renewal. You see the new price before anything is saved.', 'flexacommerce' ) . '</p>'
			. '<p><button type="submit" class="fxc-button fxc-button--secondary">' . esc_html__( 'Update quantities', 'flexacommerce' ) . '</button></p></form>';
	}

	/**
	 * The renewals' shipping address, or the billing one when nothing is
	 * shipped, in a collapsed section that opens on the customer's own
	 * submission (ADR-076).
	 *
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function address_form( Subscription $row, array $posted, array $errors ): string {
		$type    = $row->shipping !== null ? 'shipping' : 'billing';
		$current = $row->shipping ?? $row->billing;
		$mine    = ! empty( $posted['subscription_address'] );
		$values  = $mine && is_array( $posted[ $type ] ?? null ) ? $posted[ $type ] : ( $current !== null ? $current->to_array() : [] );
		$country = is_string( $values['country'] ?? null ) && $values['country'] !== '' ? strtoupper( $values['country'] ) : Settings::string( 'store_country' );
		$fields  = FieldSchema::for_address( $type, $country )->core()->to_array();
		$title   = $type === 'shipping' ? __( 'Change the shipping address', 'flexacommerce' ) : __( 'Change the billing address', 'flexacommerce' );
		return '<details class="fxc-account__subscription-address"' . ( $mine ? ' open' : '' ) . '><summary>' . esc_html( $title ) . '</summary>'
			. '<form method="post" class="fxc-account__form" novalidate>' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'change' )
			. '<input type="hidden" name="subscription_address" value="1">'
			. '<fieldset><legend>' . esc_html( $type === 'shipping' ? __( 'Shipping address', 'flexacommerce' ) : __( 'Billing address', 'flexacommerce' ) ) . '</legend>'
			. CheckoutForm::fields( $type, $fields, $values, Countries::allowed(), $mine ? $errors : [] )
			. '<p><button type="submit" name="' . esc_attr( CheckoutPage::REFRESH ) . '" value="1" formnovalidate>' . esc_html__( 'Update the fields for this country', 'flexacommerce' ) . '</button></p>'
			. '</fieldset><p>' . esc_html__( 'Only this subscription changes. Your saved addresses stay as they are.', 'flexacommerce' ) . '</p>'
			. '<p><button type="submit" class="fxc-button fxc-button--secondary">' . esc_html__( 'Save address', 'flexacommerce' ) . '</button></p></form></details>';
	}

	/**
	 * A change that moves the price, with the new lines and price and a
	 * button that posts it again to confirm (ADR-076).
	 *
	 * @param array<string,mixed> $posted
	 */
	private function preview( Subscription $row, Snapshot $preview, array $posted ): string {
		$items = '';
		foreach ( self::lines( $preview ) as $item ) {
			$items .= '<li>' . esc_html( $item->quantity > 1 ? sprintf( '%1$s × %2$d', $item->name, $item->quantity ) : $item->name ) . '</li>';
		}
		$hidden = '';
		foreach ( is_array( $posted['subscription_qty'] ?? null ) ? $posted['subscription_qty'] : [] as $index => $quantity ) {
			if ( is_int( $index ) && is_scalar( $quantity ) ) {
				$hidden .= '<input type="hidden" name="subscription_qty[' . $index . ']" value="' . esc_attr( (string) absint( $quantity ) ) . '">';
			}
		}
		if ( ! empty( $posted['subscription_address'] ) ) {
			$type    = $row->shipping !== null ? 'shipping' : 'billing';
			$hidden .= '<input type="hidden" name="subscription_address" value="1">';
			foreach ( is_array( $posted[ $type ] ?? null ) ? $posted[ $type ] : [] as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) ) {
					$hidden .= '<input type="hidden" name="' . esc_attr( $type . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $value ) . '">';
				}
			}
		}
		$service = Plugin::instance()->subscriptions();
		return '<section class="fxc-account__subscription-preview" aria-labelledby="fxc-subscription-preview"><h3 id="fxc-subscription-preview" tabindex="-1">' . esc_html__( 'Review your change', 'flexacommerce' ) . '</h3>'
			. '<ul>' . $items . '</ul><p>' . sprintf(
				/* translators: 1: new renewal price, 2: current renewal price. */
				esc_html__( 'New renewal price: %1$s (now %2$s).', 'flexacommerce' ),
				'<strong>' . esc_html( $preview->plan->per( $this->checkout->format( $preview->total, $row->currency ) ) ) . '</strong>',
				esc_html( $service->price_text( $row ) )
			) . '</p>'
			. ( $row->next_payment_at !== null && ! $row->is_paused()
				/* translators: %s: date of the next renewal. */
				? '<p>' . esc_html( sprintf( __( 'It applies from the renewal on %s.', 'flexacommerce' ), self::day( $row->next_payment_at ) ) ) . '</p>'
				: '' )
			. '<form method="post">' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'change' ) . $hidden
			. '<input type="hidden" name="subscription_total" value="' . esc_attr( (string) $preview->total ) . '">'
			. '<input type="hidden" name="subscription_confirm" value="1">'
			. '<p><button type="submit" class="fxc-button">' . esc_html__( 'Confirm the change', 'flexacommerce' ) . '</button> '
			. '<a href="' . esc_url( AccountPage::url( 'view-subscription', (string) $row->id ) ) . '">' . esc_html__( 'Keep it as it is', 'flexacommerce' ) . '</a></p></form></section>';
	}

	/**
	 * Other variations of the product lines that can be switched to
	 * (ADR-077): one choice per line, the current one first. Nothing
	 * shows when no line has another variation on a plan.
	 *
	 * @param array<string,mixed> $posted
	 */
	private function switch_form( Subscription $row, Snapshot $snapshot, array $posted ): string {
		$cart   = Plugin::instance()->cart()->cart();
		$values = is_array( $posted['subscription_variant'] ?? null ) ? $posted['subscription_variant'] : [];
		$fields = '';
		foreach ( self::lines( $snapshot ) as $index => $item ) {
			if ( (int) $item->variant_id <= 0 ) {
				continue;
			}
			$choices = $cart->switch_rows( $item->product_id, (int) $item->variant_id, self::selection( $item ) );
			if ( $choices === [] ) {
				continue;
			}
			$chosen = is_scalar( $values[ $index ] ?? null ) ? absint( $values[ $index ] ) : (int) $item->variant_id;
			$id     = 'fxc-subscription-variant-' . $index;
			/* translators: %s: the item the subscription has now. */
			$options = '<option value="' . esc_attr( (string) $item->variant_id ) . '">' . esc_html( sprintf( __( '%s (current)', 'flexacommerce' ), $item->name ) ) . '</option>';
			foreach ( $choices as $choice ) {
				$options .= sprintf(
					'<option value="%1$d"%2$s>%3$s</option>',
					$choice['variant_id'],
					selected( $chosen, $choice['variant_id'], false ),
					/* translators: 1: the option, like "1 kg", 2: its price, like "$20.00 / month". */
					esc_html( sprintf( __( '%1$s: %2$s', 'flexacommerce' ), $choice['label'], $choice['plan']->per( $this->checkout->format( $choice['price'], $row->currency ) ) ) )
				);
			}
			$fields .= '<p class="fxc-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $item->name ) . '</label><select id="' . esc_attr( $id ) . '" name="subscription_variant[' . $index . ']">' . $options . '</select></p>';
		}
		if ( $fields === '' ) {
			return '';
		}
		return '<form method="post" class="fxc-account__subscription-switch">' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'switch' )
			. '<fieldset><legend>' . esc_html__( 'Switch plan', 'flexacommerce' ) . '</legend>' . $fields . '</fieldset>'
			. '<p>' . esc_html__( 'The part of this period you already paid for counts toward the new plan. You see the price before anything changes.', 'flexacommerce' ) . '</p>'
			. '<p><button type="submit" class="fxc-button fxc-button--secondary">' . esc_html__( 'Review the switch', 'flexacommerce' ) . '</button></p></form>';
	}

	/**
	 * A plan switch with the new items and renewal price, the credit for
	 * the period paid, what is charged now and when it applies (ADR-077).
	 *
	 * @param array<string,mixed> $posted
	 */
	private function switch_preview( Subscription $row, SwitchQuote $quote, array $posted ): string {
		$new   = $quote->snapshot;
		$items = '';
		foreach ( self::lines( $new ) as $item ) {
			$items .= '<li>' . esc_html( $item->quantity > 1 ? sprintf( '%1$s × %2$d', $item->name, $item->quantity ) : $item->name ) . '</li>';
		}
		$hidden = '';
		foreach ( is_array( $posted['subscription_variant'] ?? null ) ? $posted['subscription_variant'] : [] as $index => $variant ) {
			if ( is_int( $index ) && is_scalar( $variant ) ) {
				$hidden .= '<input type="hidden" name="subscription_variant[' . $index . ']" value="' . esc_attr( (string) absint( $variant ) ) . '">';
			}
		}
		$money = fn ( int $amount ): string => $this->checkout->format( $amount, $row->currency );
		$terms = '<p>' . sprintf(
			/* translators: 1: new renewal price, 2: current renewal price. */
			esc_html__( 'New renewal price: %1$s (now %2$s).', 'flexacommerce' ),
			'<strong>' . esc_html( $new->plan->per( $money( $new->total ) ) ) . '</strong>',
			esc_html( Plugin::instance()->subscriptions()->price_text( $row ) )
		) . '</p>';
		if ( $quote->credit > 0 ) {
			/* translators: %s: amount credited. */
			$terms .= '<p>' . esc_html( sprintf( __( 'Credit for the part of this period you already paid for: %s.', 'flexacommerce' ), $money( $quote->credit ) ) ) . '</p>';
		}
		$terms .= $quote->charge > 0
			/* translators: %s: amount charged now. */
			? '<p>' . sprintf( esc_html__( 'To pay now: %s.', 'flexacommerce' ), '<strong>' . esc_html( $money( $quote->charge ) ) . '</strong>' ) . '</p>'
			: '<p>' . esc_html__( 'Nothing to pay now.', 'flexacommerce' ) . '</p>';
		$terms .= '<p>' . esc_html(
			$quote->charge > 0
				/* translators: %s: date of the next payment. */
				? sprintf( __( 'The switch applies once this is paid. The next payment is on %s.', 'flexacommerce' ), self::day( $quote->next_payment_at ) )
				/* translators: %s: date of the next payment. */
				: sprintf( __( 'The switch applies now. The next payment is on %s.', 'flexacommerce' ), self::day( $quote->next_payment_at ) )
		) . '</p>';
		return '<section class="fxc-account__subscription-preview" aria-labelledby="fxc-subscription-preview"><h3 id="fxc-subscription-preview" tabindex="-1">' . esc_html__( 'Review your switch', 'flexacommerce' ) . '</h3>'
			. '<ul>' . $items . '</ul>' . $terms
			. '<form method="post">' . self::hidden( 'subscription' ) . self::subscription_fields( $row, 'switch' ) . $hidden
			. '<input type="hidden" name="subscription_quote" value="' . esc_attr( $new->total . ':' . $quote->charge ) . '">'
			. '<input type="hidden" name="subscription_confirm" value="1">'
			. '<p><button type="submit" class="fxc-button">' . esc_html__( 'Confirm the switch', 'flexacommerce' ) . '</button> '
			. '<a href="' . esc_url( AccountPage::url( 'view-subscription', (string) $row->id ) ) . '">' . esc_html__( 'Keep it as it is', 'flexacommerce' ) . '</a></p></form></section>';
	}

	/**
	 * The options chosen for a line, as the cart takes them.
	 *
	 * @return array<string,string>
	 */
	private static function selection( \Flexa\Commerce\Orders\NewOrderItem $item ): array {
		$out = [];
		foreach ( is_array( $item->meta['selection'] ?? null ) ? $item->meta['selection'] : [] as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * The product lines a customer can change, by their index in the
	 * snapshot; bundle parts follow their bundle.
	 *
	 * @return array<int,\Flexa\Commerce\Orders\NewOrderItem>
	 */
	private static function lines( Snapshot $snapshot ): array {
		$out = [];
		foreach ( $snapshot->items as $index => $item ) {
			if ( $item->type === OrderItem::PRODUCT && ! isset( $item->meta['bundled_by'] ) ) {
				$out[ $index ] = $item;
			}
		}
		return $out;
	}

	private static function subscription_fields( Subscription $row, string $do ): string {
		return '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $row->id ) . '">'
			. '<input type="hidden" name="subscription_do" value="' . esc_attr( $do ) . '">';
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

	/** The files of the customer's paid orders (ADR-058). */
	public function downloads( int $user_id ): string {
		$links = Plugin::instance()->downloads()->for_customer( $user_id );
		if ( $links === [] ) {
			return '<p class="fxc-notice">' . esc_html__( 'No downloads yet.', 'flexacommerce' ) . '</p>';
		}
		return OrderConfirmation::download_table( $links );
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

		$html .= OrderConfirmation::invoice( $order );
		$html .= OrderConfirmation::downloads( $order );
		$html .= OrderConfirmation::shipments( $order );
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

	private static function day( string $utc ): string {
		return mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $utc ) );
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
