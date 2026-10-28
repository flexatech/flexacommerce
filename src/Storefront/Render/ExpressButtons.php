<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Payments\ExpressGateway;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\WalletPlaces;

defined( 'ABSPATH' ) || exit;

/**
 * Wallet buttons (ADR-053): Apple Pay and Google Pay on the product page,
 * the cart and the top of the checkout. The box stays hidden until the
 * gateway's script finds a wallet on the device, so a shopper without one
 * sees nothing. A required field a wallet cannot fill (a company, a field
 * of the merchant's) keeps the buttons off the product page and the
 * cart; on the checkout the shopper fills it in the form first. Pages
 * where no gateway turned them on never build the gateways (WalletPlaces).
 */
final class ExpressButtons {
	/** Address fields a wallet shares. */
	private const WALLET_FIELDS = [ 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	/**
	 * @param string              $place   one of ExpressGateway::PLACES
	 * @param array<string,mixed> $context `product` => [product_id] on a product page
	 */
	public static function html( string $place, array $context = [] ): string {
		if ( ! WalletPlaces::on( $place ) ) {
			return '';
		}
		$found = self::gateway( $place );
		if ( $found === null ) {
			return '';
		}
		[ $id, $config ] = $found;
		$country         = Settings::string( 'store_country' );
		$extra           = [];
		$phone           = false;
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			foreach ( FieldSchema::for_address( $type, $country )->fields() as $field ) {
				if ( $field->id === 'phone' && $type === 'billing' ) {
					$phone = $field->required;
				}
				if ( $field->required && $field->shown_in( $country ) && ! in_array( $field->id, self::WALLET_FIELDS, true ) ) {
					$extra[] = $type . '[' . $field->id . ']';
				}
			}
		}
		foreach ( FieldSchema::additional()->fields() as $field ) {
			if ( $field->required && $field->shown_in( $country ) ) {
				$extra[] = $field->id === 'customer_note' ? 'customer_note' : 'additional[' . $field->id . ']';
			}
		}
		if ( $extra !== [] && $place !== 'checkout' ) {
			return '';
		}
		$script = (string) ( $config['script'] ?? '' );
		unset( $config['script'] );
		if ( $script === '' ) {
			return '';
		}
		Assets::use_express( $script );

		$terms = Plugin::instance()->checkout()->service()->pages()->terms_url();
		$data  = $config + [
			'payment_method' => $id,
			'place'          => $place,
			'phone'          => $phone,
			'fields'         => $extra,
		] + $context;
		$html  = '<div class="fxc-express fxc-express--' . esc_attr( $place ) . '" data-fxc-express="' . esc_attr( (string) wp_json_encode( $data ) ) . '" hidden>';
		if ( $place === 'checkout' ) {
			$html .= '<p class="fxc-express__title">' . esc_html__( 'Express checkout', 'flexacommerce' ) . '</p>';
		}
		$html .= '<div class="fxc-express__buttons"></div>';
		if ( $terms !== '' ) {
			$html .= '<p class="fxc-express__terms">' . sprintf(
				/* translators: %s: link to the terms and conditions */
				esc_html__( 'Paying with a wallet means you accept the %s.', 'flexacommerce' ),
				'<a href="' . esc_url( $terms ) . '" target="_blank">' . esc_html__( 'terms and conditions', 'flexacommerce' ) . '</a>'
			) . '</p>';
		}
		$html .= '<p class="fxc-express__message" role="alert" hidden></p>';
		if ( $place === 'checkout' ) {
			$html .= '<p class="fxc-express__or"><span>' . esc_html__( 'or', 'flexacommerce' ) . '</span></p>';
		}
		return $html . '</div>';
	}

	/**
	 * The first enabled gateway with buttons for this place.
	 *
	 * @return array{0:string,1:array<string,mixed>}|null
	 */
	private static function gateway( string $place ): ?array {
		$gateways = Plugin::instance()->payments()->gateways();
		$gateways->settings()->prime( $gateways->ids() );
		foreach ( $gateways->ids() as $id ) {
			try {
				$gateway = $gateways->get( $id );
				if ( ! $gateway instanceof ExpressGateway || ! $gateways->settings()->enabled( $gateway ) ) {
					continue;
				}
				$config = $gateway->express_config( $place );
				if ( $config !== null ) {
					return [ $id, $config ];
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return null;
	}
}
