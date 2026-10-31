<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Exceptions\RateLimited;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters in transients (doc 06 §2.9). Nothing is written
 * until an action is counted, so pages that only read never touch the
 * options table. Each limit is filterable as
 * `flexacommerce.rate_limit.{name}` with `['limit' => n, 'window' => seconds]`;
 * a limit of 0 turns it off.
 */
final class RateLimiter {
	public const PREFIX = 'fxc_rl_';

	/** name => [limit, window seconds] */
	public const DEFAULTS = [
		'checkout_session' => [ 10, 600 ],
		'checkout_ip'      => [ 30, 600 ],
		'order_key_ip'     => [ 10, 3600 ],
		'order_lookup_ip'  => [ 10, 3600 ],
	];

	public function __construct( private readonly Clock $clock = new Clock() ) {}

	/**
	 * Counts one action and throws once the limit is passed.
	 *
	 * @throws RateLimited
	 */
	public function hit( string $name, string $subject ): void {
		$this->check( $name, $subject );
		$this->count( $name, $subject );
	}

	/**
	 * Throws when the subject has used up the limit, without counting.
	 *
	 * @throws RateLimited
	 */
	public function check( string $name, string $subject ): void {
		[ $limit ] = $this->limit( $name );
		$state     = $this->state( $name, $subject );
		if ( $limit > 0 && $state !== null && $state['count'] >= $limit ) {
			throw new RateLimited( max( 1, $state['until'] - $this->clock->now()->getTimestamp() ) );
		}
	}

	/** Records one action, for limits that count failures only. */
	public function count( string $name, string $subject ): void {
		[ $limit, $window ] = $this->limit( $name );
		if ( $limit <= 0 || $subject === '' ) {
			return;
		}
		$now   = $this->clock->now()->getTimestamp();
		$state = $this->state( $name, $subject ) ?? [
			'count' => 0,
			'until' => $now + $window,
		];
		++$state['count'];
		set_transient( self::key( $name, $subject ), $state, max( 1, $state['until'] - $now ) );
	}

	/** Actions counted in the open window, 0 when none is open. */
	public function attempts( string $name, string $subject ): int {
		return $this->state( $name, $subject )['count'] ?? 0;
	}

	public function reset( string $name, string $subject ): void {
		delete_transient( self::key( $name, $subject ) );
	}

	/** @return array{0:int,1:int} limit and window in seconds */
	public function limit( string $name ): array {
		$default = self::DEFAULTS[ $name ] ?? [ 0, 60 ];
		$value   = apply_filters(
			'flexacommerce.rate_limit.' . $name,
			[
				'limit'  => $default[0],
				'window' => $default[1],
			]
		);
		$value   = is_array( $value ) ? $value : [];
		return [ max( 0, (int) ( $value['limit'] ?? $default[0] ) ), max( 1, (int) ( $value['window'] ?? $default[1] ) ) ];
	}

	/** @return array{count:int,until:int}|null null when no window is open */
	private function state( string $name, string $subject ): ?array {
		if ( $subject === '' ) {
			return null;
		}
		$stored = get_transient( self::key( $name, $subject ) );
		if ( ! is_array( $stored ) || (int) ( $stored['until'] ?? 0 ) <= $this->clock->now()->getTimestamp() ) {
			return null;
		}
		return [
			'count' => (int) ( $stored['count'] ?? 0 ),
			'until' => (int) $stored['until'],
		];
	}

	/** Hashed so neither IPs nor tokens are stored in the option name. */
	private static function key( string $name, string $subject ): string {
		return self::PREFIX . substr( hash( 'sha256', $name . '|' . $subject . '|' . wp_salt( 'nonce' ) ), 0, 32 );
	}
}
