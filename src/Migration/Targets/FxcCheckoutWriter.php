<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * The Checkout Field Editor's fields into the checkout layout (doc 05
 * §5.10, ADR-036), saved through CheckoutLayout::save so they pass the
 * builder's checks. A section the merchant saved in the extension replaces
 * the fields of that section; the page around them stays. Each field
 * becomes the nearest entry; what does not fit is left out or changed, and
 * the report says so, naming the field.
 */
final class FxcCheckoutWriter {
	/** Extension types FlexaCommerce has under the same name. */
	private const SAME_TYPES = [ 'text', 'textarea', 'select', 'radio', 'checkbox', 'date', 'number', 'tel', 'email' ];

	/** Extension types kept as a text field: the customer types the answer. */
	private const AS_TEXT = [ 'password', 'url', 'datetime_local', 'time', 'month', 'week' ];

	/** Extension types that show text and take no answer. */
	private const TEXT_ONLY = [ 'heading', 'paragraph', 'label' ];

	/**
	 * WooCommerce's own labels and placeholders, in the versions the
	 * extension may have saved: a field that kept them keeps FlexaCommerce's
	 * own wording, which follows the site language.
	 */
	private const WC_TEXT = [
		'email'         => [ 'Email address' ],
		'first_name'    => [ 'First name' ],
		'last_name'     => [ 'Last name' ],
		'company'       => [ 'Company name', 'Company' ],
		'country'       => [ 'Country / Region', 'Country' ],
		'address_1'     => [ 'Street address', 'Address', 'House number and street name' ],
		'address_2'     => [ 'Apartment, suite, unit, etc.', 'Apartment, suite, unit, etc. (optional)', 'Apartment, suite, unit etc. (optional)' ],
		'city'          => [ 'Town / City' ],
		'state'         => [ 'State / County', 'State' ],
		'postcode'      => [ 'Postcode / ZIP', 'Postcode' ],
		'phone'         => [ 'Phone' ],
		'customer_note' => [ 'Order notes', 'Order notes (optional)', 'Notes about your order, e.g. special notes for delivery.' ],
	];

	/** @var list<Problem> */
	private array $problems = [];

	/** @var array<string,true> ids of every field in the layout, so a renamed one stays unique */
	private array $taken = [];

	public function checkout_fields( Record $record, Mapping $map ): WriteResult {
		$this->problems = [];
		$sections       = is_array( $record->data['sections'] ?? null ) ? $record->data['sections'] : [];
		$raw            = get_option( CheckoutLayout::OPTION, [] );
		$layout         = CheckoutLayout::from_array( is_array( $raw ) ? $raw : [] )->to_array();
		$this->taken    = [];
		foreach ( CheckoutLayout::SECTIONS as $section ) {
			foreach ( CheckoutLayout::CORE[ $section ] as $id ) {
				$this->taken[ $id ] = true;
			}
		}

		$imported = 0;
		foreach ( CheckoutLayout::SECTIONS as $section ) {
			if ( ! is_array( $sections[ $section ] ?? null ) ) {
				// Not saved in the extension: the field ids stay taken, the section stays as it is.
				foreach ( $layout['fields'][ $section ] as $entry ) {
					$this->taken[ $entry['id'] ] = true;
				}
				continue;
			}
			$layout['fields'][ $section ] = $this->section( $section, array_values( $sections[ $section ] ), $layout['fields'][ $section ] );
			++$imported;
		}
		if ( $imported === 0 ) {
			throw new Rejected( 'no_checkout_sections', 'The checkout fields were not imported. The record has no section of the Checkout Field Editor.' );
		}

		try {
			CheckoutLayout::save( $layout );
		} catch ( InvalidCheckoutLayout $e ) {
			throw new Rejected( 'invalid_checkout_layout', sprintf( 'The checkout fields were not imported: %s', $e->getMessage() ), [ 'field' => $e->field ] );
		}
		$action = $map->row( Record::CHECKOUT_FIELDS, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		$map->remember( Record::CHECKOUT_FIELDS, $record->source_id(), 1, $record->checksum );
		return new WriteResult( $action, 1, $this->problems );
	}

	/**
	 * The extension's fields in its order, then the core fields it left out
	 * (hidden), then the fields made in FlexaCommerce that it does not have.
	 *
	 * @param list<mixed>                $fields
	 * @param list<array<string,mixed>>  $current
	 * @return list<array<string,mixed>>
	 */
	private function section( string $section, array $fields, array $current ): array {
		$out    = [];
		$seen   = [];
		$custom = 0;
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['name'] ?? null ) ) {
				continue;
			}
			$core = $this->core_id( $section, $field['name'] );
			if ( $core !== null ) {
				if ( ! isset( $seen[ $core ] ) ) {
					$out[]         = $this->core( $section, $core, $field );
					$seen[ $core ] = true;
				}
				continue;
			}
			$entry = $this->custom( $section, $field );
			if ( $entry === null ) {
				continue;
			}
			if ( ++$custom > CheckoutLayout::MAX_CUSTOM ) {
				$this->problems[] = Problem::warning(
					'checkout_fields_cut',
					sprintf( 'The %1$s section has more than %2$d fields of your own. "%3$s" and the ones after it were left out.', $section, CheckoutLayout::MAX_CUSTOM, $entry['label'] )
				);
				break;
			}
			$out[]                       = $entry;
			$seen[ $entry['id'] ]        = true;
			$this->taken[ $entry['id'] ] = true;
		}

		foreach ( CheckoutLayout::CORE[ $section ] as $id ) {
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( in_array( $id, CheckoutLayout::LOCKED, true ) ) {
				$this->problems[] = Problem::warning( 'checkout_field_locked', sprintf( 'The %1$s field "%2$s" was removed in the Checkout Field Editor. FlexaCommerce needs it, so it stays shown and required.', $section, $id ) );
				continue;
			}
			$out[] = [
				'id'     => $id,
				'answer' => CheckoutLayout::HIDDEN,
			];
		}

		foreach ( $current as $entry ) {
			if ( ! empty( $entry['custom'] ) && ! isset( $seen[ $entry['id'] ] ) && $custom < CheckoutLayout::MAX_CUSTOM ) {
				$out[]                       = $entry;
				$this->taken[ $entry['id'] ] = true;
				++$custom;
			}
		}
		return $out;
	}

	/** The core field a name of the extension stands for, if any. */
	private function core_id( string $section, string $name ): ?string {
		if ( $section === 'additional' ) {
			return $name === 'order_comments' || $name === 'customer_note' ? 'customer_note' : null;
		}
		$prefix = $section . '_';
		if ( ! str_starts_with( $name, $prefix ) ) {
			return null;
		}
		$id = substr( $name, strlen( $prefix ) );
		return in_array( $id, CheckoutLayout::CORE[ $section ], true ) ? $id : null;
	}

	/**
	 * @param array<array-key,mixed> $field
	 * @return array<string,mixed>
	 */
	private function core( string $section, string $id, array $field ): array {
		$label  = $this->label( $field, $section, $id );
		$answer = ! self::enabled( $field ) ? CheckoutLayout::HIDDEN : ( self::on( $field['required'] ?? false ) ? CheckoutLayout::REQUIRED : CheckoutLayout::OPTIONAL );
		if ( in_array( $id, CheckoutLayout::LOCKED, true ) && $answer !== CheckoutLayout::REQUIRED ) {
			$this->problems[] = Problem::warning( 'checkout_field_locked', sprintf( 'The %1$s field "%2$s" was turned off or optional in the Checkout Field Editor. FlexaCommerce needs it, so it stays shown and required.', $section, $label !== '' ? $label : $id ) );
			$answer           = CheckoutLayout::REQUIRED;
		}
		if ( in_array( $id, CheckoutLayout::BY_COUNTRY, true ) && $answer === CheckoutLayout::OPTIONAL ) {
			// Whether a state or postcode is needed follows the country, as it did in WooCommerce.
			$answer = CheckoutLayout::REQUIRED;
		}
		$entry = [
			'id'          => $id,
			'label'       => self::is_wc_text( $id, $label ) ? '' : $label,
			'placeholder' => $this->placeholder( $field, $id, $section, $label ),
			'help'        => $this->help( $field, $section, $label !== '' ? $label : $id ),
			'answer'      => $answer,
		];
		$width = self::width( $field );
		if ( $width !== null ) {
			$entry['width'] = $width;
		}
		return $entry;
	}

	/**
	 * @param array<array-key,mixed> $field
	 * @return array<string,mixed>|null
	 */
	private function custom( string $section, array $field ): ?array {
		$name = (string) $field['name'];
		$type = is_string( $field['type'] ?? null ) && $field['type'] !== '' ? strtolower( $field['type'] ) : 'text';
		$text = self::clean( $field['label'] ?? '' );
		$what = $text !== '' ? sprintf( '"%1$s" (%2$s)', $text, $name ) : sprintf( '"%s"', $name );

		if ( in_array( $type, self::TEXT_ONLY, true ) ) {
			$this->problems[] = Problem::info( 'checkout_field_text', sprintf( 'The %1$s %2$s %3$s was left out: it shows text and takes no answer. Add it as a heading or paragraph between the sections of the Checkout page.', $section, $type, $what ) );
			return null;
		}
		if ( $type === 'hidden' ) {
			$this->problems[] = Problem::warning( 'checkout_field_hidden', sprintf( 'The hidden %1$s field %2$s was left out. FlexaCommerce has no field with a fixed value.', $section, $what ) );
			return null;
		}
		if ( in_array( $type, [ 'multiselect', 'checkboxgroup' ], true ) ) {
			$this->problems[] = Problem::warning( 'checkout_field_multiple', sprintf( 'The %1$s field %2$s was left out. FlexaCommerce has no field with several answers; add it again as one checkbox per choice.', $section, $what ) );
			return null;
		}
		if ( in_array( $type, self::AS_TEXT, true ) ) {
			$this->problems[] = Problem::info( 'checkout_field_as_text', sprintf( 'The %1$s field %2$s is a %3$s field in the Checkout Field Editor. It was imported as a text field.', $section, $what, $type ) );
			$type             = 'text';
		}
		if ( ! in_array( $type, self::SAME_TYPES, true ) ) {
			$this->problems[] = Problem::warning( 'checkout_field_unknown_type', sprintf( 'The %1$s field %2$s has the type "%3$s", which FlexaCommerce does not know. It was left out.', $section, $what, $type ) );
			return null;
		}

		$id    = $this->custom_id( $section, $name );
		$label = $this->label( $field, $section, $name );
		if ( $label === '' ) {
			$label = ucfirst( str_replace( '_', ' ', $id ) );
			$label = mb_substr( $label, 0, CheckoutLayout::LABEL_MAX );
		}
		$answer = ! self::enabled( $field ) ? CheckoutLayout::HIDDEN : ( self::on( $field['required'] ?? false ) ? CheckoutLayout::REQUIRED : CheckoutLayout::OPTIONAL );
		if ( self::has_rules( $field ) ) {
			$this->problems[] = Problem::warning( 'checkout_field_logic_dropped', sprintf( 'The %1$s field %2$s showed on conditions. The conditions were left out and the field is no longer required; set "Show this field" in the checkout builder.', $section, $what ) );
			$answer           = $answer === CheckoutLayout::REQUIRED ? CheckoutLayout::OPTIONAL : $answer;
		}
		$default = $field['default'] ?? '';
		if ( ( is_scalar( $default ) && trim( (string) $default ) !== '' ) || self::on( $field['checked'] ?? false ) ) {
			$this->problems[] = Problem::info( 'checkout_field_default', sprintf( 'The default answer of the %1$s field %2$s was left out. Customers start with the field empty.', $section, $what ) );
		}

		$entry = [
			'id'          => $id,
			'label'       => $label,
			'placeholder' => $this->placeholder( $field, $name, $section, $label ),
			'help'        => $this->help( $field, $section, $label ),
			'width'       => self::width( $field ) ?? CheckoutLayout::FULL,
			'answer'      => $answer,
			'custom'      => true,
			'type'        => $type,
			'email'       => ! array_key_exists( 'show_in_email', $field ) || self::on( $field['show_in_email'] ),
		];
		if ( $type === 'select' || $type === 'radio' ) {
			$entry['options'] = $this->choices( $field['options'] ?? null, $section, $what );
			if ( $entry['options'] === [] ) {
				$this->problems[] = Problem::warning( 'checkout_field_no_choices', sprintf( 'The %1$s field %2$s has no choices. It was left out.', $section, $what ) );
				return null;
			}
		}
		return $entry;
	}

	/** A field id the builder accepts and no other field has: the name without its section, or the whole name when that is taken. */
	private function custom_id( string $section, string $name ): string {
		$short = str_starts_with( $name, $section . '_' ) ? substr( $name, strlen( $section ) + 1 ) : $name;
		foreach ( [ $short, $name ] as $candidate ) {
			$id = self::slug( $candidate );
			if ( ! isset( $this->taken[ $id ] ) ) {
				return $id;
			}
		}
		$base = rtrim( substr( self::slug( $name ), 0, 36 ), '_' );
		$n    = 2;
		while ( isset( $this->taken[ $base . '_' . $n ] ) ) {
			++$n;
		}
		return $base . '_' . $n;
	}

	private static function slug( string $name ): string {
		$id = trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $name ) ), '_' );
		$id = preg_match( '/^[a-z]/', $id ) === 1 ? $id : 'field_' . $id;
		return rtrim( substr( $id, 0, 40 ), '_' );
	}

	/**
	 * The extension's `key => text` list (or, from older versions, "a|b")
	 * as builder choices. An empty key is the "choose" line of a select
	 * and is left out; keys become lower case slugs.
	 *
	 * @return list<array{value:string,label:string}>
	 */
	private function choices( mixed $raw, string $section, string $what ): array {
		if ( is_string( $raw ) ) {
			$raw = array_map( 'trim', explode( '|', $raw ) );
			$raw = array_combine( $raw, $raw );
		}
		$out  = [];
		$seen = [];
		foreach ( is_array( $raw ) ? $raw : [] as $key => $text ) {
			$key   = trim( (string) $key );
			$label = self::clean( is_scalar( $text ) ? (string) $text : '' );
			if ( $key === '' ) {
				continue;
			}
			$label = $label !== '' ? $label : $key;
			$value = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $key ) ), '-' );
			$value = $value !== '' ? substr( $value, 0, 36 ) : 'choice';
			$base  = $value;
			$n     = 2;
			while ( isset( $seen[ $value ] ) ) {
				$value = $base . '-' . $n;
				++$n;
			}
			$seen[ $value ] = true;
			$out[]          = [
				'value' => $value,
				'label' => mb_substr( $label, 0, CheckoutLayout::LABEL_MAX ),
			];
		}
		if ( count( $out ) > CheckoutLayout::MAX_CHOICES ) {
			$this->problems[] = Problem::warning( 'checkout_choices_cut', sprintf( 'The %1$s field %2$s has %3$d choices and a field holds %4$d. The choices after the first %4$d were left out.', $section, $what, count( $out ), CheckoutLayout::MAX_CHOICES ) );
			$out              = array_slice( $out, 0, CheckoutLayout::MAX_CHOICES );
		}
		return $out;
	}

	/** @param array<array-key,mixed> $field */
	private function label( array $field, string $section, string $name ): string {
		return $this->fit( self::clean( $field['label'] ?? '' ), CheckoutLayout::LABEL_MAX, sprintf( 'label of the %1$s field "%2$s"', $section, $name ) );
	}

	/** @param array<array-key,mixed> $field */
	private function placeholder( array $field, string $id, string $section, string $label ): string {
		$text = $this->fit( self::clean( $field['placeholder'] ?? '' ), CheckoutLayout::LABEL_MAX, sprintf( 'placeholder of the %1$s field "%2$s"', $section, $label !== '' ? $label : $id ) );
		return self::is_wc_text( $id, $text ) ? '' : $text;
	}

	/**
	 * The extension's description (its Pro version has one) as the help line.
	 *
	 * @param array<array-key,mixed> $field
	 */
	private function help( array $field, string $section, string $label ): string {
		return $this->fit( self::clean( $field['description'] ?? '' ), CheckoutLayout::HELP_MAX, sprintf( 'description of the %1$s field "%2$s"', $section, $label ) );
	}

	private function fit( string $text, int $max, string $what ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$this->problems[] = Problem::info( 'checkout_text_cut', sprintf( 'The %1$s was cut to %2$d characters.', $what, $max ) );
		return rtrim( mb_substr( $text, 0, $max ) );
	}

	/** Plain text: the extension keeps labels as HTML and choices with entities. */
	private static function clean( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( sanitize_text_field( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	private static function is_wc_text( string $id, string $text ): bool {
		if ( $text === '' ) {
			return true;
		}
		foreach ( self::WC_TEXT[ $id ] ?? [] as $stock ) {
			// phpcs:ignore WordPress.WP.I18n -- WooCommerce's own strings, as the site language shows them.
			if ( strcasecmp( $text, $stock ) === 0 || strcasecmp( $text, translate( $stock, 'woocommerce' ) ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Half width for the extension's left and right halves, full for a wide row, null when it says neither.
	 *
	 * @param array<array-key,mixed> $field
	 */
	private static function width( array $field ): ?string {
		$class = $field['class'] ?? [];
		$class = is_string( $class ) ? preg_split( '/[\s,]+/', $class ) : $class;
		$class = is_array( $class ) ? array_map( 'strval', array_filter( $class, 'is_scalar' ) ) : [];
		if ( array_intersect( $class, [ 'form-row-first', 'form-row-last' ] ) !== [] ) {
			return CheckoutLayout::HALF;
		}
		return in_array( 'form-row-wide', $class, true ) ? CheckoutLayout::FULL : null;
	}

	/**
	 * The extension treats a field without the key as enabled.
	 *
	 * @param array<array-key,mixed> $field
	 */
	private static function enabled( array $field ): bool {
		return ! array_key_exists( 'enabled', $field ) || self::on( $field['enabled'] );
	}

	private static function on( mixed $value ): bool {
		return in_array( $value, [ true, 1, '1', 'yes', 'true' ], true );
	}

	/**
	 * Conditions saved by the extension's Pro version.
	 *
	 * @param array<array-key,mixed> $field
	 */
	private static function has_rules( array $field ): bool {
		foreach ( [ 'conditional_rules', 'conditional_rules_json', 'conditional_rules_ajax', 'conditional_rules_ajax_json' ] as $key ) {
			$rules = $field[ $key ] ?? null;
			if ( ( is_array( $rules ) && $rules !== [] ) || ( is_string( $rules ) && trim( $rules ) !== '' && trim( $rules ) !== '[]' ) ) {
				return true;
			}
		}
		return false;
	}
}
