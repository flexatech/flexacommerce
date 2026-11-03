<?php

declare(strict_types=1);

namespace Flexa\Commerce\Recovery;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\Session;
use Flexa\Commerce\Cart\SessionRepository;
use Flexa\Commerce\Cart\SessionStore;
use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponEditor;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\Mailer;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Abandoned cart recovery (ADR-063). The five-minute cron looks at carts of
 * known shoppers that went quiet, keeps a copy of each in fxcm_recoveries
 * and sends the reminders when they fall due. An order by the same email or
 * account settles the row: recovered when a reminder or a link reached the
 * shopper, else the row goes. Nothing here runs on a shopper's request
 * except the link handlers and the address the checkout sends.
 *
 * @phpstan-import-type Values from RecoverySettings
 * @phpstan-import-type Reminder from RecoverySettings
 */
final class RecoveryService {
	/** Rows looked at and reminders sent per cron run. */
	public const BATCH = 50;

	/** A cart quiet for longer is no longer followed. */
	public const FOLLOW_DAYS = 7;

	/** Settled rows are kept this long for the stats. */
	public const KEEP_DAYS = 90;

	public const RECOVER_PARAM = 'fxcm_recover';

	public const STOP_PARAM = 'fxcm_recover_stop';

	/**
	 * @param \Closure():CartService  $cart
	 * @param \Closure():CouponEditor $coupons
	 */
	public function __construct(
		private readonly RecoveryRepository $recoveries,
		private readonly SessionRepository $sessions,
		private readonly SessionStore $store,
		private readonly \Closure $cart,
		private readonly \Closure $coupons,
		private readonly Mailer $mailer,
		private readonly EmailTemplates $templates,
		private readonly Clock $clock,
		private readonly Logger $log,
	) {}

	public function repository(): RecoveryRepository {
		return $this->recoveries;
	}

	/** The five-minute cron: find quiet carts, then send what is due. */
	public function run(): void {
		$settings = RecoverySettings::get();
		if ( ! $settings['enabled'] ) {
			return;
		}
		$this->detect( $settings );
		$this->send_due( $settings );
	}

	/** The daily cron: give up on old carts and drop old rows. */
	public function daily(): void {
		$now = $this->clock->db_now();
		$this->recoveries->expire( $this->clock->db_ago( self::FOLLOW_DAYS * DAY_IN_SECONDS ), $now );
		$this->recoveries->purge( $this->clock->db_ago( self::KEEP_DAYS * DAY_IN_SECONDS ) );
	}

	/**
	 * The address a guest typed at checkout. False when reminders are off,
	 * guests are not followed or the visitor has no cart yet.
	 */
	public function capture( int $user_id, string $email ): bool {
		if ( ! RecoverySettings::captures_guests() ) {
			return false;
		}
		return $this->store->set_email( $user_id, $email );
	}

	/** @param Values $settings */
	public function detect( array $settings ): void {
		$quiet = $this->clock->db_ago( $settings['abandon_minutes'] * MINUTE_IN_SECONDS );
		foreach ( $this->sessions->idle( $this->clock->db_ago( self::FOLLOW_DAYS * DAY_IN_SECONDS ), $quiet, self::BATCH ) as $session ) {
			try {
				$this->check( $session, $settings );
			} catch ( \Throwable $e ) {
				$this->log->error(
					'Could not check the cart of session {session}: {message}',
					[
						'session' => $session->id,
						'message' => $e->getMessage(),
					]
				);
			}
			$this->sessions->mark_checked( $session->id, $session->updated_at );
		}
	}

	/** @param Values $settings */
	public function send_due( array $settings ): void {
		$now = $this->clock->db_now();
		foreach ( $this->recoveries->due( $now, self::BATCH ) as $row ) {
			try {
				$this->remind( $row, $settings, $now );
			} catch ( \Throwable $e ) {
				$this->recoveries->sent( $row->id, $row->reminder, false, null, '', $now );
				$this->log->error(
					'Could not send the cart reminder {id}: {message}',
					[
						'id'      => $row->id,
						'message' => $e->getMessage(),
					]
				);
			}
		}
	}

	/**
	 * The recovery link: the left cart goes back into the visitor's cart
	 * with the reminder's coupon. False for a link that does not match.
	 */
	public function recover( string $key, int $user_id ): bool {
		$row = $this->verify( $key );
		if ( $row === null ) {
			return false;
		}
		if ( $row->status === Recovery::RECOVERED ) {
			return true;
		}
		$coupons = $row->cart['coupons'];
		if ( $row->coupon_code !== '' ) {
			$coupons[] = Coupon::normalize( $row->coupon_code );
		}
		( $this->cart )()->restore(
			$user_id,
			Cart::from_data(
				[
					'lines'   => $row->cart['lines'],
					'coupons' => $coupons,
				]
			)
		);
		if ( $user_id <= 0 ) {
			$this->store->set_email( $user_id, $row->email );
		}
		$this->recoveries->clicked( $row->id, $this->store->current( $user_id )?->id, $this->clock->db_now() );
		return true;
	}

	/** The row a link names, when its token matches. */
	public function verify( string $key ): ?Recovery {
		if ( preg_match( '/^(\d{1,20})\.([a-f0-9]{32})$/', $key, $m ) !== 1 ) {
			return null;
		}
		$row = $this->recoveries->find( (int) $m[1] );
		return $row !== null && hash_equals( $row->token, $m[2] ) ? $row : null;
	}

	/** No more reminders to the address of the row the link names. */
	public function unsubscribe( string $key ): bool {
		$row = $this->verify( $key );
		if ( $row === null || $row->email === '' ) {
			return false;
		}
		$this->recoveries->unsubscribe( $row->email, $this->clock->db_now() );
		return true;
	}

	/** A sample of a reminder, sent to `$to`. */
	public function test( int $index, string $to ): bool {
		$settings = RecoverySettings::get();
		$reminder = $settings['reminders'][ $index ] ?? $settings['reminders'][0];
		$currency = Settings::string( 'currency' );
		$row      = new Recovery(
			0,
			null,
			null,
			$to,
			[
				'lines'   => [],
				'coupons' => [],
				'items'   => [
					[
						'name'     => __( 'Sample product', 'flexacommerce' ),
						'quantity' => 2,
						'total'    => 4000,
						'image'    => '',
					],
				],
			],
			4000,
			$currency,
			2,
			Recovery::OPEN,
			str_repeat( '0', 32 ),
			$index,
			0,
			null,
			$this->clock->db_now(),
			null,
			'',
			null,
			null,
			null,
			$this->clock->db_now()
		);
		$coupon   = $reminder['coupon_type'] !== '' ? 'CART-SAMPLE' : '';
		return $this->email( $row, $reminder, $index, $coupon );
	}

	/** @param Values $settings */
	private function check( Session $session, array $settings ): void {
		unset( $settings );
		$email = $session->email;
		if ( $email === '' && $session->user_id !== null ) {
			$user  = get_userdata( $session->user_id );
			$email = $user instanceof \WP_User ? (string) $user->user_email : '';
		}
		if ( $email === '' ) {
			return;
		}
		$row  = $this->recoveries->open_for( $session->id, $email );
		$cart = Cart::from_data( $session->data );
		if ( $cart->is_empty() ) {
			if ( $row !== null ) {
				$this->settle( $row, true );
			}
			return;
		}
		if ( $this->recoveries->is_unsubscribed( $email ) ) {
			return;
		}
		$state = ( $this->cart )()->preview( $cart, (int) $session->user_id );
		$items = [];
		foreach ( $state->items as $item ) {
			$image   = $item->product !== null ? get_the_post_thumbnail_url( $item->product->id, 'thumbnail' ) : false;
			$items[] = [
				'name'     => $item->name(),
				'quantity' => $item->line->quantity,
				'total'    => $item->total?->amount,
				'image'    => is_string( $image ) ? $image : '',
			];
		}
		$total  = $state->discounted_subtotal();
		$fields = [
			'session_id'       => $session->id,
			'user_id'          => $session->user_id,
			'email'            => $email,
			'cart'             => [
				'lines'   => $cart->to_data()['lines'],
				'coupons' => $cart->coupons,
				'items'   => $items,
			],
			'total'            => $total->amount,
			'currency'         => $total->currency,
			'item_count'       => $cart->item_count(),
			'next_send_at'     => $this->next_send( $row !== null ? $row->reminder : 0, $session->updated_at ),
			'last_activity_at' => $session->updated_at,
		];
		$now    = $this->clock->db_now();
		if ( $row === null ) {
			$this->recoveries->insert( $fields, $now );
		} else {
			$this->recoveries->refresh( $row->id, $fields, $now );
		}
	}

	/**
	 * An order since the cart was left settles the row. With `$empty` the
	 * cart is gone without one: lost after a reminder, else forgotten.
	 *
	 * @return bool whether the row is settled
	 */
	private function settle( Recovery $row, bool $empty ): bool {
		$order = $this->recoveries->order_since( $row->email, $row->user_id, $row->last_activity_at );
		if ( $order === null && ! $empty ) {
			return false;
		}
		$now = $this->clock->db_now();
		if ( ! $row->was_reached() ) {
			$this->recoveries->delete( $row->id );
		} elseif ( $order !== null ) {
			$this->recoveries->recovered( $row->id, $order['id'], $now );
		} else {
			$this->recoveries->lost( $row->id, $now );
		}
		return true;
	}

	/** @param Values $settings */
	private function remind( Recovery $row, array $settings, string $now ): void {
		if ( $this->settle( $row, false ) ) {
			return;
		}
		$session = $row->session_id !== null ? $this->sessions->find( $row->session_id ) : null;
		if ( $session !== null && $session->updated_at > $row->last_activity_at ) {
			// The shopper is back; the next check takes a new copy and sets the time again.
			$this->recoveries->sent( $row->id, $row->reminder, false, null, '', $now );
			return;
		}
		$index = $this->next_enabled( $row->reminder );
		if ( $index === null || $this->recoveries->is_unsubscribed( $row->email ) ) {
			$this->recoveries->sent( $row->id, RecoverySettings::REMINDERS, false, null, '', $now );
			return;
		}
		$reminder = $settings['reminders'][ $index ];
		$code     = $this->coupon( $row, $reminder );
		$sent     = $this->email( $row, $reminder, $index, $code !== '' ? $code : $row->coupon_code );
		$this->recoveries->sent( $row->id, $index + 1, $sent, $this->next_send( $index + 1, $row->last_activity_at ), $code, $now );
	}

	/** The first reminder switched on from `$from` on. */
	private function next_enabled( int $from ): ?int {
		$reminders = RecoverySettings::get()['reminders'];
		$count     = count( $reminders );
		for ( $i = max( 0, $from ); $i < $count; $i++ ) {
			if ( $reminders[ $i ]['enabled'] ) {
				return $i;
			}
		}
		return null;
	}

	/** When the next reminder from `$from` on falls due, counted from the last cart activity. */
	private function next_send( int $from, string $last_activity ): ?string {
		$index = $this->next_enabled( $from );
		if ( $index === null ) {
			return null;
		}
		$hours = RecoverySettings::get()['reminders'][ $index ]['delay_hours'];
		$due   = ( new \DateTimeImmutable( $last_activity, new \DateTimeZone( 'UTC' ) ) )->modify( sprintf( '+%d hours', $hours ) )->format( Clock::DB_FORMAT );
		$now   = $this->clock->db_now();
		return $due > $now ? $due : $now;
	}

	/**
	 * A coupon for this shopper alone, used once, when the reminder gives one.
	 *
	 * @param Reminder $reminder
	 */
	private function coupon( Recovery $row, array $reminder ): string {
		if ( $reminder['coupon_type'] === '' ) {
			return '';
		}
		$percent = $reminder['coupon_type'] === 'percent';
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$code = 'CART-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) );
			try {
				( $this->coupons )()->save(
					[
						'code'               => $code,
						'type'               => $percent ? Coupon::PERCENT : Coupon::FIXED_CART,
						'amount'             => $percent ? $reminder['coupon_amount'] * 100 : $reminder['coupon_amount'],
						'status'             => Coupon::ACTIVE,
						/* translators: %s: customer email address */
						'description'        => sprintf( __( 'Cart reminder for %s', 'flexacommerce' ), $row->email ),
						'expires_at'         => $this->clock->now()->modify( sprintf( '+%d days', $reminder['coupon_days'] ) )->format( Clock::DB_FORMAT ),
						'usage_limit'        => 1,
						'per_customer_limit' => 1,
						'emails'             => [ $row->email ],
					]
				);
				return $code;
			} catch ( InvalidCoupon $e ) {
				if ( $e->field !== 'code' ) {
					throw $e;
				}
			}
		}
		return '';
	}

	/** @param Reminder $reminder */
	private function email( Recovery $row, array $reminder, int $index, string $coupon ): bool {
		$switched = $row->user_id !== null && switch_to_user_locale( $row->user_id );
		try {
			$texts   = RecoverySettings::default_texts( $index );
			$message = $reminder['message'] !== '' ? $reminder['message'] : $texts['message'];
			$message = strtr( $message, [ '{site_title}' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ] );
			$cart    = ( $this->cart )();
			$money   = static fn ( int $amount ): string => $row->currency !== '' ? $cart->format( new Money( $amount, $row->currency ) ) : '';
			$items   = [];
			foreach ( $row->cart['items'] as $item ) {
				$items[] = [
					'name'     => $item['name'],
					'quantity' => $item['quantity'],
					'total'    => $item['total'] !== null ? $money( $item['total'] ) : '',
					'image'    => $item['image'],
				];
			}
			$html = $this->templates->render(
				'cart-reminder',
				[
					'email_id'        => CartReminderEmail::ID,
					'message'         => $message,
					'items'           => $items,
					'total'           => $money( $row->total ),
					'coupon'          => $coupon !== '' ? $this->coupon_label( $coupon, $reminder ) : null,
					'recover_url'     => add_query_arg( self::RECOVER_PARAM, $row->key(), home_url( '/' ) ),
					'unsubscribe_url' => add_query_arg( self::STOP_PARAM, $row->key(), home_url( '/' ) ),
				]
			);
			return $this->mailer->send(
				new CartReminderEmail(),
				new EmailMessage(
					$reminder['subject'] !== '' ? $reminder['subject'] : $texts['subject'],
					$reminder['heading'] !== '' ? $reminder['heading'] : $texts['heading'],
					$html,
					null,
					[ $row->email ]
				),
				[
					'enabled'    => true,
					'recipients' => '',
					'subject'    => '',
					'heading'    => '',
				]
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * @param Reminder $reminder
	 * @return array{code:string,text:string}
	 */
	private function coupon_label( string $code, array $reminder ): array {
		$value = $reminder['coupon_type'] === 'percent'
			? $reminder['coupon_amount'] . '%'
			: ( $this->cart )()->format( new Money( $reminder['coupon_amount'], Settings::string( 'currency' ) ) );
		return [
			'code' => $code,
			'text' => sprintf(
				/* translators: 1: discount such as 10% or $5.00, 2: number of days */
				_n( 'Use this code for %1$s off. It works once and ends in %2$d day.', 'Use this code for %1$s off. It works once and ends in %2$d days.', $reminder['coupon_days'], 'flexacommerce' ),
				$value,
				$reminder['coupon_days']
			),
		];
	}
}
