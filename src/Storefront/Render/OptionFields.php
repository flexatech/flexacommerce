<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSet;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Pricing\PricingService;

defined( 'ABSPATH' ) || exit;

/**
 * The product option fields of the add-to-cart form (ADR-025). They post
 * `options[<set>-<field>]`, so the form works without JavaScript. Which
 * fields show at first is worked out here from the choices picked at the
 * start; a hidden field has its inputs disabled, so it posts nothing. With
 * JavaScript the store shows and hides fields as the customer picks, and
 * adds up what the options cost.
 */
final class OptionFields {
	/**
	 * @param list<OptionSet> $sets
	 */
	public static function html( array $sets, PricingService $money ): string {
		$html = '';
		foreach ( $sets as $set ) {
			$values = self::defaults( $set );
			$html  .= '<div class="fxc-options__set">';
			foreach ( $set->fields as $field ) {
				$html .= self::field( $set, $field, $field->visible( $values ), $money );
			}
			$html .= '</div>';
		}
		return '<div class="fxc-options" data-wp-on--change="actions.options" data-wp-on--input="actions.options" data-wp-init="actions.options">'
			. $html
			. '<p class="fxc-options__total" data-wp-bind--hidden="!context.extra" hidden>'
			. esc_html__( 'Options:', 'flexacommerce' ) . ' <span data-wp-text="context.extra"></span></p></div>';
	}

	/**
	 * What the store needs to show, hide and price the fields: in page
	 * order, the logic and the prices only.
	 *
	 * @param list<OptionSet> $sets
	 * @return list<array<string,mixed>>
	 */
	public static function spec( array $sets ): array {
		$spec = [];
		foreach ( $sets as $set ) {
			foreach ( $set->fields as $field ) {
				$row = [
					'key'  => ProductOptions::key( $set->id, $field->id ),
					'set'  => $set->id,
					'id'   => $field->id,
					'type' => $field->type,
				];
				if ( $field->logic !== null ) {
					$row['logic'] = $field->logic;
				}
				if ( $field->has_choices() ) {
					$prices = [];
					foreach ( $field->choices as $choice ) {
						if ( $choice['price_mode'] !== OptionField::NONE ) {
							$prices[ (string) $choice['id'] ] = [ $choice['price_mode'], $choice['price'] ];
						}
					}
					if ( $prices !== [] ) {
						$row['prices'] = $prices;
					}
				} elseif ( $field->price_mode !== OptionField::NONE ) {
					$row['price'] = [ $field->price_mode, $field->price ];
				}
				$spec[] = $row;
			}
		}
		return $spec;
	}

	/** @param list<OptionSet> $sets */
	public static function has_percent( array $sets ): bool {
		foreach ( $sets as $set ) {
			foreach ( $set->fields as $field ) {
				if ( $field->price_mode === OptionField::PERCENT ) {
					return true;
				}
				foreach ( $field->choices as $choice ) {
					if ( $choice['price_mode'] === OptionField::PERCENT ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** @return array<int,string|list<string>> field id => the value picked at the start */
	private static function defaults( OptionSet $set ): array {
		$values = [];
		foreach ( $set->fields as $field ) {
			if ( ! $field->has_choices() || ! $field->visible( $values ) ) {
				continue;
			}
			$picked = [];
			foreach ( $field->choices as $choice ) {
				if ( $choice['default'] ) {
					$picked[] = (string) $choice['id'];
				}
			}
			if ( $picked !== [] ) {
				$values[ $field->id ] = $field->type === OptionField::CHECKBOXES ? $picked : $picked[0];
			}
		}
		return $values;
	}

	private static function field( OptionSet $set, OptionField $field, bool $visible, PricingService $money ): string {
		$key    = ProductOptions::key( $set->id, $field->id );
		$id     = wp_unique_id( 'fxc-option-' );
		$hidden = $visible ? '' : ' hidden';
		$off    = $visible ? '' : ' disabled';
		$help   = $field->help !== '' ? sprintf( '<p class="fxc-option__help" id="%1$s-help">%2$s</p>', esc_attr( $id ), esc_html( $field->help ) ) : '';
		$about  = $field->help !== '' ? sprintf( ' aria-describedby="%s-help"', esc_attr( $id ) ) : '';
		$attrs  = sprintf( 'class="fxc-option fxc-option--%1$s" data-option="%2$s"%3$s', esc_attr( $field->type ), esc_attr( $key ), $hidden );

		if ( $field->type === OptionField::HEADING ) {
			return sprintf( '<div %1$s><p class="fxc-option__heading">%2$s</p>%3$s</div>', $attrs, esc_html( $field->label ), $help );
		}

		$required = $field->required ? ' <span class="fxc-option__required" aria-hidden="true">*</span>' : '';
		$name     = 'options[' . $key . ']';

		if ( $field->type === OptionField::SWATCH || $field->type === OptionField::BUTTON ) {
			$swatch = $field->type === OptionField::SWATCH;
			$html   = sprintf( '<fieldset %1$s%2$s><legend>%3$s%4$s</legend><div class="fxc-swatches__list">', $attrs, $about, esc_html( $field->label ), $required );
			foreach ( $field->choices as $choice ) {
				$price = self::price_tag( $choice['price_mode'], $choice['price'], $money );
				$text  = esc_html( $choice['label'] ) . $price;
				$html .= sprintf(
					'<label class="fxc-swatch fxc-swatch--%1$s"%2$s><input class="fxc-swatch__input" type="radio" name="%3$s" value="%4$d"%5$s%6$s%7$s><span class="fxc-swatch__face"%8$s>%9$s</span>%10$s</label>',
					$swatch ? 'color' : 'button',
					$swatch ? ' title="' . esc_attr( wp_strip_all_tags( $text ) ) . '"' : '',
					esc_attr( $name ),
					$choice['id'],
					$choice['default'] ? ' checked' : '',
					$field->required ? ' required' : '',
					$off,
					$swatch ? ' style="--fxc-swatch-color:' . esc_attr( $choice['color'] ) . '"' : '',
					$swatch ? '' : $text,
					$swatch ? '<span class="screen-reader-text">' . $text . '</span>' : ''
				);
			}
			return $html . '</div>' . $help . '</fieldset>';
		}

		if ( $field->type === OptionField::RADIO || $field->type === OptionField::CHECKBOXES ) {
			$radio = $field->type === OptionField::RADIO;
			$html  = sprintf( '<fieldset %1$s%2$s><legend>%3$s%4$s</legend>', $attrs, $about, esc_html( $field->label ), $required );
			foreach ( $field->choices as $choice ) {
				$html .= sprintf(
					'<label class="fxc-option__choice"><input type="%1$s" name="%2$s" value="%3$d"%4$s%5$s%6$s> %7$s%8$s</label>',
					$radio ? 'radio' : 'checkbox',
					esc_attr( $radio ? $name : $name . '[]' ),
					$choice['id'],
					$choice['default'] ? ' checked' : '',
					$radio && $field->required ? ' required' : '',
					$off,
					esc_html( $choice['label'] ),
					self::price_tag( $choice['price_mode'], $choice['price'], $money )
				);
			}
			return $html . $help . '</fieldset>';
		}

		$label  = sprintf(
			'<label for="%1$s">%2$s%3$s%4$s</label> ',
			esc_attr( $id ),
			esc_html( $field->label ),
			$field->has_choices() ? '' : self::price_tag( $field->price_mode, $field->price, $money ),
			$required
		);
		$common = sprintf( 'id="%1$s" name="%2$s"%3$s%4$s%5$s', esc_attr( $id ), esc_attr( $name ), $field->required ? ' required' : '', $about, $off );

		if ( $field->type === OptionField::SELECT ) {
			$control = '<select ' . $common . '><option value="">' . esc_html__( 'Choose an option', 'flexacommerce' ) . '</option>';
			foreach ( $field->choices as $choice ) {
				$control .= sprintf(
					'<option value="%1$d"%2$s>%3$s%4$s</option>',
					$choice['id'],
					$choice['default'] ? ' selected' : '',
					esc_html( $choice['label'] ),
					wp_strip_all_tags( self::price_tag( $choice['price_mode'], $choice['price'], $money ) )
				);
			}
			$control .= '</select>';
		} elseif ( $field->type === OptionField::DATE ) {
			[ $earliest, $latest ] = $field->date_range();
			$control               = sprintf(
				'<input type="date" %1$s%2$s%3$s>',
				$common,
				$earliest !== null ? ' min="' . esc_attr( $earliest ) . '"' : '',
				$latest !== null ? ' max="' . esc_attr( $latest ) . '"' : ''
			);
		} elseif ( $field->type === OptionField::COLOR ) {
			$control = sprintf( '<input type="color" %1$s value="%2$s">', $common, esc_attr( $field->placeholder !== '' ? $field->placeholder : OptionField::START ) );
		} elseif ( $field->type === OptionField::NUMBER ) {
			$control = sprintf(
				'<input type="number" %1$s%2$s%3$s step="%4$s"%5$s inputmode="decimal">',
				$common,
				$field->min !== null ? ' min="' . esc_attr( self::number( $field->min ) ) . '"' : '',
				$field->max !== null ? ' max="' . esc_attr( self::number( $field->max ) ) . '"' : '',
				esc_attr( $field->step !== null ? self::number( $field->step ) : 'any' ),
				$field->placeholder !== '' ? ' placeholder="' . esc_attr( $field->placeholder ) . '"' : ''
			);
		} else {
			$max     = $field->max_length > 0 ? sprintf( ' maxlength="%d"', $field->max_length ) : '';
			$hint    = $field->placeholder !== '' ? ' placeholder="' . esc_attr( $field->placeholder ) . '"' : '';
			$control = $field->type === OptionField::TEXTAREA
				? '<textarea ' . $common . $max . $hint . ' rows="3"></textarea>'
				: '<input type="text" ' . $common . $max . $hint . '>';
		}
		return sprintf( '<div %1$s>%2$s%3$s%4$s</div>', $attrs, $label, $control, $help );
	}

	/** ` (+$2.00)`, ` (+10%)` or ` (+$5.00 once)`, empty for a free choice. */
	private static function price_tag( string $mode, int $price, PricingService $money ): string {
		if ( $mode === OptionField::NONE || $price <= 0 ) {
			return '';
		}
		$text = $mode === OptionField::PERCENT
			? rtrim( rtrim( number_format( $price / 100, 2, '.', '' ), '0' ), '.' ) . '%'
			: $money->format( $money->money( $price ) );
		if ( $mode === OptionField::ONCE ) {
			/* translators: %s: an amount charged once per cart line, whatever the quantity. */
			$text = sprintf( __( '%s once', 'flexacommerce' ), $text );
		}
		return ' <span class="fxc-option__price">(+' . esc_html( $text ) . ')</span>';
	}

	private static function number( float $value ): string {
		return rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
	}
}
