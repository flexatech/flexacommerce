<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Contracts\Exceptions\InvalidPriceRule;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce pricing-rule list [--status=<status>] [--type=<type>] [--fields=<fields>] [--format=<format>]
 *   wp flexacommerce pricing-rule enable <id>...
 *   wp flexacommerce pricing-rule disable <id>...
 *   wp flexacommerce pricing-rule delete <id>... [--yes]
 *
 * The same repository as the Promotions screen, so a change from here
 * checks the rule the same way and clears page caches the same way.
 */
final class PriceRuleCommand {
	public const FIELDS = [ 'id', 'name', 'status', 'type', 'change', 'products', 'customers', 'starts', 'ends' ];

	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		foreach ( [ 'list', 'enable', 'disable', 'delete' ] as $command ) {
			WP_CLI::add_command( 'flexacommerce pricing-rule ' . $command, [ $instance, $command === 'list' ? 'list_rules' : $command ] );
		}
	}

	/**
	 * Lists the pricing rules in the order they are checked.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only rules that apply now (live), start later (scheduled), are past their end (ended) or are turned off (off).
	 * ---
	 * options:
	 *   - live
	 *   - scheduled
	 *   - ended
	 *   - "off"
	 * ---
	 *
	 * [--type=<type>]
	 * : Only rules of one type.
	 * ---
	 * options:
	 *   - adjust
	 *   - bulk
	 *   - graduated
	 *   - fee
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns: id, name, status, type, change, products, customers, starts, ends.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce pricing-rule list --status=live
	 *     wp flexacommerce pricing-rule disable $(wp flexacommerce pricing-rule list --type=fee --format=ids)
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function list_rules( array $args, array $assoc_args ): void {
		unset( $args );
		$rows   = self::rows( self::repository()->all(), gmdate( 'Y-m-d H:i:s' ), Plugin::instance()->cart()->pricing() );
		$rows   = array_values(
			array_filter(
				$rows,
				static fn ( array $row ): bool => ( ! isset( $assoc_args['status'] ) || $row['status'] === $assoc_args['status'] )
					&& ( ! isset( $assoc_args['type'] ) || $row['type'] === $assoc_args['type'] )
			)
		);
		$format = $assoc_args['format'] ?? 'table';
		if ( $format === 'ids' ) {
			WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}
		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : self::FIELDS;
		$wrong  = array_diff( $fields, self::FIELDS );
		if ( $wrong !== [] ) {
			WP_CLI::error( sprintf( 'Unknown field: %s. Use %s.', implode( ', ', $wrong ), implode( ', ', self::FIELDS ) ) );
		}
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Turns pricing rules on.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more rule ids, from `wp flexacommerce pricing-rule list`.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function enable( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$this->switch_rules( $args, true );
	}

	/**
	 * Turns pricing rules off. They keep their settings and can be turned on again.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more rule ids, from `wp flexacommerce pricing-rule list`.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function disable( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$this->switch_rules( $args, false );
	}

	/**
	 * Deletes pricing rules. Orders placed with them keep their prices.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more rule ids, from `wp flexacommerce pricing-rule list`.
	 *
	 * [--yes]
	 * : Delete without asking.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		$repository          = self::repository();
		[ $rules, $missing ] = self::found( $repository, $args );
		if ( $rules === [] ) {
			WP_CLI::error( self::not_found( $missing ) );
		}
		$names = implode( ', ', array_map( static fn ( PriceRule $r ): string => sprintf( '%d "%s"', $r->id, $r->name ), $rules ) );
		WP_CLI::confirm( sprintf( 'Delete %s: %s?', count( $rules ) === 1 ? 'pricing rule' : count( $rules ) . ' pricing rules', $names ), $assoc_args );
		foreach ( $rules as $rule ) {
			$repository->delete( $rule->id );
		}
		self::finish( sprintf( 'Deleted %s.', $names ), $missing );
	}

	/**
	 * One row per rule, as the list shows it. Dates are in the site's time zone.
	 *
	 * @param list<PriceRule> $rules
	 * @return list<array<string,int|string>>
	 */
	public static function rows( array $rules, string $now, PricingService $pricing ): array {
		$local = static fn ( ?string $utc ): string => $utc === null ? '' : get_date_from_gmt( $utc, 'Y-m-d H:i' );
		return array_map(
			static fn ( PriceRule $rule ): array => [
				'id'        => $rule->id,
				'name'      => $rule->name,
				'status'    => self::status( $rule, $now ),
				'type'      => $rule->type,
				'change'    => self::change( $rule, $pricing ),
				'products'  => self::products( $rule ),
				'customers' => match ( $rule->customers ) {
					PriceRule::LOGGED_IN => 'logged in',
					PriceRule::ROLES     => implode( ', ', $rule->roles ),
					default              => 'everyone',
				},
				'starts'    => $local( $rule->starts_at ),
				'ends'      => $local( $rule->ends_at ),
			],
			$rules
		);
	}

	/** Off, ended, scheduled or live, the words of the Promotions screen. */
	public static function status( PriceRule $rule, string $now ): string {
		return match ( true ) {
			! $rule->enabled                                         => 'off',
			$rule->ends_at !== null && $rule->ends_at <= $now        => 'ended',
			$rule->starts_at !== null && $now < $rule->starts_at     => 'scheduled',
			default                                                  => 'live',
		};
	}

	/** What the rule does to the price: "-10%", "5+ -10%, 10+ -20%", "+$2.00 fee". */
	private static function change( PriceRule $rule, PricingService $pricing ): string {
		$one = static function ( string $mode, int $amount, string $sign ) use ( $pricing ): string {
			return match ( $mode ) {
				PriceRule::PERCENT => $sign . rtrim( rtrim( number_format( $amount / 100, 2, '.', '' ), '0' ), '.' ) . '%',
				PriceRule::FIXED   => $sign . $pricing->format( $pricing->money( $amount ) ),
				default            => 'price ' . $pricing->format( $pricing->money( $amount ) ),
			};
		};
		if ( $rule->has_tiers() ) {
			$tiers = array_map( static fn ( array $t ): string => $t['min'] . '+ ' . $one( $t['mode'], $t['amount'], '-' ), $rule->tiers );
			return ( $rule->type === PriceRule::GRADUATED ? 'each unit at its tier: ' : '' ) . implode( ', ', $tiers );
		}
		if ( $rule->is_fee() ) {
			$cap = $rule->mode === PriceRule::PERCENT && $rule->cap > 0 ? ' (at most ' . $pricing->format( $pricing->money( $rule->cap ) ) . ')' : '';
			return $one( $rule->mode, $rule->amount, '+' ) . ' fee' . $cap;
		}
		return $one( $rule->mode, $rule->amount, '-' );
	}

	private static function products( PriceRule $rule ): string {
		$text = match ( $rule->applies_to ) {
			PriceRule::PRODUCTS   => self::count( count( $rule->product_ids ), 'product' ),
			PriceRule::CATEGORIES => self::count( count( $rule->category_ids ), 'category', 'categories' ),
			default               => 'all products',
		};
		$out = count( $rule->exclude_product_ids );
		return $out > 0 ? sprintf( '%s, %d left out', $text, $out ) : $text;
	}

	private static function count( int $n, string $one, string $many = '' ): string {
		return $n . ' ' . ( $n === 1 ? $one : ( $many !== '' ? $many : $one . 's' ) );
	}

	/** @param array<int,string> $args */
	private function switch_rules( array $args, bool $on ): void {
		$repository          = self::repository();
		[ $rules, $missing ] = self::found( $repository, $args );
		if ( $rules === [] ) {
			WP_CLI::error( self::not_found( $missing ) );
		}
		$changed = [];
		$failed  = false;
		foreach ( $rules as $rule ) {
			if ( $rule->enabled === $on ) {
				WP_CLI::log( sprintf( 'Rule %d "%s" is already %s.', $rule->id, $rule->name, $on ? 'on' : 'off' ) );
				continue;
			}
			try {
				$repository->save( [ 'enabled' => $on ], $rule->id );
				$changed[] = sprintf( '%d "%s"', $rule->id, $rule->name );
			} catch ( InvalidPriceRule $e ) {
				// A rule saved before a role was removed, say: fix it on the Promotions screen.
				WP_CLI::warning( sprintf( 'Rule %d "%s" was not changed: %s', $rule->id, $rule->name, $e->getMessage() ) );
				$failed = true;
			}
		}
		if ( $changed !== [] ) {
			WP_CLI::success( sprintf( 'Turned %s %s.', $on ? 'on' : 'off', implode( ', ', $changed ) ) );
		}
		self::finish( '', $missing, $failed );
	}

	/**
	 * @param array<int,string> $args
	 * @return array{0:list<PriceRule>,1:list<string>}
	 */
	private static function found( PriceRuleRepository $repository, array $args ): array {
		$rules   = [];
		$missing = [];
		foreach ( array_unique( $args ) as $arg ) {
			$rule = ctype_digit( $arg ) ? $repository->find( (int) $arg ) : null;
			if ( $rule === null ) {
				$missing[] = $arg;
			} else {
				$rules[] = $rule;
			}
		}
		return [ $rules, $missing ];
	}

	/** @param list<string> $missing */
	private static function not_found( array $missing ): string {
		return sprintf( 'No pricing rule with id %s. See `wp flexacommerce pricing-rule list`.', implode( ', ', $missing ) );
	}

	/** @param list<string> $missing */
	private static function finish( string $done, array $missing, bool $failed = false ): void {
		if ( $done !== '' ) {
			WP_CLI::success( $done );
		}
		if ( $missing !== [] ) {
			WP_CLI::warning( self::not_found( $missing ) );
		}
		if ( $missing !== [] || $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	private static function repository(): PriceRuleRepository {
		return Plugin::instance()->price_rule_repository();
	}
}
