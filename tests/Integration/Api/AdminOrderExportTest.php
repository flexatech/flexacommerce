<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\GiftCards\GiftCardService;
use Flexa\Commerce\Orders\Csv\OrderCsvExporter;
use Flexa\Commerce\Orders\Csv\OrderCsvRepository;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderQuery;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * The order CSV export (ADR-070): the list filters, both layouts, the
 * paging that new orders cannot move, and the cells a spreadsheet reads.
 */
final class AdminOrderExportTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_one_row_per_order_with_addresses_totals_payments_and_tracking(): void {
		$product = $this->simple( 1000 );
		$order   = $this->detailed( $product );
		Plugin::instance()->payments()->service()->record_manual_payment( $order->id, 1000, '', TransitionContext::admin() );
		$this->gift_card_payment( $order->id, 800, 300 );
		Plugin::instance()->orders()->shipment_service()->add( $order->id, 'ups', '', '1Z999', '', null, '', false, false, TransitionContext::admin() );
		Plugin::instance()->orders()->orders()->set_meta(
			$order->id,
			'_fxc_fields',
			(string) wp_json_encode(
				[
					[
						'key'   => '_billing_vat',
						'label' => 'VAT number',
						'value' => 'GB123',
					],
				]
			)
		);

		$first = $this->export( [] );
		self::assertSame( 200, $first->get_status() );
		$data = $first->get_data();
		self::assertSame( 1, $data['total'] );
		self::assertTrue( $data['done'] );
		self::assertMatchesRegularExpression( '/^orders-\d{4}-\d{2}-\d{2}\.csv$/', $data['name'] );

		$rows = self::rows( $data['header'] . $data['lines'] );
		self::assertCount( 1, $rows );
		$row = $rows[0];
		self::assertSame( $order->order_number, $row['Order number'] );
		self::assertSame( 'Processing', $row['Status'] );
		self::assertSame( 'ada@example.com', $row['Email'] );
		self::assertSame( 'Ada', $row['Billing first name'] );
		self::assertSame( '1 Main St', $row['Shipping address 1'] );
		self::assertSame( 'GB', $row['Shipping country'] );
		self::assertSame( "'+44 20 1234", $row['Phone'], 'a leading + is guarded, as Woo does' );
		self::assertSame( 'Flat rate', $row['Shipping method'] );
		self::assertSame( 'SAVE5', $row['Coupons'] );
		self::assertSame( '3', $row['Items'] );
		self::assertSame( '30.00', $row['Subtotal'] );
		self::assertSame( '5.00', $row['Discount'] );
		self::assertSame( '4.50', $row['Shipping'] );
		self::assertSame( '29.50', $row['Total'] );
		self::assertSame( '5.00', $row['Paid by gift card'] );
		self::assertSame( '1Z999', $row['Tracking numbers'] );
		self::assertSame( 'Leave at the door, please', $row['Customer note'] );
		self::assertSame( 'VAT number: GB123', $row['Checkout fields'] );
		self::assertSame( 'USD', $row['Currency'] );
	}

	public function test_one_row_per_product_line_and_the_formula_guard(): void {
		$product = $this->simple( 1000 );
		$order   = $this->detailed( $product );

		$data = $this->export( [ 'layout' => 'items' ] )->get_data();
		self::assertMatchesRegularExpression( '/^order-items-/', $data['name'] );
		$rows = self::rows( $data['header'] . $data['lines'] );
		self::assertCount( 2, $rows, 'shipping and discount lines are not product rows' );
		self::assertSame( [ $order->order_number, $order->order_number ], array_column( $rows, 'Order number' ) );
		self::assertSame( "'=HYPERLINK(\"x\")", $rows[0]['Product'] );
		self::assertSame( 'TEE-M', $rows[0]['SKU'] );
		self::assertSame( 'Size: M, Gift wrap: Yes', $rows[0]['Details'] );
		self::assertSame( '2', $rows[0]['Quantity'] );
		self::assertSame( '10.00', $rows[0]['Unit price'] );
		self::assertSame( '20.00', $rows[0]['Line subtotal'] );
		self::assertSame( 'Mug', $rows[1]['Product'] );
		self::assertSame( '0', $rows[1]['Refunded quantity'] );
	}

	public function test_the_list_filters_apply_and_dates_are_store_days(): void {
		update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
		$product = $this->simple( 1000 );
		$late    = $this->place( [ $product => 1 ] );
		$early   = $this->place( [ $product => 1 ], 0, null, OrderStatus::ON_HOLD );
		// 18:00 UTC on 31 October is 01:00 on 1 November in the store.
		$this->created_at( $late->id, '2026-10-31 18:00:00' );
		$this->created_at( $early->id, '2026-10-31 16:00:00' );

		self::assertSame( [ $late->id ], $this->exported_ids( [ 'date_from' => '2026-11-01' ] ) );
		self::assertSame( [ $early->id ], $this->exported_ids( [ 'date_to' => '2026-10-31' ] ) );
		self::assertSame(
			[ $late->id, $early->id ],
			$this->exported_ids(
				[
					'date_from' => '2026-10-31',
					'date_to'   => '2026-11-01',
				]
			)
		);
		self::assertSame( [ $early->id ], $this->exported_ids( [ 'status' => OrderStatus::ON_HOLD ] ) );
		self::assertSame( [ $late->id ], $this->exported_ids( [ 'search' => $late->order_number ] ) );

		$data = $this->export( [ 'search' => $late->order_number ] )->get_data();
		$row  = self::rows( $data['header'] . $data['lines'] )[0];
		self::assertSame( '2026-11-01 01:00:00', $row['Date'] );

		// The list takes the same dates.
		$list = $this->send( '/admin/orders', [ 'date_to' => '2026-10-31' ] );
		self::assertSame( [ $early->id ], array_column( $list->get_data(), 'id' ) );
		self::assertSame( 400, $this->send( '/admin/orders', [ 'date_from' => '31/10/2026' ] )->get_status() );
	}

	public function test_pages_follow_the_ids_and_ignore_orders_placed_meanwhile(): void {
		$product  = $this->simple( 1000 );
		$ids      = [];
		$ids[]    = $this->place( [ $product => 1 ] )->id;
		$ids[]    = $this->place( [ $product => 1 ] )->id;
		$ids[]    = $this->place( [ $product => 1 ] )->id;
		$exporter = new OrderCsvExporter( new OrderCsvRepository( $GLOBALS['wpdb'] ), GiftCardService::GATEWAY );
		$query    = OrderQuery::from_params( [] );

		$scope = $exporter->scope( $query );
		self::assertSame(
			[
				'until' => max( $ids ),
				'total' => 3,
			],
			$scope
		);
		$this->place( [ $product => 1 ] );

		$seen  = [];
		$after = 0;
		do {
			$page  = $exporter->page( $query, OrderCsvExporter::ORDERS, $after, $scope['until'], 2 );
			$after = $page['last'];
			foreach ( self::rows( OrderCsvExporter::header( OrderCsvExporter::ORDERS ) . $page['lines'] ) as $row ) {
				$seen[] = $row['Order number'];
			}
		} while ( $page['orders'] === 2 );

		$numbers = array_map( static fn ( int $id ): string => Plugin::instance()->orders()->service()->get( $id )->order_number, $ids );
		self::assertSame( $numbers, $seen );
	}

	public function test_only_order_viewers_may_export(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->export( [] )->get_status() );
	}

	/** A processing order with two products, a coupon, shipping, a note and both addresses. */
	private function detailed( int $product ): Order {
		$billing  = new AddressView( type: 'billing', first_name: 'Ada', last_name: 'Lovelace', country: 'GB', email: 'ada@example.com', phone: '+44 20 1234' );
		$shipping = new AddressView( type: 'shipping', first_name: 'Ada', last_name: 'Lovelace', address_1: '1 Main St', city: 'London', postcode: 'N1', country: 'GB' );
		$new      = new NewOrder(
			status: OrderStatus::PROCESSING,
			currency: 'USD',
			currency_exponent: 2,
			items: [
				NewOrderItem::product(
					$product,
					$product,
					$product,
					'=HYPERLINK("x")',
					'TEE-M',
					[
						[
							'label' => 'Size',
							'value' => 'M',
						],
						[
							'label' => 'Gift wrap',
							'value' => 'Yes',
						],
					],
					2,
					1000,
					discount: 500
				),
				NewOrderItem::product( $product, $product, $product, 'Mug', null, [], 1, 1000 ),
				NewOrderItem::shipping( 'flat_rate:1', 'Flat rate', 450 ),
				NewOrderItem::discount( 'SAVE5', 500, [] ),
			],
			subtotal: 3000,
			discount_total: 500,
			shipping_total: 450,
			total: 2950,
			customer_email: 'ada@example.com',
			customer_note: 'Leave at the door, please',
			billing: $billing,
			shipping: $shipping,
			payment_method: 'cod',
			payment_method_title: 'Cash on delivery',
			checkout_token: wp_generate_password( 32, false ),
		);
		return $this->orders()->create( $new, $this->orders()->next_number(), new TransitionContext( 'customer' ) );
	}

	/** A succeeded card payment and a refund back to it, as the gift card gateway writes them. */
	private function gift_card_payment( int $order_id, int $paid, int $refunded ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( [ [ 'payment', $paid ], [ 'refund', $refunded ], [ 'payment', 999 ] ] as $n => [ $type, $amount ] ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				Schema::table( 'transactions' ),
				[
					'order_id'        => $order_id,
					'type'            => $type,
					'status'          => $n === 2 ? 'failed' : 'succeeded',
					'gateway'         => GiftCardService::GATEWAY,
					'amount'          => $amount,
					'currency'        => 'USD',
					'idempotency_key' => 'test-' . $order_id . '-' . $n,
					'created_at'      => $now,
					'updated_at'      => $now,
				]
			);
		}
	}

	private function created_at( int $order_id, string $utc ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'orders' ), [ 'created_at' => $utc ], [ 'id' => $order_id ] ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array<string,string> $filters
	 * @return list<int>
	 */
	private function exported_ids( array $filters ): array {
		$data    = $this->export( $filters )->get_data();
		$numbers = array_column( self::rows( $data['header'] . $data['lines'] ), 'Order number' );
		return array_map( static fn ( string $number ): int => (int) Plugin::instance()->orders()->orders()->find_by_number( $number )?->id, $numbers );
	}

	/** @param array<string,string> $query */
	private function export( array $query ): \WP_REST_Response {
		return $this->send( '/admin/orders/export', $query );
	}

	/** @param array<string,string> $query */
	private function send( string $path, array $query ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/flexacommerce/v1' . $path );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	/** @return list<array<string,string>> rows keyed by header */
	private static function rows( string $csv ): array {
		$lines  = array_values( array_filter( explode( "\n", $csv ), static fn ( string $line ): bool => $line !== '' ) );
		$header = str_getcsv( (string) array_shift( $lines ), ',', '"', '' );
		return array_map( static fn ( string $line ): array => array_combine( $header, str_getcsv( $line, ',', '"', '' ) ), $lines );
	}
}
