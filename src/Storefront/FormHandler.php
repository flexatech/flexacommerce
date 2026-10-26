<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\NotPurchasable;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\SameSite;

defined( 'ABSPATH' ) || exit;

/**
 * The no-JavaScript path (doc 02 §6.3): cart forms post to
 * `admin-post.php?action=flexacommerce_cart`, run the same CartService calls
 * as the REST routes behind the same checks (same-site, session nonce), and
 * redirect back with a result code. Messages are looked up from the code,
 * so a crafted URL cannot put text on the page.
 */
final class FormHandler {
	public const ACTION = 'flexacommerce_cart';
	public const RESULT = 'fxc-cart';
	public const FIELD  = 'fxc_nonce';

	public static function hidden_fields( string $operation ): string {
		$cart  = Plugin::instance()->cart();
		$nonce = $cart->nonce()->create( get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() );
		return sprintf(
			'<input type="hidden" name="action" value="%1$s"><input type="hidden" name="fxc_op" value="%2$s"><input type="hidden" name="%3$s" value="%4$s">',
			esc_attr( self::ACTION ),
			esc_attr( $operation ),
			esc_attr( self::FIELD ),
			esc_attr( $nonce )
		);
	}

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the session nonce is verified below.
		$back = wp_get_referer();
		$back = is_string( $back ) ? remove_query_arg( [ self::RESULT, 'fxc-product' ], $back ) : Assets::cart_url();

		$cart  = Plugin::instance()->cart();
		$nonce = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( ! SameSite::current() || ! $cart->nonce()->verify( $nonce, get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ) ) {
			self::redirect( $back, 'expired' );
		}

		$operation = isset( $_POST['fxc_op'] ) ? sanitize_key( wp_unslash( $_POST['fxc_op'] ) ) : '';
		$user      = get_current_user_id();
		$service   = $cart->cart();
		$product   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		try {
			if ( $operation === 'add' ) {
				$attributes = [];
				$raw        = isset( $_POST['attributes'] ) && is_array( $_POST['attributes'] ) ? wp_unslash( $_POST['attributes'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and value is sanitized in the loop below.
				foreach ( $raw as $key => $value ) {
					if ( is_scalar( $value ) ) {
						$attributes[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
					}
				}
				$options = isset( $_POST['options'] ) && is_array( $_POST['options'] ) ? ProductOptions::posted( wp_unslash( $_POST['options'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ProductOptions::accept() cleans each value for its field type.
				$service->add(
					$user,
					$product,
					isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0,
					isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1,
					$attributes,
					$options
				);
				self::redirect( $back, 'added', $product );
			}
			if ( $operation === 'update' ) {
				$remove = isset( $_POST['remove'] ) ? sanitize_key( wp_unslash( $_POST['remove'] ) ) : '';
				if ( $remove !== '' ) {
					$service->remove( $user, $remove );
					self::redirect( $back, 'removed' );
				}
				$quantities = isset( $_POST['quantities'] ) && is_array( $_POST['quantities'] ) ? wp_unslash( $_POST['quantities'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and value is sanitized in the loop below.
				$current    = $service->state( $user )->cart;
				foreach ( $quantities as $key => $quantity ) {
					$key  = sanitize_key( (string) $key );
					$line = $current->line( $key );
					$qty  = is_numeric( $quantity ) ? max( 0, (int) $quantity ) : null;
					if ( $line === null || $qty === null || $qty === $line->quantity ) {
						continue;
					}
					$service->set_quantity( $user, $key, $qty );
				}
				self::redirect( $back, 'updated' );
			}
			if ( $operation === 'coupon' ) {
				$remove = isset( $_POST['remove_coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['remove_coupon'] ) ) : '';
				if ( $remove !== '' ) {
					$service->remove_coupon( $user, $remove );
					self::redirect( $back, 'coupon_removed' );
				}
				$service->apply_coupon( $user, isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '' );
				self::redirect( $back, 'coupon_applied' );
			}
		} catch ( CommerceException $e ) {
			self::redirect( $back, self::code_for( $e ), $product );
		}
		// phpcs:enable
		self::redirect( $back, 'invalid' );
	}

	/** The message for a result code in the current URL, for the component that sent the form. */
	public static function notice_for( int $product_id ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the code selects a fixed message.
		$code = isset( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT ] ) ) : '';
		$for  = isset( $_GET['fxc-product'] ) ? absint( $_GET['fxc-product'] ) : 0;
		// phpcs:enable
		if ( $code === '' || ( $product_id > 0 && $for !== $product_id ) || ( $product_id === 0 && $for > 0 && $code === 'added' ) ) {
			return '';
		}
		$message = self::messages()[ $code ] ?? null;
		if ( $message === null ) {
			return '';
		}
		$error = ! in_array( $code, [ 'added', 'updated', 'removed', 'coupon_applied', 'coupon_removed' ], true );
		$link  = $code === 'added' ? ' <a href="' . esc_url( Assets::cart_url() ) . '">' . esc_html__( 'View cart', 'flexacommerce' ) . '</a>' : '';
		return sprintf(
			'<p class="fxc-notice%1$s" role="%2$s">%3$s%4$s</p>',
			$error ? ' fxc-notice--error' : '',
			$error ? 'alert' : 'status',
			esc_html( $message ),
			$link
		);
	}

	/** @return array<string,string> */
	private static function messages(): array {
		return [
			'added'              => __( 'Added to your cart.', 'flexacommerce' ),
			'updated'            => __( 'Cart updated.', 'flexacommerce' ),
			'removed'            => __( 'Item removed from the cart.', 'flexacommerce' ),
			'expired'            => __( 'Your session has expired. Please try again.', 'flexacommerce' ),
			'insufficient_stock' => __( 'There is not enough stock for that quantity.', 'flexacommerce' ),
			'incomplete'         => __( 'Choose product options before adding to the cart.', 'flexacommerce' ),
			'not_purchasable'    => __( 'This product cannot be bought right now.', 'flexacommerce' ),
			'rate_limited'       => __( 'Too many cart changes. Wait a minute and try again.', 'flexacommerce' ),
			'invalid'            => __( 'Could not update the cart. Please try again.', 'flexacommerce' ),
			'coupon_applied'     => __( 'Coupon applied.', 'flexacommerce' ),
			'coupon_removed'     => __( 'Coupon removed.', 'flexacommerce' ),
			'coupon_empty'       => __( 'Enter a coupon code.', 'flexacommerce' ),
			'coupon_duplicate'   => __( 'That coupon is already applied.', 'flexacommerce' ),
			'coupon_invalid'     => __( 'That coupon cannot be used with this cart.', 'flexacommerce' ),
			'coupon_limit'       => __( 'No more coupons can be added to this cart.', 'flexacommerce' ),
		];
	}

	private static function code_for( CommerceException $e ): string {
		return match ( true ) {
			$e instanceof InsufficientStock => 'insufficient_stock',
			$e instanceof NotPurchasable => 'not_purchasable',
			$e instanceof RateLimited => 'rate_limited',
			$e instanceof InvalidCartRequest && $e->reason === 'incomplete_selection' => 'incomplete',
			$e instanceof InvalidCartRequest && $e->reason === 'coupon_applied' => 'coupon_duplicate',
			$e instanceof InvalidCartRequest && in_array( $e->reason, [ 'coupon_empty', 'coupon_invalid', 'coupon_limit' ], true ) => $e->reason,
			default => 'invalid',
		};
	}

	private static function redirect( string $url, string $code, int $product_id = 0 ): never {
		$args = [ self::RESULT => $code ];
		if ( $product_id > 0 ) {
			$args['fxc-product'] = $product_id;
		}
		wp_safe_redirect( add_query_arg( $args, $url ), 303 );
		exit;
	}
}
