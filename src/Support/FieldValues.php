<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\SettingField;

defined( 'ABSPATH' ) || exit;

/**
 * Values for a list of SettingField (gateway settings, shipping method
 * instances). Unknown keys are dropped; a secret left empty or masked
 * keeps the stored value, and secrets leave only as `••••last4`.
 */
final class FieldValues {
	public const MASK = '••••';

	/**
	 * @param list<SettingField>  $fields
	 * @param array<string,mixed> $stored
	 * @return array<string,mixed>
	 */
	public static function with_defaults( array $fields, array $stored ): array {
		$out = [];
		foreach ( $fields as $field ) {
			$out[ $field->id ] = array_key_exists( $field->id, $stored ) ? $stored[ $field->id ] : $field->default;
		}
		return $out;
	}

	/**
	 * @param list<SettingField>  $fields
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $stored
	 * @return array<string,mixed> stored values merged with the sanitized input
	 */
	public static function sanitize( array $fields, array $input, array $stored ): array {
		$out = self::with_defaults( $fields, $stored );
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field->id, $input ) ) {
				continue;
			}
			$value = $input[ $field->id ];
			if ( $field->type === 'secret' ) {
				$text = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( $text === '' || str_starts_with( $text, self::MASK ) ) {
					continue;
				}
				$out[ $field->id ] = $text;
				continue;
			}
			$out[ $field->id ] = self::value( $field, $value );
		}
		return $out;
	}

	/**
	 * @param list<SettingField>  $fields
	 * @param array<string,mixed> $values
	 * @return array<string,mixed> safe to send to the browser
	 */
	public static function masked( array $fields, array $values ): array {
		foreach ( $fields as $field ) {
			if ( $field->type === 'secret' ) {
				$secret               = is_string( $values[ $field->id ] ?? null ) ? $values[ $field->id ] : '';
				$values[ $field->id ] = $secret === '' ? '' : self::MASK . substr( $secret, -4 );
			}
		}
		return $values;
	}

	private static function value( SettingField $field, mixed $value ): mixed {
		if ( $field->type === 'checkbox' ) {
			return in_array( $value, [ true, 1, '1', 'yes', 'true', 'on' ], true );
		}
		if ( $field->type === 'number' || $field->type === 'money' ) {
			if ( ! is_numeric( $value ) ) {
				return $field->default;
			}
			return $field->type === 'money' ? max( 0, (int) $value ) : $value + 0;
		}
		$text = is_scalar( $value ) ? (string) $value : '';
		if ( $field->type === 'select' ) {
			return array_key_exists( $text, $field->options ) ? $text : $field->default;
		}
		return $field->type === 'textarea' ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
	}
}
