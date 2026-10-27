<?php

declare(strict_types=1);

namespace Flexa\Commerce\Options;

use Flexa\Commerce\Contracts\Exceptions\InvalidOptionSet;

defined( 'ABSPATH' ) || exit;

/**
 * The option sets, in list order, kept in one autoloaded option like the
 * pricing rules: a store has a few, and every product page reads them. A
 * save takes the fields it is sent over the stored set and checks the
 * result.
 */
final class OptionSetRepository {
	public const OPTION = ProductOptions::OPTION;

	public const MAX_SETS    = 200;
	public const MAX_FIELDS  = 20;
	public const MAX_CHOICES = 30;
	public const MAX_RULES   = 10;
	public const MAX_NAME    = 60;
	public const MAX_LABEL   = 80;
	public const MAX_HELP    = 200;
	public const MAX_LIST    = 500;
	public const MAX_ID      = 99999;
	public const MAX_LENGTH  = 1000;
	public const TEXT_LENGTH = 200;
	public const MAX_NUMBER  = 1000000000;
	public const MAX_DAYS    = 3650;

	/** @var list<OptionSet>|null */
	private ?array $all = null;

	/** The option value `$all` was read from; another site or a rollback gives a different one. */
	private mixed $read = null;

	/** @return list<OptionSet> */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( $this->all === null || $stored !== $this->read ) {
			$this->read = $stored;
			$this->all  = [];
			foreach ( is_array( $stored ) ? $stored : [] as $row ) {
				if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
					$this->all[] = OptionSet::from_array( $row );
				}
			}
		}
		return $this->all;
	}

	public function find( int $id ): ?OptionSet {
		foreach ( $this->all() as $set ) {
			if ( $set->id === $id ) {
				return $set;
			}
		}
		return null;
	}

	/**
	 * Adds a set (`$id` 0, at the end of the list) or changes one.
	 *
	 * @param array<string,mixed> $input
	 * @throws InvalidOptionSet
	 */
	public function save( array $input, int $id = 0 ): OptionSet {
		$list = $this->all();
		if ( $id > 0 ) {
			$old = $this->find( $id );
			if ( $old === null ) {
				throw new InvalidOptionSet( __( 'Option set not found.', 'flexacommerce' ), 'id' );
			}
		} else {
			if ( count( $list ) >= self::MAX_SETS ) {
				/* translators: %d: the largest number of option sets */
				throw new InvalidOptionSet( sprintf( __( 'A store can have up to %d option sets.', 'flexacommerce' ), self::MAX_SETS ) );
			}
			$old = new OptionSet( 0, '' );
			$id  = array_reduce( $list, static fn ( int $max, OptionSet $s ): int => max( $max, $s->id ), 0 ) + 1;
		}
		$set = self::checked( array_merge( $old->to_array(), array_intersect_key( $input, $old->to_array() ), [ 'id' => $id ] ) );

		$found = false;
		foreach ( $list as $i => $existing ) {
			if ( $existing->id === $id ) {
				$list[ $i ] = $set;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $set;
		}
		$this->store( $list );
		return $set;
	}

	/** @throws InvalidOptionSet */
	public function delete( int $id ): void {
		if ( $this->find( $id ) === null ) {
			throw new InvalidOptionSet( __( 'Option set not found.', 'flexacommerce' ), 'id' );
		}
		$this->store( array_values( array_filter( $this->all(), static fn ( OptionSet $s ): bool => $s->id !== $id ) ) );
	}

	/**
	 * Puts the sets in the given order; ids it leaves out keep their place
	 * after the given ones.
	 *
	 * @param list<int> $ids
	 */
	public function reorder( array $ids ): void {
		$rank = array_flip( array_values( array_unique( $ids ) ) );
		$list = $this->all();
		$at   = array_flip( array_map( static fn ( OptionSet $s ): int => $s->id, $list ) );
		usort(
			$list,
			static fn ( OptionSet $a, OptionSet $b ): int => [ $rank[ $a->id ] ?? PHP_INT_MAX, $at[ $a->id ] ] <=> [ $rank[ $b->id ] ?? PHP_INT_MAX, $at[ $b->id ] ]
		);
		$this->store( $list );
	}

	/** @param list<OptionSet> $list */
	private function store( array $list ): void {
		$rows = array_map( static fn ( OptionSet $s ): array => $s->to_array(), $list );
		update_option( self::OPTION, $rows, true );
		$this->all  = $list;
		$this->read = get_option( self::OPTION, [] );
		/**
		 * Fires after the option sets change, so a page cache can be cleared.
		 *
		 * @param list<OptionSet> $list the sets in order
		 */
		do_action( 'flexacommerce.option_sets.saved', $list );
	}

	/**
	 * @param array<string,mixed> $in
	 * @throws InvalidOptionSet
	 */
	private static function checked( array $in ): OptionSet {
		$name = self::text( $in['name'], self::MAX_NAME, 'name' );
		if ( $name === '' ) {
			throw new InvalidOptionSet( __( 'Enter a name for the option set.', 'flexacommerce' ), 'name' );
		}
		if ( ! in_array( $in['applies_to'], OptionSet::SCOPES, true ) ) {
			throw new InvalidOptionSet( __( 'Choose one of the listed options.', 'flexacommerce' ), 'applies_to' );
		}
		foreach ( [ 'product_ids', 'category_ids', 'exclude_product_ids' ] as $key ) {
			$in[ $key ] = self::ids( $in[ $key ], $key );
		}
		if ( $in['applies_to'] === OptionSet::PRODUCTS && $in['product_ids'] === [] ) {
			throw new InvalidOptionSet( __( 'Pick at least one product.', 'flexacommerce' ), 'product_ids' );
		}
		if ( $in['applies_to'] === OptionSet::CATEGORIES && $in['category_ids'] === [] ) {
			throw new InvalidOptionSet( __( 'Pick at least one category.', 'flexacommerce' ), 'category_ids' );
		}

		return new OptionSet(
			id: (int) $in['id'],
			name: $name,
			enabled: filter_var( $in['enabled'], FILTER_VALIDATE_BOOL ),
			applies_to: $in['applies_to'],
			product_ids: $in['applies_to'] === OptionSet::PRODUCTS ? $in['product_ids'] : [],
			category_ids: $in['applies_to'] === OptionSet::CATEGORIES ? $in['category_ids'] : [],
			exclude_product_ids: $in['exclude_product_ids'],
			fields: self::fields( $in['fields'] ),
		);
	}

	/**
	 * Fields keep the ids they are sent with, so logic rules can name them;
	 * a field sent without one gets the next free id.
	 *
	 * @return list<OptionField>
	 * @throws InvalidOptionSet
	 */
	private static function fields( mixed $value ): array {
		if ( ! is_array( $value ) || $value === [] ) {
			throw new InvalidOptionSet( __( 'Add at least one field.', 'flexacommerce' ), 'fields' );
		}
		$value = array_values( $value );
		if ( count( $value ) > self::MAX_FIELDS ) {
			/* translators: %d: the largest number of fields */
			throw new InvalidOptionSet( sprintf( __( 'An option set can have up to %d fields.', 'flexacommerce' ), self::MAX_FIELDS ), 'fields' );
		}
		$next = 1;
		foreach ( $value as $row ) {
			$next = max( $next, ( is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0 ) + 1 );
		}

		/** @var array<int,OptionField> $done */
		$done   = [];
		$inputs = 0;
		foreach ( $value as $index => $row ) {
			if ( ! is_array( $row ) ) {
				throw new InvalidOptionSet( __( 'Each field needs a type and a label.', 'flexacommerce' ), 'fields', $index );
			}
			$id = filter_var( $row['id'] ?? 0, FILTER_VALIDATE_INT );
			if ( $id === false || $id < 0 || $id > self::MAX_ID ) {
				throw new InvalidOptionSet( __( 'A field has an invalid ID.', 'flexacommerce' ), 'fields', $index );
			}
			$id = $id === 0 ? $next++ : $id;
			if ( isset( $done[ $id ] ) ) {
				throw new InvalidOptionSet( __( 'Two fields have the same ID.', 'flexacommerce' ), 'fields', $index );
			}
			$field       = self::field( $row, $id, $done, $index );
			$done[ $id ] = $field;
			if ( $field->type !== OptionField::HEADING ) {
				++$inputs;
			}
		}
		if ( $inputs === 0 ) {
			throw new InvalidOptionSet( __( 'Add at least one field a customer can fill in.', 'flexacommerce' ), 'fields' );
		}
		self::check_refs( $done );
		return array_values( $done );
	}

	/**
	 * @param array<array-key,mixed>  $row
	 * @param array<int,OptionField> $before the fields above, by id
	 * @throws InvalidOptionSet
	 */
	private static function field( array $row, int $id, array $before, int $index ): OptionField {
		$type = $row['type'] ?? null;
		if ( ! in_array( $type, OptionField::TYPES, true ) ) {
			throw new InvalidOptionSet( __( 'Choose a field type from the list.', 'flexacommerce' ), 'fields', $index );
		}
		$label = self::text( $row['label'] ?? '', self::MAX_LABEL, 'fields', $index );
		if ( $label === '' ) {
			throw new InvalidOptionSet( __( 'Enter a label for each field.', 'flexacommerce' ), 'fields', $index );
		}
		$help    = self::text( $row['help'] ?? '', self::MAX_HELP, 'fields', $index );
		$heading = $type === OptionField::HEADING;
		$choices = in_array( $type, OptionField::CHOICES, true );
		$typed   = ! $heading && ! $choices;

		$max_length = 0;
		if ( $type === OptionField::TEXT || $type === OptionField::TEXTAREA ) {
			$max_length = filter_var( $row['max_length'] ?? 0, FILTER_VALIDATE_INT );
			if ( $max_length === false || $max_length < 0 || $max_length > self::MAX_LENGTH ) {
				/* translators: %d: the largest character limit */
				throw new InvalidOptionSet( sprintf( __( 'Set a character limit from 1 to %d, or leave it empty.', 'flexacommerce' ), self::MAX_LENGTH ), 'fields', $index );
			}
		}

		[ $min, $max, $step ] = [ null, null, null ];
		if ( $type === OptionField::NUMBER ) {
			$min  = self::number( $row['min'] ?? null, $index );
			$max  = self::number( $row['max'] ?? null, $index );
			$step = self::number( $row['step'] ?? null, $index );
			if ( $min !== null && $max !== null && $max < $min ) {
				throw new InvalidOptionSet( __( 'The largest number must not be below the smallest.', 'flexacommerce' ), 'fields', $index );
			}
			if ( $step !== null && $step <= 0 ) {
				throw new InvalidOptionSet( __( 'The step must be above 0.', 'flexacommerce' ), 'fields', $index );
			}
		}

		if ( $type === OptionField::DATE ) {
			$min = self::days( $row['min'] ?? null, $index );
			$max = self::days( $row['max'] ?? null, $index );
			if ( $min !== null && $max !== null && $max < $min ) {
				throw new InvalidOptionSet( __( 'The latest date must not be before the earliest.', 'flexacommerce' ), 'fields', $index );
			}
		}

		$placeholder = $typed ? self::text( $row['placeholder'] ?? '', self::MAX_LABEL, 'fields', $index ) : '';
		if ( $type === OptionField::COLOR ) {
			if ( $placeholder !== '' && preg_match( '/^#[0-9a-fA-F]{6}$/', $placeholder ) !== 1 ) {
				throw new InvalidOptionSet( __( 'Enter the starting color as #rrggbb.', 'flexacommerce' ), 'fields', $index );
			}
			$placeholder = strtolower( $placeholder );
		}

		[ $mode, $price, $formula ] = $typed
			? self::price( $row, $index )
			: [ OptionField::NONE, 0, '' ];

		return new OptionField(
			id: $id,
			type: $type,
			label: $label,
			help: $help,
			// A color input always posts a color, so it cannot be left empty.
			required: ! $heading && $type !== OptionField::COLOR && filter_var( $row['required'] ?? false, FILTER_VALIDATE_BOOL ),
			placeholder: $placeholder,
			max_length: $max_length,
			min: $min,
			max: $max,
			step: $step,
			price_mode: $mode,
			price: $price,
			choices: $choices ? self::choices( $row['choices'] ?? null, $type, $index ) : [],
			logic: self::logic( $row['logic'] ?? null, $before, $index ),
			formula: $formula,
		);
	}

	/**
	 * @return list<array{id:int,label:string,price_mode:string,price:int,default:bool,color:string,formula:string}>
	 * @throws InvalidOptionSet
	 */
	private static function choices( mixed $value, string $type, int $index ): array {
		if ( ! is_array( $value ) || $value === [] ) {
			throw new InvalidOptionSet( __( 'Add at least one choice.', 'flexacommerce' ), 'fields', $index );
		}
		$value = array_values( $value );
		if ( count( $value ) > self::MAX_CHOICES ) {
			/* translators: %d: the largest number of choices */
			throw new InvalidOptionSet( sprintf( __( 'A field can have up to %d choices.', 'flexacommerce' ), self::MAX_CHOICES ), 'fields', $index );
		}
		$next = 1;
		foreach ( $value as $row ) {
			$next = max( $next, ( is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0 ) + 1 );
		}
		$choices  = [];
		$defaults = 0;
		foreach ( $value as $row ) {
			$row = is_array( $row ) ? $row : [];
			$id  = filter_var( $row['id'] ?? 0, FILTER_VALIDATE_INT );
			if ( $id === false || $id < 0 || $id > self::MAX_ID ) {
				throw new InvalidOptionSet( __( 'A choice has an invalid ID.', 'flexacommerce' ), 'fields', $index );
			}
			$id = $id === 0 ? $next++ : $id;
			if ( isset( $choices[ $id ] ) ) {
				throw new InvalidOptionSet( __( 'Two choices have the same ID.', 'flexacommerce' ), 'fields', $index );
			}
			$label = self::text( $row['label'] ?? '', self::MAX_NAME, 'fields', $index );
			if ( $label === '' ) {
				throw new InvalidOptionSet( __( 'Enter a name for each choice.', 'flexacommerce' ), 'fields', $index );
			}
			$color = '';
			if ( $type === OptionField::SWATCH ) {
				$color = is_string( $row['color'] ?? null ) ? strtolower( trim( $row['color'] ) ) : '';
				if ( preg_match( '/^#[0-9a-f]{6}$/', $color ) !== 1 ) {
					throw new InvalidOptionSet( __( 'Pick a color for each swatch.', 'flexacommerce' ), 'fields', $index );
				}
			}
			[ $mode, $price, $formula ] = self::price( $row, $index );
			$default                    = filter_var( $row['default'] ?? false, FILTER_VALIDATE_BOOL );
			$defaults                  += $default ? 1 : 0;
			$choices[ $id ]             = [
				'id'         => $id,
				'label'      => $label,
				'price_mode' => $mode,
				'price'      => $price,
				'default'    => $default,
				'color'      => $color,
				'formula'    => $formula,
			];
		}
		if ( $defaults > 1 && $type !== OptionField::CHECKBOXES ) {
			throw new InvalidOptionSet( __( 'Only one choice can be picked at the start.', 'flexacommerce' ), 'fields', $index );
		}
		return array_values( $choices );
	}

	/**
	 * Rules name a field above this one; `is` and `is_not` need a field
	 * with choices and one of its choice ids.
	 *
	 * @param array<int,OptionField> $before
	 * @return array{action:string,match:string,rules:list<array{field:int,op:string,value:string}>}|null
	 * @throws InvalidOptionSet
	 */
	private static function logic( mixed $value, array $before, int $index ): ?array {
		if ( ! is_array( $value ) || ! is_array( $value['rules'] ?? null ) || $value['rules'] === [] ) {
			return null;
		}
		$action = $value['action'] ?? OptionField::SHOW;
		$match  = $value['match'] ?? OptionField::ALL;
		if ( ! in_array( $action, OptionField::ACTIONS, true ) || ! in_array( $match, OptionField::MATCHES, true ) ) {
			throw new InvalidOptionSet( __( 'Choose one of the listed options.', 'flexacommerce' ), 'fields', $index );
		}
		if ( count( $value['rules'] ) > self::MAX_RULES ) {
			/* translators: %d: the largest number of conditions */
			throw new InvalidOptionSet( sprintf( __( 'A field can have up to %d conditions.', 'flexacommerce' ), self::MAX_RULES ), 'fields', $index );
		}
		$rules = [];
		foreach ( $value['rules'] as $rule ) {
			$rule   = is_array( $rule ) ? $rule : [];
			$target = $before[ (int) ( $rule['field'] ?? 0 ) ] ?? null;
			if ( $target === null || $target->type === OptionField::HEADING ) {
				throw new InvalidOptionSet( __( 'A condition must name a field above this one.', 'flexacommerce' ), 'fields', $index );
			}
			$op = $rule['op'] ?? null;
			if ( ! in_array( $op, OptionField::OPS, true ) ) {
				throw new InvalidOptionSet( __( 'Choose one of the listed options.', 'flexacommerce' ), 'fields', $index );
			}
			$text = '';
			if ( $op === OptionField::IS || $op === OptionField::IS_NOT ) {
				$choice = is_scalar( $rule['value'] ?? null ) ? $target->choice( (int) $rule['value'] ) : null;
				if ( ! $target->has_choices() || $choice === null ) {
					throw new InvalidOptionSet( __( 'Pick a choice for each condition.', 'flexacommerce' ), 'fields', $index );
				}
				$text = (string) $choice['id'];
			}
			$rules[] = [
				'field' => $target->id,
				'op'    => $op,
				'value' => $text,
			];
		}
		return [
			'action' => $action,
			'match'  => $match,
			'rules'  => $rules,
		];
	}

	/**
	 * The price of a field or a choice: mode, amount, formula.
	 *
	 * @param array<array-key,mixed> $row
	 * @return array{0:string,1:int,2:string}
	 * @throws InvalidOptionSet
	 */
	private static function price( array $row, int $index ): array {
		$mode  = $row['price_mode'] ?? OptionField::NONE;
		$value = $row['price'] ?? 0;
		if ( ! in_array( $mode, OptionField::PRICE_MODES, true ) ) {
			throw new InvalidOptionSet( __( 'Choose one of the listed options.', 'flexacommerce' ), 'fields', $index );
		}
		if ( $mode === OptionField::NONE ) {
			return [ OptionField::NONE, 0, '' ];
		}
		if ( $mode === OptionField::FORMULA ) {
			$formula = is_string( $row['formula'] ?? null ) ? trim( sanitize_text_field( $row['formula'] ) ) : '';
			if ( $formula === '' ) {
				throw new InvalidOptionSet( __( 'Enter a formula for the price.', 'flexacommerce' ), 'fields', $index );
			}
			if ( strlen( $formula ) > Formula::MAX_LENGTH || Formula::refs( $formula ) === null ) {
				/* translators: %s: the formula as entered */
				throw new InvalidOptionSet( sprintf( __( 'The formula %s cannot be worked out. Check its brackets, signs and names.', 'flexacommerce' ), $formula ), 'fields', $index );
			}
			return [ OptionField::FORMULA, 0, $formula ];
		}
		$amount = filter_var( $value, FILTER_VALIDATE_INT );
		if ( $mode === OptionField::PERCENT && ( $amount === false || $amount < 1 || $amount > OptionField::FULL_PERCENT ) ) {
			throw new InvalidOptionSet( __( 'Enter a percent above 0 and up to 100.', 'flexacommerce' ), 'fields', $index );
		}
		if ( $amount === false || $amount < 1 ) {
			throw new InvalidOptionSet( __( 'Enter an amount above 0.', 'flexacommerce' ), 'fields', $index );
		}
		return [ $mode, $amount, '' ];
	}

	/**
	 * A formula reads fields of its own set only, above or below it, and no
	 * heading.
	 *
	 * @param array<int,OptionField> $fields by id
	 * @throws InvalidOptionSet
	 */
	private static function check_refs( array $fields ): void {
		$index = 0;
		foreach ( $fields as $field ) {
			$formulas = [ $field->formula ];
			foreach ( $field->choices as $choice ) {
				$formulas[] = $choice['formula'];
			}
			foreach ( array_filter( $formulas ) as $formula ) {
				foreach ( (array) Formula::refs( $formula ) as $ref ) {
					if ( ! isset( $fields[ $ref ] ) || $fields[ $ref ]->type === OptionField::HEADING ) {
						throw new InvalidOptionSet(
							/* translators: 1: a field reference such as {4}, 2: the formula */
							sprintf( __( 'The formula %2$s uses %1$s, which is not a field of this set.', 'flexacommerce' ), '{' . $ref . '}', $formula ),
							'fields',
							$index
						);
					}
				}
			}
			++$index;
		}
	}

	/** @throws InvalidOptionSet */
	private static function number( mixed $value, int $index ): ?float {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( ! is_numeric( $value ) || abs( (float) $value ) > self::MAX_NUMBER ) {
			throw new InvalidOptionSet( __( 'Enter a number.', 'flexacommerce' ), 'fields', $index );
		}
		return (float) $value;
	}

	/**
	 * Days from today, for the dates a date field offers.
	 *
	 * @throws InvalidOptionSet
	 */
	private static function days( mixed $value, int $index ): ?float {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$days = filter_var( $value, FILTER_VALIDATE_INT );
		if ( $days === false ) {
			$days = is_float( $value ) && floor( $value ) === $value ? (int) $value : false;
		}
		if ( $days === false || $days < 0 || $days > self::MAX_DAYS ) {
			/* translators: %d: the largest number of days */
			throw new InvalidOptionSet( sprintf( __( 'Enter a number of days from 0 to %d.', 'flexacommerce' ), self::MAX_DAYS ), 'fields', $index );
		}
		return (float) $days;
	}

	/** @throws InvalidOptionSet */
	private static function text( mixed $value, int $max, string $field, int $index = -1 ): string {
		$text = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
		if ( mb_strlen( $text ) > $max ) {
			/* translators: %d: the largest number of characters */
			throw new InvalidOptionSet( sprintf( __( 'Keep it to %d characters or fewer.', 'flexacommerce' ), $max ), $field, $index );
		}
		return $text;
	}

	/**
	 * @return list<int>
	 * @throws InvalidOptionSet
	 */
	private static function ids( mixed $value, string $field ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidOptionSet( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
		}
		$ids = [];
		foreach ( $value as $id ) {
			$id = filter_var( $id, FILTER_VALIDATE_INT );
			if ( $id === false || $id <= 0 ) {
				throw new InvalidOptionSet( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
			}
			$ids[ $id ] = $id;
		}
		if ( count( $ids ) > self::MAX_LIST ) {
			throw new InvalidOptionSet( __( 'The list is too long.', 'flexacommerce' ), $field );
		}
		return array_values( $ids );
	}
}
