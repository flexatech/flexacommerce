<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout page as the merchant arranged it in the builder (doc 11,
 * ADR-031, ADR-032): per section the order, labels, widths and answers of
 * the core fields, and the custom fields; then the page itself, which
 * sections sit in which column, their titles, the headings and paragraphs
 * between them, one or two columns and where phones show the summary.
 * FieldSchema applies the fields before the `flexacommerce.checkout.fields`
 * filter; CheckoutForm prints the page.
 *
 * Every section always holds all of its core fields, and the page always
 * holds every section: one missing is added at the end with its defaults.
 *
 * @phpstan-type Choice array{value:string,label:string}
 * @phpstan-type Entry array{id:string,label:string,placeholder:string,help:string,width:string,answer:string,custom:bool,type?:string,options?:list<Choice>,email?:bool}
 * @phpstan-type Block array{id:string,title?:string,type?:string,text?:string}
 * @phpstan-type Page array{columns:string,summary:string,main:list<Block>,side:list<Block>}
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

	/** The parts of the page, in their default order: three in the main column, three in the side. */
	public const BLOCKS = [ 'billing', 'shipping', 'additional', 'shipping_method', 'summary', 'payment' ];

	public const MAIN = 'main';
	public const SIDE = 'side';

	public const TWO_COLUMNS = 'two';
	public const ONE_COLUMN  = 'one';

	/** Where phones show the order summary: where it is, or folded at the top. */
	public const SUMMARY_END = 'end';
	public const SUMMARY_TOP = 'top';

	public const CONTENT_TYPES = [ 'heading', 'paragraph' ];
	public const MAX_CONTENT   = 20;
	public const HEADING_MAX   = 120;
	public const PARAGRAPH_MAX = 1000;

	/** Tags a paragraph keeps. */
	public const PARAGRAPH_TAGS = [
		'a'      => [ 'href' => true ],
		'strong' => [],
		'em'     => [],
	];

	public const PREVIEW_TTL = 3600;

	/** Query argument of the preview link; its value is a nonce for PREVIEW_NONCE. */
	public const PREVIEW_ARG   = 'fxc_preview';
	public const PREVIEW_NONCE = 'flexacommerce-checkout-preview';

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

	private const PREVIEW = 'flexacommerce_checkout_preview_';

	private static ?self $memo     = null;
	private static mixed $memo_raw = null;
	private static ?self $preview  = null;

	/**
	 * @param array<string,list<Entry>> $fields
	 * @param Page                      $page
	 */
	private function __construct( private readonly array $fields, private readonly array $page ) {}

	public static function default(): self {
		return self::from_array( [] );
	}

	/** The stored layout, read and normalised once per request. */
	public static function current(): self {
		if ( self::$preview !== null ) {
			return self::$preview;
		}
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
	 * Keeps an unsaved layout for "Preview on site", for one user and one hour.
	 *
	 * @param array<mixed> $data
	 * @throws InvalidCheckoutLayout When an entry breaks a rule.
	 */
	public static function store_draft( int $user_id, array $data ): self {
		$layout = self::from_array( $data, true );
		set_transient( self::PREVIEW . $user_id, $layout->to_array(), self::PREVIEW_TTL );
		return $layout;
	}

	public static function draft( int $user_id ): ?self {
		$raw = get_transient( self::PREVIEW . $user_id );
		return is_array( $raw ) ? self::from_array( $raw ) : null;
	}

	/** Shows a draft instead of the stored layout for the rest of the request; null ends it. */
	public static function preview( ?self $layout ): void {
		if ( $layout === null ) {
			self::$preview = null;
			return;
		}
		$filtered      = apply_filters( 'flexacommerce.checkout.layout', $layout );
		self::$preview = $filtered instanceof self ? $filtered : $layout;
	}

	public static function previewing(): bool {
		return self::$preview !== null;
	}

	/**
	 * With `$strict`, a broken entry throws; without, it is dropped, so a
	 * damaged option never breaks the checkout page.
	 *
	 * @param array<mixed> $data `{fields:{billing:[...],shipping:[...],additional:[...]},page:{columns,summary,main:[...],side:[...]}}`
	 * @throws InvalidCheckoutLayout In strict mode, when an entry breaks a rule.
	 */
	public static function from_array( array $data, bool $strict = false ): self {
		$sections = is_array( $data['fields'] ?? null ) ? $data['fields'] : [];
		$fields   = [];
		foreach ( self::SECTIONS as $section ) {
			$fields[ $section ] = self::normalise( $section, is_array( $sections[ $section ] ?? null ) ? array_values( $sections[ $section ] ) : [], $strict );
		}
		return new self( $fields, self::page_from( is_array( $data['page'] ?? null ) ? $data['page'] : [], $strict ) );
	}

	/** @return array{fields:array<string,list<Entry>>,page:Page} */
	public function to_array(): array {
		return [
			'fields' => $this->fields,
			'page'   => $this->page,
		];
	}

	/** `two` or `one`. */
	public function columns(): string {
		return $this->page['columns'];
	}

	/** Where phones show the order summary: `end` or `top`. */
	public function summary_position(): string {
		return $this->page['summary'];
	}

	/** @return list<Block> the sections and content of a column, in order */
	public function blocks( string $column ): array {
		return $this->page[ $column ] ?? [];
	}

	/** The merchant's title for a section, '' for the default. */
	public function title( string $block ): string {
		foreach ( [ self::MAIN, self::SIDE ] as $column ) {
			foreach ( $this->page[ $column ] as $item ) {
				if ( $item['id'] === $block ) {
					return $item['title'] ?? '';
				}
			}
		}
		return '';
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
		$default = self::default();
		return $this->fields === $default->fields && $this->page === $default->page;
	}

	/**
	 * @param array<mixed> $raw
	 * @return Page
	 * @throws InvalidCheckoutLayout In strict mode.
	 */
	private static function page_from( array $raw, bool $strict ): array {
		$page    = [
			'columns' => self::choice( $raw['columns'] ?? null, [ self::TWO_COLUMNS, self::ONE_COLUMN ], self::TWO_COLUMNS, $strict, 'page.columns' ),
			'summary' => self::choice( $raw['summary'] ?? null, [ self::SUMMARY_END, self::SUMMARY_TOP ], self::SUMMARY_END, $strict, 'page.summary' ),
			'main'    => [],
			'side'    => [],
		];
		$given   = isset( $raw[ self::MAIN ] ) || isset( $raw[ self::SIDE ] );
		$seen    = [];
		$content = 0;
		foreach ( [ self::MAIN, self::SIDE ] as $column ) {
			foreach ( is_array( $raw[ $column ] ?? null ) ? array_values( $raw[ $column ] ) : [] as $index => $item ) {
				$path = 'page.' . $column . '.' . $index;
				try {
					if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) ) {
						throw new InvalidCheckoutLayout( __( 'Each section needs an id.', 'flexacommerce' ), $path . '.id' );
					}
					$id = $item['id'];
					if ( isset( $seen[ $id ] ) ) {
						/* translators: %s: section id */
						throw new InvalidCheckoutLayout( sprintf( __( 'The section "%s" is on the page twice.', 'flexacommerce' ), $id ), $path . '.id' );
					}
					if ( in_array( $id, self::BLOCKS, true ) ) {
						$block = [
							'id'    => $id,
							'title' => self::text( $item['title'] ?? '', self::LABEL_MAX, $strict, $path . '.title' ),
						];
					} else {
						if ( ++$content > self::MAX_CONTENT ) {
							/* translators: %d: maximum number of headings and paragraphs */
							throw new InvalidCheckoutLayout( sprintf( __( 'The page can hold at most %d headings and paragraphs.', 'flexacommerce' ), self::MAX_CONTENT ), $path . '.id' );
						}
						$block = self::content( $id, $item, $strict, $path );
					}
				} catch ( InvalidCheckoutLayout $e ) {
					if ( $strict ) {
						throw $e;
					}
					continue;
				}
				$seen[ $id ]       = true;
				$page[ $column ][] = $block;
			}
		}
		foreach ( self::BLOCKS as $index => $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				if ( $strict && $given ) {
					/* translators: %s: section id */
					throw new InvalidCheckoutLayout( sprintf( __( 'The section "%s" must stay on the page.', 'flexacommerce' ), $id ), 'page' );
				}
				$page[ $index < 3 ? self::MAIN : self::SIDE ][] = [
					'id'    => $id,
					'title' => '',
				];
			}
		}
		if ( $page['columns'] === self::ONE_COLUMN && $page['side'] !== [] ) {
			$page['main'] = array_merge( $page['main'], $page['side'] );
			$page['side'] = [];
		}
		// Payment ends its column: the Place order button is the last thing in it.
		foreach ( [ self::MAIN, self::SIDE ] as $column ) {
			$ids   = array_column( $page[ $column ], 'id' );
			$index = array_search( 'payment', $ids, true );
			if ( is_int( $index ) && $index !== count( $ids ) - 1 ) {
				if ( $strict ) {
					throw new InvalidCheckoutLayout( __( 'Payment stays at the end of its column, with the Place order button.', 'flexacommerce' ), 'page.' . $column . '.' . $index . '.id' );
				}
				$payment           = array_splice( $page[ $column ], $index, 1 );
				$page[ $column ][] = $payment[0];
			}
		}
		return $page;
	}

	/**
	 * A heading or a paragraph between the sections.
	 *
	 * @param array<mixed> $raw
	 * @return Block
	 * @throws InvalidCheckoutLayout When the item breaks a rule.
	 */
	private static function content( string $id, array $raw, bool $strict, string $path ): array {
		if ( preg_match( '/^text_[a-z0-9]{1,16}$/', $id ) !== 1 ) {
			/* translators: %s: section id */
			throw new InvalidCheckoutLayout( sprintf( __( 'The section "%s" is not known.', 'flexacommerce' ), $id ), $path . '.id' );
		}
		$type = self::choice( $raw['type'] ?? '', self::CONTENT_TYPES, 'heading', true, $path . '.type' );
		if ( $type === 'heading' ) {
			$text = self::text( $raw['text'] ?? '', self::HEADING_MAX, $strict, $path . '.text' );
		} else {
			$text = is_string( $raw['text'] ?? null ) ? trim( wp_kses( $raw['text'], self::PARAGRAPH_TAGS ) ) : '';
			if ( mb_strlen( $text ) > self::PARAGRAPH_MAX ) {
				if ( $strict ) {
					/* translators: %d: maximum number of characters */
					throw new InvalidCheckoutLayout( sprintf( __( 'Use at most %d characters.', 'flexacommerce' ), self::PARAGRAPH_MAX ), $path . '.text' );
				}
				$text = trim( wp_kses( mb_substr( $text, 0, self::PARAGRAPH_MAX ), self::PARAGRAPH_TAGS ) );
			}
		}
		if ( $text === '' ) {
			throw new InvalidCheckoutLayout( __( 'Write the text, or delete the item.', 'flexacommerce' ), $path . '.text' );
		}
		return [
			'id'   => $id,
			'type' => $type,
			'text' => $text,
		];
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
