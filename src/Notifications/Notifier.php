<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/**
 * Binds every email's trigger hooks with one listener per hook. The order
 * actions already run after commit (doc 04 §5.1), so a send never holds a
 * lock. A failing email is logged and never reaches the code that fired the
 * action. All emails are off while `flexacommerce.emails.suspended` is true,
 * which the importer sets for the length of a migration (doc 05 §8.3).
 */
final class Notifier {
	/** @var array<string,list<Email>> hook => emails */
	private array $by_hook = [];

	/** @param \Closure():list<Email> $emails */
	public function __construct(
		private readonly \Closure $emails,
		private readonly Mailer $mailer,
		private readonly EmailSettings $settings,
		private readonly OrderData $data,
		private readonly Logger $log,
	) {}

	/** Safe to call again: a hook already bound is skipped. */
	public function bind(): void {
		$this->by_hook = [];
		$accepted      = [];
		foreach ( ( $this->emails )() as $email ) {
			foreach ( $email->trigger_hooks() as $hook => $args ) {
				$this->by_hook[ $hook ][] = $email;
				$accepted[ $hook ]        = max( $accepted[ $hook ] ?? 1, (int) $args );
			}
		}
		foreach ( $accepted as $hook => $args ) {
			if ( has_action( $hook, [ $this, 'dispatch' ] ) === false ) {
				add_action( $hook, [ $this, 'dispatch' ], 20, $args );
			}
		}
	}

	/** Listener of every trigger hook; the hook name comes from current_action(). */
	public function dispatch( mixed ...$args ): void {
		$hook = (string) current_action();
		if ( ! isset( $this->by_hook[ $hook ] ) || self::suspended() ) {
			return;
		}
		$this->settings->prime( array_map( static fn ( Email $email ): string => $email->id(), $this->by_hook[ $hook ] ) );
		foreach ( $this->by_hook[ $hook ] as $email ) {
			$this->send( $email, $hook, array_values( $args ) );
		}
		$view = $args[0] ?? null;
		if ( $view instanceof OrderView ) {
			$this->data->forget( $view->id );
		}
	}

	public static function suspended(): bool {
		return (bool) apply_filters( 'flexacommerce.emails.suspended', false );
	}

	/** @param list<mixed> $args */
	private function send( Email $email, string $hook, array $args ): void {
		$view     = $args[0] ?? null;
		$locale   = $email->to_customer() && $view instanceof OrderView ? $this->data->locale( $view->id ) : '';
		$switched = $locale !== '' && $locale !== determine_locale() && switch_to_locale( $locale );
		try {
			$message = $email->render( $hook, $args );
			if ( $message === null ) {
				return;
			}
			$settings = $this->settings->get( $email->id() );
			if ( $settings['enabled'] ) {
				$this->mailer->send( $email, $message, $settings );
			}
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Email {email} failed on {hook}: {message}',
				[
					'email'     => $email->id(),
					'hook'      => $hook,
					'order_id'  => $view instanceof OrderView ? $view->id : null,
					'message'   => $e->getMessage(),
					'exception' => $e,
				]
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
