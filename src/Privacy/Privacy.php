<?php

declare(strict_types=1);

namespace Flexa\Commerce\Privacy;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the core personal data tools (Tools > Export / Erase Personal Data)
 * and adds the suggested privacy policy text. Admin requests only; the
 * order and cart modules are built when a request is processed.
 */
final class Privacy {
	use HasInstance;

	private ?PersonalData $data = null;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'erasers' ] );
		add_action( 'admin_init', [ $this, 'policy' ] );
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public function exporters( array $exporters ): array {
		$exporters['flexacommerce-orders']    = [
			'exporter_friendly_name' => __( 'FlexaCommerce orders', 'flexacommerce' ),
			'callback'               => [ $this, 'export_orders' ],
		];
		$exporters['flexacommerce-cart']      = [
			'exporter_friendly_name' => __( 'FlexaCommerce cart', 'flexacommerce' ),
			'callback'               => [ $this, 'export_cart' ],
		];
		$exporters['flexacommerce-addresses'] = [
			'exporter_friendly_name' => __( 'FlexaCommerce saved addresses', 'flexacommerce' ),
			'callback'               => [ $this, 'export_addresses' ],
		];
		$exporters['flexacommerce-downloads'] = [
			'exporter_friendly_name' => __( 'FlexaCommerce downloads', 'flexacommerce' ),
			'callback'               => [ $this, 'export_downloads' ],
		];
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public function erasers( array $erasers ): array {
		$erasers['flexacommerce-orders']    = [
			'eraser_friendly_name' => __( 'FlexaCommerce orders', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_orders' ],
		];
		$erasers['flexacommerce-cart']      = [
			'eraser_friendly_name' => __( 'FlexaCommerce cart', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_cart' ],
		];
		$erasers['flexacommerce-addresses'] = [
			'eraser_friendly_name' => __( 'FlexaCommerce saved addresses', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_addresses' ],
		];
		$erasers['flexacommerce-downloads'] = [
			'eraser_friendly_name' => __( 'FlexaCommerce downloads', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_downloads' ],
		];
		return $erasers;
	}

	/**
	 * Download permissions with the counts and dates (ADR-058), 100 a page.
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export_downloads( string $email, int $page = 1 ): array {
		$rows = Plugin::instance()->downloads()->permissions()->for_email( $email, 100, ( max( 1, $page ) - 1 ) * 100 );
		$data = [];
		foreach ( $rows as $permission ) {
			$data[] = [
				'group_id'    => 'flexacommerce-downloads',
				'group_label' => __( 'Downloads', 'flexacommerce' ),
				'item_id'     => 'fxc-download-' . $permission->id,
				'data'        => [
					[
						'name'  => __( 'Order', 'flexacommerce' ),
						'value' => (string) $permission->order_id,
					],
					[
						'name'  => __( 'Product', 'flexacommerce' ),
						'value' => get_the_title( $permission->product_id ),
					],
					[
						'name'  => __( 'Times downloaded', 'flexacommerce' ),
						'value' => (string) $permission->download_count,
					],
					[
						'name'  => __( 'Last downloaded', 'flexacommerce' ),
						'value' => (string) $permission->last_download_at,
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $rows ) < 100,
		];
	}

	/**
	 * Removes the download permissions of an email address, so its links stop working.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase_downloads( string $email, int $page = 1 ): array {
		unset( $page );
		$removed = Plugin::instance()->downloads()->permissions()->delete_for_email( $email );
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed > 0 ? [ __( 'Download access removed.', 'flexacommerce' ) ] : [],
			'done'           => true,
		];
	}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public function export_orders( string $email, int $page = 1 ): array {
		return $this->data()->export_orders( $email, $page );
	}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public function export_cart( string $email, int $page = 1 ): array {
		unset( $page );
		return $this->data()->export_session( $email );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase_orders( string $email, int $page = 1 ): array {
		unset( $page );
		return $this->data()->erase_orders( $email );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase_cart( string $email, int $page = 1 ): array {
		unset( $page );
		return $this->data()->erase_session( $email );
	}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public function export_addresses( string $email, int $page = 1 ): array {
		unset( $page );
		return $this->data()->export_addresses( $email );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase_addresses( string $email, int $page = 1 ): array {
		unset( $page );
		return $this->data()->erase_addresses( $email );
	}

	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$collect = __( 'When you buy from us we ask for your name, billing and shipping address, email address and phone number, and we keep the products you ordered, the amounts and the payment method. We use this to process and ship your order, contact you about it and handle refunds.', 'flexacommerce' );
		if ( Settings::bool( 'store_customer_ip' ) ) {
			$collect .= ' ' . __( 'We also record the IP address the order was placed from, to help detect fraud.', 'flexacommerce' );
		}
		$cart = sprintf(
			/* translators: 1: hours a guest cart is kept, 2: days a signed-in customer's cart is kept. */
			__( 'While you shop we keep your cart in a cookie named fxc_session and in our database, for %1$d hours for guests and %2$d days for signed-in customers. A second cookie, fxc_cart_count, holds only the number of items in the cart.', 'flexacommerce' ),
			Settings::int( 'guest_session_hours' ),
			Settings::int( 'user_session_days' )
		);
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for the parts of your policy that cover the store. Change it to match what your store does, in particular the payment and shipping services you use.', 'flexacommerce' ) . '</p>'
			. '<h2>' . esc_html__( 'What we collect when you place an order', 'flexacommerce' ) . '</h2>'
			. '<p>' . esc_html( $collect ) . '</p>'
			. '<p>' . esc_html( $cart ) . '</p>'
			. '<h2>' . esc_html__( 'How long we keep it', 'flexacommerce' ) . '</h2>'
			. '<p>' . esc_html__( 'We keep order records for as long as tax and accounting rules require. If you ask us to erase your data, we remove your name, addresses, email, phone, IP address and order notes from your orders and keep the amounts and products for our accounts. Orders that are still being processed are erased once they are completed or cancelled.', 'flexacommerce' ) . '</p>'
			. '<h2>' . esc_html__( 'Who we share it with', 'flexacommerce' ) . '</h2>'
			. '<p>' . esc_html__( 'Payment providers receive the details needed to take the payment, and shipping carriers receive your shipping address and phone number.', 'flexacommerce' ) . '</p>';
		wp_add_privacy_policy_content( 'FlexaCommerce', wp_kses_post( $text ) );
	}

	private function data(): PersonalData {
		if ( $this->data === null ) {
			$plugin     = Plugin::instance();
			$orders     = $plugin->orders();
			$this->data = new PersonalData(
				$orders->orders(),
				$orders->history(),
				$orders->service(),
				$plugin->cart()->session_rows(),
				$plugin->services()->money(),
			);
		}
		return $this->data;
	}
}
