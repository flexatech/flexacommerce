<?php

declare(strict_types=1);

namespace Flexa\Commerce\Options;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Inventory\OptionStockRepository;

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
	 * A formula price (ADR-039) reads the fields of its own set, the line
	 * quantity and the unit price in the main unit (`$exponent` digits).
	 *
	 * @param Values $values
	 */
	public function extra( array $values, int $base, int $qty = 1, int $exponent = 2 ): int {
		$found    = [];
		$measures = [];
		foreach ( $values as $key => $value ) {
			[ $set_id, $field_id ] = array_map( 'intval', array_pad( explode( '-', (string) $key, 2 ), 2, '0' ) );
			$field                 = $this->sets->find( $set_id )?->field( $field_id );
			if ( $field !== null ) {
				$found[]                           = [ $set_id, $field, $value ];
				$measures[ $set_id ][ $field->id ] = $field->measure( $value );
			}
		}
		$extra = 0;
		foreach ( $found as [ $set_id, $field, $value ] ) {
			$extra += $field->charge( $value, $base, OptionField::inputs( $base, $qty, $exponent, $measures[ $set_id ] ), $exponent );
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
	 * The picked choices that track stock (ADR-040), as OptionStockRepository keys
	 * with the name a shopper reads, such as "Finish: Gold".
	 *
	 * @param Values $values
	 * @return array<string,string>
	 */
	public function tracked( array $values ): array {
		$tracked = [];
		foreach ( $values as $key => $value ) {
			[ $set_id, $field_id ] = array_map( 'intval', array_pad( explode( '-', (string) $key, 2 ), 2, '0' ) );
			$field                 = $this->sets->find( $set_id )?->field( $field_id );
			if ( $field === null || ! $field->has_choices() ) {
				continue;
			}
			foreach ( (array) $value as $id ) {
				$choice = $field->choice( (int) $id );
				if ( $choice !== null && $choice['track_stock'] ) {
					$tracked[ OptionStockRepository::key( $set_id, $field_id, $choice['id'] ) ] = $field->label . ': ' . $choice['label'];
				}
			}
		}
		return $tracked;
	}

	/**
	 * Checks that the choices of a line are in stock for `$quantity`, on top
	 * of the other lines of the cart that use them. The message says how
	 * many more can be added, the line holding `$quantity - $adding`.
	 *
	 * @param Values                      $values
	 * @param list<array{0:Values,1:int}> $others values and quantity of the other lines
	 * @throws InsufficientStock
	 */
	public function assert_stock( array $values, int $quantity, int $adding, array $others, int $variant_id ): void {
		$tracked = $this->tracked( $values );
		$stock   = $this->sets->stock();
		if ( $tracked === [] || $stock === null ) {
			return;
		}
		$used = [];
		foreach ( $others as [ $other, $qty ] ) {
			foreach ( array_keys( array_intersect_key( $this->tracked( $other ), $tracked ) ) as $key ) {
				$used[ $key ] = ( $used[ $key ] ?? 0 ) + $qty;
			}
		}
		$levels = $stock->levels( array_keys( $tracked ) );
		foreach ( $tracked as $key => $label ) {
			if ( isset( $levels[ $key ] ) && $levels[ $key ] < ( $used[ $key ] ?? 0 ) + $quantity ) {
				throw OptionStockRepository::short(
					[
						'quantity'   => $adding,
						'label'      => $label,
						'variant_id' => $variant_id,
					],
					$levels[ $key ] - ( $used[ $key ] ?? 0 ) - ( $quantity - $adding )
				);
			}
		}
	}

	/**
	 * The choices of the product's sets that are sold out, in one query and
	 * only when a set tracks stock.
	 *
	 * @param list<OptionSet> $sets
	 * @return array<string,true> OptionStockRepository keys
	 */
	public function sold_out( array $sets ): array {
		$keys = [];
		foreach ( $sets as $set ) {
			foreach ( $set->fields as $field ) {
				foreach ( $field->choices as $choice ) {
					if ( $choice['track_stock'] ) {
						$keys[] = OptionStockRepository::key( $set->id, $field->id, $choice['id'] );
					}
				}
			}
		}
		$stock = $this->sets->stock();
		if ( $keys === [] || $stock === null ) {
			return [];
		}
		$levels = $stock->levels( $keys );
		$out    = [];
		foreach ( $keys as $key ) {
			if ( ( $levels[ $key ] ?? 0 ) <= 0 ) {
				$out[ $key ] = true;
			}
		}
		return $out;
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
