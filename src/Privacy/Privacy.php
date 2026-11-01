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
		$exporters['flexacommerce-orders']       = [
			'exporter_friendly_name' => __( 'FlexaCommerce orders', 'flexacommerce' ),
			'callback'               => [ $this, 'export_orders' ],
		];
		$exporters['flexacommerce-cart']         = [
			'exporter_friendly_name' => __( 'FlexaCommerce cart', 'flexacommerce' ),
			'callback'               => [ $this, 'export_cart' ],
		];
		$exporters['flexacommerce-addresses']    = [
			'exporter_friendly_name' => __( 'FlexaCommerce saved addresses', 'flexacommerce' ),
			'callback'               => [ $this, 'export_addresses' ],
		];
		$exporters['flexacommerce-downloads']    = [
			'exporter_friendly_name' => __( 'FlexaCommerce downloads', 'flexacommerce' ),
			'callback'               => [ $this, 'export_downloads' ],
		];
		$exporters['flexacommerce-reminders']    = [
			'exporter_friendly_name' => __( 'FlexaCommerce cart reminders', 'flexacommerce' ),
			'callback'               => [ $this, 'export_reminders' ],
		];
		$exporters['flexacommerce-stock-alerts'] = [
			'exporter_friendly_name' => __( 'FlexaCommerce back-in-stock emails', 'flexacommerce' ),
			'callback'               => [ $this, 'export_stock_alerts' ],
		];
		$exporters['flexacommerce-wishlist']     = [
			'exporter_friendly_name' => __( 'FlexaCommerce wishlist', 'flexacommerce' ),
			'callback'               => [ $this, 'export_wishlist' ],
		];
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public function erasers( array $erasers ): array {
		$erasers['flexacommerce-orders']       = [
			'eraser_friendly_name' => __( 'FlexaCommerce orders', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_orders' ],
		];
		$erasers['flexacommerce-cart']         = [
			'eraser_friendly_name' => __( 'FlexaCommerce cart', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_cart' ],
		];
		$erasers['flexacommerce-addresses']    = [
			'eraser_friendly_name' => __( 'FlexaCommerce saved addresses', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_addresses' ],
		];
		$erasers['flexacommerce-downloads']    = [
			'eraser_friendly_name' => __( 'FlexaCommerce downloads', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_downloads' ],
		];
		$erasers['flexacommerce-reminders']    = [
			'eraser_friendly_name' => __( 'FlexaCommerce cart reminders', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_reminders' ],
		];
		$erasers['flexacommerce-stock-alerts'] = [
			'eraser_friendly_name' => __( 'FlexaCommerce back-in-stock emails', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_stock_alerts' ],
		];
		$erasers['flexacommerce-wishlist']     = [
			'eraser_friendly_name' => __( 'FlexaCommerce wishlist', 'flexacommerce' ),
			'callback'             => [ $this, 'erase_wishlist' ],
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

	/**
	 * Left carts kept for reminders, and a request to stop them (ADR-063).
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export_reminders( string $email, int $page = 1 ): array {
		unset( $page );
		$data = [];
		foreach ( Plugin::instance()->recovery()->repository()->for_email( $email ) as $row ) {
			$items = [];
			foreach ( $row->cart['items'] as $item ) {
				$items[] = sprintf( '%s × %d', $item['name'], $item['quantity'] );
			}
			$fields = [
				[
					'name'  => __( 'Cart items', 'flexacommerce' ),
					'value' => implode( ', ', $items ),
				],
				[
					'name'  => __( 'Left on', 'flexacommerce' ),
					'value' => $row->last_activity_at !== '' ? get_date_from_gmt( $row->last_activity_at ) : '',
				],
				[
					'name'  => __( 'Reminders sent', 'flexacommerce' ),
					'value' => (string) $row->emails_sent,
				],
			];
			if ( $row->unsubscribed_at !== null ) {
				$fields[] = [
					'name'  => __( 'Reminders stopped on', 'flexacommerce' ),
					'value' => get_date_from_gmt( $row->unsubscribed_at ),
				];
			}
			$data[] = [
				'group_id'    => 'flexacommerce-reminders',
				'group_label' => __( 'Cart reminders', 'flexacommerce' ),
				'item_id'     => 'fxc-recovery-' . $row->id,
				'data'        => $fields,
			];
		}
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Drops the left carts of an address and the address on guest carts.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase_reminders( string $email, int $page = 1 ): array {
		unset( $page );
		$plugin  = Plugin::instance();
		$removed = $plugin->recovery()->repository()->erase_email( $email ) + $plugin->cart()->session_rows()->clear_email( $email );
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Back-in-stock signups (ADR-065).
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export_stock_alerts( string $email, int $page = 1 ): array {
		unset( $page );
		$service = Plugin::instance()->stock_alerts();
		$data    = [];
		foreach ( $service->repository()->for_email( strtolower( $email ) ) as $alert ) {
			$product = Plugin::instance()->catalog()->products()->find( $alert->product_id );
			$fields  = [
				[
					'name'  => __( 'Product', 'flexacommerce' ),
					'value' => $product !== null ? $service->label( $product, $alert->variant_id ) : '#' . $alert->product_id,
				],
				[
					'name'  => __( 'Signed up on', 'flexacommerce' ),
					'value' => get_date_from_gmt( $alert->created_at ),
				],
				[
					'name'  => __( 'Status', 'flexacommerce' ),
					'value' => $alert->status,
				],
			];
			if ( $alert->sent_at !== null ) {
				$fields[] = [
					'name'  => __( 'Emailed on', 'flexacommerce' ),
					'value' => get_date_from_gmt( $alert->sent_at ),
				];
			}
			$data[] = [
				'group_id'    => 'flexacommerce-stock-alerts',
				'group_label' => __( 'Back-in-stock emails', 'flexacommerce' ),
				'item_id'     => 'fxc-stock-alert-' . $alert->id,
				'data'        => $fields,
			];
		}
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase_stock_alerts( string $email, int $page = 1 ): array {
		unset( $page );
		return [
			'items_removed'  => Plugin::instance()->stock_alerts()->repository()->erase_email( strtolower( $email ) ) > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * The products saved by the account of the address (ADR-066). Guest
	 * lists carry no address, so they cannot be found by one.
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export_wishlist( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		$list = $user instanceof \WP_User ? Plugin::instance()->wishlist()->repository()->for_user( $user->ID ) : null;
		$data = [];
		foreach ( $list !== null ? Plugin::instance()->wishlist()->items( $list ) : [] as $product_id ) {
			$data[] = [
				'group_id'    => 'flexacommerce-wishlist',
				'group_label' => __( 'Wishlist', 'flexacommerce' ),
				'item_id'     => 'fxc-wishlist-' . $product_id,
				'data'        => [
					[
						'name'  => __( 'Product', 'flexacommerce' ),
						'value' => get_the_title( $product_id ) !== '' ? get_the_title( $product_id ) : '#' . $product_id,
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase_wishlist( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		$list = $user instanceof \WP_User ? Plugin::instance()->wishlist()->repository()->for_user( $user->ID ) : null;
		if ( $list !== null ) {
			Plugin::instance()->wishlist()->repository()->delete( $list->id );
		}
		return [
			'items_removed'  => $list !== null,
			'items_retained' => false,
			'messages'       => [],
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
			. '<p>' . esc_html__( 'If you are signed in, or typed your email at checkout, and leave items in your cart without ordering, we may email you up to three reminders about it. Each email has a link to stop them. We keep the record of a left cart for up to 90 days.', 'flexacommerce' ) . '</p>'
			. '<p>' . esc_html__( 'If you ask to hear when a product is back in stock, we keep your email address and the product until we send that one email, or until you use the link in it to stop. We delete the record 90 days after it is sent or stopped.', 'flexacommerce' ) . '</p>'
			. '<p>' . esc_html__( 'When you save products to a wishlist we keep the list in our database. For guests it is found by a cookie named fxc_wishlist and deleted 90 days after its last change; a second cookie, fxc_wishlist_ids, holds only the product numbers in the list. A signed-in customer\'s list stays with the account until the account is deleted. The share link shows the products only, never your name.', 'flexacommerce' ) . '</p>'
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
