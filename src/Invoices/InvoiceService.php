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
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * PDF invoices and packing slips (ADR-060). An order gets its invoice
 * number the first time an invoice is made for it (by the admin, an email
 * that carries it, or the customer's link) and keeps it. Numbers come from
 * fxc_sequences, so they are unique and increasing; a yearly reset uses a
 * counter per year.
 *
 * @phpstan-import-type Values from InvoiceSettings
 */
final class InvoiceService {
	public const SEQUENCE = 'invoice_number';

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

	/** The number the next new invoice will get, formatted. */
	public function next_number(): string {
		$now = $this->clock->now();
		return $this->format( $this->sequence->current( $this->sequence_name( $now ) ) + 1, $now );
	}

	/** The counter value the next invoice will get. */
	public function next_counter(): int {
		return $this->sequence->current( $this->sequence_name( $this->clock->now() ) ) + 1;
	}

	/**
	 * Makes the next invoice start at `$next` (or later). The counter never
	 * goes back, so a number is never given twice.
	 */
	public function set_next_counter( int $next ): void {
		if ( $next > 1 ) {
			$this->sequence->raise_to( $this->sequence_name( $this->clock->now() ), $next - 1 );
		}
	}

	/** The prefix and suffix may hold `{year}` and `{month}` of the invoice date (site time). */
	public function format( int $number, \DateTimeImmutable $when ): string {
		$settings = $this->settings();
		$local    = $when->setTimezone( wp_timezone() );
		$tokens   = [
			'{year}'  => $local->format( 'Y' ),
			'{month}' => $local->format( 'm' ),
		];
		return strtr( $settings['prefix'], $tokens )
			. str_pad( (string) $number, $settings['padding'], '0', STR_PAD_LEFT )
			. strtr( $settings['suffix'], $tokens );
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

	public function customer_url( Order $order ): string {
		return add_query_arg(
			[
				'fxc_invoice' => $order->id,
				'key'         => $order->order_key,
			],
			home_url( '/' )
		);
	}

	/**
	 * One PDF for the orders, an invoice or packing slip per order in the
	 * given order. Invoices are numbered first when missing.
	 *
	 * @param list<Order> $orders
	 */
	public function pdf( array $orders, string $document = InvoiceRenderer::INVOICE ): string {
		$document = $document === InvoiceRenderer::PACKING_SLIP ? InvoiceRenderer::PACKING_SLIP : InvoiceRenderer::INVOICE;
		$settings = $this->settings();
		$pdf      = new PdfDocument( InvoiceSettings::paper( $settings['paper'] ) );
		foreach ( $this->fonts() as $key => $path ) {
			$pdf->add_font( $key, TrueTypeFont::from_file( $path ) );
		}
		$renderer = new InvoiceRenderer( $settings, $this->money, $this->logo( $settings['logo_id'] ) );
		foreach ( $orders as $order ) {
			$invoice  = $document === InvoiceRenderer::INVOICE ? $this->create( $order ) : $this->invoices->find( $order->id );
			$switched = $order->locale !== '' && $order->locale !== determine_locale() && switch_to_locale( $order->locale );
			try {
				$renderer->render( $pdf, $order, $invoice, $document );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}
		$title = count( $orders ) === 1 ? $this->filename( $orders, $document ) : get_bloginfo( 'name' );
		return $pdf->output( preg_replace( '/\.pdf$/', '', $title ) ?? $title );
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
	 * when the settings attach it to this email. The file is written to the
	 * temp folder and removed after sending.
	 *
	 * @param mixed $attachments
	 * @return list<string>|mixed
	 */
	public function attach( mixed $attachments, string $email_id, ?int $order_id ): mixed {
		if ( $order_id === null || ! is_array( $attachments ) || ! in_array( $email_id, $this->settings()['attach_to'], true ) ) {
			return $attachments;
		}
		$order = $this->orders->find( $order_id );
		if ( $order === null ) {
			return $attachments;
		}
		try {
			$bytes = $this->pdf( [ $order ] );
		} catch ( \Throwable $e ) {
			do_action( 'flexacommerce.invoice.failed', $order_id, $e->getMessage() );
			return $attachments;
		}
		$dir = trailingslashit( get_temp_dir() ) . 'fxc-invoice-' . bin2hex( random_bytes( 8 ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $attachments;
		}
		$path = $dir . '/' . $this->filename( [ $order ] );
		if ( file_put_contents( $path, $bytes ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary attachment.
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
	private function sequence_name( \DateTimeImmutable $when ): string {
		return $this->settings()['reset'] === 'yearly'
			? self::SEQUENCE . '_' . $when->setTimezone( wp_timezone() )->format( 'Y' )
			: self::SEQUENCE;
	}
}
