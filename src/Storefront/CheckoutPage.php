<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Checkout\CheckoutPages;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutValidator;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\CheckoutForm;
use Flexa\Commerce\Storefront\Render\OrderConfirmation;
use Flexa\Commerce\Storefront\Render\OrderPay;
use Flexa\Commerce\Support\Capabilities;
use Flexa\Commerce\Support\SameSite;
use Flexa\Commerce\Support\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout page and the order views hung off it (doc 02 §7.5). The
 * form posts to the page itself: template_redirect runs the same
 * CheckoutService calls as the REST routes behind the same checks
 * (same-site, session nonce) and redirects on success. On failure nothing
 * is stored; the page renders again in the same request with the posted
 * values and the errors.
 */
final class CheckoutPage {
	use HasInstance;

	public const PLACE   = 'fxc_checkout';
	public const PAY     = 'fxc_pay';
	public const REFRESH = 'fxc_refresh';

	/** @var array<string,mixed> */
	private array $posted = [];

	/** @var array<string,string> */
	private array $errors = [];

	private ?Order $order = null;

	private ?CheckoutForm $form = null;

	private ?OrderConfirmation $confirmation = null;

	private ?OrderPay $pay = null;

	public static function form_action(): string {
		return Plugin::instance()->checkout()->pages()->checkout_url();
	}

	public static function hidden_fields( string $checkout_token, int $expected_total ): string {
		return sprintf(
			'<input type="hidden" name="%1$s" value="1"><input type="hidden" name="checkout_token" value="%2$s"><input type="hidden" name="expected_total" value="%3$d" data-fxc-expected>%4$s',
			esc_attr( self::PLACE ),
			esc_attr( $checkout_token ),
			$expected_total,
			self::nonce_field()
		);
	}

	public static function pay_fields(): string {
		return '<input type="hidden" name="' . esc_attr( self::PAY ) . '" value="1">' . self::nonce_field();
	}

	public function handle(): void {
		nocache_headers();
		// phpcs:disable WordPress.Security.NonceVerification -- the session nonce is verified in verified() before anything changes.
		$received = isset( $_GET[ CheckoutPages::RECEIVED ] ) ? absint( $_GET[ CheckoutPages::RECEIVED ] ) : 0;
		$pay      = isset( $_GET[ CheckoutPages::PAY ] ) ? absint( $_GET[ CheckoutPages::PAY ] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$service  = $this->service();
		$user     = get_current_user_id();

		if ( $received > 0 || $pay > 0 ) {
			// The key is in the URL: keep it out of Referer headers to other sites.
			if ( ! headers_sent() ) {
				header( 'Referrer-Policy: no-referrer' );
			}
			$this->order = $service->viewable_order( $received > 0 ? $received : $pay, $key, $user );
		}

		if ( $received > 0 && $this->order !== null && isset( $_GET[ CheckoutService::RETURN_ARG ] ) ) {
			$returned = $service->returned( $this->order, absint( $_GET[ CheckoutService::RETURN_ARG ] ), $this->query() );
			// A declined card or a failed 3-D Secure: back to paying, with the reason.
			self::redirect( $returned === 'failed' ? $service->pages()->pay_url( $this->order ) : $service->pages()->confirmation_url( $this->order ) );
		}

		if ( $pay > 0 && $this->order !== null && isset( $_POST[ self::PAY ] ) ) {
			$this->posted = $this->posted();
			if ( ! $this->verified() ) {
				return;
			}
			$method = sanitize_key( (string) ( $this->posted['payment_method'] ?? '' ) );
			try {
				$result = $service->retry_payment( $this->order->id, $key, $method, $this->gateway_input( $method ), $user );
				self::redirect( $result->redirect );
			} catch ( InvalidCheckout $e ) {
				$this->errors = $e->errors;
			} catch ( OrderNotFound ) {
				$this->order = null;
			} catch ( CommerceException $e ) {
				$this->errors = [ 'order' => $e->getMessage() ];
			}
			return;
		}

		if ( $received === 0 && $pay === 0 && isset( $_POST[ self::PLACE ] ) ) {
			$this->place( $service, $user );
		}
		// phpcs:enable
	}

	/** What the checkout block or shortcode shows for this request. */
	public function render(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$received = isset( $_GET[ CheckoutPages::RECEIVED ] );
		$pay      = isset( $_GET[ CheckoutPages::PAY ] );
		// phpcs:enable
		$service = $this->service();
		if ( $received || $pay ) {
			if ( $this->order === null ) {
				return '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'Order not found. Use the link from your order email or sign in to your account.', 'flexacommerce' ) . '</p>';
			}
			if ( $received ) {
				return $this->confirmation( $service )->html( $this->order );
			}
			$this->pay ??= new OrderPay( $service, $this->confirmation( $service ) );
			return $this->pay->html( $this->order, $this->posted, $this->errors );
		}
		$this->form ??= new CheckoutForm();
		// The draft goes in first: the state reads the layout for its cart conditions.
		$preview = $this->preview();
		$state   = $service->form_state( get_current_user_id() );
		if ( $preview ) {
			$state            = ( $state['cart']['items'] ?? [] ) === [] ? $service->sample_state( $state, get_current_user_id() ) : $state;
			$state['preview'] = true;
		}
		return $this->form->html( $state, $this->posted, $this->errors );
	}

	/**
	 * "Preview on site" from the checkout builder (ADR-032): a manager's
	 * link with a nonce shows their unsaved layout for this request.
	 */
	private function preview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is the nonce, checked below.
		$nonce = isset( $_GET[ CheckoutLayout::PREVIEW_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ CheckoutLayout::PREVIEW_ARG ] ) ) : '';
		if ( $nonce === '' || ! Capabilities::can_manage() || wp_verify_nonce( $nonce, CheckoutLayout::PREVIEW_NONCE ) === false ) {
			return false;
		}
		$draft = CheckoutLayout::draft( get_current_user_id() );
		CheckoutLayout::preview( $draft );
		return $draft !== null;
	}

	/**
	 * The order confirmation alone, for a block or shortcode on a page of
	 * its own. Empty without an order in the URL, so the editor and a direct
	 * visit show nothing.
	 */
	public function render_confirmation(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the key is the access check.
		$id  = isset( $_GET[ CheckoutPages::RECEIVED ] ) ? absint( $_GET[ CheckoutPages::RECEIVED ] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		if ( $id === 0 ) {
			return '';
		}
		$service = $this->service();
		$order   = $this->order !== null && $this->order->id === $id ? $this->order : $service->viewable_order( $id, $key, get_current_user_id() );
		if ( $order === null ) {
			return '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'Order not found. Use the link from your order email or sign in to your account.', 'flexacommerce' ) . '</p>';
		}
		$this->order = $order;
		return $this->confirmation( $service )->html( $order );
	}

	private function place( CheckoutService $service, int $user ): void {
		$this->posted = $this->posted();
		if ( ! $this->verified() ) {
			return;
		}
		$method                = sanitize_key( (string) ( $this->posted['payment_method'] ?? '' ) );
		$data                  = $this->posted;
		$data['gateway_input'] = $this->gateway_input( $method );
		$request               = CheckoutRequest::from_array( $data, $user, ClientIp::get() );

		try {
			// Requote for the posted address first, so a re-rendered form shows its rates.
			$destination = ( new CheckoutValidator() )->destination( $request );
			if ( $destination->country !== '' ) {
				$service->update_shipping( $user, $destination, $request->shipping_rate, $method );
			}
			if ( isset( $this->posted[ self::REFRESH ] ) ) {
				return;
			}
			$result = $service->place_order( $request );
			self::redirect( $result->redirect );
		} catch ( InvalidCheckout $e ) {
			$this->errors = $e->errors;
		} catch ( CommerceException $e ) {
			$this->errors = [ 'cart' => $e->getMessage() ];
		}
	}

	private function verified(): bool {
		$cart  = Plugin::instance()->cart();
		$nonce = is_string( $this->posted[ FormHandler::FIELD ] ?? null ) ? $this->posted[ FormHandler::FIELD ] : '';
		if ( SameSite::current() && $cart->nonce()->verify( $nonce, get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ) ) {
			return true;
		}
		$this->errors = [ 'cart' => __( 'Your session has expired. Please try again.', 'flexacommerce' ) ];
		return false;
	}

	/**
	 * The raw submission. Values stay unsanitized here on purpose: the
	 * validator cleans each field by its type and the renderer escapes.
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

	/** @return array<string,mixed> the fields of the chosen gateway only */
	private function gateway_input( string $method ): array {
		$all = is_array( $this->posted['gateway_input'] ?? null ) ? $this->posted['gateway_input'] : [];
		$own = is_array( $all[ $method ] ?? null ) ? $all[ $method ] : [];
		$out = [];
		foreach ( $own as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function query(): array {
		$out = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the gateway verifies its own signed return.
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	private function service(): CheckoutService {
		return Plugin::instance()->checkout()->service();
	}

	private function confirmation( CheckoutService $service ): OrderConfirmation {
		return $this->confirmation ??= new OrderConfirmation( $service );
	}

	private static function nonce_field(): string {
		$cart = Plugin::instance()->cart();
		return sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( FormHandler::FIELD ),
			esc_attr( $cart->nonce()->create( get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ) )
		);
	}

	/**
	 * A gateway redirect leaves the site, so it cannot go through
	 * wp_safe_redirect(); every other target is one of our own pages.
	 */
	private static function redirect( string $url ): never {
		$own = wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $url, PHP_URL_HOST );
		if ( $own ) {
			wp_safe_redirect( $url, 303 );
		} else {
			wp_redirect( $url, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the URL comes from the payment gateway.
		}
		exit;
	}
}
