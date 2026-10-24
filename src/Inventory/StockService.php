<?php

declare(strict_types=1);

namespace Flexa\Commerce\Inventory;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stock reads and movements (doc 02 §7.4). Every decrement is one
 * conditional UPDATE, so two buyers of the last unit cannot both succeed.
 * Movements are written to the `stock` log channel; Orders adds its own
 * order_history rows in phase 4. Events fire after commit:
 * `flexacommerce.stock.changed`, `.low`, `.out`.
 */
final class StockService {
	public function __construct(
		private readonly VariantRepository $variants,
		private readonly ProductRepository $products,
		private readonly Transaction $transaction,
		private readonly AfterCommit $after_commit,
		private readonly Logger $ledger,
		private readonly Clock $clock,
	) {}

	/** From data already loaded with the product: no query. */
	public function availability( Product $product, Variant $variant ): Availability {
		return Availability::of( $product->stock_owner( $variant ) );
	}

	/** Fresh read of the stock owner, bypassing caches. One query. */
	public function available( Product $product, Variant $variant ): Availability {
		$owner = $product->stock_owner( $variant );
		if ( $owner->id <= 0 ) {
			return Availability::of( $owner );
		}
		$rows = $this->variants->stock_rows( [ $owner->id ] );
		return isset( $rows[ $owner->id ] ) ? Availability::from_stock_row( $rows[ $owner->id ] ) : new Availability( Variant::OUTOFSTOCK, 0, false );
	}

	/**
	 * Take stock for a set of lines, all or nothing. Lines that share a stock
	 * owner are added up first. Throws InsufficientStock naming the first row
	 * that cannot cover its quantity; the transaction then rolls back.
	 *
	 * @param list<StockLine> $lines
	 * @return list<StockChange>
	 */
	public function deduct( array $lines, string $reason, int $order_id = 0 ): array {
		return $this->move( $lines, -1, $reason, $order_id );
	}

	/**
	 * Put stock back (cancellation, refund with restock).
	 *
	 * @param list<StockLine> $lines
	 * @return list<StockChange>
	 */
	public function release( array $lines, string $reason, int $order_id = 0 ): array {
		return $this->move( $lines, 1, $reason, $order_id );
	}

	/** Manual correction by `$delta` units. Returns the new quantity, or null when stock is not managed. */
	public function adjust( Product $product, Variant $variant, int $delta, string $reason, int $actor_id = 0 ): ?int {
		$owner = $product->stock_owner( $variant );
		if ( $delta === 0 ) {
			return $owner->manage_stock ? (int) $owner->stock_quantity : null;
		}
		$changes = $this->transaction->run(
			function () use ( $product, $owner, $delta, $reason, $actor_id ): array {
				$before = $this->variants->stock_rows( [ $owner->id ], true )[ $owner->id ] ?? null;
				if ( $before === null || ! $before['manage_stock'] ) {
					return [];
				}
				$this->variants->increment_stock( $owner->id, $delta, $product->id );
				$change = $this->change( $owner->id, $before, $delta, $reason, 0 );
				$this->record( [ $change ], $actor_id );
				return [ $change ];
			}
		);
		return $changes === [] ? null : $changes[0]->new_quantity;
	}

	/**
	 * @param list<StockLine> $lines
	 * @return list<StockChange>
	 */
	private function move( array $lines, int $sign, string $reason, int $order_id ): array {
		$wanted = [];
		foreach ( $lines as $line ) {
			if ( $line->quantity <= 0 ) {
				continue;
			}
			$owner = $line->product->stock_owner( $line->variant );
			if ( ! $owner->manage_stock || $owner->id <= 0 ) {
				continue;
			}
			$wanted[ $owner->id ] ??= [
				'product_id' => $line->product->id,
				'quantity'   => 0,
				'variant_id' => $line->variant->id,
			];
			$wanted[ $owner->id ]['quantity'] += $line->quantity;
		}
		if ( $wanted === [] ) {
			return [];
		}
		ksort( $wanted );

		return $this->transaction->run(
			function () use ( $wanted, $sign, $reason, $order_id ): array {
				$before  = $this->variants->stock_rows( array_keys( $wanted ), true );
				$changes = [];
				foreach ( $wanted as $owner_id => $want ) {
					$row = $before[ $owner_id ] ?? null;
					if ( $row === null || ! $row['manage_stock'] ) {
						continue;
					}
					if ( $sign < 0 ) {
						if ( ! $this->variants->decrement_stock( $owner_id, $want['quantity'], $want['product_id'] ) ) {
							throw new InsufficientStock( $want['variant_id'], max( 0, (int) $row['stock_quantity'] ) );
						}
					} else {
						$this->variants->increment_stock( $owner_id, $want['quantity'], $want['product_id'] );
					}
					$changes[] = $this->change( $owner_id, $row, $sign * $want['quantity'], $reason, $order_id );
				}
				$this->record( $changes, 0 );
				return $changes;
			}
		);
	}

	/** @param array{product_id:int,manage_stock:bool,stock_quantity:?int,backorders:string,stock_status:string,low_stock_threshold:?int} $row */
	private function change( int $owner_id, array $row, int $delta, string $reason, int $order_id ): StockChange {
		$old   = (int) $row['stock_quantity'];
		$new   = $old + $delta;
		$after = Availability::from_stock_row( [ 'stock_quantity' => $new ] + $row );
		return new StockChange(
			variant_id: $owner_id,
			product_id: $row['product_id'],
			old_quantity: $old,
			new_quantity: $new,
			old_status: $row['stock_status'],
			new_status: $after->status,
			reason: sanitize_key( $reason ),
			order_id: $order_id,
			low_threshold: $row['low_stock_threshold'] ?? Settings::int( 'low_stock_threshold' ),
		);
	}

	/**
	 * Ledger entries and events once the transaction commits.
	 *
	 * @param list<StockChange> $changes
	 */
	private function record( array $changes, int $actor_id ): void {
		if ( $changes === [] ) {
			return;
		}
		$this->after_commit->defer(
			function () use ( $changes, $actor_id ): void {
				$synced = [];
				foreach ( $changes as $change ) {
					$this->ledger->info(
						'Stock of variant {variant_id}: {old} to {new} ({reason})',
						[
							'variant_id' => $change->variant_id,
							'product_id' => $change->product_id,
							'old'        => $change->old_quantity,
							'new'        => $change->new_quantity,
							'reason'     => $change->reason,
							'order_id'   => $change->order_id,
							'actor_id'   => $actor_id,
						]
					);
					if ( $change->status_changed() && ! isset( $synced[ $change->product_id ] ) ) {
						$synced[ $change->product_id ] = true;
						$this->products->sync_denormalized( $change->product_id, $this->clock->db_now() );
					}
					do_action( 'flexacommerce.stock.changed', $change->variant_id, $change->old_quantity, $change->new_quantity, $change->reason );
					if ( $change->ran_out() ) {
						do_action( 'flexacommerce.stock.out', $change->variant_id, $change->product_id );
						$this->notify( $change, true );
					} elseif ( $change->crossed_low() ) {
						do_action( 'flexacommerce.stock.low', $change->variant_id, $change->product_id, $change->new_quantity );
						$this->notify( $change, false );
					}
				}
			}
		);
	}

	/** Low and out-of-stock email to the site admin (doc 07 phase 3, P2). */
	private function notify( StockChange $change, bool $out ): void {
		if ( ! Settings::bool( 'notify_low_stock' ) ) {
			return;
		}
		$send = apply_filters( 'flexacommerce.stock.notify', true, $change->variant_id, $out );
		if ( $send !== true ) {
			return;
		}
		$title   = get_the_title( $change->product_id );
		$subject = $out
			/* translators: %s: product name. */
			? sprintf( __( 'Out of stock: %s', 'flexacommerce' ), $title )
			/* translators: %s: product name. */
			: sprintf( __( 'Low stock: %s', 'flexacommerce' ), $title );
		$body    = sprintf(
			/* translators: 1: product name, 2: variant id, 3: quantity left. */
			__( '%1$s (variant #%2$d) has %3$d left in stock.', 'flexacommerce' ),
			$title,
			$change->variant_id,
			max( 0, $change->new_quantity )
		);
		$edit    = get_edit_post_link( $change->product_id, 'raw' );
		if ( is_string( $edit ) && $edit !== '' ) {
			$body .= "\n\n" . $edit;
		}
		wp_mail( (string) get_option( 'admin_email' ), $subject, $body );
	}
}
