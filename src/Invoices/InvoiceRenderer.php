<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Invoices\Pdf\PdfDocument;
use Flexa\Commerce\Invoices\Pdf\PdfImage;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lays out an invoice or a packing slip of one order on new pages of a
 * PdfDocument (ADR-060): the shop and the document details, both
 * addresses, the items (the header row repeats on each page), the totals
 * on an invoice, the customer's note, the footer and "Page X of Y".
 *
 * @phpstan-import-type Values from InvoiceSettings
 */
final class InvoiceRenderer {
	public const INVOICE = 'invoice';

	public const PACKING_SLIP = 'packing_slip';

	private const MARGIN = 48.0;

	private const TEXT = [ 0.13, 0.13, 0.13 ];

	private const MUTED = [ 0.4, 0.4, 0.4 ];

	private const RULE = [ 0.82, 0.82, 0.82 ];

	private const FILL = [ 0.95, 0.95, 0.95 ];

	private PdfDocument $pdf;

	private float $y = 0.0;

	private float $bottom = 0.0;

	private string $document = self::INVOICE;

	/** @var list<array{label:string,x:float,width:float,align:string}> */
	private array $columns = [];

	/** @param Values $settings */
	public function __construct(
		private readonly array $settings,
		private readonly MoneyFactory $money,
		private readonly ?PdfImage $logo = null,
	) {}

	public function render( PdfDocument $pdf, Order $order, ?Invoice $invoice, string $document ): void {
		$this->pdf      = $pdf;
		$this->document = $document;
		$first          = $pdf->add_page();
		$this->y        = self::MARGIN;
		$this->bottom   = $pdf->height - self::MARGIN - $this->footer_height();

		$this->header( $order, $invoice );
		$this->addresses( $order );
		$this->items( $order );
		if ( $document === self::INVOICE ) {
			$this->totals( $order );
		}
		if ( $order->customer_note !== '' ) {
			$this->note( $order->customer_note );
		}
		$this->footers( $first );
	}

	private function header( Order $order, ?Invoice $invoice ): void {
		$pdf   = $this->pdf;
		$left  = self::MARGIN;
		$right = $pdf->width - self::MARGIN;
		$title = $this->document === self::INVOICE ? __( 'Invoice', 'flexacommerce' ) : __( 'Packing slip', 'flexacommerce' );
		$pdf->text( $right, $this->y + 20, $title, 'bold', 20, self::TEXT, 'right' );

		$y = $this->y;
		if ( $this->logo !== null ) {
			$scale = min( 170 / $this->logo->width, 64 / $this->logo->height, 1.0 );
			$h     = $this->logo->height * $scale;
			$pdf->image( $this->logo, $left, $y, $this->logo->width * $scale, $h );
			$y += $h + 14;
		} else {
			$y += 20;
		}
		$name = $this->settings['shop_name'] !== '' ? $this->settings['shop_name'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( $this->logo === null ) {
			$pdf->text( $left, $y - 4, $name, 'bold', 15, self::TEXT );
			$y += 12;
		} else {
			$pdf->text( $left, $y, $name, 'bold', 10, self::TEXT );
			$y += 13;
		}
		foreach ( array_merge( $this->shop_address(), self::lines( $this->settings['shop_details'] ) ) as $line ) {
			foreach ( $pdf->wrap( $line, 'regular', 9, 250 ) as $part ) {
				$pdf->text( $left, $y, $part, 'regular', 9, self::MUTED );
				$y += 12;
			}
		}

		$details = [];
		if ( $this->document === self::INVOICE && $invoice !== null ) {
			$details[ __( 'Invoice number', 'flexacommerce' ) ] = $invoice->number;
			$details[ __( 'Invoice date', 'flexacommerce' ) ]   = self::date( $invoice->date );
		}
		$details[ __( 'Order number', 'flexacommerce' ) ] = $order->order_number;
		$details[ __( 'Order date', 'flexacommerce' ) ]   = self::date( $order->created_at );
		if ( $this->document === self::INVOICE && $order->payment_method_title !== '' ) {
			$details[ __( 'Payment method', 'flexacommerce' ) ] = $order->payment_method_title;
		}
		$shipping = implode( ', ', array_map( static fn ( OrderItem $item ): string => $item->name, $order->items_of_type( OrderItem::SHIPPING ) ) );
		if ( $this->document === self::PACKING_SLIP && $shipping !== '' ) {
			$details[ __( 'Shipping method', 'flexacommerce' ) ] = $shipping;
		}
		$label_x = $right - 210;
		$dy      = $this->y + 46;
		foreach ( $details as $label => $value ) {
			$pdf->text( $label_x, $dy, $label, 'regular', 9, self::MUTED );
			$lines = $pdf->wrap( $value, 'bold', 9, 110 );
			foreach ( $lines as $line ) {
				$pdf->text( $right, $dy, $line, 'bold', 9, self::TEXT, 'right' );
				$dy += 12;
			}
			$dy += 2;
		}
		$this->y = max( $y, $dy ) + 18;
	}

	private function addresses( Order $order ): void {
		$blocks = [
			[ __( 'Bill to', 'flexacommerce' ), $order->billing, true ],
			[ __( 'Ship to', 'flexacommerce' ), $order->shipping, false ],
		];
		if ( $this->document === self::PACKING_SLIP ) {
			$blocks = array_reverse( $blocks );
		}
		$x      = self::MARGIN;
		$width  = ( $this->pdf->width - 2 * self::MARGIN ) / 2 - 12;
		$bottom = $this->y;
		foreach ( $blocks as [ $title, $address, $contact ] ) {
			$lines = $address !== null ? $this->address_lines( $address, $contact ) : [];
			if ( $lines === [] && ! $contact ) {
				continue;
			}
			if ( $lines === [] ) {
				$lines = array_values( array_filter( [ $order->customer_name, $order->customer_email ] ) );
			}
			$y = $this->y;
			$this->pdf->text( $x, $y, mb_strtoupper( $title ), 'bold', 8, self::MUTED );
			$y += 14;
			foreach ( $lines as $line ) {
				foreach ( $this->pdf->wrap( $line, 'regular', 9.5, $width ) as $part ) {
					$this->pdf->text( $x, $y, $part, 'regular', 9.5, self::TEXT );
					$y += 12.5;
				}
			}
			$bottom = max( $bottom, $y );
			$x     += $width + 24;
		}
		$this->y = $bottom + 18;
	}

	private function items( Order $order ): void {
		$right         = $this->pdf->width - self::MARGIN;
		$left          = self::MARGIN;
		$prices        = $this->document === self::INVOICE;
		$this->columns = $prices
			? [
				[
					'label' => __( 'Product', 'flexacommerce' ),
					'x'     => $left + 6,
					'width' => $right - $left - 230,
					'align' => 'left',
				],
				[
					'label' => __( 'Qty', 'flexacommerce' ),
					'x'     => $right - 170,
					'width' => 40,
					'align' => 'right',
				],
				[
					'label' => __( 'Price', 'flexacommerce' ),
					'x'     => $right - 86,
					'width' => 76,
					'align' => 'right',
				],
				[
					'label' => __( 'Total', 'flexacommerce' ),
					'x'     => $right - 6,
					'width' => 76,
					'align' => 'right',
				],
			]
			: [
				[
					'label' => __( 'Product', 'flexacommerce' ),
					'x'     => $left + 6,
					'width' => $right - $left - 90,
					'align' => 'left',
				],
				[
					'label' => __( 'Qty', 'flexacommerce' ),
					'x'     => $right - 6,
					'width' => 60,
					'align' => 'right',
				],
			];
		$this->table_header();

		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$lines = [];
			foreach ( $this->pdf->wrap( $item->name, 'regular', 9.5, $this->columns[0]['width'] ) as $line ) {
				$lines[] = [ $line, 'regular', 9.5, self::TEXT ];
			}
			$extra = [];
			foreach ( $item->attributes as $attribute ) {
				$extra[] = $attribute['label'] . ': ' . $attribute['value'];
			}
			if ( $this->settings['show_sku'] && (string) $item->sku !== '' ) {
				/* translators: %s: product SKU */
				$extra[] = sprintf( __( 'SKU: %s', 'flexacommerce' ), (string) $item->sku );
			}
			foreach ( $extra as $text ) {
				foreach ( $this->pdf->wrap( $text, 'regular', 8, $this->columns[0]['width'] ) as $line ) {
					$lines[] = [ $line, 'regular', 8, self::MUTED ];
				}
			}
			$height = 8 + array_sum( array_map( static fn ( array $line ): float => $line[2] + 3.5, $lines ) );
			$this->room( $height, true );

			$y = $this->y + 8;
			foreach ( $lines as $i => [ $text, $font, $size, $color ] ) {
				$y += $size + ( $i === 0 ? 0 : 3.5 );
				$this->pdf->text( $this->columns[0]['x'], $y, $text, $font, $size, $color );
			}
			$first = $this->y + 8 + 9.5;
			$this->pdf->text( $this->columns[1]['x'], $first, number_format_i18n( $item->quantity ), 'regular', 9.5, self::TEXT, 'right' );
			if ( $prices ) {
				$this->pdf->text( $this->columns[2]['x'], $first, $this->money( $order, $item->unit_price ), 'regular', 9.5, self::TEXT, 'right' );
				$this->pdf->text( $this->columns[3]['x'], $first, $this->money( $order, $item->subtotal ), 'regular', 9.5, self::TEXT, 'right' );
			}
			$this->y += $height + 4;
			$this->pdf->line( $left, $this->y, $right, $this->y, 0.5, self::RULE );
		}
		$this->y += 10;
	}

	private function totals( Order $order ): void {
		$rows = [ [ __( 'Subtotal', 'flexacommerce' ), $this->money( $order, $order->subtotal ), false ] ];
		foreach ( $order->items_of_type( OrderItem::DISCOUNT ) as $item ) {
			$label = ! empty( $item->meta['automatic'] )
				/* translators: %s: the name of an automatic discount */
				? sprintf( __( 'Discount: %s', 'flexacommerce' ), $item->name )
				/* translators: %s: coupon code */
				: sprintf( __( 'Coupon: %s', 'flexacommerce' ), $item->name );
			$rows[] = [ $label, $item->total === 0 && ! empty( $item->meta['free_shipping'] ) ? __( 'Free shipping', 'flexacommerce' ) : $this->money( $order, -$item->total ), false ];
		}
		$shipping = $order->items_of_type( OrderItem::SHIPPING );
		if ( $shipping !== [] ) {
			$rows[] = [ __( 'Shipping', 'flexacommerce' ) . ' (' . implode( ', ', array_map( static fn ( OrderItem $item ): string => $item->name, $shipping ) ) . ')', $this->money( $order, $order->shipping_total ), false ];
		}
		foreach ( $order->items_of_type( OrderItem::FEE ) as $item ) {
			$rows[] = [ $item->name !== '' ? $item->name : __( 'Fee', 'flexacommerce' ), $this->money( $order, $item->total ), false ];
		}
		$taxes = $order->items_of_type( OrderItem::TAX );
		foreach ( $taxes as $item ) {
			$rows[] = [ $item->name !== '' ? $item->name : __( 'Tax', 'flexacommerce' ), $this->money( $order, $item->total ), false ];
		}
		if ( $taxes === [] && $order->tax_total > 0 ) {
			$rows[] = [ __( 'Tax', 'flexacommerce' ), $this->money( $order, $order->tax_total ), false ];
		}
		$rows[] = [ __( 'Total', 'flexacommerce' ), $this->money( $order, $order->total ), true ];
		if ( $order->amount_refunded > 0 ) {
			$rows[] = [ __( 'Refunded', 'flexacommerce' ), $this->money( $order, -$order->amount_refunded ), false ];
		}
		if ( $order->balance_due() > 0 && $order->amount_paid > 0 ) {
			$rows[] = [ __( 'Amount due', 'flexacommerce' ), $this->money( $order, $order->balance_due() ), true ];
		}

		$right = $this->pdf->width - self::MARGIN - 6;
		$label = $right - 100;
		foreach ( $rows as [ $text, $amount, $strong ] ) {
			$lines = $this->pdf->wrap( $text, $strong ? 'bold' : 'regular', 9.5, 170 );
			$this->room( count( $lines ) * 13 + 6, false );
			if ( $strong ) {
				$this->pdf->line( $label - 170, $this->y, $right + 6, $this->y, 0.75, self::TEXT );
				$this->y += 4;
			}
			$y = $this->y + 10;
			foreach ( $lines as $i => $line ) {
				$this->pdf->text( $label, $y + $i * 13, $line, $strong ? 'bold' : 'regular', 9.5, $strong ? self::TEXT : self::MUTED, 'right' );
			}
			$this->pdf->text( $right, $y, $amount, $strong ? 'bold' : 'regular', 9.5, self::TEXT, 'right' );
			$this->y += count( $lines ) * 13 + 4;
		}
		$this->y += 12;
	}

	private function note( string $note ): void {
		$width = $this->pdf->width - 2 * self::MARGIN;
		$lines = $this->pdf->wrap( $note, 'regular', 9, $width );
		$this->room( 30, false );
		$this->pdf->text( self::MARGIN, $this->y + 10, mb_strtoupper( __( 'Note', 'flexacommerce' ) ), 'bold', 8, self::MUTED );
		$this->y += 24;
		foreach ( $lines as $line ) {
			$this->room( 12, false );
			$this->pdf->text( self::MARGIN, $this->y, $line, 'regular', 9, self::TEXT );
			$this->y += 12;
		}
	}

	/** The footer text and "Page X of Y" on each page of this document. */
	private function footers( int $first ): void {
		$last  = $this->pdf->page_count() - 1;
		$lines = $this->footer_lines();
		for ( $page = $first; $page <= $last; $page++ ) {
			$this->pdf->page( $page );
			$y = $this->pdf->height - self::MARGIN + 6 - 11 * count( $lines );
			foreach ( $lines as $line ) {
				$this->pdf->text( $this->pdf->width / 2, $y, $line, 'regular', 8, self::MUTED, 'center' );
				$y += 11;
			}
			if ( $last > $first ) {
				/* translators: 1: page number, 2: number of pages */
				$text = sprintf( __( 'Page %1$d of %2$d', 'flexacommerce' ), $page - $first + 1, $last - $first + 1 );
				$this->pdf->text( $this->pdf->width - self::MARGIN, $this->pdf->height - self::MARGIN / 2, $text, 'regular', 8, self::MUTED, 'right' );
			}
		}
		$this->pdf->page( $last );
	}

	/** @return list<string> */
	private function footer_lines(): array {
		$lines = [];
		foreach ( self::lines( $this->settings['footer'] ) as $line ) {
			array_push( $lines, ...$this->pdf->wrap( $line, 'regular', 8, $this->pdf->width - 2 * self::MARGIN ) );
		}
		return $lines;
	}

	private function footer_height(): float {
		$count = count( $this->footer_lines() );
		return $count > 0 ? 11 * $count + 12 : 0;
	}

	/** Starts a new page when `$height` does not fit; in the items table the header row repeats. */
	private function room( float $height, bool $table ): void {
		if ( $this->y + $height <= $this->bottom ) {
			return;
		}
		$this->pdf->add_page();
		$this->y = self::MARGIN;
		if ( $table ) {
			$this->table_header();
		}
	}

	private function table_header(): void {
		$left  = self::MARGIN;
		$width = $this->pdf->width - 2 * self::MARGIN;
		$this->pdf->rect( $left, $this->y, $width, 20, self::FILL );
		foreach ( $this->columns as $column ) {
			$this->pdf->text( $column['x'], $this->y + 13.5, mb_strtoupper( $column['label'] ), 'bold', 7.5, self::MUTED, $column['align'] );
		}
		$this->y += 20;
	}

	/** @return list<string> */
	private function address_lines( AddressView $address, bool $contact ): array {
		$states = Countries::states( $address->country );
		$region = trim( implode( ' ', array_filter( [ $address->city, $states[ $address->state ] ?? $address->state, $address->postcode ] ) ) );
		$lines  = [
			$address->name(),
			$address->company,
			$address->address_1,
			$address->address_2,
			$region,
			$address->country !== '' ? Countries::name( $address->country ) : '',
		];
		if ( $contact ) {
			$lines[] = $address->email;
			$lines[] = $address->phone;
		}
		return array_values( array_filter( array_map( 'trim', $lines ), static fn ( string $line ): bool => $line !== '' ) );
	}

	/**
	 * The shop address from the invoice settings, or from the store
	 * location in the general settings.
	 *
	 * @return list<string>
	 */
	private function shop_address(): array {
		if ( $this->settings['shop_address'] !== '' ) {
			return self::lines( $this->settings['shop_address'] );
		}
		$country = Settings::string( 'store_country' );
		$states  = Countries::states( $country );
		$state   = Settings::string( 'store_state' );
		$region  = trim(
			implode(
				' ',
				array_filter(
					[
						Settings::string( 'store_city' ),
						$states[ $state ] ?? $state,
						Settings::string( 'store_postcode' ),
					]
				)
			)
		);
		return array_values( array_filter( [ $region, $country !== '' ? Countries::name( $country ) : '' ] ) );
	}

	/** @return list<string> */
	private static function lines( string $text ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $text ) ?: [] ), static fn ( string $line ): bool => $line !== '' ) );
	}

	private static function date( string $utc ): string {
		return $utc === '' ? '' : (string) mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $utc ) );
	}

	private function money( Order $order, int $amount ): string {
		return html_entity_decode( $this->money->format( $order->money( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
