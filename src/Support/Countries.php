<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * ISO 3166-1 alpha-2 countries with translated names, the states the
 * checkout offers as a list, and postcode rules. Countries without a state
 * list take free text.
 */
final class Countries {
	/** Countries where addresses have no postcode: the field is hidden. */
	private const NO_POSTCODE = [ 'AE', 'AG', 'AO', 'AW', 'BF', 'BI', 'BJ', 'BO', 'BS', 'BW', 'BZ', 'CD', 'CF', 'CG', 'CI', 'CK', 'CM', 'DJ', 'DM', 'ER', 'FJ', 'GA', 'GD', 'GH', 'GM', 'GQ', 'GY', 'HK', 'KI', 'KM', 'KN', 'KP', 'LY', 'ML', 'MO', 'MR', 'MW', 'NR', 'NU', 'QA', 'RW', 'SB', 'SC', 'SL', 'SR', 'ST', 'SY', 'TD', 'TF', 'TG', 'TK', 'TL', 'TO', 'TV', 'UG', 'VU', 'YE', 'ZW' ];

	/** Postcode shapes checked at checkout, after normalize_postcode(). */
	private const POSTCODE_PATTERNS = [
		'US' => '/^\d{5}(-\d{4})?$/',
		'CA' => '/^[A-Z]\d[A-Z] ?\d[A-Z]\d$/',
		'GB' => '/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/',
		'AU' => '/^\d{4}$/',
		'NZ' => '/^\d{4}$/',
		'DE' => '/^\d{5}$/',
		'FR' => '/^\d{5}$/',
		'IT' => '/^\d{5}$/',
		'ES' => '/^\d{5}$/',
		'NL' => '/^\d{4} ?[A-Z]{2}$/',
		'BE' => '/^\d{4}$/',
		'AT' => '/^\d{4}$/',
		'CH' => '/^\d{4}$/',
		'JP' => '/^\d{3}-?\d{4}$/',
		'IN' => '/^\d{6}$/',
		'VN' => '/^\d{6}$/',
		'BR' => '/^\d{5}-?\d{3}$/',
	];

	/** @var array<string,string>|null */
	private static ?array $names = null;

	/** @return array<string,string> code => name, sorted by name */
	public static function all(): array {
		if ( self::$names === null ) {
			$names = self::names();
			uasort( $names, static fn ( string $a, string $b ): int => strcasecmp( remove_accents( $a ), remove_accents( $b ) ) );
			self::$names = $names;
		}
		return self::$names;
	}

	public static function exists( string $code ): bool {
		return isset( self::all()[ $code ] );
	}

	public static function name( string $code ): string {
		return self::all()[ $code ] ?? $code;
	}

	/**
	 * The countries the store sells to: the `sell_to_countries` setting, or
	 * every country when it is empty.
	 *
	 * @return array<string,string>
	 */
	public static function allowed(): array {
		$codes = Settings::get( 'sell_to_countries' );
		if ( ! is_array( $codes ) || $codes === [] ) {
			return self::all();
		}
		return array_intersect_key( self::all(), array_flip( $codes ) );
	}

	/** @return array<string,string> state code => name; empty means free text */
	public static function states( string $country ): array {
		return match ( $country ) {
			'US'    => self::us_states(),
			'CA'    => [
				'AB' => __( 'Alberta', 'flexacommerce' ),
				'BC' => __( 'British Columbia', 'flexacommerce' ),
				'MB' => __( 'Manitoba', 'flexacommerce' ),
				'NB' => __( 'New Brunswick', 'flexacommerce' ),
				'NL' => __( 'Newfoundland and Labrador', 'flexacommerce' ),
				'NT' => __( 'Northwest Territories', 'flexacommerce' ),
				'NS' => __( 'Nova Scotia', 'flexacommerce' ),
				'NU' => __( 'Nunavut', 'flexacommerce' ),
				'ON' => __( 'Ontario', 'flexacommerce' ),
				'PE' => __( 'Prince Edward Island', 'flexacommerce' ),
				'QC' => __( 'Quebec', 'flexacommerce' ),
				'SK' => __( 'Saskatchewan', 'flexacommerce' ),
				'YT' => __( 'Yukon', 'flexacommerce' ),
			],
			'AU'    => [
				'ACT' => __( 'Australian Capital Territory', 'flexacommerce' ),
				'NSW' => __( 'New South Wales', 'flexacommerce' ),
				'NT'  => __( 'Northern Territory', 'flexacommerce' ),
				'QLD' => __( 'Queensland', 'flexacommerce' ),
				'SA'  => __( 'South Australia', 'flexacommerce' ),
				'TAS' => __( 'Tasmania', 'flexacommerce' ),
				'VIC' => __( 'Victoria', 'flexacommerce' ),
				'WA'  => __( 'Western Australia', 'flexacommerce' ),
			],
			default => [],
		};
	}

	/**
	 * The state code for a value that may be the code or the name, as a
	 * wallet shares it ("CA" or "California"). Anything else is kept.
	 */
	public static function state_code( string $country, string $value ): string {
		$value  = trim( $value );
		$states = self::states( $country );
		if ( $states === [] || isset( $states[ strtoupper( $value ) ] ) ) {
			return $states === [] ? $value : strtoupper( $value );
		}
		foreach ( $states as $code => $name ) {
			if ( strcasecmp( $name, $value ) === 0 ) {
				return (string) $code;
			}
		}
		return $value;
	}

	public static function uses_postcode( string $country ): bool {
		return ! in_array( $country, self::NO_POSTCODE, true );
	}

	public static function normalize_postcode( string $postcode ): string {
		return strtoupper( trim( (string) preg_replace( '/\s+/', ' ', $postcode ) ) );
	}

	/** Countries without a known pattern accept any non-empty value of up to 16 characters. */
	public static function valid_postcode( string $country, string $postcode ): bool {
		$postcode = self::normalize_postcode( $postcode );
		if ( isset( self::POSTCODE_PATTERNS[ $country ] ) ) {
			return preg_match( self::POSTCODE_PATTERNS[ $country ], $postcode ) === 1;
		}
		return $postcode !== '' && strlen( $postcode ) <= 16;
	}

	/** @return array<string,string> */
	private static function us_states(): array {
		return [
			'AL' => __( 'Alabama', 'flexacommerce' ),
			'AK' => __( 'Alaska', 'flexacommerce' ),
			'AZ' => __( 'Arizona', 'flexacommerce' ),
			'AR' => __( 'Arkansas', 'flexacommerce' ),
			'CA' => __( 'California', 'flexacommerce' ),
			'CO' => __( 'Colorado', 'flexacommerce' ),
			'CT' => __( 'Connecticut', 'flexacommerce' ),
			'DE' => __( 'Delaware', 'flexacommerce' ),
			'DC' => __( 'District of Columbia', 'flexacommerce' ),
			'FL' => __( 'Florida', 'flexacommerce' ),
			'GA' => __( 'Georgia', 'flexacommerce' ),
			'HI' => __( 'Hawaii', 'flexacommerce' ),
			'ID' => __( 'Idaho', 'flexacommerce' ),
			'IL' => __( 'Illinois', 'flexacommerce' ),
			'IN' => __( 'Indiana', 'flexacommerce' ),
			'IA' => __( 'Iowa', 'flexacommerce' ),
			'KS' => __( 'Kansas', 'flexacommerce' ),
			'KY' => __( 'Kentucky', 'flexacommerce' ),
			'LA' => __( 'Louisiana', 'flexacommerce' ),
			'ME' => __( 'Maine', 'flexacommerce' ),
			'MD' => __( 'Maryland', 'flexacommerce' ),
			'MA' => __( 'Massachusetts', 'flexacommerce' ),
			'MI' => __( 'Michigan', 'flexacommerce' ),
			'MN' => __( 'Minnesota', 'flexacommerce' ),
			'MS' => __( 'Mississippi', 'flexacommerce' ),
			'MO' => __( 'Missouri', 'flexacommerce' ),
			'MT' => __( 'Montana', 'flexacommerce' ),
			'NE' => __( 'Nebraska', 'flexacommerce' ),
			'NV' => __( 'Nevada', 'flexacommerce' ),
			'NH' => __( 'New Hampshire', 'flexacommerce' ),
			'NJ' => __( 'New Jersey', 'flexacommerce' ),
			'NM' => __( 'New Mexico', 'flexacommerce' ),
			'NY' => __( 'New York', 'flexacommerce' ),
			'NC' => __( 'North Carolina', 'flexacommerce' ),
			'ND' => __( 'North Dakota', 'flexacommerce' ),
			'OH' => __( 'Ohio', 'flexacommerce' ),
			'OK' => __( 'Oklahoma', 'flexacommerce' ),
			'OR' => __( 'Oregon', 'flexacommerce' ),
			'PA' => __( 'Pennsylvania', 'flexacommerce' ),
			'RI' => __( 'Rhode Island', 'flexacommerce' ),
			'SC' => __( 'South Carolina', 'flexacommerce' ),
			'SD' => __( 'South Dakota', 'flexacommerce' ),
			'TN' => __( 'Tennessee', 'flexacommerce' ),
			'TX' => __( 'Texas', 'flexacommerce' ),
			'UT' => __( 'Utah', 'flexacommerce' ),
			'VT' => __( 'Vermont', 'flexacommerce' ),
			'VA' => __( 'Virginia', 'flexacommerce' ),
			'WA' => __( 'Washington', 'flexacommerce' ),
			'WV' => __( 'West Virginia', 'flexacommerce' ),
			'WI' => __( 'Wisconsin', 'flexacommerce' ),
			'WY' => __( 'Wyoming', 'flexacommerce' ),
			'AA' => __( 'Armed Forces (AA)', 'flexacommerce' ),
			'AE' => __( 'Armed Forces (AE)', 'flexacommerce' ),
			'AP' => __( 'Armed Forces (AP)', 'flexacommerce' ),
		];
	}

	/** @return array<string,string> */
	private static function names(): array {
		return [
			'AD' => __( 'Andorra', 'flexacommerce' ),
			'AE' => __( 'United Arab Emirates', 'flexacommerce' ),
			'AF' => __( 'Afghanistan', 'flexacommerce' ),
			'AG' => __( 'Antigua & Barbuda', 'flexacommerce' ),
			'AI' => __( 'Anguilla', 'flexacommerce' ),
			'AL' => __( 'Albania', 'flexacommerce' ),
			'AM' => __( 'Armenia', 'flexacommerce' ),
			'AO' => __( 'Angola', 'flexacommerce' ),
			'AQ' => __( 'Antarctica', 'flexacommerce' ),
			'AR' => __( 'Argentina', 'flexacommerce' ),
			'AS' => __( 'American Samoa', 'flexacommerce' ),
			'AT' => __( 'Austria', 'flexacommerce' ),
			'AU' => __( 'Australia', 'flexacommerce' ),
			'AW' => __( 'Aruba', 'flexacommerce' ),
			'AX' => __( 'Åland Islands', 'flexacommerce' ),
			'AZ' => __( 'Azerbaijan', 'flexacommerce' ),
			'BA' => __( 'Bosnia & Herzegovina', 'flexacommerce' ),
			'BB' => __( 'Barbados', 'flexacommerce' ),
			'BD' => __( 'Bangladesh', 'flexacommerce' ),
			'BE' => __( 'Belgium', 'flexacommerce' ),
			'BF' => __( 'Burkina Faso', 'flexacommerce' ),
			'BG' => __( 'Bulgaria', 'flexacommerce' ),
			'BH' => __( 'Bahrain', 'flexacommerce' ),
			'BI' => __( 'Burundi', 'flexacommerce' ),
			'BJ' => __( 'Benin', 'flexacommerce' ),
			'BL' => __( 'St. Barthélemy', 'flexacommerce' ),
			'BM' => __( 'Bermuda', 'flexacommerce' ),
			'BN' => __( 'Brunei', 'flexacommerce' ),
			'BO' => __( 'Bolivia', 'flexacommerce' ),
			'BQ' => __( 'Caribbean Netherlands', 'flexacommerce' ),
			'BR' => __( 'Brazil', 'flexacommerce' ),
			'BS' => __( 'Bahamas', 'flexacommerce' ),
			'BT' => __( 'Bhutan', 'flexacommerce' ),
			'BV' => __( 'Bouvet Island', 'flexacommerce' ),
			'BW' => __( 'Botswana', 'flexacommerce' ),
			'BY' => __( 'Belarus', 'flexacommerce' ),
			'BZ' => __( 'Belize', 'flexacommerce' ),
			'CA' => __( 'Canada', 'flexacommerce' ),
			'CC' => __( 'Cocos (Keeling) Islands', 'flexacommerce' ),
			'CD' => __( 'Congo - Kinshasa', 'flexacommerce' ),
			'CF' => __( 'Central African Republic', 'flexacommerce' ),
			'CG' => __( 'Congo - Brazzaville', 'flexacommerce' ),
			'CH' => __( 'Switzerland', 'flexacommerce' ),
			'CI' => __( 'Côte d’Ivoire', 'flexacommerce' ),
			'CK' => __( 'Cook Islands', 'flexacommerce' ),
			'CL' => __( 'Chile', 'flexacommerce' ),
			'CM' => __( 'Cameroon', 'flexacommerce' ),
			'CN' => __( 'China', 'flexacommerce' ),
			'CO' => __( 'Colombia', 'flexacommerce' ),
			'CR' => __( 'Costa Rica', 'flexacommerce' ),
			'CU' => __( 'Cuba', 'flexacommerce' ),
			'CV' => __( 'Cape Verde', 'flexacommerce' ),
			'CW' => __( 'Curaçao', 'flexacommerce' ),
			'CX' => __( 'Christmas Island', 'flexacommerce' ),
			'CY' => __( 'Cyprus', 'flexacommerce' ),
			'CZ' => __( 'Czechia', 'flexacommerce' ),
			'DE' => __( 'Germany', 'flexacommerce' ),
			'DJ' => __( 'Djibouti', 'flexacommerce' ),
			'DK' => __( 'Denmark', 'flexacommerce' ),
			'DM' => __( 'Dominica', 'flexacommerce' ),
			'DO' => __( 'Dominican Republic', 'flexacommerce' ),
			'DZ' => __( 'Algeria', 'flexacommerce' ),
			'EC' => __( 'Ecuador', 'flexacommerce' ),
			'EE' => __( 'Estonia', 'flexacommerce' ),
			'EG' => __( 'Egypt', 'flexacommerce' ),
			'EH' => __( 'Western Sahara', 'flexacommerce' ),
			'ER' => __( 'Eritrea', 'flexacommerce' ),
			'ES' => __( 'Spain', 'flexacommerce' ),
			'ET' => __( 'Ethiopia', 'flexacommerce' ),
			'FI' => __( 'Finland', 'flexacommerce' ),
			'FJ' => __( 'Fiji', 'flexacommerce' ),
			'FK' => __( 'Falkland Islands', 'flexacommerce' ),
			'FM' => __( 'Micronesia', 'flexacommerce' ),
			'FO' => __( 'Faroe Islands', 'flexacommerce' ),
			'FR' => __( 'France', 'flexacommerce' ),
			'GA' => __( 'Gabon', 'flexacommerce' ),
			'GB' => __( 'United Kingdom', 'flexacommerce' ),
			'GD' => __( 'Grenada', 'flexacommerce' ),
			'GE' => __( 'Georgia', 'flexacommerce' ),
			'GF' => __( 'French Guiana', 'flexacommerce' ),
			'GG' => __( 'Guernsey', 'flexacommerce' ),
			'GH' => __( 'Ghana', 'flexacommerce' ),
			'GI' => __( 'Gibraltar', 'flexacommerce' ),
			'GL' => __( 'Greenland', 'flexacommerce' ),
			'GM' => __( 'Gambia', 'flexacommerce' ),
			'GN' => __( 'Guinea', 'flexacommerce' ),
			'GP' => __( 'Guadeloupe', 'flexacommerce' ),
			'GQ' => __( 'Equatorial Guinea', 'flexacommerce' ),
			'GR' => __( 'Greece', 'flexacommerce' ),
			'GS' => __( 'South Georgia & South Sandwich Islands', 'flexacommerce' ),
			'GT' => __( 'Guatemala', 'flexacommerce' ),
			'GU' => __( 'Guam', 'flexacommerce' ),
			'GW' => __( 'Guinea-Bissau', 'flexacommerce' ),
			'GY' => __( 'Guyana', 'flexacommerce' ),
			'HK' => __( 'Hong Kong SAR China', 'flexacommerce' ),
			'HM' => __( 'Heard & McDonald Islands', 'flexacommerce' ),
			'HN' => __( 'Honduras', 'flexacommerce' ),
			'HR' => __( 'Croatia', 'flexacommerce' ),
			'HT' => __( 'Haiti', 'flexacommerce' ),
			'HU' => __( 'Hungary', 'flexacommerce' ),
			'ID' => __( 'Indonesia', 'flexacommerce' ),
			'IE' => __( 'Ireland', 'flexacommerce' ),
			'IL' => __( 'Israel', 'flexacommerce' ),
			'IM' => __( 'Isle of Man', 'flexacommerce' ),
			'IN' => __( 'India', 'flexacommerce' ),
			'IO' => __( 'British Indian Ocean Territory', 'flexacommerce' ),
			'IQ' => __( 'Iraq', 'flexacommerce' ),
			'IR' => __( 'Iran', 'flexacommerce' ),
			'IS' => __( 'Iceland', 'flexacommerce' ),
			'IT' => __( 'Italy', 'flexacommerce' ),
			'JE' => __( 'Jersey', 'flexacommerce' ),
			'JM' => __( 'Jamaica', 'flexacommerce' ),
			'JO' => __( 'Jordan', 'flexacommerce' ),
			'JP' => __( 'Japan', 'flexacommerce' ),
			'KE' => __( 'Kenya', 'flexacommerce' ),
			'KG' => __( 'Kyrgyzstan', 'flexacommerce' ),
			'KH' => __( 'Cambodia', 'flexacommerce' ),
			'KI' => __( 'Kiribati', 'flexacommerce' ),
			'KM' => __( 'Comoros', 'flexacommerce' ),
			'KN' => __( 'St. Kitts & Nevis', 'flexacommerce' ),
			'KP' => __( 'North Korea', 'flexacommerce' ),
			'KR' => __( 'South Korea', 'flexacommerce' ),
			'KW' => __( 'Kuwait', 'flexacommerce' ),
			'KY' => __( 'Cayman Islands', 'flexacommerce' ),
			'KZ' => __( 'Kazakhstan', 'flexacommerce' ),
			'LA' => __( 'Laos', 'flexacommerce' ),
			'LB' => __( 'Lebanon', 'flexacommerce' ),
			'LC' => __( 'St. Lucia', 'flexacommerce' ),
			'LI' => __( 'Liechtenstein', 'flexacommerce' ),
			'LK' => __( 'Sri Lanka', 'flexacommerce' ),
			'LR' => __( 'Liberia', 'flexacommerce' ),
			'LS' => __( 'Lesotho', 'flexacommerce' ),
			'LT' => __( 'Lithuania', 'flexacommerce' ),
			'LU' => __( 'Luxembourg', 'flexacommerce' ),
			'LV' => __( 'Latvia', 'flexacommerce' ),
			'LY' => __( 'Libya', 'flexacommerce' ),
			'MA' => __( 'Morocco', 'flexacommerce' ),
			'MC' => __( 'Monaco', 'flexacommerce' ),
			'MD' => __( 'Moldova', 'flexacommerce' ),
			'ME' => __( 'Montenegro', 'flexacommerce' ),
			'MF' => __( 'St. Martin', 'flexacommerce' ),
			'MG' => __( 'Madagascar', 'flexacommerce' ),
			'MH' => __( 'Marshall Islands', 'flexacommerce' ),
			'MK' => __( 'North Macedonia', 'flexacommerce' ),
			'ML' => __( 'Mali', 'flexacommerce' ),
			'MM' => __( 'Myanmar (Burma)', 'flexacommerce' ),
			'MN' => __( 'Mongolia', 'flexacommerce' ),
			'MO' => __( 'Macao SAR China', 'flexacommerce' ),
			'MP' => __( 'Northern Mariana Islands', 'flexacommerce' ),
			'MQ' => __( 'Martinique', 'flexacommerce' ),
			'MR' => __( 'Mauritania', 'flexacommerce' ),
			'MS' => __( 'Montserrat', 'flexacommerce' ),
			'MT' => __( 'Malta', 'flexacommerce' ),
			'MU' => __( 'Mauritius', 'flexacommerce' ),
			'MV' => __( 'Maldives', 'flexacommerce' ),
			'MW' => __( 'Malawi', 'flexacommerce' ),
			'MX' => __( 'Mexico', 'flexacommerce' ),
			'MY' => __( 'Malaysia', 'flexacommerce' ),
			'MZ' => __( 'Mozambique', 'flexacommerce' ),
			'NA' => __( 'Namibia', 'flexacommerce' ),
			'NC' => __( 'New Caledonia', 'flexacommerce' ),
			'NE' => __( 'Niger', 'flexacommerce' ),
			'NF' => __( 'Norfolk Island', 'flexacommerce' ),
			'NG' => __( 'Nigeria', 'flexacommerce' ),
			'NI' => __( 'Nicaragua', 'flexacommerce' ),
			'NL' => __( 'Netherlands', 'flexacommerce' ),
			'NO' => __( 'Norway', 'flexacommerce' ),
			'NP' => __( 'Nepal', 'flexacommerce' ),
			'NR' => __( 'Nauru', 'flexacommerce' ),
			'NU' => __( 'Niue', 'flexacommerce' ),
			'NZ' => __( 'New Zealand', 'flexacommerce' ),
			'OM' => __( 'Oman', 'flexacommerce' ),
			'PA' => __( 'Panama', 'flexacommerce' ),
			'PE' => __( 'Peru', 'flexacommerce' ),
			'PF' => __( 'French Polynesia', 'flexacommerce' ),
			'PG' => __( 'Papua New Guinea', 'flexacommerce' ),
			'PH' => __( 'Philippines', 'flexacommerce' ),
			'PK' => __( 'Pakistan', 'flexacommerce' ),
			'PL' => __( 'Poland', 'flexacommerce' ),
			'PM' => __( 'St. Pierre & Miquelon', 'flexacommerce' ),
			'PN' => __( 'Pitcairn Islands', 'flexacommerce' ),
			'PR' => __( 'Puerto Rico', 'flexacommerce' ),
			'PS' => __( 'Palestinian Territories', 'flexacommerce' ),
			'PT' => __( 'Portugal', 'flexacommerce' ),
			'PW' => __( 'Palau', 'flexacommerce' ),
			'PY' => __( 'Paraguay', 'flexacommerce' ),
			'RE' => __( 'Réunion', 'flexacommerce' ),
			'RO' => __( 'Romania', 'flexacommerce' ),
			'RS' => __( 'Serbia', 'flexacommerce' ),
			'RU' => __( 'Russia', 'flexacommerce' ),
			'RW' => __( 'Rwanda', 'flexacommerce' ),
			'SA' => __( 'Saudi Arabia', 'flexacommerce' ),
			'SB' => __( 'Solomon Islands', 'flexacommerce' ),
			'SC' => __( 'Seychelles', 'flexacommerce' ),
			'SD' => __( 'Sudan', 'flexacommerce' ),
			'SE' => __( 'Sweden', 'flexacommerce' ),
			'SG' => __( 'Singapore', 'flexacommerce' ),
			'SH' => __( 'St. Helena', 'flexacommerce' ),
			'SI' => __( 'Slovenia', 'flexacommerce' ),
			'SJ' => __( 'Svalbard & Jan Mayen', 'flexacommerce' ),
			'SK' => __( 'Slovakia', 'flexacommerce' ),
			'SL' => __( 'Sierra Leone', 'flexacommerce' ),
			'SM' => __( 'San Marino', 'flexacommerce' ),
			'SN' => __( 'Senegal', 'flexacommerce' ),
			'SO' => __( 'Somalia', 'flexacommerce' ),
			'SR' => __( 'Suriname', 'flexacommerce' ),
			'SS' => __( 'South Sudan', 'flexacommerce' ),
			'ST' => __( 'São Tomé & Príncipe', 'flexacommerce' ),
			'SV' => __( 'El Salvador', 'flexacommerce' ),
			'SX' => __( 'Sint Maarten', 'flexacommerce' ),
			'SY' => __( 'Syria', 'flexacommerce' ),
			'SZ' => __( 'Eswatini', 'flexacommerce' ),
			'TC' => __( 'Turks & Caicos Islands', 'flexacommerce' ),
			'TD' => __( 'Chad', 'flexacommerce' ),
			'TF' => __( 'French Southern Territories', 'flexacommerce' ),
			'TG' => __( 'Togo', 'flexacommerce' ),
			'TH' => __( 'Thailand', 'flexacommerce' ),
			'TJ' => __( 'Tajikistan', 'flexacommerce' ),
			'TK' => __( 'Tokelau', 'flexacommerce' ),
			'TL' => __( 'Timor-Leste', 'flexacommerce' ),
			'TM' => __( 'Turkmenistan', 'flexacommerce' ),
			'TN' => __( 'Tunisia', 'flexacommerce' ),
			'TO' => __( 'Tonga', 'flexacommerce' ),
			'TR' => __( 'Türkiye', 'flexacommerce' ),
			'TT' => __( 'Trinidad & Tobago', 'flexacommerce' ),
			'TV' => __( 'Tuvalu', 'flexacommerce' ),
			'TW' => __( 'Taiwan', 'flexacommerce' ),
			'TZ' => __( 'Tanzania', 'flexacommerce' ),
			'UA' => __( 'Ukraine', 'flexacommerce' ),
			'UG' => __( 'Uganda', 'flexacommerce' ),
			'UM' => __( 'U.S. Outlying Islands', 'flexacommerce' ),
			'US' => __( 'United States', 'flexacommerce' ),
			'UY' => __( 'Uruguay', 'flexacommerce' ),
			'UZ' => __( 'Uzbekistan', 'flexacommerce' ),
			'VA' => __( 'Vatican City', 'flexacommerce' ),
			'VC' => __( 'St. Vincent & Grenadines', 'flexacommerce' ),
			'VE' => __( 'Venezuela', 'flexacommerce' ),
			'VG' => __( 'British Virgin Islands', 'flexacommerce' ),
			'VI' => __( 'U.S. Virgin Islands', 'flexacommerce' ),
			'VN' => __( 'Vietnam', 'flexacommerce' ),
			'VU' => __( 'Vanuatu', 'flexacommerce' ),
			'WF' => __( 'Wallis & Futuna', 'flexacommerce' ),
			'WS' => __( 'Samoa', 'flexacommerce' ),
			'YE' => __( 'Yemen', 'flexacommerce' ),
			'YT' => __( 'Mayotte', 'flexacommerce' ),
			'ZA' => __( 'South Africa', 'flexacommerce' ),
			'ZM' => __( 'Zambia', 'flexacommerce' ),
			'ZW' => __( 'Zimbabwe', 'flexacommerce' ),
		];
	}
}
