<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Transaction as DbTransaction;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\Mailer;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Payments\TransactionRepository;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gift cards (ADR-069). A paid order line of a gift card product issues
 * one card per unit, worth what the unit cost, and emails the code to the
 * recipient now or on the chosen day. A code entered in the cart pays part
 * or all of the total: at submit each card is locked and debited, and the
 * order gets a `fxc_gift_card` payment per card, so refunds go back to the
 * card through the gift card gateway. A cancelled order gets its gift card
 * payments back, and the cards it sold stop working.
 *
 * Store credit (ADR-072) is a card owned by one customer: refunds and the
 * merchant add to it, and it pays for that customer's carts by itself,
 * after any codes entered. User meta `CREDIT_META` holds its id, so a cart
 * of a customer who never had credit reads no table.
 */
final class GiftCardService {
	public const GATEWAY = 'fxc_gift_card';

	public const CREDIT_META = '_fxc_store_credit';

	/** The code the cart rows and buttons use for store credit; the real code is never sent. */
	public const CREDIT_CODE = 'store-credit';

	/** Emails sent at most per cron run. */
	public const BATCH = 50;

	/** Attempts at a code that is not taken yet. */
	private const CODE_TRIES = 5;

	/** @param \Closure():PaymentService $payments built only for a cancel */
	public function __construct(
		private readonly GiftCardRepository $cards,
		private readonly OrderService $orders,
		private readonly TransactionRepository $transactions,
		private readonly \Closure $payments,
		private readonly DbTransaction $db,
		private readonly Mailer $mailer,
		private readonly EmailTemplates $templates,
		private readonly MoneyFactory $money,
		private readonly Clock $clock,
		private readonly Logger $log,
	) {}

	public function repository(): GiftCardRepository {
		return $this->cards;
	}

	/**
	 * The card a code names when it can pay now, null when no card has that
	 * code.
	 *
	 * @throws GiftCardUnusable
	 */
	public function usable( string $code ): ?GiftCard {
		$card = $this->cards->find_by_code( GiftCard::normalize( $code ) );
		if ( $card === null ) {
			return null;
		}
		$this->check( $card );
		return $card;
	}

	/**
	 * How much of `$limit` each card pays, in the order they were entered.
	 * Cards that cannot pay any more are left out.
	 *
	 * @param list<string> $codes
	 * @return list<AppliedGiftCard>
	 */
	public function allocate( array $codes, int $limit, string $currency ): array {
		$out   = [];
		$left  = max( 0, $limit );
		$found = $this->cards->by_codes( $codes );
		foreach ( $codes as $code ) {
			$card = $found[ $code ] ?? null;
			if ( $card === null || ! $this->can_pay( $card, $currency ) ) {
				continue;
			}
			$amount = min( $card->balance, $left );
			$left  -= $amount;
			$out[]  = new AppliedGiftCard( $card->id, $card->code, $amount, $card->balance );
		}
		return $out;
	}

	/**
	 * What the cart and checkout show for each code: the share of `$limit`
	 * it pays, or why it cannot pay. Store credit (ADR-072) comes last as
	 * `store-credit`, with `credit` set and `used` false while the shopper
	 * turned it off.
	 *
	 * @param list<string> $codes
	 * @return list<array{code:string,label:string,amount:int,balance:int,message:string,credit:bool,used:bool}>
	 */
	public function rows( array $codes, int $limit, string $currency, ?string $credit = null, bool $use_credit = true ): array {
		$paying = [];
		$all    = $credit !== null && $use_credit ? [ ...$codes, $credit ] : $codes;
		foreach ( $this->allocate( $all, $limit, $currency ) as $entry ) {
			$paying[ $entry->code ] = $entry;
		}
		$found = $this->cards->by_codes( $credit !== null ? [ ...$codes, $credit ] : $codes );
		$rows  = [];
		foreach ( $codes as $code ) {
			$card   = $found[ $code ] ?? null;
			$entry  = $paying[ $code ] ?? null;
			$rows[] = [
				'code'    => $code,
				'label'   => GiftCard::mask( $code ),
				'amount'  => $entry->amount ?? 0,
				'balance' => $card->balance ?? 0,
				'message' => $card === null ? __( 'This gift card does not exist.', 'flexacommerce' ) : (string) $card->problem( $this->clock->db_now(), $currency ),
				'credit'  => false,
				'used'    => true,
			];
		}
		$card = $credit !== null ? $found[ $credit ] ?? null : null;
		if ( $card !== null ) {
			$rows[] = [
				'code'    => self::CREDIT_CODE,
				'label'   => __( 'Store credit', 'flexacommerce' ),
				'amount'  => $paying[ $credit ]->amount ?? 0,
				'balance' => $card->balance,
				'message' => '',
				'credit'  => true,
				'used'    => $use_credit,
			];
		}
		return $rows;
	}

	/**
	 * Debits the cards for a new order and records one payment per card.
	 * Runs inside the checkout transaction: a card spent elsewhere since the
	 * quote throws, and the order is rolled back.
	 *
	 * @param list<AppliedGiftCard> $applied
	 * @throws GiftCardUnusable
	 */
	public function redeem( Order $order, array $applied, TransitionContext $ctx ): int {
		$paid = 0;
		$now  = $this->clock->db_now();
		foreach ( $applied as $entry ) {
			if ( $entry->amount <= 0 ) {
				continue;
			}
			$card = $this->cards->find( $entry->id, true );
			if ( $card !== null && $card->is_credit() && $card->user_id !== $order->customer_id ) {
				$card = null;
			}
			if ( $card === null || ! $this->can_pay( $card, $order->currency ) || $card->balance < $entry->amount ) {
				throw new GiftCardUnusable(
					$card?->is_credit() === true
						? __( 'Your store credit changed. Check your order and place it again.', 'flexacommerce' )
						: sprintf(
							/* translators: %s: last characters of the gift card code. */
							__( 'The balance of gift card %s changed. Check your order and place it again.', 'flexacommerce' ),
							$entry->masked()
						)
				);
			}
			$balance = $card->balance - $entry->amount;
			$this->cards->update( $card->id, [ 'balance' => $balance ], $now );
			$txn_id = $this->transactions->insert(
				$order->id,
				Transaction::PAYMENT,
				Transaction::SUCCEEDED,
				Transaction::GATEWAY,
				self::GATEWAY,
				$entry->amount,
				$order->currency,
				wp_generate_uuid4(),
				$now,
				null,
				// Unique per order, so one card can pay several; the gateway reads the card id from the front.
				$card->id . '-' . $order->id,
				[],
				$card->is_credit() ? [ 'store_credit' => true ] : [ 'gift_card' => GiftCard::mask( $card->code ) ]
			);
			$this->cards->add_activity( $card->id, GiftCardActivity::REDEEMED, -$entry->amount, $balance, $order->id, $txn_id, '', $ctx->actor_type === 'customer' ? $ctx->actor_id : null, $now );
			$txn = $this->transactions->find( $txn_id );
			$this->orders->record_payment(
				$order->id,
				$entry->amount,
				$ctx->with_message(
					$card->is_credit()
						? __( 'Paid with store credit.', 'flexacommerce' )
						: sprintf(
							/* translators: %s: last characters of the gift card code. */
							__( 'Paid with gift card %s.', 'flexacommerce' ),
							GiftCard::mask( $card->code )
						)
				),
				$txn?->view()
			);
			$paid += $entry->amount;
		}
		return $paid;
	}

	/**
	 * Puts a refund back on the card it was paid with. A second call for the
	 * same refund transaction does nothing, so a retried refund credits once.
	 *
	 * @throws InvalidRefund
	 */
	public function credit( int $card_id, int $amount, int $refund_id ): void {
		$this->db->run(
			function () use ( $card_id, $amount, $refund_id ): void {
				$card = $this->cards->find( $card_id, true );
				if ( $card === null ) {
					throw new InvalidRefund( __( 'The gift card this order was paid with no longer exists.', 'flexacommerce' ) );
				}
				if ( $this->cards->has_activity( $card->id, GiftCardActivity::CREDITED, $refund_id ) ) {
					return;
				}
				$now     = $this->clock->db_now();
				$balance = $card->balance + $amount;
				$this->cards->update( $card->id, [ 'balance' => $balance ], $now );
				$this->cards->add_activity( $card->id, GiftCardActivity::CREDITED, $amount, $balance, $this->transactions->find( $refund_id )?->order_id, $refund_id, '', null, $now );
			}
		);
	}

	/**
	 * The store credit of a customer (ADR-072), made with a zero balance
	 * when `$create` is set and there is none yet.
	 */
	public function credit_account( int $user_id, bool $create = false ): ?GiftCard {
		if ( $user_id <= 0 ) {
			return null;
		}
		$card = $this->cards->for_user( $user_id );
		if ( $card !== null || ! $create ) {
			return $card;
		}
		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return null;
		}
		$card = $this->db->run(
			function () use ( $user_id, $user ): GiftCard {
				$have = $this->cards->for_user( $user_id, true );
				if ( $have !== null ) {
					return $have;
				}
				return $this->insert(
					[
						'initial_amount'  => 0,
						'balance'         => 0,
						'currency'        => Settings::string( 'currency' ),
						'status'          => GiftCard::ACTIVE,
						'recipient_email' => strtolower( $user->user_email ),
						'recipient_name'  => self::text( $user->display_name ),
						'user_id'         => $user_id,
					],
					GiftCardActivity::ISSUED,
					null,
					null,
					''
				);
			}
		);
		update_user_meta( $user_id, self::CREDIT_META, $card->id );
		return $card;
	}

	/**
	 * Adds to or takes from a customer's store credit, with the reason the
	 * customer sees (ADR-072).
	 *
	 * @throws \InvalidArgumentException when the change is zero or takes more than the balance
	 */
	public function adjust_credit( int $user_id, int $amount, string $note, int $actor_id ): GiftCard {
		$card = $this->credit_account( $user_id, $amount > 0 );
		if ( $card === null ) {
			throw new \InvalidArgumentException( __( 'This customer has no store credit to take from.', 'flexacommerce' ) );
		}
		return $this->adjust( $card->id, $amount, $note, $actor_id ) ?? $card;
	}

	/**
	 * Pays a refund into the customer's store credit (ADR-072): a succeeded
	 * refund row on the order and a credit on the account, together.
	 *
	 * @param list<\Flexa\Commerce\Orders\RefundLine> $lines
	 * @throws InvalidRefund
	 */
	public function refund_to_credit( int $order_id, int $amount, array $lines, string $reason, TransitionContext $ctx ): Transaction {
		$order = $this->orders->find( $order_id );
		if ( $order === null || (int) $order->customer_id <= 0 ) {
			throw new InvalidRefund( __( 'Only an order placed by a customer account can be refunded to store credit.', 'flexacommerce' ) );
		}
		$card = $this->credit_account( (int) $order->customer_id, true );
		if ( $card === null ) {
			throw new InvalidRefund( __( 'The customer account of this order no longer exists.', 'flexacommerce' ) );
		}
		if ( strtoupper( $card->currency ) !== strtoupper( $order->currency ) ) {
			throw new InvalidRefund( __( 'The store credit of this customer is in another currency.', 'flexacommerce' ) );
		}
		$txn = ( $this->payments )()->refund_as(
			$order_id,
			$amount,
			$lines,
			$reason,
			self::GATEWAY,
			function ( Transaction $refund, Order $order ) use ( $card, $amount, $ctx ): void {
				$locked  = $this->cards->find( $card->id, true ) ?? $card;
				$now     = $this->clock->db_now();
				$balance = $locked->balance + $amount;
				$this->cards->update( $locked->id, [ 'balance' => $balance ], $now );
				$this->cards->add_activity(
					$locked->id,
					GiftCardActivity::CREDITED,
					$amount,
					$balance,
					$order->id,
					$refund->id,
					sprintf(
						/* translators: %s: order number. */
						__( 'Refund of order #%s', 'flexacommerce' ),
						$order->order_number
					),
					$ctx->actor_type === 'admin' ? $ctx->actor_id : null,
					$now
				);
				$this->transactions->update_status( $refund->id, Transaction::SUCCEEDED, $now, 'store-credit-' . $locked->id . '-' . $refund->id, '', '', [ 'store_credit' => true ] );
			},
			$ctx
		);
		$this->orders->payment_note( $order_id, __( 'The refund was added to the customer\'s store credit.', 'flexacommerce' ), $ctx );
		return $txn;
	}

	/**
	 * Order events (priority 5, before the emails): a paid order issues its
	 * cards; a cancelled order gets its gift card payments back, and a
	 * cancelled or refunded order disables the cards it sold.
	 */
	public function on_order( OrderView $view ): void {
		if ( in_array( $view->status, [ OrderStatus::PROCESSING, OrderStatus::COMPLETED ], true ) ) {
			foreach ( $view->items as $item ) {
				if ( $item->gift_card ) {
					$this->issue_for_order( $view->id );
					return;
				}
			}
			return;
		}
		if ( $view->status === OrderStatus::CANCELLED ) {
			$this->give_back( $view->id );
		}
		if ( in_array( $view->status, [ OrderStatus::CANCELLED, OrderStatus::REFUNDED ], true ) ) {
			$this->disable_sold( $view->id );
		}
	}

	/**
	 * One card per unit of each gift card line, made once per line. Returns
	 * the number of cards made.
	 */
	public function issue_for_order( int $order_id ): int {
		$made = $this->db->run(
			function () use ( $order_id ): array {
				$order = $this->orders->find( $order_id );
				if ( $order === null ) {
					return [];
				}
				$have = [];
				foreach ( $this->cards->for_order( $order_id ) as $card ) {
					$have[ (int) $card->order_item_id ] = ( $have[ (int) $card->order_item_id ] ?? 0 ) + 1;
				}
				$made = [];
				foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
					$details = $item->meta['gift_card'] ?? null;
					if ( ! is_array( $details ) || $item->quantity <= 0 ) {
						continue;
					}
					// What was paid for each card, so a coupon on the card does not add value.
					$value = intdiv( max( 0, $item->subtotal - $item->discount_total ), $item->quantity );
					for ( $i = $have[ $item->id ] ?? 0; $i < $item->quantity && $value > 0; $i++ ) {
						$made[] = $this->insert(
							[
								'initial_amount'  => $value,
								'balance'         => $value,
								'currency'        => $order->currency,
								'status'          => GiftCard::ACTIVE,
								'recipient_email' => self::email( $details['to'] ?? '' ) ?: $order->customer_email,
								'recipient_name'  => self::text( $details['name'] ?? '' ),
								'sender_name'     => self::text( $details['from'] ?? '' ) ?: $order->customer_name,
								'message'         => self::message( $details['message'] ?? '' ),
								'order_id'        => $order->id,
								'order_item_id'   => $item->id,
								'deliver_at'      => self::deliver_at( (string) ( $details['date'] ?? '' ), $this->clock->now() ),
								'expires_at'      => $this->expiry(),
							],
							GiftCardActivity::ISSUED,
							$order->id,
							null,
							'',
						);
					}
				}
				return $made;
			}
		);
		$now  = $this->clock->db_now();
		foreach ( $made as $card ) {
			if ( $card->deliver_at === null || $card->deliver_at <= $now ) {
				$this->send( $card );
			}
		}
		if ( $made !== [] ) {
			$this->orders->payment_note(
				$order_id,
				sprintf(
					/* translators: %d: number of gift cards. */
					_n( '%d gift card issued.', '%d gift cards issued.', count( $made ), 'flexacommerce' ),
					count( $made )
				),
				TransitionContext::system()
			);
		}
		return count( $made );
	}

	/**
	 * A card the merchant makes by hand.
	 *
	 * @param array{amount:int,recipient_email?:string,recipient_name?:string,sender_name?:string,message?:string,expires_at?:?string,deliver_at?:?string,send?:bool,note?:string} $input
	 */
	public function create( array $input, int $user_id ): GiftCard {
		$card = $this->db->run(
			fn (): GiftCard => $this->insert(
				[
					'initial_amount'  => $input['amount'],
					'balance'         => $input['amount'],
					'currency'        => Settings::string( 'currency' ),
					'status'          => GiftCard::ACTIVE,
					'recipient_email' => self::email( $input['recipient_email'] ?? '' ),
					'recipient_name'  => self::text( $input['recipient_name'] ?? '' ),
					'sender_name'     => self::text( $input['sender_name'] ?? '' ),
					'message'         => self::message( $input['message'] ?? '' ),
					'deliver_at'      => $input['deliver_at'] ?? null,
					'expires_at'      => array_key_exists( 'expires_at', $input ) ? $input['expires_at'] : $this->expiry(),
				],
				GiftCardActivity::ISSUED,
				null,
				$user_id,
				(string) ( $input['note'] ?? '' )
			)
		);
		if ( ( $input['send'] ?? false ) && $card->recipient_email !== '' && ( $card->deliver_at === null || $card->deliver_at <= $this->clock->db_now() ) ) {
			$this->send( $card );
		}
		return $this->cards->find( $card->id ) ?? $card;
	}

	/**
	 * Status, recipient and expiry; the balance changes through adjust().
	 *
	 * @param array<string,mixed> $changes
	 */
	public function update( int $id, array $changes ): ?GiftCard {
		$card = $this->cards->find( $id );
		if ( $card === null ) {
			return null;
		}
		$fields = [];
		if ( in_array( $changes['status'] ?? null, GiftCard::STATUSES, true ) ) {
			$fields['status'] = (string) $changes['status'];
		}
		if ( array_key_exists( 'recipient_email', $changes ) ) {
			$fields['recipient_email'] = self::email( $changes['recipient_email'] );
		}
		foreach ( [ 'recipient_name', 'sender_name' ] as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$fields[ $key ] = self::text( $changes[ $key ] );
			}
		}
		if ( array_key_exists( 'message', $changes ) ) {
			$fields['message'] = self::message( $changes['message'] );
		}
		if ( array_key_exists( 'expires_at', $changes ) ) {
			$fields['expires_at'] = is_string( $changes['expires_at'] ) && $changes['expires_at'] !== '' ? $changes['expires_at'] : null;
		}
		if ( $fields !== [] ) {
			$this->cards->update( $id, $fields, $this->clock->db_now() );
		}
		return $this->cards->find( $id );
	}

	/**
	 * Adds to or takes from the balance, never below zero, with a note.
	 *
	 * @throws \InvalidArgumentException when the change is zero or takes more than the balance
	 */
	public function adjust( int $id, int $amount, string $note, int $user_id ): ?GiftCard {
		return $this->db->run(
			function () use ( $id, $amount, $note, $user_id ): ?GiftCard {
				$card = $this->cards->find( $id, true );
				if ( $card === null ) {
					return null;
				}
				if ( $amount === 0 || $card->balance + $amount < 0 ) {
					throw new \InvalidArgumentException( __( 'Enter an amount to add, or to take off that is at most the balance.', 'flexacommerce' ) );
				}
				$now     = $this->clock->db_now();
				$balance = $card->balance + $amount;
				$this->cards->update( $card->id, [ 'balance' => $balance ], $now );
				$this->cards->add_activity( $card->id, GiftCardActivity::ADJUSTED, $amount, $balance, null, null, sanitize_text_field( $note ), $user_id, $now );
				return $this->cards->find( $card->id );
			}
		);
	}

	/** Cron: the cards whose delivery day has come. Returns the number of emails sent. */
	public function deliver_due(): int {
		$sent = 0;
		foreach ( $this->cards->due_delivery( $this->clock->db_now(), self::BATCH ) as $card ) {
			if ( $this->send( $card ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/** Sends the code to the recipient and stamps the delivery. */
	public function send( GiftCard $card ): bool {
		if ( $card->recipient_email === '' || $card->status !== GiftCard::ACTIVE ) {
			return false;
		}
		$order    = $card->order_id !== null ? $this->orders->find( $card->order_id ) : null;
		$switched = $order !== null && $order->locale !== '' && switch_to_locale( $order->locale );
		try {
			$site   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
			$amount = $this->money->format( $this->money->from_minor( $card->balance, $card->currency ) );
			$from   = $card->sender_name !== '' ? $card->sender_name : $site;
			$html   = $this->templates->render(
				'gift-card',
				[
					'email_id'       => GiftCardEmail::ID,
					'amount'         => $amount,
					'code'           => GiftCard::format( $card->code ),
					'recipient_name' => $card->recipient_name,
					'sender_name'    => $from,
					'message'        => $card->message,
					'expires'        => $card->expires_at !== null ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card->expires_at . ' UTC' ) ) : '',
					'shop_url'       => home_url( '/' ),
				]
			);
			$sent   = $this->mailer->send(
				new GiftCardEmail(),
				new EmailMessage(
					sprintf(
						/* translators: 1: gift card amount, 2: who sends it. */
						__( 'You received a %1$s gift card from %2$s', 'flexacommerce' ),
						$amount,
						$from
					),
					__( 'Your gift card', 'flexacommerce' ),
					$html,
					null,
					[ $card->recipient_email ],
					[ 'gift_card_id' => $card->id ]
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
		if ( $sent ) {
			$this->cards->update( $card->id, [ 'delivered_at' => $this->clock->db_now() ], $this->clock->db_now() );
		}
		return $sent;
	}

	/** Months from the setting, null when cards do not expire. */
	public function expiry(): ?string {
		$months = Settings::int( 'gift_card_expiry_months' );
		return $months > 0 ? $this->clock->now()->modify( '+' . $months . ' months' )->format( 'Y-m-d H:i:s' ) : null;
	}

	/** @throws GiftCardUnusable */
	private function check( GiftCard $card ): void {
		$problem = $card->problem( $this->clock->db_now(), Settings::string( 'currency' ) );
		if ( $problem !== null ) {
			throw new GiftCardUnusable( $problem );
		}
	}

	private function can_pay( GiftCard $card, string $currency ): bool {
		return $card->is_usable( $this->clock->db_now() ) && strtoupper( $card->currency ) === strtoupper( $currency );
	}

	/**
	 * Inserts a card with a fresh code and its first activity row. Runs inside
	 * the caller's transaction.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function insert( array $fields, string $type, ?int $order_id, ?int $user_id, string $note ): GiftCard {
		$now = $this->clock->db_now();
		for ( $try = 1; ; $try++ ) {
			try {
				$id = $this->cards->insert( [ 'code' => GiftCard::generate() ] + $fields, $now );
				break;
			} catch ( QueryFailed $e ) {
				if ( $e->errno !== QueryFailed::DUPLICATE_KEY || $try >= self::CODE_TRIES ) {
					throw $e;
				}
			}
		}
		$this->cards->add_activity( $id, $type, (int) $fields['initial_amount'], (int) $fields['balance'], $order_id, null, sanitize_text_field( $note ), $user_id, $now );
		$card = $this->cards->find( $id );
		if ( $card === null ) {
			throw new \RuntimeException( 'The new gift card could not be read back.' );
		}
		return $card;
	}

	/** Refunds every gift card payment of a cancelled order back to its card. */
	private function give_back( int $order_id ): void {
		$refunded = [];
		$payments = $this->transactions->for_order( $order_id );
		foreach ( $payments as $txn ) {
			if ( $txn->type === Transaction::REFUND && $txn->parent_id !== null && $txn->status !== Transaction::FAILED && $txn->status !== Transaction::CANCELLED ) {
				$refunded[ $txn->parent_id ] = ( $refunded[ $txn->parent_id ] ?? 0 ) + $txn->amount;
			}
		}
		foreach ( $payments as $txn ) {
			if ( $txn->type !== Transaction::PAYMENT || $txn->status !== Transaction::SUCCEEDED || $txn->gateway !== self::GATEWAY ) {
				continue;
			}
			$left = $txn->amount - ( $refunded[ $txn->id ] ?? 0 );
			if ( $left <= 0 ) {
				continue;
			}
			try {
				( $this->payments )()->refund( $order_id, $left, [], __( 'Order cancelled', 'flexacommerce' ), true, TransitionContext::system(), $txn->id );
			} catch ( \Throwable $e ) {
				$this->log->error(
					'Could not return gift card payment {txn} of cancelled order {order_id}',
					[
						'txn'       => $txn->id,
						'order_id'  => $order_id,
						'exception' => $e,
					]
				);
			}
		}
	}

	/** Cards sold in an order that was cancelled or refunded stop working. */
	private function disable_sold( int $order_id ): void {
		$now = $this->clock->db_now();
		foreach ( $this->cards->for_order( $order_id ) as $card ) {
			if ( $card->status === GiftCard::ACTIVE ) {
				$this->cards->update( $card->id, [ 'status' => GiftCard::DISABLED ], $now );
				$this->cards->add_activity( $card->id, GiftCardActivity::ADJUSTED, 0, $card->balance, $order_id, null, __( 'Disabled: the order was cancelled or refunded.', 'flexacommerce' ), null, $now );
			}
		}
	}

	/** The store-local day, as a UTC moment at its start; null for today or an unreadable date. */
	public static function deliver_at( string $date, \DateTimeImmutable $now ): ?string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) !== 1 ) {
			return null;
		}
		$local = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( $local === false || $local <= $now ) {
			return null;
		}
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function email( mixed $value ): string {
		$email = is_string( $value ) ? sanitize_email( $value ) : '';
		return is_email( $email ) ? strtolower( $email ) : '';
	}

	private static function text( mixed $value ): string {
		return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, 200 ) : '';
	}

	private static function message( mixed $value ): string {
		return is_string( $value ) ? mb_substr( sanitize_textarea_field( $value ), 0, 1000 ) : '';
	}
}
