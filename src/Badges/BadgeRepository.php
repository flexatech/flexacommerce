<?php

declare(strict_types=1);

namespace Flexa\Commerce\Badges;

use Flexa\Commerce\Contracts\Exceptions\InvalidBadge;

defined( 'ABSPATH' ) || exit;

/**
 * The badges, in the order they stack, kept in one autoloaded option: a
 * store has a handful, and every product list reads them. A save takes
 * the fields it is sent over the stored badge and checks the result.
 */
final class BadgeRepository {
	public const OPTION = 'flexacommerce_badges';

	public const MAX_BADGES = 50;
	public const MAX_LABEL  = 40;
	public const MAX_LIST   = 500;
	public const MAX_DAYS   = 365;

	/** @var list<Badge>|null */
	private ?array $all = null;

	/** The option value `$all` was read from; another site or a rollback gives a different one. */
	private mixed $read = null;

	/** @return list<Badge> */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( $this->all === null || $stored !== $this->read ) {
			$this->read = $stored;
			$this->all  = [];
			foreach ( is_array( $stored ) ? $stored : [] as $row ) {
				if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
					$this->all[] = Badge::from_array( $row );
				}
			}
		}
		return $this->all;
	}

	public function find( int $id ): ?Badge {
		foreach ( $this->all() as $badge ) {
			if ( $badge->id === $id ) {
				return $badge;
			}
		}
		return null;
	}

	/**
	 * Adds a badge (`$id` 0, at the end of the list) or changes one.
	 *
	 * @param array<string,mixed> $input
	 * @throws InvalidBadge
	 */
	public function save( array $input, int $id = 0 ): Badge {
		$list = $this->all();
		if ( $id > 0 ) {
			$old = $this->find( $id );
			if ( $old === null ) {
				throw new InvalidBadge( __( 'Badge not found.', 'flexacommerce' ), 'id' );
			}
		} else {
			if ( count( $list ) >= self::MAX_BADGES ) {
				/* translators: %d: the largest number of badges */
				throw new InvalidBadge( sprintf( __( 'A store can have up to %d badges.', 'flexacommerce' ), self::MAX_BADGES ) );
			}
			$old = new Badge( 0, '' );
			$id  = array_reduce( $list, static fn ( int $max, Badge $b ): int => max( $max, $b->id ), 0 ) + 1;
		}
		$badge = self::checked( array_merge( $old->to_array(), array_intersect_key( $input, $old->to_array() ), [ 'id' => $id ] ) );

		$found = false;
		foreach ( $list as $i => $existing ) {
			if ( $existing->id === $id ) {
				$list[ $i ] = $badge;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $badge;
		}
		$this->store( $list );
		return $badge;
	}

	/** @throws InvalidBadge */
	public function delete( int $id ): void {
		if ( $this->find( $id ) === null ) {
			throw new InvalidBadge( __( 'Badge not found.', 'flexacommerce' ), 'id' );
		}
		$this->store( array_values( array_filter( $this->all(), static fn ( Badge $b ): bool => $b->id !== $id ) ) );
	}

	/**
	 * Puts the badges in the given order; ids it leaves out keep their
	 * place after the given ones.
	 *
	 * @param list<int> $ids
	 */
	public function reorder( array $ids ): void {
		$rank = array_flip( array_values( array_unique( $ids ) ) );
		$list = $this->all();
		$at   = array_flip( array_map( static fn ( Badge $b ): int => $b->id, $list ) );
		usort(
			$list,
			static fn ( Badge $a, Badge $b ): int => [ $rank[ $a->id ] ?? PHP_INT_MAX, $at[ $a->id ] ] <=> [ $rank[ $b->id ] ?? PHP_INT_MAX, $at[ $b->id ] ]
		);
		$this->store( $list );
	}

	/** @param list<Badge> $list */
	private function store( array $list ): void {
		$rows = array_map( static fn ( Badge $b ): array => $b->to_array(), $list );
		update_option( self::OPTION, $rows, true );
		$this->all  = $list;
		$this->read = get_option( self::OPTION, [] );
		/**
		 * Fires after the badges change, so a page cache can be cleared.
		 *
		 * @param list<Badge> $list the badges in order
		 */
		do_action( 'flexacommerce.badges.saved', $list );
	}

	/**
	 * @param array<string,mixed> $in
	 * @throws InvalidBadge
	 */
	private static function checked( array $in ): Badge {
		$label = trim( sanitize_text_field( is_scalar( $in['label'] ) ? (string) $in['label'] : '' ) );
		if ( $label === '' ) {
			throw new InvalidBadge( __( 'Enter the text of the badge.', 'flexacommerce' ), 'label' );
		}
		if ( mb_strlen( $label ) > self::MAX_LABEL ) {
			/* translators: %d: the largest number of characters */
			throw new InvalidBadge( sprintf( __( 'Keep the text to %d characters or fewer.', 'flexacommerce' ), self::MAX_LABEL ), 'label' );
		}
		foreach ( [ 'bg_color', 'text_color' ] as $field ) {
			$color = is_string( $in[ $field ] ) ? sanitize_hex_color( strtolower( trim( $in[ $field ] ) ) ) : null;
			if ( ! is_string( $color ) || strlen( $color ) !== 7 ) {
				throw new InvalidBadge( __( 'Enter a colour as #rrggbb.', 'flexacommerce' ), $field );
			}
			$in[ $field ] = $color;
		}
		foreach (
			[
				'position'   => Badge::POSITIONS,
				'shape'      => Badge::SHAPES,
				'condition'  => Badge::CONDITIONS,
				'applies_to' => Badge::SCOPES,
				'places'     => Badge::PLACES,
				'customers'  => Badge::CUSTOMERS,
			] as $field => $allowed
		) {
			if ( ! in_array( $in[ $field ], $allowed, true ) ) {
				throw new InvalidBadge( __( 'Choose one of the listed options.', 'flexacommerce' ), $field );
			}
		}
		$days = filter_var( $in['new_days'], FILTER_VALIDATE_INT );
		if ( $days === false || $days < 1 || $days > self::MAX_DAYS ) {
			/* translators: %d: the largest number of days */
			throw new InvalidBadge( sprintf( __( 'Enter a number of days from 1 to %d.', 'flexacommerce' ), self::MAX_DAYS ), 'new_days' );
		}
		foreach ( [ 'product_ids', 'category_ids', 'exclude_product_ids' ] as $field ) {
			$in[ $field ] = self::ids( $in[ $field ], $field );
		}
		if ( $in['applies_to'] === Badge::PRODUCTS && $in['product_ids'] === [] ) {
			throw new InvalidBadge( __( 'Pick at least one product.', 'flexacommerce' ), 'product_ids' );
		}
		if ( $in['applies_to'] === Badge::CATEGORIES && $in['category_ids'] === [] ) {
			throw new InvalidBadge( __( 'Pick at least one category.', 'flexacommerce' ), 'category_ids' );
		}
		$in['roles']     = $in['customers'] === Badge::ROLES ? self::roles( $in['roles'] ) : [];
		$in['starts_at'] = self::date( $in['starts_at'], 'starts_at' );
		$in['ends_at']   = self::date( $in['ends_at'], 'ends_at' );
		if ( $in['starts_at'] !== null && $in['ends_at'] !== null && $in['ends_at'] <= $in['starts_at'] ) {
			throw new InvalidBadge( __( 'The end must be after the start.', 'flexacommerce' ), 'ends_at' );
		}
		return new Badge(
			id: (int) $in['id'],
			label: $label,
			bg_color: $in['bg_color'],
			text_color: $in['text_color'],
			position: $in['position'],
			shape: $in['shape'],
			enabled: filter_var( $in['enabled'], FILTER_VALIDATE_BOOL ),
			condition: $in['condition'],
			new_days: $days,
			applies_to: $in['applies_to'],
			product_ids: $in['product_ids'],
			category_ids: $in['category_ids'],
			exclude_product_ids: $in['exclude_product_ids'],
			places: $in['places'],
			starts_at: $in['starts_at'],
			ends_at: $in['ends_at'],
			customers: $in['customers'],
			roles: $in['roles'],
		);
	}

	/**
	 * @return list<string>
	 * @throws InvalidBadge
	 */
	private static function roles( mixed $value ): array {
		$roles = [];
		foreach ( is_array( $value ) ? $value : [] as $role ) {
			if ( ! is_string( $role ) || ! wp_roles()->is_role( $role ) ) {
				throw new InvalidBadge( __( 'Choose roles from the list.', 'flexacommerce' ), 'roles' );
			}
			$roles[ $role ] = $role;
		}
		if ( $roles === [] ) {
			throw new InvalidBadge( __( 'Pick at least one role.', 'flexacommerce' ), 'roles' );
		}
		return array_values( $roles );
	}

	/**
	 * @return list<int>
	 * @throws InvalidBadge
	 */
	private static function ids( mixed $value, string $field ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidBadge( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
		}
		$ids = [];
		foreach ( $value as $id ) {
			$id = filter_var( $id, FILTER_VALIDATE_INT );
			if ( $id === false || $id <= 0 ) {
				throw new InvalidBadge( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
			}
			$ids[ $id ] = $id;
		}
		if ( count( $ids ) > self::MAX_LIST ) {
			throw new InvalidBadge( __( 'The list is too long.', 'flexacommerce' ), $field );
		}
		return array_values( $ids );
	}

	/** @throws InvalidBadge */
	private static function date( mixed $value, string $field ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$text = is_string( $value ) ? rtrim( str_replace( 'T', ' ', preg_replace( '/\.\d+Z?$/', 'Z', $value ) ?? '' ), 'Z' ) : '';
		foreach ( [ 'Y-m-d H:i:s', 'Y-m-d H:i' ] as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $text, new \DateTimeZone( 'UTC' ) );
			if ( $date !== false && $date->format( $format ) === $text ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
		throw new InvalidBadge( __( 'Enter a valid date and time.', 'flexacommerce' ), $field );
	}
}
