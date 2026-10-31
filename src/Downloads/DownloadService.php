<?php

declare(strict_types=1);

namespace Flexa\Commerce\Downloads;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderRepository;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Grants download permissions when an order is paid and checks a download
 * link before the file is sent (ADR-058).
 */
final class DownloadService {
	public function __construct(
		private readonly PermissionRepository $permissions,
		private readonly VariantRepository $variants,
		private readonly OrderRepository $orders,
		private readonly Clock $clock,
	) {}

	public function permissions(): PermissionRepository {
		return $this->permissions;
	}

	/** Rows for every downloadable line of an open order; existing rows stay. */
	public function grant_for_order( int $order_id ): int {
		$order = $this->orders->find( $order_id );
		return $order !== null ? $this->grant_for_view( $order->view(), true ) : 0;
	}

	/**
	 * The same from the view the order events carry, so a checkout of goods
	 * that ship costs no extra read. Only lines that sold files when the
	 * order was placed are looked at, unless `$every_line` asks for all of
	 * them (the admin's grant button, after a product gained files).
	 */
	public function grant_for_view( OrderView $order, bool $every_line = false ): int {
		if ( ! in_array( $order->status, PermissionRepository::OPEN, true ) ) {
			return 0;
		}
		$rows = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			if ( ! $item->downloadable && ! $every_line ) {
				continue;
			}
			$variant = $item->variant_id !== null ? $this->variants->find( $item->variant_id ) : null;
			if ( $variant === null || ! $variant->sells_files() ) {
				continue;
			}
			$base = $order->paid_at ?? $this->clock->db_now();
			foreach ( $variant->downloads->files as $file ) {
				$rows[] = $this->row( $order->id, $item->id, (int) $item->product_id, $variant->id, $file['id'], $order->customer_id, $order->customer_email, $item->quantity, $variant->downloads->limit, $variant->downloads->expiry_days, $base );
			}
		}
		return $rows !== [] ? $this->permissions->grant( $rows ) : 0;
	}

	/**
	 * After a product save: files that were removed lose their permissions,
	 * and files that were added reach the open orders that bought the variant.
	 */
	public function sync_product( int $product_id, Product $before ): void {
		$old = [];
		foreach ( [ $before->base, ...$before->variations ] as $variant ) {
			$old[ $variant->id ] = $variant->sells_files() ? $variant->downloads->ids() : [];
		}
		foreach ( $this->variants->for_product( $product_id ) as $variant ) {
			if ( ! isset( $old[ $variant->id ] ) ) {
				continue;
			}
			$ids = $variant->sells_files() ? $variant->downloads->ids() : [];
			if ( array_diff( $old[ $variant->id ], $ids ) !== [] ) {
				$this->permissions->delete_missing_files( $variant->id, $ids );
			}
			$added = array_values( array_diff( $ids, $old[ $variant->id ] ) );
			if ( $added === [] ) {
				continue;
			}
			$rows = [];
			foreach ( $this->permissions->open_lines( $variant->id ) as $line ) {
				foreach ( $added as $file_id ) {
					$rows[] = $this->row( $line['order_id'], $line['order_item_id'], $line['product_id'], $variant->id, $file_id, $line['customer_id'], $line['email'], $line['quantity'], $variant->downloads->limit, $variant->downloads->expiry_days, $line['paid_at'] ?? $line['created_at'] );
				}
			}
			$this->permissions->grant( $rows );
		}
	}

	/**
	 * Links an order's customer can use, with the file names, for the order
	 * pages and the emails.
	 *
	 * @return list<array{name:string,product:string,url:string,remaining:?int,expires:?string}>
	 */
	public function links( int $order_id ): array {
		$order = $this->orders->find( $order_id );
		if ( $order === null || ! in_array( $order->status, PermissionRepository::OPEN, true ) ) {
			return [];
		}
		$names = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$names[ $item->id ] = $item->name;
		}
		return $this->present( $this->permissions->for_order( $order_id ), $names );
	}

	/**
	 * A signed-in customer's downloads across their orders.
	 *
	 * @return list<array{name:string,product:string,url:string,remaining:?int,expires:?string}>
	 */
	public function for_customer( int $user_id ): array {
		return $user_id > 0 ? $this->present( $this->permissions->for_customer( $user_id ), [] ) : [];
	}

	/**
	 * Counts a download that is about to start. A request for the rest of a
	 * file (a range that does not start at 0) is not counted again.
	 *
	 * @throws DownloadDenied when the last download was taken in the meantime
	 */
	public function record( Permission $permission, bool $take ): void {
		if ( ! $this->permissions->consume( $permission->id, $this->clock->db_now(), $take ) ) {
			throw new DownloadDenied( __( 'You have used all the downloads for this file.', 'flexacommerce' ), 410 );
		}
		/**
		 * Fires when a file download starts.
		 *
		 * @param Permission $permission as it was before this download
		 * @param bool       $counted    false for the rest of a cut-off download
		 */
		do_action( 'flexacommerce.download.started', $permission, $take );
	}

	/**
	 * The permission behind a link, or the reason it cannot be used.
	 *
	 * @throws DownloadDenied
	 */
	public function resolve( string $token, int $user_id ): Permission {
		$permission = $this->permissions->find_by_token( $token ) ?? throw new DownloadDenied( __( 'This download link is not valid.', 'flexacommerce' ), 404 );
		$order      = $this->orders->find( $permission->order_id );
		if ( $order === null || ! in_array( $order->status, PermissionRepository::OPEN, true ) ) {
			throw new DownloadDenied( __( 'This order does not allow downloads.', 'flexacommerce' ), 403 );
		}
		if ( $permission->is_expired( $this->clock->db_now() ) ) {
			throw new DownloadDenied( __( 'This download link has expired.', 'flexacommerce' ), 410 );
		}
		if ( $permission->is_used_up() ) {
			throw new DownloadDenied( __( 'You have used all the downloads for this file.', 'flexacommerce' ), 410 );
		}
		if ( $this->file( $permission ) === null ) {
			throw new DownloadDenied( __( 'This file is no longer available.', 'flexacommerce' ), 404 );
		}
		if ( (bool) apply_filters( 'flexacommerce.downloads.require_login', false ) && $permission->customer_id !== null && $permission->customer_id !== $user_id ) {
			throw new DownloadDenied( __( 'Sign in to download this file.', 'flexacommerce' ), 403 );
		}
		return $permission;
	}

	/** @return array{id:string,name:string,file:string}|null */
	public function file( Permission $permission ): ?array {
		return $this->variants->find( $permission->variant_id )?->downloads->file( $permission->file_id );
	}

	public static function url( Permission $permission ): string {
		return add_query_arg( 'fxc_download', $permission->token, home_url( '/' ) );
	}

	/**
	 * @param list<Permission>  $permissions
	 * @param array<int,string> $names order line id => product name
	 * @return list<array{name:string,product:string,url:string,remaining:?int,expires:?string}>
	 */
	private function present( array $permissions, array $names ): array {
		$now = $this->clock->db_now();
		$out = [];
		foreach ( $permissions as $permission ) {
			$file = $this->file( $permission );
			if ( $file === null || $permission->is_expired( $now ) ) {
				continue;
			}
			$out[] = [
				'name'      => $file['name'],
				'product'   => $names[ $permission->order_item_id ] ?? get_the_title( $permission->product_id ),
				'url'       => self::url( $permission ),
				'remaining' => $permission->downloads_remaining,
				'expires'   => $permission->access_expires,
			];
		}
		return $out;
	}

	/** @return array{order_id:int,order_item_id:int,product_id:int,variant_id:int,file_id:string,customer_id:?int,email:string,downloads_remaining:?int,access_expires:?string,granted_at:string} */
	private function row( int $order_id, int $item_id, int $product_id, int $variant_id, string $file_id, ?int $customer_id, string $email, int $quantity, ?int $limit, ?int $expiry_days, string $base ): array {
		return [
			'order_id'            => $order_id,
			'order_item_id'       => $item_id,
			'product_id'          => $product_id,
			'variant_id'          => $variant_id,
			'file_id'             => $file_id,
			'customer_id'         => $customer_id,
			'email'               => $email,
			'downloads_remaining' => $limit !== null ? $limit * max( 1, $quantity ) : null,
			'access_expires'      => $expiry_days !== null ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $base . ' UTC' ) + $expiry_days * DAY_IN_SECONDS ) : null,
			'granted_at'          => $this->clock->db_now(),
		];
	}
}
