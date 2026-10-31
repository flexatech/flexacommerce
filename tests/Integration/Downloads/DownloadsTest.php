<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Downloads;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Downloads\DownloadService;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\DownloadHandler;
use Flexa\Commerce\Storefront\Render\Account;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Downloadable products (ADR-058): permissions are granted when an order is
 * paid, an order of downloads only completes itself, links are checked and
 * counted, and product edits reach the orders that bought the files.
 */
final class DownloadsTest extends OrderTestCase {
	private string $path = '';
	private string $url  = '';
	private int $user    = 0;

	public function set_up(): void {
		parent::set_up();
		$uploads    = wp_get_upload_dir();
		$this->path = $uploads['basedir'] . '/fxc-test-ebook.pdf';
		$this->url  = $uploads['baseurl'] . '/fxc-test-ebook.pdf';
		file_put_contents( $this->path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->user = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wpdb;
		wp_delete_file( $this->path );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'downloads' ) ); // phpcs:ignore WordPress.DB
		Settings::update( [ 'download_method' => 'force' ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_a_paid_order_of_downloads_only_completes_and_gets_links(): void {
		$product = $this->ebook( 2 );
		$order   = $this->buy( $product, 3 );
		self::assertSame( S::PENDING, $order->status );
		self::assertSame( [], $this->permissions( $order ), 'Nothing is granted before payment.' );

		$order = $this->orders()->record_payment( $order->id, $order->total, new TransitionContext( 'gateway' ) );
		self::assertSame( S::COMPLETED, $order->status, 'Nothing to ship, so the order completes.' );
		$permissions = $this->permissions( $order );
		self::assertCount( 1, $permissions );
		self::assertSame( 6, $permissions[0]->downloads_remaining, 'The limit counts per unit bought.' );
		self::assertNotNull( $permissions[0]->access_expires );
		self::assertSame( $this->user, $permissions[0]->customer_id );

		$links = Plugin::instance()->downloads()->links( $order->id );
		self::assertSame( 'Ebook', $links[0]['name'] );
		self::assertSame( DownloadService::url( $permissions[0] ), $links[0]['url'] );
		self::assertSame( $links, Plugin::instance()->notifications()->data()->downloads( $order->view() ) );
	}

	public function test_an_order_with_a_shipped_line_stays_processing(): void {
		$shipped = $this->simple( 500 );
		$ebook   = $this->ebook();
		$order   = $this->buy( $ebook, 1, [ $shipped ] );
		$order   = $this->orders()->record_payment( $order->id, $order->total, new TransitionContext( 'gateway' ) );
		self::assertSame( S::PROCESSING, $order->status );
		self::assertCount( 1, $this->permissions( $order ), 'Processing orders can download already.' );
	}

	public function test_a_link_is_counted_and_refused_when_used_up(): void {
		$order      = $this->paid( $this->ebook( 1 ) );
		$permission = $this->permissions( $order )[0];
		$handler    = DownloadHandler::instance();

		$plan = $handler->plan( $permission->token, 0 );
		self::assertSame( 'force', $plan['method'] ?? '' );
		self::assertSame( realpath( $this->path ), $plan['path'] ?? '' );
		self::assertSame( 'Ebook.pdf', $plan['name'] ?? '', 'The file name gets the extension of the file.' );

		$after = Plugin::instance()->downloads()->permissions()->find( $permission->id );
		self::assertSame( [ 0, 1 ], [ $after?->downloads_remaining, $after?->download_count ] );
		self::assertNotNull( $after?->last_download_at );

		$used = $handler->plan( $permission->token, 0 );
		self::assertSame( 410, $used['status'] ?? 0 );
		self::assertSame( 404, $handler->plan( 'nope', 0 )['status'] ?? 0 );
	}

	public function test_the_rest_of_a_cut_off_download_is_not_counted(): void {
		$order      = $this->paid( $this->ebook( 1 ) );
		$permission = $this->permissions( $order )[0];
		Plugin::instance()->downloads()->permissions()->update_limits( $permission->id, 1, null );

		$plan = DownloadHandler::instance()->plan( $permission->token, 0, 'bytes=40-' );
		self::assertSame( [ 40, 99 ], $plan['range'] ?? null );
		self::assertSame( 1, Plugin::instance()->downloads()->permissions()->find( $permission->id )?->downloads_remaining );

		self::assertSame( [ 0, 9 ], DownloadHandler::range( 'bytes=0-9', 100 ) );
		self::assertSame( [ 90, 99 ], DownloadHandler::range( 'bytes=-10', 100 ) );
		self::assertNull( DownloadHandler::range( 'bytes=0-1,5-9', 100 ) );
		self::assertNull( DownloadHandler::range( 'bytes=200-', 100 ) );
	}

	public function test_expired_cancelled_and_login_rules_refuse_the_link(): void {
		$order      = $this->paid( $this->ebook() );
		$permission = $this->permissions( $order )[0];
		$handler    = DownloadHandler::instance();

		add_filter( 'flexacommerce.downloads.require_login', '__return_true' );
		self::assertSame( 403, $handler->plan( $permission->token, 0 )['status'] ?? 0 );
		self::assertArrayHasKey( 'method', $handler->plan( $permission->token, $this->user ) );
		remove_filter( 'flexacommerce.downloads.require_login', '__return_true' );

		Plugin::instance()->downloads()->permissions()->update_limits( $permission->id, null, '2020-01-01 00:00:00' );
		self::assertSame( 410, $handler->plan( $permission->token, 0 )['status'] ?? 0 );
		self::assertSame( [], Plugin::instance()->downloads()->links( $order->id ), 'Expired links are not listed.' );

		Plugin::instance()->downloads()->permissions()->update_limits( $permission->id, null, null );
		$this->orders()->transition( $order->id, S::REFUNDED, TransitionContext::admin() );
		self::assertSame( 403, $handler->plan( $permission->token, 0 )['status'] ?? 0 );
	}

	public function test_remote_files_redirect_and_the_redirect_method_skips_the_protected_folder(): void {
		$product = $this->ebook( null, 'https://files.example.com/ebook.pdf' );
		$plan    = DownloadHandler::instance()->plan( $this->permissions( $this->paid( $product ) )[0]->token, 0 );
		self::assertSame( [ 'redirect', 'https://files.example.com/ebook.pdf' ], [ $plan['method'] ?? '', $plan['url'] ?? '' ] );

		Settings::update( [ 'download_method' => 'redirect' ] );
		$plan = DownloadHandler::instance()->plan( $this->permissions( $this->paid( $this->ebook() ) )[0]->token, 0 );
		self::assertSame( [ 'redirect', $this->url ], [ $plan['method'] ?? '', $plan['url'] ?? '' ] );

		$folder = wp_get_upload_dir()['basedir'] . '/' . DownloadHandler::FOLDER;
		DownloadHandler::protect( $folder );
		self::assertFileExists( $folder . '/.htaccess' );
		copy( $this->path, $folder . '/locked.pdf' );
		try {
			$locked = $this->ebook( null, wp_get_upload_dir()['baseurl'] . '/' . DownloadHandler::FOLDER . '/locked.pdf' );
			$plan   = DownloadHandler::instance()->plan( $this->permissions( $this->paid( $locked ) )[0]->token, 0 );
			self::assertSame( 'force', $plan['method'] ?? '', 'The web server refuses that folder, so PHP sends the file.' );
		} finally {
			wp_delete_file( $folder . '/locked.pdf' );
		}
	}

	public function test_product_edits_reach_open_orders(): void {
		$product = $this->ebook();
		$order   = $this->paid( $product );
		$first   = $this->service()->get( $product )->base->downloads->files[0];

		$this->save(
			$product,
			[
				'base' => [
					'downloads' => [
						'files' => [
							$first,
							[
								'name' => 'Bonus',
								'file' => $this->url,
							],
						],
					],
				],
			]
		);
		$files = array_map( static fn ( $p ): string => $p->file_id, $this->permissions( $order ) );
		self::assertCount( 2, $files, 'An added file reaches the order.' );
		self::assertContains( $first['id'], $files );

		$this->save( $product, [ 'base' => [ 'downloads' => [ 'files' => [ $first ] ] ] ] );
		self::assertSame( [ $first['id'] ], array_map( static fn ( $p ): string => $p->file_id, $this->permissions( $order ) ), 'A removed file loses its links.' );
	}

	public function test_files_outside_the_uploads_folder_are_refused(): void {
		$this->expectException( InvalidProduct::class );
		$this->ebook( null, ABSPATH . 'wp-config.php' );
	}

	public function test_the_account_page_lists_the_customer_downloads(): void {
		$this->paid( $this->ebook() );
		$view = new Account( new CustomerProfile(), Plugin::instance()->checkout()->service() );
		$html = $view->downloads( $this->user );
		self::assertStringContainsString( 'Ebook', $html );
		self::assertStringContainsString( 'fxc_download=', $html );
		self::assertStringContainsString( 'No downloads yet.', $view->downloads( self::factory()->user->create() ) );
	}

	public function test_the_admin_order_routes_list_change_and_revoke_access(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$order      = $this->paid( $this->ebook( 2 ) );
		$permission = $this->permissions( $order )[0];
		$other      = $this->paid( $this->ebook() );

		$detail = $this->send( 'GET', '/admin/orders/' . $order->id )->get_data();
		self::assertTrue( $detail['items'][0]['downloadable'] );
		self::assertSame( [ 'Ebook', 2 ], [ $detail['downloads'][0]['file_name'], $detail['downloads'][0]['downloads_remaining'] ] );
		self::assertStringEndsWith( 'Z', (string) $detail['downloads'][0]['access_expires'] );

		$path    = '/admin/orders/' . $order->id . '/downloads/' . $permission->id;
		$changed = $this->send(
			'PATCH',
			$path,
			[
				'downloads_remaining' => null,
				'access_expires'      => '2030-05-01',
			]
		);
		self::assertSame( 200, $changed->get_status(), (string) wp_json_encode( $changed->get_data() ) );
		self::assertSame( [ null, '2030-05-01T23:59:59Z' ], [ $changed->get_data()['downloads'][0]['downloads_remaining'], $changed->get_data()['downloads'][0]['access_expires'] ] );

		self::assertSame( 404, $this->send( 'DELETE', '/admin/orders/' . $other->id . '/downloads/' . $permission->id )->get_status(), 'A permission of another order is not reachable.' );
		self::assertSame(
			400,
			$this->send(
				'PATCH',
				$path,
				[
					'downloads_remaining' => 1,
					'access_expires'      => 'soon',
				]
			)->get_status()
		);

		self::assertSame( [], $this->send( 'DELETE', $path )->get_data()['downloads'] );
		$granted = $this->send( 'POST', '/admin/orders/' . $order->id . '/downloads' );
		self::assertSame( 201, $granted->get_status() );
		self::assertCount( 1, $granted->get_data()['downloads'], 'Granting again restores what the product sells.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'DELETE', $path )->get_status() );
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function ebook( ?int $limit = null, ?string $file = null ): int {
		$id = $this->product( 'Ebook' );
		$this->save(
			$id,
			[
				'base' => [
					'regular_price' => 900,
					'virtual'       => true,
					'downloadable'  => true,
					'downloads'     => [
						'files'       => [
							[
								'name' => 'Ebook',
								'file' => $file ?? $this->url,
							],
						],
						'limit'       => $limit,
						'expiry_days' => 30,
					],
				],
			]
		);
		return $id;
	}

	/** @param list<int> $shipped simple products added as lines that ship */
	private function buy( int $product_id, int $qty = 1, array $shipped = [] ): Order {
		$items    = [];
		$subtotal = 0;
		foreach ( [ $product_id, ...$shipped ] as $id ) {
			$variant   = $this->service()->get( $id )->base;
			$price     = (int) $variant->regular_price;
			$quantity  = $id === $product_id ? $qty : 1;
			$items[]   = NewOrderItem::product(
				$id,
				$variant->id,
				$variant->id,
				'Product ' . $id,
				null,
				[],
				$quantity,
				$price,
				extra: [
					'virtual'      => $variant->virtual,
					'downloadable' => $variant->sells_files(),
				]
			);
			$subtotal += $price * $quantity;
		}
		$new = new NewOrder(
			status: S::PENDING,
			currency: 'USD',
			currency_exponent: 2,
			items: $items,
			subtotal: $subtotal,
			shipping_total: 0,
			total: $subtotal,
			customer_email: 'ada@example.com',
			customer_id: $this->user,
			billing: new AddressView( type: 'billing', first_name: 'Ada', email: 'ada@example.com' ),
			payment_method: 'cod',
			payment_method_title: 'cod',
			checkout_token: wp_generate_password( 32, false ),
		);
		return $this->orders()->create( $new, $this->orders()->next_number(), new TransitionContext( 'customer' ) );
	}

	private function paid( int $product_id ): Order {
		$order = $this->buy( $product_id );
		return $this->orders()->record_payment( $order->id, $order->total, new TransitionContext( 'gateway' ) );
	}

	/** @return list<\Flexa\Commerce\Downloads\Permission> */
	private function permissions( Order $order ): array {
		return Plugin::instance()->downloads()->permissions()->for_order( $order->id );
	}
}
