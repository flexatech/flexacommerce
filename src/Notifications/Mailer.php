<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\HistoryRepository;
use Flexa\Commerce\Maintenance\Cron;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settles recipients, subject and heading (settings, then the per-email
 * filters), wraps the body in the layout and sends it with wp_mail().
 * Every attempt for an order writes an `email` history row, sent or not.
 *
 * With the `defer_emails` setting the message is still built on the
 * request, so it shows the order as it was, but wp_mail() runs in a
 * single cron event per email (doc 02 §6.6). The built message waits in a
 * transient; when it cannot be stored or scheduled it is sent at once.
 *
 * @phpstan-type Outgoing array{email:string,title:string,order_id:?int,recipients:list<string>,subject:string,html:string}
 */
final class Mailer {
	private const QUEUE_PREFIX = 'fxc_mail_';

	public function __construct(
		private readonly EmailTemplates $templates,
		private readonly HistoryRepository $history,
		private readonly Clock $clock,
		private readonly Logger $log,
	) {}

	/**
	 * Sends now, or queues when deferral is on. True when sent or queued.
	 *
	 * @param array{enabled:bool,recipients:string,subject:string,heading:string} $settings
	 */
	public function send( Email $email, EmailMessage $message, array $settings ): bool {
		$outgoing = $this->prepare( $email, $message, $settings );
		if ( $outgoing === null ) {
			return false;
		}
		if ( Settings::bool( 'defer_emails' ) && $this->queue( $outgoing ) ) {
			return true;
		}
		return $this->deliver( $outgoing );
	}

	/** Cron handler: sends one queued email. A missing entry was already sent or expired. */
	public function send_deferred( string $queue_id ): void {
		if ( preg_match( '/^[a-f0-9]{32}$/', $queue_id ) !== 1 ) {
			return;
		}
		$outgoing = get_transient( self::QUEUE_PREFIX . $queue_id );
		delete_transient( self::QUEUE_PREFIX . $queue_id );
		if ( ! is_array( $outgoing ) || ! isset( $outgoing['email'], $outgoing['html'] ) ) {
			return;
		}
		/** @var Outgoing $outgoing */
		$this->deliver( $outgoing );
	}

	/** @param Outgoing $outgoing */
	private function queue( array $outgoing ): bool {
		$queue_id = bin2hex( random_bytes( 16 ) );
		if ( ! set_transient( self::QUEUE_PREFIX . $queue_id, $outgoing, DAY_IN_SECONDS ) ) {
			return false;
		}
		if ( wp_schedule_single_event( time(), Cron::SEND_EMAIL, [ $queue_id ] ) !== true ) {
			delete_transient( self::QUEUE_PREFIX . $queue_id );
			return false;
		}
		return true;
	}

	/**
	 * @param array{enabled:bool,recipients:string,subject:string,heading:string} $settings
	 * @return Outgoing|null null when nobody would receive it
	 */
	private function prepare( Email $email, EmailMessage $message, array $settings ): ?array {
		$id    = $email->id();
		$order = $message->order;

		$recipients = $message->recipients;
		if ( ! $email->to_customer() ) {
			$configured = EmailSettings::addresses( $settings['recipients'] );
			$recipients = $configured !== [] ? $configured : ( $recipients !== [] ? $recipients : [ (string) get_option( 'admin_email' ) ] );
		}
		$filtered   = apply_filters( 'flexacommerce.email.recipients', $recipients, $id, $order );
		$recipients = EmailSettings::addresses( implode( ',', array_filter( is_array( $filtered ) ? $filtered : [], 'is_string' ) ) );
		if ( $recipients === [] ) {
			return null;
		}

		$subject = $this->text( 'subject', $settings['subject'] !== '' ? $settings['subject'] : $message->subject, $id, $order );
		$heading = $this->text( 'heading', $settings['heading'] !== '' ? $settings['heading'] : $message->heading, $id, $order );
		$content = (string) apply_filters( 'flexacommerce.email.content_html', $message->content_html, $id, $order );
		$html    = $this->templates->render(
			'layout',
			[
				'email_id'   => $id,
				'heading'    => $heading,
				'content'    => $content,
				'site_title' => self::site_title(),
				'site_url'   => home_url( '/' ),
			]
		);

		return [
			'email'      => $id,
			'title'      => $email->title(),
			'order_id'   => $order?->id,
			'recipients' => $recipients,
			'subject'    => $subject,
			'html'       => $html,
		];
	}

	/** @param Outgoing $outgoing */
	private function deliver( array $outgoing ): bool {
		$id         = $outgoing['email'];
		$recipients = $outgoing['recipients'];
		$error      = '';
		$listen     = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $listen );
		try {
			$sent = wp_mail( $recipients, $outgoing['subject'], $outgoing['html'], [ 'Content-Type: text/html; charset=UTF-8' ] );
		} finally {
			remove_action( 'wp_mail_failed', $listen );
		}

		if ( ! $sent ) {
			$this->log->warning(
				'Email {email} was not sent: {error}',
				[
					'email'    => $id,
					'order_id' => $outgoing['order_id'],
					'error'    => $error,
				]
			);
		}
		if ( $outgoing['order_id'] !== null ) {
			$this->record( $id, $outgoing['title'], $outgoing['order_id'], $recipients, $sent, $error );
		}
		return $sent;
	}

	/** @param list<string> $recipients */
	private function record( string $email_id, string $title, int $order_id, array $recipients, bool $sent, string $error ): void {
		$to      = implode( ', ', $recipients );
		$message = $sent
			/* translators: 1: email name, 2: recipient addresses. */
			? sprintf( __( '%1$s email sent to %2$s.', 'flexacommerce' ), $title, $to )
			/* translators: 1: email name, 2: recipient addresses. */
			: sprintf( __( '%1$s email to %2$s could not be sent.', 'flexacommerce' ), $title, $to );
		$context = [
			'email' => $email_id,
			'sent'  => $sent,
		];
		if ( $error !== '' ) {
			$context['error'] = $error;
		}
		try {
			$this->history->add( $order_id, 'email', $message, $this->clock->db_now(), 'system', 0, false, null, null, $context );
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Could not record email {email} for order {order_id}: {message}',
				[
					'email'    => $email_id,
					'order_id' => $order_id,
					'message'  => $e->getMessage(),
				]
			);
		}
	}

	private function text( string $field, string $value, string $id, ?OrderView $order ): string {
		$value = strtr( $value, self::placeholders( $order ) );
		return wp_strip_all_tags( (string) apply_filters( "flexacommerce.email.{$field}", $value, $id, $order ) );
	}

	/** @return array<string,string> */
	private static function placeholders( ?OrderView $order ): array {
		$created = $order !== null ? strtotime( $order->created_at . ' UTC' ) : false;
		return [
			'{site_title}'   => self::site_title(),
			'{order_number}' => $order !== null ? $order->number : '',
			'{order_date}'   => $created !== false ? (string) wp_date( (string) get_option( 'date_format' ), $created ) : '',
		];
	}

	private static function site_title(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}
}
