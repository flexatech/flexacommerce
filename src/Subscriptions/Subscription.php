<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Catalog\SubscriptionPlan;
use Flexa\Commerce\Contracts\Orders\AddressView;

defined( 'ABSPATH' ) || exit;

/**
 * A row of fxc_subscriptions (ADR-073). Dates are UTC `Y-m-d H:i:s`.
 * `billed` counts the periods paid for, the first order's included unless
 * it only started a trial; `anchor_day` keeps a monthly plan on its day.
 * `payment_token` is the saved method renewals charge (ADR-074): null
 * until it is looked up, '' when the payment saved none. `retry_count`
 * and `retry_at` follow a failed automatic charge.
 */
final class Subscription {
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly int $customer_id,
		public readonly string $customer_email,
		public readonly string $currency,
		public readonly SubscriptionPlan $plan,
		public readonly int $anchor_day,
		public readonly int $billed,
		public readonly int $total,
		public readonly ?int $parent_order_id,
		public readonly string $payment_method,
		public readonly string $payment_method_title,
		public readonly string $payment_customer,
		public readonly ?string $payment_token,
		public readonly string $payment_label,
		public readonly int $retry_count,
		public readonly ?string $retry_at,
		public readonly ?Snapshot $snapshot,
		public readonly ?AddressView $billing,
		public readonly ?AddressView $shipping,
		public readonly ?string $start_at,
		public readonly ?string $trial_end_at,
		public readonly ?string $next_payment_at,
		public readonly ?string $end_at,
		public readonly ?string $cancelled_at,
		public readonly string $created_at,
		public readonly string $updated_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$at       = static fn ( mixed $v ): ?string => $v === null || $v === '' ? null : (string) $v;
		$snapshot = Snapshot::from_json( isset( $row['snapshot'] ) ? (string) $row['snapshot'] : null );
		return new self(
			id: (int) $row['id'],
			status: in_array( $row['status'] ?? null, SubscriptionStatus::ALL, true ) ? (string) $row['status'] : SubscriptionStatus::PENDING,
			customer_id: (int) ( $row['customer_id'] ?? 0 ),
			customer_email: (string) ( $row['customer_email'] ?? '' ),
			currency: (string) ( $row['currency'] ?? '' ),
			plan: new SubscriptionPlan(
				in_array( $row['period'] ?? null, SubscriptionPlan::PERIODS, true ) ? (string) $row['period'] : SubscriptionPlan::MONTH,
				max( 1, (int) ( $row['interval_count'] ?? 1 ) ),
				(int) ( $row['length'] ?? 0 ),
				(int) ( $row['trial_days'] ?? 0 ),
				$snapshot->plan->signup_fee ?? 0
			),
			anchor_day: (int) ( $row['anchor_day'] ?? 0 ),
			billed: (int) ( $row['billed'] ?? 0 ),
			total: (int) ( $row['total'] ?? 0 ),
			parent_order_id: isset( $row['parent_order_id'] ) ? (int) $row['parent_order_id'] : null,
			payment_method: (string) ( $row['payment_method'] ?? '' ),
			payment_method_title: (string) ( $row['payment_method_title'] ?? '' ),
			payment_customer: (string) ( $row['payment_customer'] ?? '' ),
			payment_token: isset( $row['payment_token'] ) ? (string) $row['payment_token'] : null,
			payment_label: (string) ( $row['payment_label'] ?? '' ),
			retry_count: (int) ( $row['retry_count'] ?? 0 ),
			retry_at: $at( $row['retry_at'] ?? null ),
			snapshot: $snapshot,
			billing: self::address( 'billing', $row['billing'] ?? null ),
			shipping: self::address( 'shipping', $row['shipping'] ?? null ),
			start_at: $at( $row['start_at'] ?? null ),
			trial_end_at: $at( $row['trial_end_at'] ?? null ),
			next_payment_at: $at( $row['next_payment_at'] ?? null ),
			end_at: $at( $row['end_at'] ?? null ),
			cancelled_at: $at( $row['cancelled_at'] ?? null ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			updated_at: (string) ( $row['updated_at'] ?? '' ),
		);
	}

	public static function address_json( ?AddressView $address ): ?string {
		return $address === null ? null : (string) wp_json_encode( $address->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function address( string $type, mixed $json ): ?AddressView {
		$data = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$s = static fn ( string $key ): string => is_scalar( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';
		return new AddressView( $type, $s( 'first_name' ), $s( 'last_name' ), $s( 'company' ), $s( 'address_1' ), $s( 'address_2' ), $s( 'city' ), $s( 'state' ), $s( 'postcode' ), $s( 'country' ), $s( 'email' ), $s( 'phone' ) );
	}

	public function is_ended(): bool {
		return in_array( $this->status, SubscriptionStatus::ENDED, true );
	}

	/** Whether the plan has run all its payments: no renewal follows. */
	public function is_last_period(): bool {
		return $this->plan->length > 0 && $this->billed >= $this->plan->length;
	}

	public function in_trial( string $now ): bool {
		return $this->trial_end_at !== null && $now < $this->trial_end_at;
	}

	/** Whether renewals charge a saved method by themselves. */
	public function is_automatic(): bool {
		return $this->payment_token !== null && $this->payment_token !== '';
	}

	public function title(): string {
		return $this->snapshot?->title() ?? '';
	}

	/** @return array<string,mixed> JS-friendly, dates as ISO 8601 UTC */
	public function to_array(): array {
		$iso = static fn ( ?string $at ): ?string => $at === null ? null : str_replace( ' ', 'T', $at ) . 'Z';
		return [
			'id'                   => $this->id,
			'status'               => $this->status,
			'status_label'         => SubscriptionStatus::label( $this->status ),
			'title'                => $this->title(),
			'customer_id'          => $this->customer_id,
			'customer_email'       => $this->customer_email,
			'currency'             => $this->currency,
			'plan'                 => $this->plan->to_array(),
			'billed'               => $this->billed,
			'total'                => $this->total,
			'parent_order_id'      => $this->parent_order_id,
			'payment_method'       => $this->payment_method,
			'payment_method_title' => $this->payment_method_title,
			'payment_label'        => $this->payment_label,
			'automatic'            => $this->is_automatic(),
			'retry_count'          => $this->retry_count,
			'retry_at'             => $iso( $this->retry_at ),
			'start_at'             => $iso( $this->start_at ),
			'trial_end_at'         => $iso( $this->trial_end_at ),
			'next_payment_at'      => $iso( $this->next_payment_at ),
			'end_at'               => $iso( $this->end_at ),
			'cancelled_at'         => $iso( $this->cancelled_at ),
			'created_at'           => $iso( $this->created_at ),
		];
	}
}
