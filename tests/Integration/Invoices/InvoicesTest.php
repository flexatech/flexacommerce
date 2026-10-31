<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Invoices;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Invoices\InvoiceRenderer;
use Flexa\Commerce\Invoices\InvoiceService;
use Flexa\Commerce\Invoices\InvoiceSettings;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\InvoiceHandler;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** PDF invoices and packing slips (ADR-060). */
final class InvoicesTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		$this->invoices()->reset();
		reset_phpmailer_instance();
	}

	/** Sequence::next() commits, so the counters and the option are cleaned after the rollback. */
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		delete_option( InvoiceSettings::OPTION );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE name LIKE %s', Schema::table( 'sequences' ), 'invoice\\_number%' ) ); // phpcs:ignore WordPress.DB
		$this->invoices()->reset();
		wp_cache_flush();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_invoices_are_numbered_in_order_and_keep_their_number(): void {
		$first  = $this->place( [ $this->simple( 1000 ) => 1 ] );
		$second = $this->place( [ $this->simple( 2000 ) => 1 ] );

		self::assertSame( 'INV-0001', $this->invoices()->next_number() );
		$a = $this->invoices()->create( $first );
		$b = $this->invoices()->create( $second );
		self::assertSame( 'INV-0001', $a->number );
		self::assertSame( 'INV-0002', $b->number );
		self::assertSame( 'INV-0001', $this->invoices()->create( $first )->number );
		self::assertSame( 'INV-0001', $this->invoices()->find( $first->id )?->number );
		self::assertSame( 3, $this->invoices()->next_counter() );
	}

	public function test_the_next_number_only_goes_up(): void {
		$this->invoices()->set_next_counter( 500 );
		self::assertSame( 500, $this->invoices()->next_counter() );
		$this->invoices()->set_next_counter( 20 );
		self::assertSame( 500, $this->invoices()->next_counter() );
		self::assertSame( 'INV-0500', $this->invoices()->create( $this->place( [ $this->simple( 1000 ) => 1 ] ) )->number );
	}

	public function test_a_yearly_reset_uses_a_counter_per_year_and_the_year_token(): void {
		$this->configure(
			[
				'prefix'  => '{year}/',
				'suffix'  => '-{month}',
				'padding' => 3,
				'reset'   => 'yearly',
			]
		);
		$now     = new \DateTimeImmutable( 'now', wp_timezone() );
		$invoice = $this->invoices()->create( $this->place( [ $this->simple( 1000 ) => 1 ] ) );
		self::assertSame( $now->format( 'Y' ) . '/001-' . $now->format( 'm' ), $invoice->number );
		self::assertSame( 1, Plugin::instance()->services()->sequence()->current( InvoiceService::SEQUENCE . '_' . $now->format( 'Y' ) ) );
		self::assertSame( 0, Plugin::instance()->services()->sequence()->current( InvoiceService::SEQUENCE ) );
	}

	public function test_the_pdf_is_made_and_long_packing_slips_break_onto_pages(): void {
		$order = $this->place( [ $this->simple( 1500 ) => 2 ], 500 );
		$pdf   = $this->invoices()->pdf( [ $order ] );
		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertStringContainsString( '/Count 1', $pdf );
		self::assertNotNull( $this->invoices()->find( $order->id ) );
		self::assertSame( 'invoice-INV-0001.pdf', $this->invoices()->filename( [ $order ] ) );

		$lines = [];
		for ( $i = 0; $i < 45; $i++ ) {
			$lines[ $this->simple( 100 + $i ) ] = 1;
		}
		$long = $this->place( $lines );
		$slip = $this->invoices()->pdf( [ $long ], InvoiceRenderer::PACKING_SLIP );
		self::assertMatchesRegularExpression( '#/Count [2-9]#', $slip );
		self::assertNull( $this->invoices()->find( $long->id ), 'A packing slip does not number the order.' );

		$both = $this->invoices()->pdf( [ $order, $long ] );
		self::assertMatchesRegularExpression( '#/Count [3-9]#', $both );
		self::assertSame( 'INV-0002', $this->invoices()->find( $long->id )?->number );
	}

	public function test_the_chosen_email_carries_the_invoice_and_the_file_is_removed(): void {
		$this->configure( [ 'attach_to' => [ 'customer_processing_order' ] ] );
		$attached = [];
		$capture  = static function ( array $args ) use ( &$attached ): array {
			$files = (array) ( $args['attachments'] ?? [] );
			foreach ( $files as $file ) {
				$attached[] = [ (string) $file, is_file( (string) $file ) ];
			}
			$attached[] = (string) $args['subject'];
			return $args;
		};
		add_filter( 'wp_mail', $capture );
		try {
			$order = $this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );
		} finally {
			remove_filter( 'wp_mail', $capture );
		}

		$files = array_values( array_filter( $attached, 'is_array' ) );
		self::assertCount( 1, $files, 'Only the customer email carries the invoice.' );
		self::assertStringEndsWith( '/invoice-INV-0001.pdf', $files[0][0] );
		self::assertTrue( $files[0][1] );
		self::assertFileDoesNotExist( $files[0][0] );
		self::assertDirectoryDoesNotExist( dirname( $files[0][0] ) );
		self::assertSame( 'INV-0001', $this->invoices()->find( $order->id )?->number );
	}

	public function test_customers_see_the_invoice_by_the_settings(): void {
		$handler = InvoiceHandler::instance();
		$paid    = $this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );
		$pending = $this->place( [ $this->simple( 1500 ) => 1 ] );

		self::assertSame( $paid->id, $handler->allowed( $paid->id, $paid->order_key, 0 )?->id );
		self::assertNull( $handler->allowed( $paid->id, 'wrong', 0 ) );
		self::assertNull( $handler->allowed( $pending->id, $pending->order_key, 0 ) );
		self::assertNull( $handler->allowed( 999999, '', 0 ) );

		$this->configure( [ 'customer_access' => 'invoiced' ] );
		self::assertNull( $handler->allowed( $paid->id, $paid->order_key, 0 ) );
		$this->invoices()->create( $paid );
		self::assertNotNull( $handler->allowed( $paid->id, $paid->order_key, 0 ) );

		$this->configure( [ 'customer_access' => 'never' ] );
		self::assertNull( $handler->allowed( $paid->id, $paid->order_key, 0 ) );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		self::assertSame( $pending->id, $handler->allowed( $pending->id, '', $admin )?->id );
		self::assertStringContainsString( 'fxc_invoice=' . $paid->id . '&key=' . $paid->order_key, $this->invoices()->customer_url( $paid ) );
	}

	/** @param array<string,mixed> $values */
	private function configure( array $values ): void {
		InvoiceSettings::update( $values );
		$this->invoices()->reset();
	}

	private function invoices(): InvoiceService {
		return Plugin::instance()->invoices();
	}
}
