<?php

declare(strict_types=1);

namespace Flexa\Commerce\Options;

use Flexa\Commerce\Support\IntMath;

defined( 'ABSPATH' ) || exit;

/**
 * One field of an option set (ADR-025): a box to type in, a list to pick
 * from, or a heading between them. A typed field costs its price when it
 * is filled; a choice field costs the price of each choice picked. A
 * percent price is in hundredths (2500 is 25%) of the unit price after
 * pricing rules; a fixed price is in minor units.
 *
 * Date fields (ADR-028) keep `min` and `max` as days from today and post
 * Y-m-d. Color fields always post a color, `#rrggbb`; their `placeholder`
 * is the color picked at the start. Swatches and buttons pick one choice
 * like radio buttons; a swatch choice has a `color`.
 *
 * @phpstan-type Choice array{id:int,label:string,price_mode:string,price:int,default:bool,color:string}
 * @phpstan-type Rule array{field:int,op:string,value:string}
 * @phpstan-type Logic array{action:string,match:string,rules:list<Rule>}
 */
final class OptionField {
	public const TEXT       = 'text';
	public const TEXTAREA   = 'textarea';
	public const NUMBER     = 'number';
	public const SELECT     = 'select';
	public const RADIO      = 'radio';
	public const CHECKBOXES = 'checkboxes';
	public const HEADING    = 'heading';
	public const DATE       = 'date';
	public const COLOR      = 'color';
	public const SWATCH     = 'swatch';
	public const BUTTON     = 'button';
	public const TYPES      = [ self::TEXT, self::TEXTAREA, self::NUMBER, self::DATE, self::COLOR, self::SELECT, self::RADIO, self::CHECKBOXES, self::SWATCH, self::BUTTON, self::HEADING ];
	public const CHOICES    = [ self::SELECT, self::RADIO, self::CHECKBOXES, self::SWATCH, self::BUTTON ];
	public const START      = '#000000';

	public const NONE         = 'none';
	public const FIXED        = 'fixed';
	public const PERCENT      = 'percent';
	public const ONCE         = 'once';
	public const PRICE_MODES  = [ self::NONE, self::FIXED, self::PERCENT, self::ONCE ];
	public const FULL_PERCENT = 10000;

	public const SHOW    = 'show';
	public const HIDE    = 'hide';
	public const ACTIONS = [ self::SHOW, self::HIDE ];
	public const ANY     = 'any';
	public const ALL     = 'all';
	public const MATCHES = [ self::ANY, self::ALL ];

	public const IS        = 'is';
	public const IS_NOT    = 'is_not';
	public const EMPTY     = 'empty';
	public const NOT_EMPTY = 'not_empty';
	public const OPS       = [ self::IS, self::IS_NOT, self::EMPTY, self::NOT_EMPTY ];

	/**
	 * @param list<Choice> $choices with a choice type
	 * @param Logic|null   $logic   when to show the field; rules name earlier fields of the set
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $label,
		public readonly string $help = '',
		public readonly bool $required = false,
		public readonly string $placeholder = '',
		public readonly int $max_length = 0,
		public readonly ?float $min = null,
		public readonly ?float $max = null,
		public readonly ?float $step = null,
		public readonly string $price_mode = self::NONE,
		public readonly int $price = 0,
		public readonly array $choices = [],
		public readonly ?array $logic = null,
	) {}

	/** @param array<string,mixed> $row a stored row, already checked */
	public static function from_array( array $row ): self {
		$one     = static fn ( mixed $value, array $allowed ): string => in_array( $value, $allowed, true ) ? (string) $value : $allowed[0];
		$num     = static fn ( string $key ): ?float => is_numeric( $row[ $key ] ?? null ) ? (float) $row[ $key ] : null;
		$choices = [];
		foreach ( is_array( $row['choices'] ?? null ) ? $row['choices'] : [] as $choice ) {
			if ( is_array( $choice ) ) {
				$choices[] = [
					'id'         => (int) ( $choice['id'] ?? 0 ),
					'label'      => (string) ( $choice['label'] ?? '' ),
					'price_mode' => $one( $choice['price_mode'] ?? null, self::PRICE_MODES ),
					'price'      => max( 0, (int) ( $choice['price'] ?? 0 ) ),
					'default'    => (bool) ( $choice['default'] ?? false ),
					'color'      => (string) ( $choice['color'] ?? '' ),
				];
			}
		}
		$logic = null;
		if ( is_array( $row['logic'] ?? null ) ) {
			$rules = [];
			foreach ( is_array( $row['logic']['rules'] ?? null ) ? $row['logic']['rules'] : [] as $rule ) {
				if ( is_array( $rule ) ) {
					$rules[] = [
						'field' => (int) ( $rule['field'] ?? 0 ),
						'op'    => $one( $rule['op'] ?? null, self::OPS ),
						'value' => (string) ( $rule['value'] ?? '' ),
					];
				}
			}
			$logic = [
				'action' => $one( $row['logic']['action'] ?? null, self::ACTIONS ),
				'match'  => $one( $row['logic']['match'] ?? null, self::MATCHES ),
				'rules'  => $rules,
			];
		}
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			type: $one( $row['type'] ?? null, self::TYPES ),
			label: (string) ( $row['label'] ?? '' ),
			help: (string) ( $row['help'] ?? '' ),
			required: (bool) ( $row['required'] ?? false ),
			placeholder: (string) ( $row['placeholder'] ?? '' ),
			max_length: max( 0, (int) ( $row['max_length'] ?? 0 ) ),
			min: $num( 'min' ),
			max: $num( 'max' ),
			step: $num( 'step' ),
			price_mode: $one( $row['price_mode'] ?? null, self::PRICE_MODES ),
			price: max( 0, (int) ( $row['price'] ?? 0 ) ),
			choices: $choices,
			logic: $logic,
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'type'        => $this->type,
			'label'       => $this->label,
			'help'        => $this->help,
			'required'    => $this->required,
			'placeholder' => $this->placeholder,
			'max_length'  => $this->max_length,
			'min'         => $this->min,
			'max'         => $this->max,
			'step'        => $this->step,
			'price_mode'  => $this->price_mode,
			'price'       => $this->price,
			'choices'     => $this->choices,
			'logic'       => $this->logic,
		];
	}

	public function has_choices(): bool {
		return in_array( $this->type, self::CHOICES, true );
	}

	/** @return Choice|null */
	public function choice( int $id ): ?array {
		foreach ( $this->choices as $choice ) {
			if ( $choice['id'] === $id ) {
				return $choice;
			}
		}
		return null;
	}

	/**
	 * What a value adds to one unit, given the unit price `$base`. A price
	 * charged once per line adds nothing here; see fee().
	 *
	 * @param string|list<string> $value
	 */
	public function charge( string|array $value, int $base ): int {
		return $this->priced( $value, static fn ( string $mode, int $price ): int => self::price_of( $mode, $price, $base ) );
	}

	/**
	 * What a value adds once to the cart line, whatever the quantity.
	 *
	 * @param string|list<string> $value
	 */
	public function fee( string|array $value ): int {
		return $this->priced( $value, static fn ( string $mode, int $price ): int => $mode === self::ONCE ? $price : 0 );
	}

	/**
	 * @param string|list<string>          $value
	 * @param callable(string,int):int $price_of
	 */
	private function priced( string|array $value, callable $price_of ): int {
		if ( ! $this->has_choices() ) {
			return $value === '' || $value === [] ? 0 : $price_of( $this->price_mode, $this->price );
		}
		$total = 0;
		foreach ( (array) $value as $id ) {
			$choice = $this->choice( (int) $id );
			if ( $choice !== null ) {
				$total += $price_of( $choice['price_mode'], $choice['price'] );
			}
		}
		return $total;
	}

	/**
	 * The value as the customer reads it: the text typed, or the names of
	 * the choices picked.
	 *
	 * @param string|list<string> $value
	 */
	public function text_of( string|array $value ): string {
		if ( ! $this->has_choices() ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			if ( $this->type === self::DATE && $value !== '' ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
				return $date !== false ? wp_date( (string) get_option( 'date_format', 'F j, Y' ), $date->getTimestamp() ) ?: $value : $value;
			}
			return $this->type === self::COLOR ? strtoupper( $value ) : $value;
		}
		$names = [];
		foreach ( (array) $value as $id ) {
			$choice = $this->choice( (int) $id );
			if ( $choice !== null ) {
				$names[] = $choice['label'];
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Whether the field shows, given the values of the fields before it
	 * (field id => value). A hidden field counts as empty.
	 *
	 * @param array<int,string|list<string>> $values
	 */
	public function visible( array $values ): bool {
		if ( $this->logic === null || $this->logic['rules'] === [] ) {
			return true;
		}
		$any = $this->logic['match'] === self::ANY;
		$met = ! $any;
		foreach ( $this->logic['rules'] as $rule ) {
			$value = $values[ $rule['field'] ] ?? '';
			$empty = $value === '' || $value === [];
			$is    = is_array( $value ) ? in_array( $rule['value'], $value, true ) : $value === $rule['value'];
			$hit   = match ( $rule['op'] ) {
				self::IS_NOT    => ! $is,
				self::EMPTY     => $empty,
				self::NOT_EMPTY => ! $empty,
				default         => $is,
			};
			if ( $any && $hit ) {
				$met = true;
				break;
			}
			if ( ! $any && ! $hit ) {
				$met = false;
				break;
			}
		}
		return $this->logic['action'] === self::SHOW ? $met : ! $met;
	}

	/**
	 * The earliest and latest dates a date field takes, Y-m-d in the site's
	 * time zone; null for no limit.
	 *
	 * @return array{0:?string,1:?string}
	 */
	public function date_range(): array {
		$today = current_datetime()->setTime( 0, 0 );
		$day   = static fn ( ?float $days ): ?string => $days === null ? null : $today->modify( '+' . (int) $days . ' days' )->format( 'Y-m-d' );
		return [ $day( $this->min ), $day( $this->max ) ];
	}

	public static function price_of( string $mode, int $price, int $base ): int {
		return match ( $mode ) {
			self::FIXED   => $price,
			self::PERCENT => IntMath::mul_div_half_up( max( 0, $base ), min( $price, self::FULL_PERCENT ), self::FULL_PERCENT ),
			default       => 0,
		};
	}
}
