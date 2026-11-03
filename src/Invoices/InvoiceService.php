<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Database\Sequence;
use Flexa\Commerce\Invoices\Pdf\PdfDocument;
use Flexa\Commerce\Invoices\Pdf\PdfImage;
use Flexa\Commerce\Invoices\Pdf\TrueTypeFont;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderRepository;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Payments\TransactionRepository;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * PDF invoices and packing slips (ADR-060). An order gets its invoice
 * number the first time an invoice is made for it (by the admin, an email
 * that carries it, or the customer's link) and keeps it. Numbers come from
 * fxcm_sequences, so they are unique and increasing; a yearly reset uses a
 * counter per year.
 *
 * Each succeeded refund can have a credit note (ADR-064) with its own
 * counter, numbered the same way and pointing to the order's invoice.
 *
 * @phpstan-import-type Values from InvoiceSettings
 */
final class InvoiceService {
	public const SEQUENCE = 'invoice_number';

	public const CREDIT_SEQUENCE = 'credit_note_number';

	/** Orders in one bulk PDF at most. */
	public const MAX_ORDERS = 100;

	/** @var Values|null */
	private ?array $settings = null;

	/** @var list<string> */
	private array $temp_files = [];

	public function __construct(
		private readonly InvoiceRepository $invoices,
		private readonly OrderRepository $orders,
		private readonly Sequence $sequence,
		private readonly Clock $clock,
		private readonly MoneyFactory $money,
		private readonly TransactionRepository $transactions,
	) {}

	/** @return Values */
	public function settings(): array {
		return $this->settings ??= InvoiceSettings::get();
	}

	/** Forgets the settings read on this request, after a save. */
	public function reset(): void {
		$this->settings = null;
	}

	public function find( int $order_id ): ?Invoice {
		return $this->invoices->find( $order_id );
	}

	/**
	 * The order's invoice, made now when it has none. Held under a lock for
	 * the order, so two requests at once give it one number. Must run
	 * outside a transaction (Sequence::next()).
	 */
	public function create( Order $order ): Invoice {
		$existing = $this->invoices->find( $order->id );
		if ( $existing !== null ) {
			return $existing;
		}
		if ( ! $this->invoices->lock( $order->id ) ) {
			throw new \RuntimeException( sprintf( 'The invoice of order %d is being created by another request.', $order->id ) );
		}
		try {
			$existing = $this->invoices->find( $order->id );
			if ( $existing !== null ) {
				return $existing;
			}
			$now     = $this->clock->now();
			$number  = $this->sequence->next( $this->sequence_name( $now ) );
			$invoice = new Invoice( $this->format( $number, $now ), $now->format( 'Y-m-d H:i:s' ) );
			$this->invoices->save( $order->id, $invoice );
		} finally {
			$this->invoices->unlock( $order->id );
		}
		do_action( 'flexacommerce.invoice.created', $order->id, $invoice->number, $invoice->date );
		return $invoice;
	}

	/**
	 * The succeeded refunds of the order, oldest first: each can have a
	 * credit note.
	 *
	 * @return list<Transaction>
	 */
	public function refunds( Order $order ): array {
		return array_values(
			array_filter(
				$this->transactions->for_order( $order->id ),
				static fn ( Transaction $txn ): bool => $txn->type === Transaction::REFUND && $txn->status === Transaction::SUCCEEDED
			)
		);
	}

	/** A succeeded refund of this order, or null. */
	public function refund( Order $order, int $refund_id ): ?Transaction {
		$txn = $refund_id > 0 ? $this->transactions->find( $refund_id ) : null;
		return $txn !== null && $txn->order_id === $order->id && $txn->type === Transaction::REFUND && $txn->status === Transaction::SUCCEEDED ? $txn : null;
	}

	/** @return array<int,CreditNote> by refund id */
	public function credit_notes( int $order_id ): array {
		return $this->invoices->credit_notes( $order_id );
	}

	/**
	 * The refund's credit note, made now when it has none. The order gets
	 * its invoice first, since a credit note corrects an invoice. Held
	 * under the order's lock like the invoice.
	 */
	public function create_credit_note( Order $order, Transaction $refund ): CreditNote {
		$existing = $this->invoices->credit_notes( $order->id )[ $refund->id ] ?? null;
		if ( $existing !== null ) {
			return $existing;
		}
		$this->create( $order );
		if ( ! $this->invoices->lock( $order->id ) ) {
			throw new \RuntimeException( sprintf( 'The credit note of refund %d is being created by another request.', $refund->id ) );
		}
		try {
			$existing = $this->invoices->credit_notes( $order->id )[ $refund->id ] ?? null;
			if ( $existing !== null ) {
				return $existing;
			}
			$now    = $this->clock->now();
			$number = $this->sequence->next( $this->sequence_name( $now, self::CREDIT_SEQUENCE ) );
			$note   = new CreditNote( $refund->id, $this->format( $number, $now, true ), $now->format( 'Y-m-d H:i:s' ) );
			$this->invoices->save_credit_note( $order->id, $note );
		} finally {
			$this->invoices->unlock( $order->id );
		}
		do_action( 'flexacommerce.credit_note.created', $order->id, $refund->id, $note->number, $note->date );
		return $note;
	}

	/** The number the next new invoice (or credit note) will get, formatted. */
	public function next_number( bool $credit = false ): string {
		$now = $this->clock->now();
		return $this->format( $this->sequence->current( $this->sequence_name( $now, $credit ? self::CREDIT_SEQUENCE : self::SEQUENCE ) ) + 1, $now, $credit );
	}

	/** The counter value the next invoice (or credit note) will get. */
	public function next_counter( bool $credit = false ): int {
		return $this->sequence->current( $this->sequence_name( $this->clock->now(), $credit ? self::CREDIT_SEQUENCE : self::SEQUENCE ) ) + 1;
	}

	/**
	 * Makes the next invoice (or credit note) start at `$next` (or later).
	 * The counter never goes back, so a number is never given twice.
	 */
	public function set_next_counter( int $next, bool $credit = false ): void {
		if ( $next > 1 ) {
			$this->sequence->raise_to( $this->sequence_name( $this->clock->now(), $credit ? self::CREDIT_SEQUENCE : self::SEQUENCE ), $next - 1 );
		}
	}

	/**
	 * The prefix and suffix may hold `{year}` and `{month}` of the date
	 * (site time). Credit notes have their own prefix and suffix and share
	 * the padding.
	 */
	public function format( int $number, \DateTimeImmutable $when, bool $credit = false ): string {
		$settings = $this->settings();
		$local    = $when->setTimezone( wp_timezone() );
		$tokens   = [
			'{year}'  => $local->format( 'Y' ),
			'{month}' => $local->format( 'm' ),
		];
		return strtr( $credit ? $settings['credit_prefix'] : $settings['prefix'], $tokens )
			. str_pad( (string) $number, $settings['padding'], '0', STR_PAD_LEFT )
			. strtr( $credit ? $settings['credit_suffix'] : $settings['suffix'], $tokens );
	}

	/**
	 * Whether the customer sees the invoice link on their order. `paid`
	 * reads only the order; `invoiced` asks whether the shop made one.
	 */
	public function customer_can( Order $order ): bool {
		$access = $this->settings()['customer_access'];
		if ( $access === 'never' || $order->anonymized_at !== null ) {
			return false;
		}
		if ( $access === 'invoiced' ) {
			return $this->invoices->find( $order->id ) !== null;
		}
		return $order->total > 0 && ( $order->is_paid() || in_array( $order->status, [ OrderStatus::PROCESSING, OrderStatus::COMPLETED, OrderStatus::REFUNDED ], true ) );
	}

	/**
	 * Whether the customer sees the credit note link of a refund: they can
	 * see the invoice, and with "After you make the invoice" the shop made
	 * this credit note.
	 */
	public function customer_can_credit( Order $order, int $refund_id ): bool {
		if ( ! $this->customer_can( $order ) ) {
			return false;
		}
		if ( $this->settings()['customer_access'] === 'invoiced' ) {
			return isset( $this->invoices->credit_notes( $order->id )[ $refund_id ] );
		}
		return true;
	}

	public function customer_url( Order $order, int $refund_id = 0 ): string {
		$args = [
			'fxcm_invoice' => $order->id,
			'key'          => $order->order_key,
		];
		if ( $refund_id > 0 ) {
			$args['refund'] = $refund_id;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * One PDF for the orders, an invoice or packing slip per order in the
	 * given order. Invoices are numbered first when missing.
	 *
	 * @param list<Order> $orders
	 */
	public function pdf( array $orders, string $document = InvoiceRenderer::INVOICE ): string {
		$document           = $document === InvoiceRenderer::PACKING_SLIP ? InvoiceRenderer::PACKING_SLIP : InvoiceRenderer::INVOICE;
		[ $pdf, $renderer ] = $this->document();
		foreach ( $orders as $order ) {
			$invoice = $document === InvoiceRenderer::INVOICE ? $this->create( $order ) : $this->invoices->find( $order->id );
			$vat     = $this->vat( $order );
			$this->in_locale( $order, static fn () => $renderer->render( $pdf, $order, $invoice, $document, $vat ) );
		}
		$title = count( $orders ) === 1 ? $this->filename( $orders, $document ) : get_bloginfo( 'name' );
		return $pdf->output( preg_replace( '/\.pdf$/', '', $title ) ?? $title );
	}

	/** The credit note of one refund as a PDF, numbered first when missing. */
	public function credit_note_pdf( Order $order, Transaction $refund ): string {
		$note               = $this->create_credit_note( $order, $refund );
		$invoice            = $this->invoices->find( $order->id );
		[ $pdf, $renderer ] = $this->document();
		$vat                = $this->vat( $order );
		$this->in_locale( $order, static fn () => $renderer->render_credit_note( $pdf, $order, $invoice, $note, $refund, $vat ) );
		return $pdf->output( preg_replace( '/\.pdf$/', '', $this->credit_filename( $note ) ) ?? $note->number );
	}

	public function credit_filename( CreditNote $note ): string {
		return sanitize_file_name( 'credit-note-' . $note->number . '.pdf' );
	}

	/** @return array{number:string,reverse_charge:bool} the buyer's EU VAT number from checkout (ADR-086) */
	private function vat( Order $order ): array {
		return [
			'number'         => (string) $this->orders->get_meta( $order->id, '_billing_vat_number' ),
			'reverse_charge' => $this->orders->get_meta( $order->id, '_fxcm_reverse_charge' ) === '1',
		];
	}

	/** @return array{0:PdfDocument,1:InvoiceRenderer} */
	private function document(): array {
		$settings = $this->settings();
		$pdf      = new PdfDocument( InvoiceSettings::paper( $settings['paper'] ) );
		foreach ( $this->fonts() as $key => $path ) {
			$pdf->add_font( $key, TrueTypeFont::from_file( $path ) );
		}
		return [ $pdf, new InvoiceRenderer( $settings, $this->money, $this->logo( $settings['logo_id'] ) ) ];
	}

	/** Draws the order in its own locale. */
	private function in_locale( Order $order, callable $draw ): void {
		$switched = $order->locale !== '' && $order->locale !== determine_locale() && switch_to_locale( $order->locale );
		try {
			$draw();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/** @param list<Order> $orders */
	public function filename( array $orders, string $document = InvoiceRenderer::INVOICE ): string {
		if ( count( $orders ) !== 1 ) {
			return ( $document === InvoiceRenderer::PACKING_SLIP ? 'packing-slips' : 'invoices' ) . '-' . $this->clock->now()->setTimezone( wp_timezone() )->format( 'Y-m-d' ) . '.pdf';
		}
		$order = $orders[0];
		if ( $document === InvoiceRenderer::PACKING_SLIP ) {
			return sanitize_file_name( 'packing-slip-' . $order->order_number . '.pdf' );
		}
		$invoice = $this->invoices->find( $order->id );
		return sanitize_file_name( 'invoice-' . ( $invoice !== null ? $invoice->number : $order->order_number ) . '.pdf' );
	}

	/**
	 * Orders by id in the given order, missing ones skipped.
	 *
	 * @param list<int> $ids
	 * @return list<Order>
	 */
	public function orders( array $ids ): array {
		$out = [];
		foreach ( array_slice( array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) ), 0, self::MAX_ORDERS ) as $id ) {
			$order = $this->orders->find( $id );
			if ( $order !== null ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * `flexacommerce.email.attachments` filter: the invoice of the order
	 * when the settings attach it to this email, and the credit note of the
	 * refund on the refund email when that is on (ADR-064). The files are
	 * written to the temp folder and removed after sending.
	 *
	 * @param mixed               $attachments
	 * @param array<string,mixed> $context
	 * @return list<string>|mixed
	 */
	public function attach( mixed $attachments, string $email_id, ?int $order_id, array $context = [] ): mixed {
		if ( $order_id === null || ! is_array( $attachments ) ) {
			return $attachments;
		}
		$settings = $this->settings();
		$invoice  = in_array( $email_id, $settings['attach_to'], true );
		$refund   = $settings['credit_attach'] && $email_id === 'customer_refunded_order' ? (int) ( $context['refund_id'] ?? 0 ) : 0;
		if ( ! $invoice && $refund <= 0 ) {
			return $attachments;
		}
		$order = $this->orders->find( $order_id );
		if ( $order === null ) {
			return $attachments;
		}
		if ( $invoice ) {
			$attachments = $this->attach_file( $attachments, $order_id, fn (): string => $this->pdf( [ $order ] ), fn (): string => $this->filename( [ $order ] ) );
		}
		$txn = $refund > 0 ? $this->refund( $order, $refund ) : null;
		if ( $txn !== null ) {
			$attachments = $this->attach_file(
				$attachments,
				$order_id,
				fn (): string => $this->credit_note_pdf( $order, $txn ),
				fn (): string => $this->credit_filename( $this->create_credit_note( $order, $txn ) )
			);
		}
		return $attachments;
	}

	/**
	 * @param list<string>          $attachments
	 * @param callable(): string    $bytes
	 * @param callable(): string    $name
	 * @return list<string>
	 */
	private function attach_file( array $attachments, int $order_id, callable $bytes, callable $name ): array {
		try {
			$content = $bytes();
		} catch ( \Throwable $e ) {
			do_action( 'flexacommerce.invoice.failed', $order_id, $e->getMessage() );
			return $attachments;
		}
		$dir = trailingslashit( get_temp_dir() ) . 'fxc-invoice-' . bin2hex( random_bytes( 8 ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $attachments;
		}
		$path = $dir . '/' . $name();
		if ( file_put_contents( $path, $content ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary attachment.
			return $attachments;
		}
		$this->temp_files[] = $path;
		$attachments[]      = $path;
		return $attachments;
	}

	/** `flexacommerce.email.sent` action: removes the attachments written for it. */
	public function cleanup(): void {
		foreach ( $this->temp_files as $path ) {
			wp_delete_file( $path );
			@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the empty folder made for the file.
		}
		$this->temp_files = [];
	}

	/**
	 * Regular and bold TrueType fonts. Noto Sans covers Latin, Greek and
	 * Cyrillic; a site for other scripts passes its own through
	 * `flexacommerce.invoice.fonts`.
	 *
	 * @return array{regular:string,bold:string}
	 */
	private function fonts(): array {
		$dir      = dirname( __DIR__, 2 ) . '/assets/fonts/';
		$defaults = [
			'regular' => $dir . 'NotoSans-Regular.ttf',
			'bold'    => $dir . 'NotoSans-Bold.ttf',
		];
		$fonts    = apply_filters( 'flexacommerce.invoice.fonts', $defaults );
		$out      = $defaults;
		foreach ( [ 'regular', 'bold' ] as $key ) {
			$path = is_array( $fonts ) && is_string( $fonts[ $key ] ?? null ) ? $fonts[ $key ] : '';
			if ( $path !== '' && is_readable( $path ) ) {
				$out[ $key ] = $path;
			}
		}
		return $out;
	}

	/** The logo, from a mid-size copy when WordPress made one so the PDF stays small. */
	private function logo( int $attachment_id ): ?PdfImage {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || $path === '' ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		foreach ( [ 'medium_large', 'large' ] as $size ) {
			$file = is_array( $meta ) ? ( $meta['sizes'][ $size ]['file'] ?? '' ) : '';
			if ( is_string( $file ) && $file !== '' && is_readable( dirname( $path ) . '/' . $file ) ) {
				$path = dirname( $path ) . '/' . $file;
				break;
			}
		}
		return is_readable( $path ) ? PdfImage::from_file( $path ) : null;
	}

	/** One counter, or one per year (site time) with the yearly reset. */
	private function sequence_name( \DateTimeImmutable $when, string $base = self::SEQUENCE ): string {
		return $this->settings()['reset'] === 'yearly'
			? $base . '_' . $when->setTimezone( wp_timezone() )->format( 'Y' )
			: $base;
	}
}
