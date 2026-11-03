<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Design;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Orders\OrderItemView;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Notifications\Mailer;
use Flexa\Commerce\Notifications\Previewable;
use Flexa\Commerce\Orders\OrderQuery;
use Flexa\Commerce\Orders\OrderRepository;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows an email in the designer (ADR-085) as it would go out for a recent
 * order, or for a made-up one when the store has none yet. Nothing is
 * sent and nothing is recorded.
 *
 * @phpstan-import-type Style from MailStyle
 * @phpstan-import-type Design from DesignTree
 */
final class Preview {
	/** How many recent orders the designer offers to preview with. */
	public const RECENT = 10;

	public function __construct(
		private readonly Designer $designer,
		private readonly Mailer $mailer,
		private readonly EmailSettings $settings,
		private readonly OrderRepository $orders,
	) {}

	/**
	 * The email as HTML, with its subject. A null design shows the default layout.
	 *
	 * @param Design|null $design
	 * @param Style       $style
	 * @return array{subject:string,html:string}
	 */
	public function render( Email $email, ?array $design, array $style, int $order_id, bool $marks = true ): array {
		$view    = $order_id > 0 ? $this->orders->find( $order_id )?->view() : null;
		$view  ??= self::sample();
		$id      = $email->id();
		$message = $email instanceof Previewable ? $email->preview( $view ) : new EmailMessage( $email->title(), $email->title(), '', $view );
		$titles  = $this->mailer->titles( $id, $message, $this->settings->get( $id ) );
		$mail    = [
			'email_id'    => $id,
			'to_customer' => $email->to_customer(),
			'heading'     => $titles['heading'],
			'content'     => (string) apply_filters( 'flexacommerce.email.content_html', $message->content_html, $id, $view ),
			'order'       => $view,
		];
		return [
			'subject' => $titles['subject'],
			'html'    => $design !== null ? $this->designer->render( $design, $style, $mail, $marks ) : $this->designer->layout( $style, $mail ),
		];
	}

	/** @return list<array{id:int,label:string}> the latest orders, newest first */
	public function recent(): array {
		$out = [];
		foreach ( $this->orders->search( new OrderQuery( per_page: self::RECENT ) )['orders'] as $order ) {
			$view  = $order->view();
			$out[] = [
				'id'    => $view->id,
				/* translators: 1: order number, 2: customer name or email. */
				'label' => trim( sprintf( __( '#%1$s %2$s', 'flexacommerce' ), $view->number, $view->customer_name !== '' ? $view->customer_name : $view->customer_email ) ),
			];
		}
		return $out;
	}

	/** An order with two products, shipping, tax and both addresses. */
	public static function sample(): OrderView {
		$currency = Settings::string( 'currency' );
		$currency = preg_match( '/^[A-Z]{3}$/', $currency ) === 1 ? $currency : 'USD';
		$m        = static fn ( int $amount ): Money => new Money( $amount, $currency );
		$zero     = $m( 0 );
		$line     = static fn ( int $id, string $type, string $name, int $qty, int $unit ): OrderItemView => new OrderItemView( $id, $type, null, null, '', $name, null, [], $qty, $m( $unit ), $m( $unit * $qty ), $zero, $m( $unit * $qty ), 0, $zero );
		$address  = static fn ( string $type ): AddressView => new AddressView( $type, 'Alex', 'Rivera', '', '123 Market Street', 'Apt 4', 'San Francisco', 'CA', '94103', 'US', $type === 'billing' ? 'alex@example.com' : '', $type === 'billing' ? '555-0100' : '' );
		return new OrderView(
			0,
			'1001',
			'processing',
			'paid',
			false,
			$currency,
			$m( 6500 ),
			$zero,
			$m( 500 ),
			$zero,
			$m( 520 ),
			$m( 7520 ),
			$m( 7520 ),
			$zero,
			null,
			'alex@example.com',
			'Alex Rivera',
			'',
			'card',
			__( 'Credit card', 'flexacommerce' ),
			'checkout',
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
			[
				$line( 1, 'product', __( 'Linen shirt', 'flexacommerce' ), 2, 2500 ),
				$line( 2, 'product', __( 'Canvas tote', 'flexacommerce' ), 1, 1500 ),
				$line( 3, 'shipping', __( 'Flat rate', 'flexacommerce' ), 1, 500 ),
				$line( 4, 'tax', __( 'Tax', 'flexacommerce' ), 1, 520 ),
			],
			$address( 'billing' ),
			$address( 'shipping' ),
		);
	}
}
