<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Orders\OrderServices;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/** Notifications part of the composition root, built with the order module. */
final class NotificationServices {
	private ?Notifier $notifier        = null;
	private ?EmailSettings $settings   = null;
	private ?EmailTemplates $templates = null;
	private ?OrderData $data           = null;
	private ?Mailer $mailer            = null;

	/** @var list<Email>|null */
	private ?array $emails = null;

	/**
	 * @param \Closure():CheckoutServices $checkout
	 * @param ?\Closure(int):list<array{name:string,product:string,url:string,remaining:?int,expires:?string}> $downloads the download links of an order (ADR-058)
	 */
	public function __construct(
		private readonly Services $services,
		private readonly OrderServices $orders,
		private readonly \Closure $checkout,
		private readonly ?\Closure $downloads = null,
	) {}

	public function notifier(): Notifier {
		return $this->notifier ??= new Notifier(
			fn (): array => $this->emails(),
			$this->mailer(),
			$this->settings(),
			$this->data(),
			$this->services->logger( 'emails' ),
		);
	}

	public function mailer(): Mailer {
		return $this->mailer ??= new Mailer( $this->templates(), $this->orders->history(), $this->services->clock(), $this->services->logger( 'emails' ) );
	}

	public function settings(): EmailSettings {
		return $this->settings ??= new EmailSettings();
	}

	public function templates(): EmailTemplates {
		return $this->templates ??= new EmailTemplates();
	}

	/**
	 * The core emails and the ones extensions add. A later email with the
	 * same id replaces an earlier one, so an extension can replace a core email.
	 *
	 * @return list<Email>
	 */
	public function emails(): array {
		if ( $this->emails !== null ) {
			return $this->emails;
		}
		$templates = $this->templates();
		$data      = $this->data();
		$core      = [
			new Emails\NewOrder( $templates, $data ),
			new Emails\CustomerOnHoldOrder( $templates, $data ),
			new Emails\CustomerProcessingOrder( $templates, $data ),
			new Emails\CustomerCompletedOrder( $templates, $data ),
			new Emails\CancelledOrder( $templates, $data ),
			new Emails\CustomerCancelledOrder( $templates, $data ),
			new Emails\CustomerRefundedOrder( $templates, $data ),
			new Emails\FailedOrder( $templates, $data ),
			new Emails\NeedsAttention( $templates, $data ),
		];
		$all       = apply_filters( 'flexacommerce.emails.register', $core );
		$byid      = [];
		foreach ( is_array( $all ) ? $all : $core as $email ) {
			if ( $email instanceof Email && preg_match( '/^[a-z0-9_]{1,64}$/', $email->id() ) === 1 ) {
				$byid[ $email->id() ] = $email;
			}
		}
		$this->emails = array_values( $byid );
		return $this->emails;
	}

	public function email( string $id ): ?Email {
		foreach ( $this->emails() as $email ) {
			if ( $email->id() === $id ) {
				return $email;
			}
		}
		return null;
	}

	public function data(): OrderData {
		return $this->data ??= new OrderData( $this->orders->orders(), $this->services->money(), $this->checkout, $this->downloads );
	}
}
