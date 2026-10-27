<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Contracts\Exceptions\InvalidOptionSet;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSet;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Product Add-ons into option sets (doc 05 §5.9, ADR-029),
 * saved through the option set repository so they pass the editor's
 * checks. Each add-on becomes the nearest field type; what does not fit
 * is left out or changed, and the report says so, naming the set and the
 * field.
 */
final class FxcOptionWriter {
	/** Where the imported sets wait to be put in WooCommerce's order: the job id, and set id => [priority, own list first, source id]. */
	public const ORDER_OPTION = 'flexacommerce_migration_option_order';

	/** Product Add-ons 2.x text types, one input per option. */
	private const LEGACY_TEXT = [ 'custom', 'custom_letters_only', 'custom_digits_only', 'custom_letters_or_digits', 'custom_email' ];

	/** @var list<Problem> */
	private array $problems = [];

	private string $set = '';

	public function __construct( private readonly OptionSetRepository $sets ) {}

	public function option_set( Record $record, Mapping $map, int $job_id ): WriteResult {
		$this->problems = [];
		$data           = $record->data;
		$global         = ( $data['scope'] ?? '' ) === 'global';
		$this->set      = trim( wp_strip_all_tags( (string) ( $data['name'] ?? '' ) ) );
		$this->set      = $this->set !== '' ? $this->set : ( $global ? __( 'Add-ons', 'flexacommerce' ) : sprintf( 'product %s', $record->source_id() ) );

		$fields = [];
		foreach ( is_array( $data['addons'] ?? null ) ? $data['addons'] : [] as $addon ) {
			if ( is_array( $addon ) ) {
				array_push( $fields, ...$this->fields( $addon ) );
			}
		}
		if ( count( $fields ) > OptionSetRepository::MAX_FIELDS ) {
			$this->problems[] = Problem::warning(
				'option_fields_cut',
				sprintf( 'The add-ons of "%1$s" have %2$d fields and an option set holds %3$d. The fields after the first %3$d were left out.', $this->set, count( $fields ), OptionSetRepository::MAX_FIELDS )
			);
			$fields           = array_slice( $fields, 0, OptionSetRepository::MAX_FIELDS );
		}
		$inputs = array_filter( $fields, static fn ( array $f ): bool => $f['type'] !== OptionField::HEADING );
		if ( $inputs === [] ) {
			throw new Rejected( 'no_option_fields', sprintf( 'The add-ons of "%s" were not imported. None of them is a field FlexaCommerce has.', $this->set ), [ 'problems' => array_map( static fn ( Problem $p ): string => $p->message, $this->problems ) ] );
		}
		foreach ( $fields as $i => $field ) {
			$fields[ $i ]['id'] = $i + 1;
		}

		$enabled = ! empty( $data['enabled'] );
		$exclude = $this->products( (array) ( $data['exclude_products'] ?? [] ), $map, false );
		if ( $global ) {
			$cats    = $this->categories( (array) ( $data['categories'] ?? [] ), $map );
			$scope   = ! empty( $data['all_products'] ) ? OptionSet::ALL_PRODUCTS : OptionSet::CATEGORIES;
			$targets = [];
			if ( $scope === OptionSet::CATEGORIES && $cats === [] ) {
				$scope            = OptionSet::ALL_PRODUCTS;
				$enabled          = false;
				$this->problems[] = Problem::warning( 'option_set_untargeted', sprintf( 'The add-on group "%s" is for no products or for categories that were not imported. It was imported turned off; pick its products or categories before you turn it on.', $this->set ) );
			}
			$name = $this->set;
		} else {
			$targets = $this->products( (array) ( $data['products'] ?? [] ), $map, true );
			if ( $targets === [] ) {
				throw new Rejected( 'option_products_missing', sprintf( 'The add-ons of "%s" were not imported. None of their products were imported.', $this->set ) );
			}
			$scope = OptionSet::PRODUCTS;
			$cats  = [];
			$more  = count( $targets ) - 1;
			/* translators: 1: a product name, 2: how many other products share its add-ons. */
			$name = $more > 0 ? sprintf( _n( '%1$s and %2$d more', '%1$s and %2$d more', $more, 'flexacommerce' ), $this->set, $more ) : $this->set;
		}

		$target = $map->target_id( Record::OPTION_SET, $record->source_id() );
		if ( $target > 0 && $this->sets->find( $target ) === null ) {
			$target = 0;
		}
		$input = [
			'name'                => mb_substr( $name, 0, OptionSetRepository::MAX_NAME ),
			'enabled'             => $enabled,
			'applies_to'          => $scope,
			'product_ids'         => $targets,
			'category_ids'        => $cats,
			'exclude_product_ids' => $exclude,
			'fields'              => $fields,
		];
		try {
			$set = $this->sets->save( $input, $target );
		} catch ( InvalidOptionSet $e ) {
			throw new Rejected( 'invalid_option_set', sprintf( 'The add-ons of "%1$s" were not imported: %2$s', $this->set, $e->getMessage() ), [ 'field' => $e->field ] );
		}

		// A job that stopped before the end of the stage leaves its list behind; another job starts afresh.
		$order = get_option( self::ORDER_OPTION, [] );
		$order = is_array( $order ) && ( $order['job'] ?? 0 ) === $job_id && is_array( $order['sets'] ?? null ) ? $order : [
			'job'  => $job_id,
			'sets' => [],
		];

		$order['sets'][ $set->id ] = [ (int) ( $data['priority'] ?? 10 ), $global ? 1 : 0, (int) $record->source_id() ];
		update_option( self::ORDER_OPTION, $order, false );

		$map->remember( Record::OPTION_SET, $record->source_id(), $set->id, $record->checksum );
		return new WriteResult( $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED, $set->id, $this->problems );
	}

	/**
	 * Puts the imported sets in the order WooCommerce shows them: by
	 * priority, a product's own add-ons before global groups of the same
	 * priority. Sets made by hand keep their place after them.
	 */
	public function reorder( int $job_id ): void {
		$stored = get_option( self::ORDER_OPTION, [] );
		$order  = is_array( $stored ) && ( $stored['job'] ?? 0 ) === $job_id && is_array( $stored['sets'] ?? null ) ? $stored['sets'] : [];
		delete_option( self::ORDER_OPTION );
		if ( $order === [] ) {
			return;
		}
		uasort( $order, static fn ( mixed $a, mixed $b ): int => (array) $a <=> (array) $b );
		$this->sets->reorder( array_map( 'intval', array_keys( $order ) ) );
	}

	public function delete( int $set_id ): bool {
		if ( $this->sets->find( $set_id ) === null ) {
			return false;
		}
		$this->sets->delete( $set_id );
		return true;
	}

	/**
	 * One add-on as zero or more fields (a 2.x text add-on has one input per
	 * option).
	 *
	 * @param array<array-key,mixed> $addon
	 * @return list<array<string,mixed>>
	 */
	private function fields( array $addon ): array {
		$type     = (string) ( $addon['type'] ?? '' );
		$label    = $this->label( $addon['name'] ?? '', OptionSetRepository::MAX_LABEL, __( 'Option', 'flexacommerce' ) );
		$options  = array_values( array_filter( is_array( $addon['options'] ?? null ) ? $addon['options'] : [], 'is_array' ) );
		$required = ! empty( $addon['required'] );
		$field    = [
			'label'    => $label,
			'help'     => $this->help( $addon, $label ),
			'required' => $required,
		];
		if ( self::conditional( $addon ) ) {
			$field['required'] = false;
			$this->problems[]  = Problem::warning( 'option_logic_dropped', sprintf( 'The add-on "%1$s" in "%2$s" shows only on some conditions. FlexaCommerce imported it without them, always shown and not required; set its conditions on the Product options screen.', $label, $this->set ) );
		}

		switch ( $type ) {
			case 'multiple_choice':
			case 'select':
			case 'radiobutton':
			case 'checkbox':
				$display = (string) ( $addon['display'] ?? '' );
				$kind    = match ( true ) {
					$type === 'checkbox'                         => OptionField::CHECKBOXES,
					$type === 'radiobutton', $display === 'radiobutton' => OptionField::RADIO,
					$display === 'images'                        => OptionField::BUTTON,
					default                                      => OptionField::SELECT,
				};
				if ( $display === 'images' && $type === 'multiple_choice' ) {
					$this->problems[] = Problem::info( 'option_images_buttons', sprintf( 'The add-on "%1$s" in "%2$s" shows its choices as images. FlexaCommerce shows them as buttons with the choice names.', $label, $this->set ) );
				}
				$choices = $this->choices( $options, $label );
				if ( $choices === [] ) {
					$this->problems[] = Problem::warning( 'option_no_choices', sprintf( 'The add-on "%1$s" in "%2$s" has no choices and was left out.', $label, $this->set ) );
					return [];
				}
				return [
					$field + [
						'type'    => $kind,
						'choices' => $choices,
					],
				];

			case 'custom_text':
			case 'custom_textarea':
				$long = $type === 'custom_textarea';
				return [ $field + $this->text_limits( $addon, $label, $long ) + $this->addon_price( $addon, $label ) + [ 'type' => $long ? OptionField::TEXTAREA : OptionField::TEXT ] ];

			case 'heading':
				return [
					[
						'type'  => OptionField::HEADING,
						'label' => $label,
						'help'  => $field['help'],
					],
				];

			case 'datepicker':
				return [ $field + $this->addon_price( $addon, $label ) + [ 'type' => OptionField::DATE ] ];

			case 'input_multiplier':
				$price = $this->addon_price( $addon, $label, false );
				if ( $price !== [] ) {
					$this->problems[] = Problem::warning( 'option_multiplier_price', sprintf( 'The add-on "%1$s" in "%2$s" charges for each unit the customer enters. FlexaCommerce imported it as a number field without that charge.', $label, $this->set ) );
				}
				$numbers = ! empty( $addon['restrictions'] ) ? [
					'min' => is_numeric( $addon['min'] ?? null ) ? (float) $addon['min'] : null,
					'max' => is_numeric( $addon['max'] ?? null ) && (float) $addon['max'] > 0 ? (float) $addon['max'] : null,
				] : [];
				return [ $field + $numbers + [ 'type' => OptionField::NUMBER ] ];

			case 'file_upload':
				$this->problems[] = Problem::warning( 'option_file_upload', sprintf( 'The add-on "%1$s" in "%2$s" is a file upload, which FlexaCommerce does not have yet. It was left out.', $label, $this->set ) );
				return [];

			case 'custom_price':
				$this->problems[] = Problem::warning( 'option_custom_price', sprintf( 'The add-on "%1$s" in "%2$s" lets the customer name a price, which FlexaCommerce does not have. It was left out.', $label, $this->set ) );
				return [];
		}

		if ( in_array( $type, self::LEGACY_TEXT, true ) ) {
			$out = [];
			foreach ( $options as $i => $option ) {
				[ $mode, $price ] = $this->price( $option['price'] ?? '', (string) ( $option['price_type'] ?? 'quantity_based' ), $label );
				$out[]            = [
					'type'       => OptionField::TEXT,
					'label'      => $this->label( $option['label'] ?? '', OptionSetRepository::MAX_LABEL, $label . ( $i > 0 ? ' ' . ( $i + 1 ) : '' ) ),
					'help'       => $field['help'],
					'required'   => $field['required'],
					'max_length' => is_numeric( $option['max'] ?? null ) ? min( OptionSetRepository::MAX_LENGTH, max( 0, (int) $option['max'] ) ) : 0,
					'price_mode' => $mode,
					'price'      => $price,
				];
			}
			if ( $type !== 'custom' ) {
				$this->problems[] = Problem::info( 'option_restriction_dropped', sprintf( 'The add-on "%1$s" in "%2$s" takes only some characters. FlexaCommerce takes any text.', $label, $this->set ) );
			}
			return $out;
		}

		$this->problems[] = Problem::warning( 'option_unknown_type', sprintf( 'The add-on "%1$s" in "%2$s" has the type "%3$s", which FlexaCommerce does not know. It was left out.', $label, $this->set, $type ) );
		return [];
	}

	/**
	 * @param list<array<array-key,mixed>> $options
	 * @return list<array<string,mixed>>
	 */
	private function choices( array $options, string $label ): array {
		if ( count( $options ) > OptionSetRepository::MAX_CHOICES ) {
			$this->problems[] = Problem::warning( 'option_choices_cut', sprintf( 'The add-on "%1$s" in "%2$s" has %3$d choices and a field holds %4$d. The choices after the first %4$d were left out.', $label, $this->set, count( $options ), OptionSetRepository::MAX_CHOICES ) );
			$options          = array_slice( $options, 0, OptionSetRepository::MAX_CHOICES );
		}
		$out = [];
		foreach ( $options as $i => $option ) {
			// 2.x choices have no price type; they were charged for every unit.
			[ $mode, $price ] = $this->price( $option['price'] ?? '', (string) ( $option['price_type'] ?? 'quantity_based' ), $label );
			$out[]            = [
				'label'      => $this->label( $option['label'] ?? '', OptionSetRepository::MAX_NAME, sprintf( 'Choice %d', $i + 1 ) ),
				'price_mode' => $mode,
				'price'      => $price,
			];
		}
		return $out;
	}

	/**
	 * The price of an add-on that is not a list of choices; 3.x and later
	 * only charge it when "Adjust price" is ticked.
	 *
	 * @param array<array-key,mixed> $addon
	 * @return array{price_mode?:string,price?:int}
	 */
	private function addon_price( array $addon, string $label, bool $report = true ): array {
		if ( array_key_exists( 'adjust_price', $addon ) && empty( $addon['adjust_price'] ) ) {
			return [];
		}
		$problems         = $this->problems;
		[ $mode, $price ] = $this->price( $addon['price'] ?? '', (string) ( $addon['price_type'] ?? 'flat_fee' ), $label );
		if ( ! $report ) {
			$this->problems = $problems;
		}
		return $mode === OptionField::NONE ? [] : [
			'price_mode' => $mode,
			'price'      => $price,
		];
	}

	/**
	 * Product Add-ons price types to price modes: a flat fee is charged once
	 * per cart line, a quantity based price for every unit, a percentage
	 * of the product price.
	 *
	 * @return array{0:string,1:int}
	 */
	private function price( mixed $value, string $type, string $label ): array {
		$clean = Decimal::clean( $value );
		if ( $clean === null || $clean === '0' ) {
			return [ OptionField::NONE, 0 ];
		}
		if ( str_starts_with( $clean, '-' ) ) {
			$this->problems[] = Problem::warning( 'option_price_negative', sprintf( 'The add-on "%1$s" in "%2$s" takes %3$s off the price. FlexaCommerce options only add to it, so this one was imported free.', $label, $this->set, ltrim( $clean, '-' ) ) );
			return [ OptionField::NONE, 0 ];
		}
		if ( $type === 'percentage_based' ) {
			$hundredths = (int) Decimal::round( Decimal::multiply( $clean, '100' ), 0 );
			return $hundredths > 0 ? [ OptionField::PERCENT, min( $hundredths, OptionField::FULL_PERCENT ) ] : [ OptionField::NONE, 0 ];
		}
		$currency          = Settings::string( 'currency' );
		[ $minor, $exact ] = Decimal::to_minor( $clean, $currency, Currency::exponent( $currency ) );
		if ( ! $exact ) {
			$this->problems[] = Problem::warning( 'option_price_rounded', sprintf( 'The price of the add-on "%1$s" in "%2$s" was rounded to %3$s.', $label, $this->set, Decimal::from_minor( $minor, $currency, Currency::exponent( $currency ) ) ) );
		}
		if ( $minor <= 0 ) {
			return [ OptionField::NONE, 0 ];
		}
		return [ $type === 'flat_fee' ? OptionField::ONCE : OptionField::FIXED, $minor ];
	}

	/**
	 * @param array<array-key,mixed> $addon
	 * @return array{max_length?:int}
	 */
	private function text_limits( array $addon, string $label, bool $long ): array {
		if ( empty( $addon['restrictions'] ) ) {
			return [];
		}
		$rule = (string) ( $addon['restrictions_type'] ?? 'any_text' );
		if ( ! $long && $rule !== '' && $rule !== 'any_text' ) {
			$this->problems[] = Problem::info( 'option_restriction_dropped', sprintf( 'The add-on "%1$s" in "%2$s" takes only some characters. FlexaCommerce takes any text.', $label, $this->set ) );
		}
		if ( is_numeric( $addon['min'] ?? null ) && (int) $addon['min'] > 0 ) {
			$this->problems[] = Problem::info( 'option_min_length_dropped', sprintf( 'The add-on "%1$s" in "%2$s" needs at least %3$d characters. FlexaCommerce has no lower limit, so any length up to the upper one is taken.', $label, $this->set, (int) $addon['min'] ) );
		}
		$max = is_numeric( $addon['max'] ?? null ) ? (int) $addon['max'] : 0;
		return $max > 0 ? [ 'max_length' => min( $max, OptionSetRepository::MAX_LENGTH ) ] : [];
	}

	/** @param array<array-key,mixed> $addon */
	private function help( array $addon, string $label ): string {
		if ( empty( $addon['description_enable'] ) && array_key_exists( 'description_enable', $addon ) ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $addon['description'] ?? '' ) ) ) );
		if ( mb_strlen( $text ) > OptionSetRepository::MAX_HELP ) {
			$this->problems[] = Problem::info( 'option_help_cut', sprintf( 'The description of the add-on "%1$s" in "%2$s" was cut to %3$d characters.', $label, $this->set, OptionSetRepository::MAX_HELP ) );
			$text             = rtrim( mb_substr( $text, 0, OptionSetRepository::MAX_HELP - 1 ) ) . '…';
		}
		return $text;
	}

	private function label( mixed $value, int $max, string $fallback ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' ) ) );
		$text = $text !== '' ? $text : $fallback;
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Whether a newer add-on shows only on conditions.
	 *
	 * @param array<array-key,mixed> $addon
	 */
	private static function conditional( array $addon ): bool {
		foreach ( [ 'conditional_logic', 'conditions' ] as $key ) {
			$value = $addon[ $key ] ?? null;
			if ( is_array( $value ) ? $value !== [] : ! empty( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<mixed> $refs
	 * @return list<int>
	 */
	private function products( array $refs, Mapping $map, bool $report ): array {
		$out     = [];
		$missing = 0;
		foreach ( $refs as $ref ) {
			$id = $map->target_id( Record::PRODUCT, Ref::parse( is_string( $ref ) ? $ref : null )->id ?? '' );
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			} else {
				++$missing;
			}
		}
		if ( $missing > 0 && $report ) {
			$this->problems[] = Problem::warning( 'option_product_missing', sprintf( '%1$d products with the add-ons of "%2$s" were not imported and were left out of the option set.', $missing, $this->set ) );
		}
		$out = array_values( $out );
		if ( count( $out ) > OptionSetRepository::MAX_LIST ) {
			$this->problems[] = Problem::warning( 'option_products_cut', sprintf( 'The option set "%1$s" names %2$d products and holds %3$d. The products after the first %3$d were left out.', $this->set, count( $out ), OptionSetRepository::MAX_LIST ) );
			$out              = array_slice( $out, 0, OptionSetRepository::MAX_LIST );
		}
		return $out;
	}

	/**
	 * @param array<mixed> $refs
	 * @return list<int>
	 */
	private function categories( array $refs, Mapping $map ): array {
		$out     = [];
		$missing = 0;
		foreach ( $refs as $ref ) {
			$id = $map->target_id( Record::TERM, Ref::parse( is_string( $ref ) ? $ref : null )->id ?? '' );
			if ( $id > 0 ) {
				$out[] = $id;
			} else {
				++$missing;
			}
		}
		if ( $missing > 0 ) {
			$this->problems[] = Problem::warning( 'option_category_missing', sprintf( '%1$d categories of the add-on group "%2$s" were not imported and were left out of the option set.', $missing, $this->set ) );
		}
		return array_slice( array_values( array_unique( $out ) ), 0, OptionSetRepository::MAX_LIST );
	}
}
