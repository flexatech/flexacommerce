<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalGateway;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeGateway;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions subscriptions (ADR-079). A subscription is an
 * order of type `shop_subscription`; its schedule sits in meta the plugin
 * reads itself, so the reader takes it from the table and works while the
 * plugin is off. The saved card or PayPal account comes over when the
 * built-in gateway can charge it: Stripe's customer and payment method,
 * PayPal Payments' vault entry.
 */
final class WooSubscriptionReader {
	public const TYPE = 'shop_subscription';

	private const SCHEDULE = [ '_billing_period', '_billing_interval', '_schedule_start', '_schedule_trial_end', '_schedule_next_payment', '_schedule_end', '_schedule_cancelled', '_requires_manual_renewal', '_stripe_customer_id', '_stripe_source_id', 'ppcp_subscription' ];

	/** Subscription meta covered by the mapping. */
	private const MAPPED_META = [ '_billing_period', '_billing_interval', '_schedule_start', '_schedule_trial_end', '_schedule_next_payment', '_schedule_end', '_schedule_cancelled', '_schedule_payment_retry', '_requires_manual_renewal', '_stripe_customer_id', '_stripe_source_id', 'ppcp_subscription', '_payment_tokens', '_subscription_renewal_order_ids_cache', '_subscription_resubscribe_order_ids_cache', '_subscription_switch_order_ids_cache', '_order_version', '_fxc_source_id', '_edit_lock', '_edit_last' ];

	private const PAID_STATUSES = [ 'processing', 'completed', 'refunded' ];

	public function __construct(
		private readonly WooEnumerationRepository $enumeration,
		private readonly WooOrderReader $orders,
	) {}

	public function subscription( int $id ): ?Record {
		self::register_type();
		$sub = wc_get_order( $id );
		if ( ! $sub instanceof \WC_Order ) {
			return null;
		}
		$status = $sub->get_status( 'edit' );
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		if ( in_array( $status, [ 'trash', 'auto-draft', 'draft' ], true ) ) {
			return null;
		}
		$meta = $this->enumeration->subscription_meta( $id, self::SCHEDULE );
		if ( ( $meta['_billing_period'] ?? '' ) === '' ) {
			// Not a subscription: a plain order named by a stray reference.
			return null;
		}
		$at       = static function ( string $key ) use ( $meta ): ?string {
			$value = array_key_exists( $key, $meta ) ? $meta[ $key ] : '0';
			return Dates::from_db( $value === '0' ? null : $value );
		};
		$customer = $sub->get_customer_id( 'edit' );
		$parent   = $sub->get_parent_id( 'edit' );

		[ $items, $depends ] = $this->orders->lines( $sub, false );
		if ( $customer > 0 ) {
			$depends[] = Ref::make( 'wp', 'user', $customer );
		}
		$orders = [];
		if ( $parent > 0 ) {
			$depends[] = Ref::make( 'wc', Record::ORDER, $parent );
			$orders[]  = [
				'order'    => Ref::make( 'wc', Record::ORDER, $parent ),
				'relation' => 'parent',
				'paid'     => self::paid( $parent ),
			];
		}
		foreach ( $this->enumeration->subscription_orders( $id ) as $row ) {
			$depends[] = Ref::make( 'wc', Record::ORDER, $row['order_id'] );
			$orders[]  = [
				'order'    => Ref::make( 'wc', Record::ORDER, $row['order_id'] ),
				'relation' => $row['relation'],
				'paid'     => self::paid( $row['order_id'] ),
			];
		}

		return new Record(
			Record::SUBSCRIPTION,
			Ref::make( 'wc', Record::SUBSCRIPTION, $id ),
			[
				'status'               => $status,
				'currency'             => $sub->get_currency( 'edit' ),
				'prices_include_tax'   => $sub->get_prices_include_tax( 'edit' ),
				'created_at'           => Dates::from_wc( $sub->get_date_created( 'edit' ) ),
				'modified_at'          => Dates::from_wc( $sub->get_date_modified( 'edit' ) ),
				'customer'             => $customer > 0 ? Ref::make( 'wp', 'user', $customer ) : null,
				'period'               => $meta['_billing_period'],
				'interval'             => max( 1, (int) ( $meta['_billing_interval'] ?? 1 ) ),
				'start_at'             => $at( '_schedule_start' ) ?? Dates::from_wc( $sub->get_date_created( 'edit' ) ),
				'trial_end_at'         => $at( '_schedule_trial_end' ),
				'next_payment_at'      => $at( '_schedule_next_payment' ),
				'end_at'               => $at( '_schedule_end' ),
				'cancelled_at'         => $at( '_schedule_cancelled' ),
				'payment_method'       => $sub->get_payment_method( 'edit' ),
				'payment_method_title' => $sub->get_payment_method_title( 'edit' ),
				'payment'              => self::payment( $sub, $meta ),
				'totals'               => [
					'subtotal' => WooOrderReader::decimal( $sub->get_subtotal() ),
					'discount' => WooOrderReader::decimal( $sub->get_discount_total( 'edit' ) ),
					'shipping' => WooOrderReader::decimal( $sub->get_shipping_total( 'edit' ) ),
					'fees'     => WooOrderReader::decimal( $sub->get_total_fees() ),
					'tax'      => WooOrderReader::decimal( $sub->get_total_tax( 'edit' ) ),
					'total'    => WooOrderReader::decimal( $sub->get_total( 'edit' ) ),
				],
				'addresses'            => [
					'billing'  => WooStoreReader::address( $sub, 'billing' ),
					'shipping' => WooStoreReader::address( $sub, 'shipping' ),
				],
				'items'                => $items,
				'orders'               => $orders,
				'history'              => WooOrderReader::history( $id ),
				'extra'                => WooCatalogReader::extra( WooCatalogReader::meta_of( $sub ), self::MAPPED_META ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/**
	 * With WooCommerce Subscriptions off, WooCommerce does not know the
	 * order type and wc_get_order() gives nothing; a plain order class
	 * reads the lines, addresses and totals the same way.
	 */
	public static function register_type(): void {
		if ( ! post_type_exists( self::TYPE ) && function_exists( 'wc_register_order_type' ) ) {
			wc_register_order_type(
				self::TYPE,
				[
					'public'                           => false,
					'show_ui'                          => false,
					'rewrite'                          => false,
					'query_var'                        => false,
					'exclude_from_order_count'         => true,
					'exclude_from_order_views'         => true,
					'exclude_from_order_webhooks'      => true,
					'exclude_from_order_reports'       => true,
					'exclude_from_order_sales_reports' => true,
				]
			);
		}
	}

	/**
	 * How the renewals were paid. `gateway` is the built-in gateway that can
	 * charge `token`, '' when the renewals are paid by hand or by a gateway
	 * FlexaCommerce does not have. `provider_billed` marks a PayPal
	 * Subscriptions plan: PayPal bills it, the store never charges it.
	 *
	 * @param array<string,string> $meta
	 * @return array{gateway:string,customer:string,token:string,label:string,manual:bool,provider_billed:bool}
	 */
	private static function payment( \WC_Order $sub, array $meta ): array {
		$method = $sub->get_payment_method( 'edit' );
		$manual = ( $meta['_requires_manual_renewal'] ?? 'false' ) === 'true';
		$out    = [
			'gateway'         => '',
			'customer'        => '',
			'token'           => '',
			'label'           => '',
			'manual'          => $manual,
			'provider_billed' => ( $meta['ppcp_subscription'] ?? '' ) !== '',
		];
		if ( $manual || $out['provider_billed'] ) {
			return $out;
		}
		$user = $sub->get_customer_id( 'edit' );
		if ( $method === 'stripe' ) {
			$token = (string) ( $meta['_stripe_source_id'] ?? '' );
			$saved = self::token( $sub, $user, 'stripe', $token );
			if ( $token === '' && $saved !== null ) {
				$token = $saved->get_token();
			}
			return [
				'gateway'  => $token !== '' ? StripeGateway::ID : '',
				'customer' => (string) ( $meta['_stripe_customer_id'] ?? '' ),
				'token'    => $token,
				'label'    => $saved instanceof \WC_Payment_Token_CC
					? StripeGateway::label(
						[
							'type' => 'card',
							'card' => [
								'brand' => strtolower( $saved->get_card_type() ),
								'last4' => $saved->get_last4(),
							],
						]
					)
					: (string) $saved?->get_display_name(),
			] + $out;
		}
		if ( $method === 'ppcp-gateway' ) {
			$saved = self::token( $sub, $user, 'ppcp-gateway', '' );
			$token = $saved?->get_token() ?? '';
			return [
				'gateway'  => $token !== '' ? PayPalGateway::ID : '',
				'customer' => $user > 0 ? (string) get_user_meta( $user, '_ppcp_target_customer_id', true ) : '',
				'token'    => $token,
				'label'    => $saved instanceof \WC_Payment_Token ? (string) $saved->get_display_name() : '',
			] + $out;
		}
		return $out;
	}

	/** The saved token the subscription uses: the one it names, else the customer's default for the gateway. */
	private static function token( \WC_Order $sub, int $user, string $gateway, string $value ): ?\WC_Payment_Token {
		$candidates = [];
		foreach ( $sub->get_payment_tokens() as $token_id ) {
			$token = \WC_Payment_Tokens::get( (int) $token_id );
			if ( $token instanceof \WC_Payment_Token ) {
				$candidates[] = $token;
			}
		}
		if ( $user > 0 ) {
			$candidates = array_merge( $candidates, array_values( \WC_Payment_Tokens::get_customer_tokens( $user, $gateway ) ) );
		}
		$fallback = null;
		foreach ( $candidates as $token ) {
			if ( $token->get_gateway_id() !== $gateway ) {
				continue;
			}
			if ( $value !== '' && $token->get_token() === $value ) {
				return $token;
			}
			if ( $fallback === null || ( $token->is_default() && ! $fallback->is_default() ) ) {
				$fallback = $token;
			}
		}
		return $value === '' ? $fallback : null;
	}

	private static function paid( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		return $order->get_date_paid( 'edit' ) !== null || in_array( $order->get_status( 'edit' ), self::PAID_STATUSES, true );
	}
}
