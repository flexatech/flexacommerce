<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\OrderData;

defined( 'ABSPATH' ) || exit;

/**
 * A core order email (doc 02 §7.9). Subclasses say which hook calls send
 * and give the default subject and heading; the body is a template named
 * after the id. One write can fire two triggers of the same email (a new
 * order created as processing, then the same order seen by status_changed),
 * so each email sends once per order and request.
 */
abstract class OrderEmail implements Email {
	public const CREATED      = 'flexacommerce.order.created';
	public const STATUS       = 'flexacommerce.order.status_changed';
	public const REFUNDED     = 'flexacommerce.order.refunded';
	public const NEEDS_REVIEW = 'flexacommerce.order.needs_attention';

	/** @var array<string,true> */
	private array $sent = [];

	public function __construct(
		protected readonly EmailTemplates $templates,
		protected readonly OrderData $data,
	) {}

	/**
	 * The order this hook call is about, when it should send.
	 *
	 * @param list<mixed> $args
	 */
	abstract protected function applies( string $hook, array $args ): ?OrderView;

	abstract protected function subject( OrderView $order ): string;

	abstract protected function heading( OrderView $order ): string;

	/** Template under templates/emails/. */
	protected function template(): string {
		return str_replace( '_', '-', $this->id() );
	}

	/**
	 * Extra template values for this call.
	 *
	 * @param list<mixed> $args
	 * @return array<string,mixed>
	 */
	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $args, $order );
		return [];
	}

	/**
	 * Tells two sends for the same order apart (a second refund).
	 *
	 * @param list<mixed> $args
	 */
	protected function key( string $hook, array $args ): string {
		unset( $hook, $args );
		return '';
	}

	public function render( string $hook, array $args ): ?EmailMessage {
		$order = $this->applies( $hook, $args );
		if ( $order === null ) {
			return null;
		}
		$key = $order->id . ':' . $order->created_at . ':' . $this->key( $hook, $args );
		if ( isset( $this->sent[ $key ] ) ) {
			return null;
		}
		$this->sent[ $key ] = true;

		$content    = $this->templates->render(
			$this->template(),
			[
				'email_id'    => $this->id(),
				'order'       => $order,
				'data'        => $this->data,
				'to_customer' => $this->to_customer(),
				'templates'   => $this->templates,
			] + $this->extra( $hook, $args, $order )
		);
		$recipients = [];
		if ( $this->to_customer() ) {
			$email      = $order->customer_email !== '' ? $order->customer_email : (string) $order->billing?->email;
			$recipients = $email !== '' ? [ $email ] : [];
		}
		return new EmailMessage( $this->subject( $order ), $this->heading( $order ), $content, $order, $recipients );
	}

	/**
	 * Arguments of status_changed: view, from, to, actor, context.
	 *
	 * @param list<mixed> $args
	 * @return array{0:?OrderView,1:string,2:string,3:string,4:array<string,mixed>}
	 */
	protected static function status_args( array $args ): array {
		$context = $args[4] ?? [];
		return [
			self::view( $args ),
			is_string( $args[1] ?? null ) ? $args[1] : '',
			is_string( $args[2] ?? null ) ? $args[2] : '',
			is_string( $args[3] ?? null ) ? $args[3] : '',
			is_array( $context ) ? $context : [],
		];
	}

	/** @param list<mixed> $args */
	protected static function view( array $args ): ?OrderView {
		return ( $args[0] ?? null ) instanceof OrderView ? $args[0] : null;
	}
}
