<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

use Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration;
use Flexa\Commerce\Contracts\Exceptions\IncompatibleApi;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\ExtensionsFacade;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;
use Flexa\Commerce\Tests\Integration\Payments\FakeGateway;

/** The facade extensions receive on `flexacommerce.loaded` (doc 04 §2). */
final class ExtensionsTest extends OrderTestCase {
	public function test_the_registries_are_the_ones_core_uses(): void {
		$plugin = Plugin::instance();
		$ext    = $plugin->extensions();

		self::assertSame( $ext, $plugin->extensions() );
		self::assertSame( $plugin->payments()->gateways(), $ext->payment_gateways() );
		self::assertSame( $plugin->shipping()->methods(), $ext->shipping_methods() );
		self::assertContains( 'cod', $ext->payment_gateways()->ids() );
		self::assertContains( 'flat_rate', $ext->shipping_methods()->types() );

		if ( ! $ext->payment_gateways()->has( FakeGateway::ID ) ) {
			$ext->payment_gateways()->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
		}
		self::assertInstanceOf( FakeGateway::class, $plugin->payments()->gateways()->get( FakeGateway::ID ) );

		$this->expectException( DuplicateRegistration::class );
		$ext->payment_gateways()->register( 'cod', static fn (): PaymentGateway => new FakeGateway() );
	}

	public function test_the_order_reader_sees_orders_and_keeps_meta(): void {
		$order  = $this->place( [ $this->simple( 1500 ) => 1 ] );
		$reader = Plugin::instance()->extensions()->orders();

		self::assertSame( $order->id, $reader->find( $order->id )?->id );
		self::assertSame( $order->id, $reader->find_by_number( $order->order_number )?->id );
		$reader->set_meta( $order->id, 'acme_ref', [ 'id' => 7 ] );
		self::assertSame( '{"id":7}', $reader->get_meta( $order->id, 'acme_ref' ) );
	}

	public function test_modules_are_built_only_when_asked_for(): void {
		$built = [];
		$ext   = new ExtensionsFacade(
			Plugin::instance()->services(),
			static function () use ( &$built ) {
				$built[] = 'payments';
				return Plugin::instance()->payments()->gateways();
			},
			static function () use ( &$built ) {
				$built[] = 'shipping';
				return Plugin::instance()->shipping()->methods();
			},
			static function () use ( &$built ) {
				$built[] = 'orders';
				return Plugin::instance()->orders()->reader();
			},
		);
		$ext->settings( 'acme' );
		$ext->money();
		self::assertSame( [], $built );

		$ext->shipping_methods();
		self::assertSame( [ 'shipping' ], $built );
	}

	public function test_an_older_api_is_refused(): void {
		$ext = Plugin::instance()->extensions();
		$ext->require_api( '1.0' );
		$this->expectException( IncompatibleApi::class );
		$ext->require_api( '2.0' );
	}
}
