<?php

declare(strict_types=1);

namespace Flexa\Commerce\Options;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Product options (ADR-025): the fields a customer fills in on the product
 * page, checked when the product goes into the cart and priced on every
 * cart read. A value is posted as `options[<set id>-<field id>]`; a field
 * with checkboxes posts a list of choice ids. Fields hidden by their logic
 * are dropped, never charged.
 *
 * @phpstan-type Values array<string,string|list<string>>
 */
final class ProductOptions {
	/** Where the option sets are kept; read here so a store without sets loads no option class. */
	public const OPTION = 'flexacommerce_product_options';

	/** @var array<int,list<OptionSet>> */
	private array $for = [];

	public function __construct( private readonly OptionSetRepository $sets ) {}

	/** Whether the store has any option set, without loading a class. */
	public static function in_use(): bool {
		return get_option( self::OPTION, [] ) !== [];
	}

	public static function key( int $set_id, int $field_id ): string {
		return $set_id . '-' . $field_id;
	}

	/**
	 * The option values of a request, keys checked, values left for
	 * accept() to clean (a long text keeps its line breaks).
	 *
	 * @return array<string,string|list<string>>
	 */
	public static function posted( mixed $raw ): array {
		$values = [];
		foreach ( is_array( $raw ) ? $raw : [] as $key => $value ) {
			if ( ! is_string( $key ) || preg_match( '/^\d{1,9}-\d{1,9}$/', $key ) !== 1 ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$values[ $key ] = (string) $value;
			} elseif ( is_array( $value ) ) {
				$values[ $key ] = array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
		}
		return $values;
	}

	/** @return list<OptionSet> the enabled sets shown on this product, in list order */
	public function sets_for( Product $product ): array {
		if ( ! isset( $this->for[ $product->id ] ) ) {
			$this->for[ $product->id ] = array_values(
				array_filter( $this->sets->all(), static fn ( OptionSet $set ): bool => $set->enabled && self::targets( $set, $product->id ) )
			);
		}
		return $this->for[ $product->id ];
	}

	/**
	 * The values a customer sent, checked against the fields of the product:
	 * hidden fields dropped, required ones present, choices known. `labels`
	 * are the lines shown with the cart item.
	 *
	 * @param array<array-key,mixed> $posted key => value, as posted
	 * @return array{values:Values,labels:list<array{name:string,value:string}>}
	 * @throws InvalidCartRequest
	 */
	public function accept( Product $product, array $posted ): array {
		$values = [];
		$labels = [];
		foreach ( $this->sets_for( $product ) as $set ) {
			$seen = [];
			foreach ( $set->fields as $field ) {
				if ( $field->type === OptionField::HEADING || ! $field->visible( $seen ) ) {
					continue;
				}
				$key   = self::key( $set->id, $field->id );
				$value = self::value_of( $field, $posted[ $key ] ?? null );
				if ( $value === '' || $value === [] ) {
					if ( $field->required ) {
						throw new InvalidCartRequest(
							/* translators: %s: the name of a product option */
							sprintf( __( 'Fill in %s before adding to the cart.', 'flexacommerce' ), $field->label ),
							'options',
							'option_required'
						);
					}
					continue;
				}
				$seen[ $field->id ] = $value;
				$values[ $key ]     = $value;
				$labels[]           = [
					'name'  => $field->label,
					'value' => $field->text_of( $value ),
				];
			}
		}
		return [
			'values' => $values,
			'labels' => $labels,
		];
	}

	/**
	 * What the values add to one unit priced `$base`, with the fields as
	 * they are now. A field deleted since the item was added adds nothing.
	 *
	 * @param Values $values
	 */
	public function extra( array $values, int $base ): int {
		$extra = 0;
		foreach ( $values as $key => $value ) {
			[ $set_id, $field_id ] = array_map( 'intval', array_pad( explode( '-', (string) $key, 2 ), 2, '0' ) );
			$field                 = $this->sets->find( $set_id )?->field( $field_id );
			if ( $field !== null ) {
				$extra += $field->charge( $value, $base );
			}
		}
		return $extra;
	}

	/**
	 * What the values add once to the cart line, whatever its quantity.
	 *
	 * @param Values $values
	 */
	public function fee( array $values ): int {
		$fee = 0;
		foreach ( $values as $key => $value ) {
			[ $set_id, $field_id ] = array_map( 'intval', array_pad( explode( '-', (string) $key, 2 ), 2, '0' ) );
			$fee                  += (int) $this->sets->find( $set_id )?->field( $field_id )?->fee( $value );
		}
		return $fee;
	}

	/**
	 * @return string|list<string> '' or [] when empty
	 * @throws InvalidCartRequest
	 */
	private static function value_of( OptionField $field, mixed $raw ): string|array {
		if ( $field->type === OptionField::CHECKBOXES ) {
			$picked = [];
			foreach ( is_array( $raw ) ? $raw : ( $raw === null || $raw === '' ? [] : [ $raw ] ) as $id ) {
				if ( $id === '' ) {
					continue;
				}
				if ( ! is_scalar( $id ) || $field->choice( (int) $id ) === null ) {
					throw self::invalid( $field );
				}
				$picked[ (int) $id ] = true;
			}
			// In the order of the choices, so the same picks give the same cart line.
			$list = [];
			foreach ( $field->choices as $choice ) {
				if ( isset( $picked[ $choice['id'] ] ) ) {
					$list[] = (string) $choice['id'];
				}
			}
			return $list;
		}
		if ( $raw !== null && ! is_scalar( $raw ) ) {
			throw self::invalid( $field );
		}
		$raw = (string) $raw;

		switch ( $field->type ) {
			case OptionField::SELECT:
			case OptionField::RADIO:
			case OptionField::SWATCH:
			case OptionField::BUTTON:
				if ( $raw === '' ) {
					return '';
				}
				if ( $field->choice( (int) $raw ) === null ) {
					throw self::invalid( $field );
				}
				return (string) (int) $raw;

			case OptionField::NUMBER:
				$raw = trim( $raw );
				if ( $raw === '' ) {
					return '';
				}
				if ( ! is_numeric( $raw ) || abs( (float) $raw ) > OptionSetRepository::MAX_NUMBER ) {
					throw self::invalid( $field );
				}
				$number = (float) $raw;
				if ( ( $field->min !== null && $number < $field->min ) || ( $field->max !== null && $number > $field->max ) ) {
					throw self::invalid( $field );
				}
				if ( $field->step !== null ) {
					$steps = ( $number - ( $field->min ?? 0.0 ) ) / $field->step;
					if ( abs( $steps - round( $steps ) ) > 1e-6 ) {
						throw self::invalid( $field );
					}
				}
				return rtrim( rtrim( sprintf( '%.6F', $number ), '0' ), '.' );

			case OptionField::DATE:
				$raw = trim( $raw );
				if ( $raw === '' ) {
					return '';
				}
				[ $year, $month, $day ] = array_map( 'intval', explode( '-', $raw ) + [ '', '', '' ] );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) !== 1 || ! checkdate( $month, $day, $year ) ) {
					throw self::invalid( $field );
				}
				[ $earliest, $latest ] = $field->date_range();
				if ( ( $earliest !== null && $raw < $earliest ) || ( $latest !== null && $raw > $latest ) ) {
					throw new InvalidCartRequest(
						/* translators: %s: the name of a product option */
						sprintf( __( 'Pick a date for %s within the dates offered.', 'flexacommerce' ), $field->label ),
						'options',
						'invalid_option'
					);
				}
				return $raw;

			case OptionField::COLOR:
				$raw = trim( $raw );
				if ( $raw === '' ) {
					return '';
				}
				if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $raw ) !== 1 ) {
					throw self::invalid( $field );
				}
				return strtolower( $raw );

			default:
				$long = $field->type === OptionField::TEXTAREA;
				$text = trim( $long ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
				$max  = $field->max_length > 0 ? $field->max_length : ( $long ? OptionSetRepository::MAX_LENGTH : OptionSetRepository::TEXT_LENGTH );
				if ( mb_strlen( $text ) > $max ) {
					throw new InvalidCartRequest(
						/* translators: 1: the name of a product option, 2: the largest number of characters */
						sprintf( __( 'Keep %1$s to %2$d characters or fewer.', 'flexacommerce' ), $field->label, $max ),
						'options',
						'invalid_option'
					);
				}
				return $text;
		}
	}

	private static function invalid( OptionField $field ): InvalidCartRequest {
		return new InvalidCartRequest(
			/* translators: %s: the name of a product option */
			sprintf( __( 'Check the value of %s.', 'flexacommerce' ), $field->label ),
			'options',
			'invalid_option'
		);
	}

	private static function targets( OptionSet $set, int $product_id ): bool {
		if ( in_array( $product_id, $set->exclude_product_ids, true ) ) {
			return false;
		}
		return match ( $set->applies_to ) {
			OptionSet::PRODUCTS   => in_array( $product_id, $set->product_ids, true ),
			OptionSet::CATEGORIES => array_intersect( $set->category_ids, self::categories( $product_id ) ) !== [],
			default               => true,
		};
	}

	/** @return list<int> the product's categories and their ancestors */
	private static function categories( int $product_id ): array {
		$terms = get_the_terms( $product_id, PostTypes::CATEGORY );
		$ids   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$ids[] = $term->term_id;
			foreach ( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) as $parent ) {
				$ids[] = (int) $parent;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
