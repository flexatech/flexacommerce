<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Invoices;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Invoices\InvoiceRenderer;
use Flexa\Commerce\Invoices\InvoiceService;
use Flexa\Commerce\Invoices\InvoiceSettings;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\InvoiceHandler;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** PDF invoices and packing slips (ADR-060), credit notes (ADR-064). */
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
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE name LIKE %s', Schema::table( 'sequences' ), 'credit\\_note\\_number%' ) ); // phpcs:ignore WordPress.DB
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

	public function test_a_credit_note_is_numbered_when_first_made_and_gives_the_order_its_invoice(): void {
		[ $order, $refund ] = $this->refunded( 500 );
		self::assertSame( [], $this->invoices()->credit_notes( $order->id ) );
		self::assertNull( $this->invoices()->find( $order->id ) );
		self::assertSame( 'CN-0001', $this->invoices()->next_number( true ) );

		$created = [];
		$spy     = static function ( int $order_id, int $refund_id, string $number ) use ( &$created ): void {
			$created[] = [ $order_id, $refund_id, $number ];
		};
		add_action( 'flexacommerce.credit_note.created', $spy, 10, 3 );
		try {
			$note  = $this->invoices()->create_credit_note( $order, $refund );
			$again = $this->invoices()->create_credit_note( $order, $refund );
		} finally {
			remove_action( 'flexacommerce.credit_note.created', $spy );
		}

		self::assertSame( 'CN-0001', $note->number );
		self::assertSame( $refund->id, $note->refund_id );
		self::assertSame( 'CN-0001', $again->number );
		self::assertSame( [ [ $order->id, $refund->id, 'CN-0001' ] ], $created, 'The hook fires once.' );
		self::assertSame( 'INV-0001', $this->invoices()->find( $order->id )?->number, 'The credit note refers to an invoice.' );
		self::assertSame( 2, $this->invoices()->next_counter(), 'The invoice took one number of its own counter.' );
		self::assertSame( 2, $this->invoices()->next_counter( true ) );

		$second = $this->refund( $order, 300 );
		self::assertSame( 'CN-0002', $this->invoices()->create_credit_note( $order, $second )->number );
		self::assertSame( [ $refund->id, $second->id ], array_keys( $this->invoices()->credit_notes( $order->id ) ) );
		self::assertSame( [ $refund->id, $second->id ], array_map( static fn ( Transaction $t ): int => $t->id, $this->invoices()->refunds( $order ) ) );
		self::assertNull( $this->invoices()->refund( $order, 999999 ) );
	}

	public function test_credit_note_numbers_have_their_own_prefix_counter_and_yearly_reset(): void {
		$this->configure(
			[
				'credit_prefix' => 'CR{year}-',
				'credit_suffix' => '/X',
				'padding'       => 2,
				'reset'         => 'yearly',
			]
		);
		$this->invoices()->set_next_counter( 7, true );
		$this->invoices()->set_next_counter( 3, true );
		[ $order, $refund ] = $this->refunded( 500 );
		$year               = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y' );

		self::assertSame( 'CR' . $year . '-07/X', $this->invoices()->create_credit_note( $order, $refund )->number );
		self::assertSame( 7, Plugin::instance()->services()->sequence()->current( InvoiceService::CREDIT_SEQUENCE . '_' . $year ) );
		self::assertSame( 'credit-note-CR' . $year . '-07X.pdf', $this->invoices()->credit_filename( $this->invoices()->credit_notes( $order->id )[ $refund->id ] ) );
	}

	public function test_the_credit_note_pdf_shows_the_refunded_lines_the_rest_and_the_reason(): void {
		[ $order, $refund ] = $this->refunded( 1500, true, 'Arrived broken' );
		$strings            = $this->drawn( fn () => $this->invoices()->credit_note_pdf( $order, $refund ) );
		self::assertContains( 'Credit note', $strings );
		self::assertContains( 'Total credited', $strings );
		self::assertContains( 'Other refunded amount', $strings, 'The 500 above the line amount.' );
		self::assertContains( 'Reason', $strings );
		self::assertNotNull( $this->invoices()->credit_notes( $order->id )[ $refund->id ] ?? null );

		$plain   = $this->refund( $order, 200 );
		$strings = $this->drawn( fn () => $this->invoices()->credit_note_pdf( $order, $plain ) );
		self::assertContains( 'Refunded amount', $strings );
		self::assertNotContains( 'Other refunded amount', $strings );
		self::assertNotContains( 'Reason', $strings );
		self::assertStringStartsWith( '%PDF-', $this->invoices()->credit_note_pdf( $order, $plain ) );
	}

	public function test_the_refunded_order_email_carries_the_credit_note_when_asked(): void {
		$order    = $this->place( [ $this->simple( 1000 ) => 2 ], 0, null, S::PROCESSING );
		$payments = Plugin::instance()->payments()->service();
		$this->orders()->record_payment( $order->id, 2000, TransitionContext::system() );
		$files = [];
		$spy   = static function ( array $args ) use ( &$files ): array {
			foreach ( (array) ( $args['attachments'] ?? [] ) as $file ) {
				$files[] = basename( (string) $file );
			}
			return $args;
		};
		add_filter( 'wp_mail', $spy );
		try {
			$payments->refund( $order->id, 500, [], '', false, TransitionContext::admin() );
			self::assertSame( [], $files, 'Off by default.' );
			self::assertSame( [], $this->invoices()->credit_notes( $order->id ) );

			$this->configure( [ 'credit_attach' => true ] );
			$refund = $payments->refund( $order->id, 700, [], '', false, TransitionContext::admin() );
		} finally {
			remove_filter( 'wp_mail', $spy );
		}

		self::assertSame( [ 'credit-note-CN-0001.pdf' ], $files );
		self::assertSame( [ $refund->id ], array_keys( $this->invoices()->credit_notes( $order->id ) ) );
	}

	public function test_customers_see_a_credit_note_by_the_settings(): void {
		$handler            = InvoiceHandler::instance();
		[ $order, $refund ] = $this->refunded( 500 );
		self::assertSame( $order->id, $handler->allowed( $order->id, $order->order_key, 0, $refund->id )?->id );
		self::assertNull( $handler->allowed( $order->id, 'wrong', 0, $refund->id ) );
		self::assertStringContainsString( '&refund=' . $refund->id, $this->invoices()->customer_url( $order, $refund->id ) );

		$this->configure( [ 'customer_access' => 'invoiced' ] );
		$this->invoices()->create( $order );
		self::assertNotNull( $handler->allowed( $order->id, $order->order_key, 0 ), 'The invoice is made.' );
		self::assertNull( $handler->allowed( $order->id, $order->order_key, 0, $refund->id ), 'The credit note is not.' );
		$this->invoices()->create_credit_note( $order, $refund );
		self::assertNotNull( $handler->allowed( $order->id, $order->order_key, 0, $refund->id ) );

		$this->configure( [ 'customer_access' => 'never' ] );
		self::assertNull( $handler->allowed( $order->id, $order->order_key, 0, $refund->id ) );
	}

	/**
	 * A paid order with one refund: of one line (amount + 500 more) when asked.
	 *
	 * @return array{0:Order,1:Transaction}
	 */
	private function refunded( int $amount, bool $with_line = false, string $reason = '' ): array {
		$order = $this->place( [ $this->simple( 1000 ) => 2 ], 0, null, S::PROCESSING );
		$this->orders()->record_payment( $order->id, 2000, TransitionContext::system() );
		$order = $this->orders()->get( $order->id );
		$lines = $with_line ? [ new RefundLine( $order->items[0]->id, 1, $amount - 500 ) ] : [];
		return [ $order, $this->refund( $order, $amount, $lines, $reason ) ];
	}

	/** @param list<RefundLine> $lines */
	private function refund( Order $order, int $amount, array $lines = [], string $reason = '' ): Transaction {
		return Plugin::instance()->payments()->service()->refund( $order->id, $amount, $lines, $reason, false, TransitionContext::admin() );
	}

	/**
	 * The plugin strings translated while the callback runs: the PDF
	 * text is compressed, so this is how a test sees what was drawn.
	 *
	 * @return list<string>
	 */
	private function drawn( callable $draw ): array {
		$strings = [];
		$spy     = static function ( string $translation, string $text, string $domain ) use ( &$strings ): string {
			if ( $domain === 'flexacommerce' ) {
				$strings[] = $text;
			}
			return $translation;
		};
		add_filter( 'gettext', $spy, 10, 3 );
		try {
			$draw();
		} finally {
			remove_filter( 'gettext', $spy );
		}
		return $strings;
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
