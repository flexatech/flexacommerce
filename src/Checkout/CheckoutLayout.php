<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of the checkout page as the merchant arranged them in the
 * builder (doc 11, ADR-031): per section the order, labels, widths and
 * answers of the core fields, and the custom fields. FieldSchema applies
 * it before the `flexacommerce.checkout.fields` filter.
 *
 * Every section always holds all of its core fields: one missing from the
 * stored list is added at the end with its defaults.
 *
 * @phpstan-type Choice array{value:string,label:string}
 * @phpstan-type Entry array{id:string,label:string,placeholder:string,help:string,width:string,answer:string,custom:bool,type?:string,options?:list<Choice>,email?:bool}
 * @api
 */
final class CheckoutLayout {
	public const OPTION = 'flexacommerce_checkout_layout';

	public const SECTIONS = [ 'billing', 'shipping', 'additional' ];

	public const REQUIRED = 'required';
	public const OPTIONAL = 'optional';
	public const HIDDEN   = 'hidden';

	public const FULL = 'full';
	public const HALF = 'half';

	public const CUSTOM_TYPES = [ 'text', 'textarea', 'select', 'radio', 'checkbox', 'date', 'number', 'tel', 'email' ];

	/** Core fields of each section, in their default order. */
	public const CORE = [
		'billing'    => [ 'email', 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ],
		'shipping'   => [ 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ],
		'additional' => [ 'customer_note' ],
	];

	/** Always shown and required: the order needs them. */
	public const LOCKED = [ 'country', 'email' ];

	/** Follow the country (some have no states or postcodes): shown or hidden only. */
	public const BY_COUNTRY = [ 'state', 'postcode' ];

	public const MAX_CUSTOM  = 30;
	public const MAX_CHOICES = 30;
	public const LABEL_MAX   = 80;
	public const HELP_MAX    = 200;

	private const DEFAULT_ANSWERS = [
		'company'       => self::HIDDEN,
		'address_2'     => self::OPTIONAL,
		'phone'         => self::OPTIONAL,
		'customer_note' => self::OPTIONAL,
	];

	private const HALF_BY_DEFAULT = [ 'first_name', 'last_name' ];

	private static ?self $memo     = null;
	private static mixed $memo_raw = null;

	/** @param array<string,list<Entry>> $fields */
	private function __construct( private readonly array $fields ) {}

	public static function default(): self {
		return self::from_array( [] );
	}

	/** The stored layout, read and normalised once per request. */
	public static function current(): self {
		$raw = get_option( self::OPTION, [] );
		if ( self::$memo === null || self::$memo_raw !== $raw ) {
			$layout         = self::from_array( is_array( $raw ) ? $raw : [] );
			$filtered       = apply_filters( 'flexacommerce.checkout.layout', $layout );
			self::$memo     = $filtered instanceof self ? $filtered : $layout;
			self::$memo_raw = $raw;
		}
		return self::$memo;
	}

	/**
	 * Checks a layout from the builder, stores it and returns it normalised.
	 *
	 * @param array<mixed> $data
	 * @throws InvalidCheckoutLayout When an entry breaks a rule.
	 */
	public static function save( array $data ): self {
		$layout = self::from_array( $data, true );
		update_option( self::OPTION, $layout->to_array(), false );
		self::$memo = null;
		do_action( 'flexacommerce.checkout.layout.saved', $layout );
		return $layout;
	}

	/** Back to the default page. */
	public static function reset(): self {
		delete_option( self::OPTION );
		self::$memo = null;
		$layout     = self::default();
		do_action( 'flexacommerce.checkout.layout.saved', $layout );
		return $layout;
	}

	/**
	 * With `$strict`, a broken entry throws; without, it is dropped, so a
	 * damaged option never breaks the checkout page.
	 *
	 * @param array<mixed> $data `{fields:{billing:[...],shipping:[...],additional:[...]}}`
	 * @throws InvalidCheckoutLayout In strict mode, when an entry breaks a rule.
	 */
	public static function from_array( array $data, bool $strict = false ): self {
		$sections = is_array( $data['fields'] ?? null ) ? $data['fields'] : [];
		$fields   = [];
		foreach ( self::SECTIONS as $section ) {
			$fields[ $section ] = self::normalise( $section, is_array( $sections[ $section ] ?? null ) ? array_values( $sections[ $section ] ) : [], $strict );
		}
		return new self( $fields );
	}

	/** @return array{fields:array<string,list<Entry>>} */
	public function to_array(): array {
		return [ 'fields' => $this->fields ];
	}

	/** @return list<Entry> in display order */
	public function section( string $section ): array {
		return $this->fields[ $section ] ?? [];
	}

	/** @return Entry|null */
	public function entry( string $section, string $id ): ?array {
		foreach ( $this->section( $section ) as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/** @return Entry|null a field the merchant added, not a core one */
	public function custom( string $section, string $id ): ?array {
		$entry = $this->entry( $section, $id );
		return $entry !== null && $entry['custom'] ? $entry : null;
	}

	public function is_default(): bool {
		return $this->fields === self::default()->fields;
	}

	/**
	 * @param list<mixed> $entries
	 * @return list<Entry>
	 * @throws InvalidCheckoutLayout In strict mode.
	 */
	private static function normalise( string $section, array $entries, bool $strict ): array {
		$out    = [];
		$seen   = [];
		$custom = 0;
		foreach ( $entries as $index => $raw ) {
			$path = $section . '.' . $index;
			try {
				if ( ! is_array( $raw ) || ! is_string( $raw['id'] ?? null ) ) {
					throw new InvalidCheckoutLayout( __( 'Each field needs an id.', 'flexacommerce' ), $path . '.id' );
				}
				$id = $raw['id'];
				if ( isset( $seen[ $id ] ) ) {
					/* translators: %s: field id */
					throw new InvalidCheckoutLayout( sprintf( __( 'The field "%s" is in this section twice.', 'flexacommerce' ), $id ), $path . '.id' );
				}
				if ( in_array( $id, self::CORE[ $section ], true ) ) {
					$entry = self::core( $id, $raw, $strict, $path );
				} else {
					if ( ++$custom > self::MAX_CUSTOM ) {
						/* translators: %d: maximum number of fields */
						throw new InvalidCheckoutLayout( sprintf( __( 'A section can hold at most %d fields of your own.', 'flexacommerce' ), self::MAX_CUSTOM ), $path . '.id' );
					}
					$entry = self::custom_entry( $id, $raw, $strict, $path );
				}
			} catch ( InvalidCheckoutLayout $e ) {
				if ( $strict ) {
					throw $e;
				}
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $entry;
		}
		foreach ( self::CORE[ $section ] as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$out[] = self::core( $id, [], false, '' );
			}
		}
		return $out;
	}

	/**
	 * @param array<mixed> $raw
	 * @return Entry
	 * @throws InvalidCheckoutLayout In strict mode.
	 */
	private static function core( string $id, array $raw, bool $strict, string $path ): array {
		$fallback = self::DEFAULT_ANSWERS[ $id ] ?? self::REQUIRED;
		$answer   = self::choice( $raw['answer'] ?? null, [ self::REQUIRED, self::OPTIONAL, self::HIDDEN ], $fallback, $strict, $path . '.answer' );
		if ( in_array( $id, self::LOCKED, true ) && $answer !== self::REQUIRED ) {
			if ( $strict ) {
				throw new InvalidCheckoutLayout( __( 'Country and email are always shown and required.', 'flexacommerce' ), $path . '.answer' );
			}
			$answer = self::REQUIRED;
		}
		if ( in_array( $id, self::BY_COUNTRY, true ) && $answer === self::OPTIONAL ) {
			if ( $strict ) {
				throw new InvalidCheckoutLayout( __( 'State and postcode follow the country: they can be shown or hidden.', 'flexacommerce' ), $path . '.answer' );
			}
			$answer = self::REQUIRED;
		}
		return [
			'id'          => $id,
			'label'       => self::text( $raw['label'] ?? '', self::LABEL_MAX, $strict, $path . '.label' ),
			'placeholder' => self::text( $raw['placeholder'] ?? '', self::LABEL_MAX, $strict, $path . '.placeholder' ),
			'help'        => self::text( $raw['help'] ?? '', self::HELP_MAX, $strict, $path . '.help' ),
			'width'       => $id === 'customer_note' ? self::FULL : self::choice( $raw['width'] ?? null, [ self::FULL, self::HALF ], in_array( $id, self::HALF_BY_DEFAULT, true ) ? self::HALF : self::FULL, $strict, $path . '.width' ),
			'answer'      => $answer,
			'custom'      => false,
		];
	}

	/**
	 * @param array<mixed> $raw
	 * @return Entry
	 * @throws InvalidCheckoutLayout When the entry breaks a rule.
	 */
	private static function custom_entry( string $id, array $raw, bool $strict, string $path ): array {
		if ( preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $id ) !== 1 || in_array( $id, FieldSchema::CORE, true ) || $id === 'customer_note' ) {
			/* translators: %s: field id */
			throw new InvalidCheckoutLayout( sprintf( __( 'The field id "%s" cannot be used.', 'flexacommerce' ), $id ), $path . '.id' );
		}
		$type = $raw['type'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, self::CUSTOM_TYPES, true ) ) {
			throw new InvalidCheckoutLayout( __( 'Choose a field type.', 'flexacommerce' ), $path . '.type' );
		}
		$label = self::text( $raw['label'] ?? '', self::LABEL_MAX, $strict, $path . '.label' );
		if ( $label === '' ) {
			throw new InvalidCheckoutLayout( __( 'Give the field a label.', 'flexacommerce' ), $path . '.label' );
		}
		$entry = [
			'id'          => $id,
			'label'       => $label,
			'placeholder' => in_array( $type, [ 'checkbox', 'radio', 'select', 'date' ], true ) ? '' : self::text( $raw['placeholder'] ?? '', self::LABEL_MAX, $strict, $path . '.placeholder' ),
			'help'        => self::text( $raw['help'] ?? '', self::HELP_MAX, $strict, $path . '.help' ),
			'width'       => $type === 'textarea' ? self::FULL : self::choice( $raw['width'] ?? null, [ self::FULL, self::HALF ], self::FULL, $strict, $path . '.width' ),
			'answer'      => self::choice( $raw['answer'] ?? null, [ self::REQUIRED, self::OPTIONAL, self::HIDDEN ], self::OPTIONAL, $strict, $path . '.answer' ),
			'custom'      => true,
			'type'        => $type,
			'options'     => [],
			'email'       => ! array_key_exists( 'email', $raw ) || in_array( $raw['email'], [ true, 1, '1', 'true' ], true ),
		];
		if ( $type === 'select' || $type === 'radio' ) {
			$entry['options'] = self::options( $raw['options'] ?? null, $strict, $path . '.options' );
		}
		return $entry;
	}

	/**
	 * @return list<Choice>
	 * @throws InvalidCheckoutLayout When no choice is left.
	 */
	private static function options( mixed $raw, bool $strict, string $path ): array {
		$out  = [];
		$seen = [];
		foreach ( is_array( $raw ) ? array_values( $raw ) : [] as $i => $option ) {
			$label = is_array( $option ) ? self::text( $option['label'] ?? '', self::LABEL_MAX, $strict, $path . '.' . $i . '.label' ) : '';
			$value = is_array( $option ) && is_string( $option['value'] ?? null ) ? $option['value'] : '';
			if ( $label === '' || preg_match( '/^[a-z0-9_-]{1,40}$/', $value ) !== 1 ) {
				if ( $strict ) {
					throw new InvalidCheckoutLayout( __( 'Each choice needs a label.', 'flexacommerce' ), $path . '.' . $i . '.label' );
				}
				continue;
			}
			if ( isset( $seen[ $value ] ) ) {
				if ( $strict ) {
					throw new InvalidCheckoutLayout( __( 'Two choices have the same value.', 'flexacommerce' ), $path . '.' . $i . '.value' );
				}
				continue;
			}
			$seen[ $value ] = true;
			$out[]          = [
				'value' => $value,
				'label' => $label,
			];
		}
		if ( count( $out ) > self::MAX_CHOICES ) {
			if ( $strict ) {
				/* translators: %d: maximum number of choices */
				throw new InvalidCheckoutLayout( sprintf( __( 'A field can have at most %d choices.', 'flexacommerce' ), self::MAX_CHOICES ), $path );
			}
			$out = array_slice( $out, 0, self::MAX_CHOICES );
		}
		if ( $out === [] ) {
			throw new InvalidCheckoutLayout( __( 'Add at least one choice.', 'flexacommerce' ), $path );
		}
		return $out;
	}

	/**
	 * @param list<string> $allowed
	 * @throws InvalidCheckoutLayout In strict mode, for a value not allowed.
	 */
	private static function choice( mixed $value, array $allowed, string $fallback, bool $strict, string $path ): string {
		if ( $value === null ) {
			return $fallback;
		}
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return $value;
		}
		if ( $strict ) {
			throw new InvalidCheckoutLayout( __( 'This choice is not available.', 'flexacommerce' ), $path );
		}
		return $fallback;
	}

	/** @throws InvalidCheckoutLayout In strict mode, for text that is too long. */
	private static function text( mixed $value, int $max, bool $strict, string $path ): string {
		$text = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		if ( mb_strlen( $text ) > $max ) {
			if ( $strict ) {
				/* translators: %d: maximum number of characters */
				throw new InvalidCheckoutLayout( sprintf( __( 'Use at most %d characters.', 'flexacommerce' ), $max ), $path );
			}
			$text = mb_substr( $text, 0, $max );
		}
		return $text;
	}
}
