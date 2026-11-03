<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Payments\GatewayRegistryService;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\RateRow;
use Flexa\Commerce\Tax\TaxRateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The store settings the sample store adds around its products: a shipping
 * zone, the two offline payment methods, a tax rate, a terms page, a
 * wishlist page and a menu. apply() returns a record of what it changed
 * and the value each setting had before; revert() takes the record and
 * puts back only what still holds the sample value, so a change the
 * merchant made after the install stays.
 */
final class DemoSettings {
	public const PARTS = [ 'shipping', 'payments', 'tax', 'pages' ];

	public const RATE_SOURCE = 'preset:sample';

	public function __construct(
		private readonly ZoneRepository $zones,
		private readonly GatewayRegistryService $gateways,
		private readonly TaxRateRepository $rates,
	) {}

	public static function make(): self {
		$plugin = Plugin::instance();
		return new self( $plugin->shipping()->service()->zones(), $plugin->payments()->gateways(), $plugin->tax()->repository() );
	}

	/**
	 * @param list<string> $parts
	 * @return array<string,mixed> the record revert() takes
	 */
	public function apply( string $type, array $parts ): array {
		$record = [
			'zone'         => 0,
			'rest_methods' => [],
			'gateways'     => [],
			'tax_rate'     => false,
			'settings'     => [],
			'pages'        => [],
			'menu'         => 0,
			'navigation'   => 0,
		];
		// A digital store has nothing to ship.
		if ( in_array( 'shipping', $parts, true ) && $type !== 'digital' ) {
			$this->shipping( $record );
		}
		if ( in_array( 'payments', $parts, true ) ) {
			$this->payments( $type, $record );
		}
		if ( in_array( 'tax', $parts, true ) ) {
			$this->tax( $record );
		}
		if ( in_array( 'pages', $parts, true ) ) {
			$this->pages( $record );
		}
		return $record;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array{zones:int,gateways:int,rates:int,pages:int,menus:int}
	 */
	public function revert( array $record ): array {
		$counts = [
			'zones'    => 0,
			'gateways' => 0,
			'rates'    => 0,
			'pages'    => 0,
			'menus'    => 0,
		];
		$zone   = (int) ( $record['zone'] ?? 0 );
		$rest   = array_map( 'intval', (array) ( $record['rest_methods'] ?? [] ) );
		if ( $zone > 0 || $rest !== [] ) {
			$this->zones->flush();
			$keep = [];
			foreach ( $this->zones->all() as $candidate ) {
				if ( $zone > 0 && $candidate->id === $zone ) {
					++$counts['zones'];
					continue;
				}
				if ( $candidate->id === Zone::REST_OF_WORLD && $rest !== [] ) {
					$methods   = array_values( array_filter( $candidate->methods, static fn ( MethodInstance $m ): bool => ! in_array( $m->instance_id, $rest, true ) ) );
					$candidate = new Zone( $candidate->id, $candidate->name, $candidate->regions, $candidate->postcodes, $methods );
				}
				$keep[] = $candidate;
			}
			$this->zones->save( $keep );
		}

		foreach ( (array) ( $record['gateways'] ?? [] ) as $id => $change ) {
			$option = GatewaySettings::PREFIX . $id;
			if ( ! is_array( $change ) || get_option( $option, null ) !== ( $change['after'] ?? null ) ) {
				continue; // Changed since the install: the merchant's settings now.
			}
			if ( ( $change['before'] ?? null ) === null ) {
				delete_option( $option );
			} else {
				update_option( $option, $change['before'], false );
			}
			$this->gateways->settings()->forget( (string) $id );
			++$counts['gateways'];
		}

		if ( ! empty( $record['tax_rate'] ) ) {
			$counts['rates'] = $this->rates->delete_source( self::RATE_SOURCE );
		}

		$restore = [];
		foreach ( (array) ( $record['settings'] ?? [] ) as $key => $change ) {
			if ( is_array( $change ) && Settings::get( (string) $key ) === ( $change['after'] ?? null ) ) {
				$restore[ (string) $key ] = $change['before'] ?? null;
			}
		}
		if ( $restore !== [] ) {
			Settings::update( $restore );
		}

		foreach ( (array) ( $record['pages'] ?? [] ) as $id ) {
			if ( get_post_type( (int) $id ) === 'page' && wp_delete_post( (int) $id, true ) ) {
				++$counts['pages'];
			}
		}
		$menu = (int) ( $record['menu'] ?? 0 );
		// wp_delete_nav_menu() also clears the theme locations that showed it.
		if ( $menu > 0 && is_nav_menu( $menu ) && wp_delete_nav_menu( $menu ) === true ) {
			++$counts['menus'];
		}
		$navigation = (int) ( $record['navigation'] ?? 0 );
		if ( $navigation > 0 && get_post_type( $navigation ) === 'wp_navigation' && wp_delete_post( $navigation, true ) ) {
			++$counts['menus'];
		}
		return $counts;
	}

	/**
	 * A zone for the store's country after the shop's own zones, so they
	 * still match first: flat rate with a cost for bulky items, free
	 * shipping from 50 or with a coupon, and pickup. "Everywhere else"
	 * gets a flat rate when it has no method.
	 *
	 * @param array<string,mixed> $record
	 */
	private function shipping( array &$record ): void {
		$country = Settings::string( 'store_country' );
		$country = $country !== '' ? $country : 'US';
		$zones   = $this->zones->all();
		$rest    = array_pop( $zones ) ?? new Zone( Zone::REST_OF_WORLD, __( 'Everywhere else', 'flexacommerce' ) );
		$sample  = new Zone(
			0,
			/* translators: %s: country code, such as US */
			sprintf( __( 'Sample shipping (%s)', 'flexacommerce' ), $country ),
			[ 'country:' . $country ],
			[],
			[
				new MethodInstance(
					0,
					'flat_rate',
					true,
					[
						'title'       => __( 'Standard shipping', 'flexacommerce' ),
						'cost'        => SampleStore::minor( '5' ),
						'class_costs' => [
							'calculation' => 'per_class',
							'costs'       => [
								SampleStore::SHIPPING_CLASS => [
									'cost'     => SampleStore::minor( '10' ),
									'per_item' => 0,
								],
							],
						],
					]
				),
				new MethodInstance(
					0,
					'free_shipping',
					true,
					[
						'title'      => __( 'Free shipping', 'flexacommerce' ),
						'requires'   => 'either',
						'min_amount' => SampleStore::minor( '50' ),
					]
				),
				new MethodInstance(
					0,
					'local_pickup',
					true,
					[
						'title'    => __( 'Pick up in store', 'flexacommerce' ),
						'location' => __( "1 Sample Street\nMonday to Friday, 9:00 to 17:00", 'flexacommerce' ),
					]
				),
			]
		);
		$known = array_map( static fn ( MethodInstance $m ): int => $m->instance_id, $rest->methods );
		if ( $rest->methods === [] ) {
			$rest = new Zone(
				$rest->id,
				$rest->name,
				[],
				[],
				[
					new MethodInstance(
						0,
						'flat_rate',
						true,
						[
							'title' => __( 'International shipping', 'flexacommerce' ),
							'cost'  => SampleStore::minor( '15' ),
						]
					),
				]
			);
		}
		$saved = $this->zones->save( [ ...$zones, $sample, $rest ] );

		$record['zone'] = $saved[ count( $zones ) ]->id ?? 0;
		$last           = end( $saved );
		if ( $last instanceof Zone ) {
			$record['rest_methods'] = array_values( array_diff( array_map( static fn ( MethodInstance $m ): int => $m->instance_id, $last->methods ), $known ) );
		}
	}

	/**
	 * Bank transfer on. Cash on delivery is on by default; a digital store
	 * has nothing to deliver, so it goes off there unless the shop set it
	 * up itself. A method the shop already set keeps its own settings.
	 *
	 * @param array<string,mixed> $record
	 */
	private function payments( string $type, array &$record ): void {
		$inputs = [
			'bacs' => [
				'enabled'        => true,
				'account_name'   => __( 'Sample Store Ltd', 'flexacommerce' ),
				'bank_name'      => __( 'Sample Bank', 'flexacommerce' ),
				'account_number' => 'GB00 SAMP 0000 0000 0000 00',
				'sort_code'      => '00-00-00',
				'instructions'   => __( 'Use {order_number} as the payment reference. This is a sample account: change it before you take real orders.', 'flexacommerce' ),
			],
		];
		if ( $type === 'digital' ) {
			$inputs['cod'] = [ 'enabled' => false ];
		}
		$settings = $this->gateways->settings();
		foreach ( $inputs as $id => $input ) {
			$gateway = $this->gateways->get( $id );
			$before  = get_option( GatewaySettings::PREFIX . $id, null );
			if ( $gateway === null || $settings->enabled( $gateway ) === $input['enabled'] || ( ! $input['enabled'] && $before !== null ) ) {
				continue;
			}
			$settings->save( $gateway, $input );
			$record['gateways'][ $id ] = [
				'before' => $before,
				'after'  => get_option( GatewaySettings::PREFIX . $id, null ),
			];
		}
	}

	/** @param array<string,mixed> $record */
	private function tax( array &$record ): void {
		$country = Settings::string( 'store_country' );
		$this->rates->save(
			RateRow::from_input(
				[
					'country' => $country !== '' ? $country : 'US',
					'rate'    => '10',
					'name'    => __( 'Sample tax', 'flexacommerce' ),
					'source'  => self::RATE_SOURCE,
				]
			)
		);
		$record['tax_rate'] = true;
		self::setting( $record, 'tax_enabled', true );
	}

	/**
	 * The cart, checkout and account pages if one is missing, a terms page,
	 * a wishlist page, the shop's filter sidebar and a menu with the shop's
	 * pages and categories.
	 *
	 * @param array<string,mixed> $record
	 */
	private function pages( array &$record ): void {
		$keys   = [ 'cart_page_id', 'checkout_page_id', 'account_page_id' ];
		$before = array_map( static fn ( string $key ): int => Settings::int( $key ), array_combine( $keys, $keys ) );
		Pages::install();
		foreach ( $before as $key => $id ) {
			$now = Settings::int( $key );
			if ( $now !== $id ) {
				$record['pages'][]          = $now;
				$record['settings'][ $key ] = [
					'before' => $id,
					'after'  => $now,
				];
			}
		}

		$terms = Settings::int( 'terms_page_id' );
		if ( $terms <= 0 || get_post_status( $terms ) !== 'publish' ) {
			$page = self::page(
				__( 'Terms and conditions', 'flexacommerce' ),
				'<!-- wp:paragraph --><p>' . esc_html__( 'These are sample terms. Write your own before the shop takes real orders: what you sell, how you deliver, returns and refunds, and how to reach you.', 'flexacommerce' ) . '</p><!-- /wp:paragraph -->'
			);
			if ( $page > 0 ) {
				$record['pages'][] = $page;
				self::setting( $record, 'terms_page_id', $page );
			}
		}

		$wishlist = Pages::find( 'wishlist' );
		if ( $wishlist === 0 && Pages::fix( 'wishlist' ) ) {
			$wishlist          = Pages::find( 'wishlist' );
			$record['pages'][] = $wishlist;
		}
		if ( Settings::string( 'wishlist' ) === 'off' ) {
			self::setting( $record, 'wishlist', 'all' );
		}
		if ( Settings::string( 'shop_filters' ) === 'off' ) {
			self::setting( $record, 'shop_filters', 'left' );
		}

		$links      = [
			[
				'label' => __( 'Shop', 'flexacommerce' ),
				'url'   => (string) get_post_type_archive_link( PostTypes::PRODUCT ),
				'page'  => 0,
			],
		];
		$categories = get_terms(
			[
				'taxonomy'   => PostTypes::CATEGORY,
				'parent'     => 0,
				'hide_empty' => true,
				'number'     => 6,
			]
		);
		foreach ( is_array( $categories ) ? $categories : [] as $term ) {
			$url = get_term_link( $term );
			if ( is_string( $url ) && $url !== '' ) {
				$links[] = [
					'label' => $term->name,
					'url'   => $url,
					'page'  => 0,
				];
			}
		}
		foreach ( [ Settings::int( 'cart_page_id' ), Settings::int( 'checkout_page_id' ), Settings::int( 'account_page_id' ), $wishlist ] as $id ) {
			$url = $id > 0 && get_post_status( $id ) === 'publish' ? get_permalink( $id ) : false;
			if ( is_string( $url ) ) {
				$links[] = [
					'label' => get_the_title( $id ),
					'url'   => $url,
					'page'  => $id,
				];
			}
		}
		if ( wp_is_block_theme() ) {
			$record['navigation'] = self::navigation( $links );
		} else {
			$record['menu'] = self::menu( $links );
		}
	}

	/**
	 * A block theme's header navigation shows the latest published menu
	 * when it points at none.
	 *
	 * @param list<array{label:string,url:string,page:int}> $links
	 */
	private static function navigation( array $links ): int {
		$blocks = '';
		foreach ( $links as $link ) {
			$attrs   = $link['page'] > 0
				? [
					'label' => $link['label'],
					'type'  => 'page',
					'id'    => $link['page'],
					'url'   => $link['url'],
					'kind'  => 'post-type',
				]
				: [
					'label' => $link['label'],
					'url'   => $link['url'],
					'kind'  => 'custom',
				];
			$blocks .= '<!-- wp:navigation-link ' . wp_json_encode( $attrs ) . ' /-->';
		}
		$id = wp_insert_post(
			[
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => __( 'Shop menu', 'flexacommerce' ),
				'post_content' => $blocks,
			],
			true
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	/**
	 * A classic theme's menu, shown in the first menu location that has none.
	 *
	 * @param list<array{label:string,url:string,page:int}> $links
	 */
	private static function menu( array $links ): int {
		$menu = wp_create_nav_menu( __( 'Shop menu', 'flexacommerce' ) );
		if ( is_wp_error( $menu ) ) {
			return 0;
		}
		foreach ( $links as $position => $link ) {
			wp_update_nav_menu_item(
				$menu,
				0,
				$link['page'] > 0
					? [
						'menu-item-title'     => $link['label'],
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $link['page'],
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-position'  => $position + 1,
					]
					: [
						'menu-item-title'    => $link['label'],
						'menu-item-url'      => $link['url'],
						'menu-item-type'     => 'custom',
						'menu-item-status'   => 'publish',
						'menu-item-position' => $position + 1,
					]
			);
		}
		$locations = get_nav_menu_locations();
		foreach ( array_keys( get_registered_nav_menus() ) as $location ) {
			if ( empty( $locations[ $location ] ) ) {
				$locations[ $location ] = $menu;
				set_theme_mod( 'nav_menu_locations', $locations );
				break;
			}
		}
		return $menu;
	}

	private static function page( string $title, string $content ): int {
		$id = wp_insert_post(
			[
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $title,
				'post_content'   => $content,
				'comment_status' => 'closed',
			],
			true
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	/**
	 * Change one store setting and note the value it had.
	 *
	 * @param array<string,mixed> $record
	 */
	private static function setting( array &$record, string $key, mixed $value ): void {
		$before = Settings::get( $key );
		if ( $before === $value ) {
			return;
		}
		Settings::update( [ $key => $value ] );
		$record['settings'][ $key ] = [
			'before' => $before,
			'after'  => Settings::get( $key ),
		];
	}
}
