<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\ShopLayout;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The store's pages (ADR-083). Cart, checkout and account are required and,
 * with terms, kept in a setting; the wishlist and order tracking pages are
 * optional and found by their shortcode or block. Activation creates each
 * required page that is not set or whose page was deleted; a page the
 * merchant picked is never replaced. Pages hold the block, which classic
 * themes render through the_content.
 */
final class Pages {
	public const KEYS = [ 'cart', 'checkout', 'account', 'terms', 'wishlist', 'tracking' ];

	public const REQUIRED = [ 'cart', 'checkout', 'account' ];

	/** Statuses a page can have and still count as the store's page; anything else is gone. */
	private const KEPT = [ 'publish', 'private', 'draft', 'pending', 'future' ];

	/**
	 * @return array{label:string,setting:?string,title:string,slug:string,content:string,blocks:list<string>,shortcodes:list<string>}
	 */
	private static function spec( string $key ): array {
		return match ( $key ) {
			'cart'     => [
				'label'      => __( 'Cart', 'flexacommerce' ),
				'setting'    => 'cart_page_id',
				'title'      => _x( 'Cart', 'page title', 'flexacommerce' ),
				'slug'       => 'cart',
				'content'    => '<!-- wp:flexacommerce/cart /-->',
				'blocks'     => [ 'flexacommerce/cart' ],
				'shortcodes' => [ 'flexacommerce_cart' ],
			],
			'checkout' => [
				'label'      => __( 'Checkout', 'flexacommerce' ),
				'setting'    => 'checkout_page_id',
				'title'      => _x( 'Checkout', 'page title', 'flexacommerce' ),
				'slug'       => 'checkout',
				'content'    => '<!-- wp:flexacommerce/checkout /-->',
				'blocks'     => [ 'flexacommerce/checkout' ],
				'shortcodes' => [ 'flexacommerce_checkout' ],
			],
			'account'  => [
				'label'      => __( 'My account', 'flexacommerce' ),
				'setting'    => 'account_page_id',
				'title'      => _x( 'My account', 'page title', 'flexacommerce' ),
				'slug'       => 'my-account',
				'content'    => '<!-- wp:flexacommerce/account /-->',
				'blocks'     => [ 'flexacommerce/account' ],
				'shortcodes' => [ 'flexacommerce_account' ],
			],
			'terms'    => [
				'label'      => __( 'Terms and conditions', 'flexacommerce' ),
				'setting'    => 'terms_page_id',
				'title'      => _x( 'Terms and conditions', 'page title', 'flexacommerce' ),
				'slug'       => 'terms',
				'content'    => '<!-- wp:paragraph --><p>' . esc_html__( 'Write your terms here before the shop takes real orders: what you sell, how you deliver, returns and refunds, and how to reach you.', 'flexacommerce' ) . '</p><!-- /wp:paragraph -->',
				'blocks'     => [],
				'shortcodes' => [],
			],
			'wishlist' => [
				'label'      => __( 'Wishlist', 'flexacommerce' ),
				'setting'    => null,
				'title'      => _x( 'Wishlist', 'page title', 'flexacommerce' ),
				'slug'       => 'wishlist',
				'content'    => '<!-- wp:shortcode -->[flexacommerce_wishlist]<!-- /wp:shortcode -->',
				'blocks'     => [],
				'shortcodes' => [ 'flexacommerce_wishlist' ],
			],
			default    => [
				'label'      => __( 'Order tracking', 'flexacommerce' ),
				'setting'    => null,
				'title'      => _x( 'Order tracking', 'page title', 'flexacommerce' ),
				'slug'       => 'order-tracking',
				'content'    => '<!-- wp:flexacommerce/order-lookup /-->',
				'blocks'     => [ 'flexacommerce/order-lookup' ],
				'shortcodes' => [ 'flexacommerce_order_tracking' ],
			],
		};
	}

	/** Activation, migration and the demo store: the required pages that are missing or in the trash. */
	public static function install(): void {
		foreach ( self::REQUIRED as $key ) {
			if ( in_array( self::problem( $key ), [ 'missing', 'trash' ], true ) ) {
				self::fix( $key );
			}
		}
	}

	/**
	 * The page in use for a key: the setting, or for the optional pages the
	 * first one holding the shortcode or block, published ones first. 0 when none.
	 */
	public static function find( string $key ): int {
		$spec = self::spec( $key );
		if ( $spec['setting'] !== null ) {
			return Settings::int( $spec['setting'] );
		}
		$markers = array_merge( $spec['shortcodes'], array_map( static fn ( string $block ): string => 'wp:' . $block, $spec['blocks'] ) );
		$found   = [];
		foreach ( $markers as $marker ) {
			$posts = get_posts(
				[
					'post_type'      => 'page',
					'post_status'    => self::KEPT,
					's'              => $marker,
					'sentence'       => true,
					'search_columns' => [ 'post_content' ],
					'posts_per_page' => 10,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			);
			foreach ( $posts as $post ) {
				if ( self::holds( $post, $spec ) ) {
					$found[] = $post;
				}
			}
		}
		usort( $found, static fn ( WP_Post $a, WP_Post $b ): int => [ $a->post_status !== 'publish', $a->ID ] <=> [ $b->post_status !== 'publish', $b->ID ] );
		return $found === [] ? 0 : $found[0]->ID;
	}

	/**
	 * What is wrong with a page: '' when it works, 'missing' (none set, or
	 * deleted), 'trash', 'unpublished' (shoppers cannot open it) or 'content'
	 * (the block or shortcode is gone).
	 */
	public static function problem( string $key ): string {
		$spec = self::spec( $key );
		$id   = self::find( $key );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || $post->post_type !== 'page' ) {
			return 'missing';
		}
		if ( $post->post_status === 'trash' ) {
			return 'trash';
		}
		if ( ! in_array( $post->post_status, self::KEPT, true ) ) {
			return 'missing';
		}
		if ( $post->post_status !== 'publish' ) {
			return 'unpublished';
		}
		return self::holds( $post, $spec ) ? '' : 'content';
	}

	/**
	 * One row per page for the Settings card, the Status checks and WP-CLI.
	 *
	 * @return list<array{key:string,label:string,required:bool,assignable:bool,problem:string,message:string,page:?array{id:int,title:string,status:string,view_url:string,edit_url:string}}>
	 */
	public static function status(): array {
		$rows = [];
		foreach ( self::KEYS as $key ) {
			$spec    = self::spec( $key );
			$id      = self::find( $key );
			$post    = $id > 0 ? get_post( $id ) : null;
			$problem = self::problem( $key );
			$page    = null;
			if ( $post instanceof WP_Post && $post->post_type === 'page' && $problem !== 'missing' ) {
				$page = [
					'id'       => $post->ID,
					'title'    => $post->post_title !== '' ? $post->post_title : __( '(no title)', 'flexacommerce' ),
					'status'   => $post->post_status,
					'view_url' => (string) get_permalink( $post ),
					'edit_url' => (string) get_edit_post_link( $post->ID, 'raw' ),
				];
			}
			$rows[] = [
				'key'        => $key,
				'label'      => $spec['label'],
				'required'   => in_array( $key, self::REQUIRED, true ),
				'assignable' => $spec['setting'] !== null,
				'problem'    => $problem,
				'message'    => self::message( $key, $problem ),
				'page'       => $page,
			];
		}
		return $rows;
	}

	private static function message( string $key, string $problem ): string {
		$required = in_array( $key, self::REQUIRED, true );
		return match ( $problem ) {
			'missing'     => $required
				? __( 'No page is set, so shoppers cannot reach it.', 'flexacommerce' )
				: ( $key === 'terms'
					? __( 'No page is set, so checkout asks for no terms.', 'flexacommerce' )
					: __( 'Not created. It is optional.', 'flexacommerce' ) ),
			'trash'       => __( 'The page is in the trash.', 'flexacommerce' ),
			'unpublished' => __( 'The page is not published, so shoppers cannot open it.', 'flexacommerce' ),
			'content'     => __( 'The page lost its FlexaCommerce block or shortcode.', 'flexacommerce' ),
			default       => '',
		};
	}

	/**
	 * Make a page work: create it when missing, restore it from the trash,
	 * publish it, or add the block it lost. Returns true when something changed.
	 */
	public static function fix( string $key ): bool {
		$spec    = self::spec( $key );
		$problem = self::problem( $key );
		$id      = self::find( $key );
		switch ( $problem ) {
			case 'missing':
				$new = wp_insert_post(
					[
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'post_title'     => $spec['title'],
						'post_name'      => $spec['slug'],
						'post_content'   => $spec['content'],
						'comment_status' => 'closed',
					],
					true
				);
				if ( is_wp_error( $new ) ) {
					return false;
				}
				if ( $spec['setting'] !== null ) {
					Settings::update( [ $spec['setting'] => $new ] );
				}
				return true;
			case 'trash':
				if ( ! wp_untrash_post( $id ) ) {
					return false;
				}
				wp_publish_post( $id );
				return self::problem( $key ) === '' || self::fix( $key );
			case 'unpublished':
				wp_publish_post( $id );
				return true;
			case 'content':
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post ) {
					return false;
				}
				$content = rtrim( $post->post_content );
				$saved   = wp_update_post(
					[
						'ID'           => $id,
						'post_content' => ( $content !== '' ? $content . "\n\n" : '' ) . $spec['content'],
					],
					true
				);
				return ! is_wp_error( $saved );
			default:
				return false;
		}
	}

	/** Use an existing page for a key with a setting; 0 clears the terms page. */
	public static function assign( string $key, int $page_id ): bool {
		$spec = self::spec( $key );
		if ( $spec['setting'] === null ) {
			return false;
		}
		if ( $page_id === 0 ) {
			if ( $key !== 'terms' ) {
				return false;
			}
		} else {
			$post = get_post( $page_id );
			if ( ! $post instanceof WP_Post || $post->post_type !== 'page' || ! in_array( $post->post_status, self::KEPT, true ) ) {
				return false;
			}
		}
		Settings::update( [ $spec['setting'] => $page_id ] );
		return true;
	}

	/**
	 * Pages to pick from, by title.
	 *
	 * @return list<array{id:int,title:string,status:string}>
	 */
	public static function choices(): array {
		$posts = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => self::KEPT,
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		$out   = [];
		foreach ( $posts as $post ) {
			$out[] = [
				'id'     => $post->ID,
				'title'  => $post->post_title !== '' ? $post->post_title : __( '(no title)', 'flexacommerce' ),
				'status' => $post->post_status,
			];
		}
		return $out;
	}

	/**
	 * The shop list (ADR-084). It is the product archive, not a page: its
	 * address comes from the shop base, and the filter sidebar from the
	 * `shop_filters` setting. `hidden_by` says why a set sidebar would not
	 * show: the Site Editor holds an edited copy of the template ('custom'),
	 * the theme ships its own ('theme'), or a classic theme's copy of
	 * archive-product.php predates the sidebar ('classic'). `page` is a page
	 * at the same address, which shoppers can no longer reach.
	 *
	 * @return array{view_url:string,filters:string,hidden_by:string,template_url:string,page:?array{id:int,title:string,edit_url:string}}
	 */
	public static function shop(): array {
		$hidden_by    = '';
		$template_url = '';
		if ( wp_is_block_theme() ) {
			$id       = get_stylesheet() . '//archive-' . PostTypes::PRODUCT;
			$template = get_block_template( $id );
			if ( $template !== null && in_array( $template->source, [ 'custom', 'theme' ], true ) ) {
				$hidden_by    = $template->source;
				$template_url = add_query_arg(
					[
						'p'      => '/wp_template/' . $id,
						'canvas' => 'edit',
					],
					admin_url( 'site-editor.php' )
				);
			}
		} else {
			$copy = locate_template( 'flexacommerce/archive-product.php' );
			if ( ! (bool) apply_filters( 'flexacommerce.classic_templates', true ) ) {
				$hidden_by = 'theme';
			} elseif ( $copy !== '' && version_compare( ShopLayout::version( $copy ), ShopLayout::CLASSIC_SINCE, '<' ) ) {
				$hidden_by = 'classic';
			}
		}
		$page = null;
		$base = trim( (string) wp_parse_url( (string) get_post_type_archive_link( PostTypes::PRODUCT ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home !== '' && str_starts_with( $base, $home . '/' ) ) {
			$base = substr( $base, strlen( $home ) + 1 );
		}
		$post = $base !== '' ? get_page_by_path( $base ) : null;
		if ( $post instanceof WP_Post && $post->post_status === 'publish' ) {
			$page = [
				'id'       => $post->ID,
				'title'    => $post->post_title !== '' ? $post->post_title : __( '(no title)', 'flexacommerce' ),
				'edit_url' => (string) get_edit_post_link( $post->ID, 'raw' ),
			];
		}
		return [
			'view_url'     => (string) get_post_type_archive_link( PostTypes::PRODUCT ),
			'filters'      => ShopLayout::side(),
			'hidden_by'    => $hidden_by,
			'template_url' => $template_url,
			'page'         => $page,
		];
	}

	/** Put the filter sidebar on the left or right of the shop, or take it off (ADR-084). */
	public static function shop_filters( string $side ): bool {
		if ( ! in_array( $side, ShopLayout::SIDES, true ) ) {
			return false;
		}
		Settings::update( [ 'shop_filters' => $side ] );
		return true;
	}

	/** @param array{blocks:list<string>,shortcodes:list<string>} $spec */
	private static function holds( WP_Post $post, array $spec ): bool {
		if ( $spec['blocks'] === [] && $spec['shortcodes'] === [] ) {
			return true;
		}
		foreach ( $spec['blocks'] as $block ) {
			if ( has_block( $block, $post ) ) {
				return true;
			}
		}
		foreach ( $spec['shortcodes'] as $shortcode ) {
			if ( str_contains( $post->post_content, '[' . $shortcode ) ) {
				return true;
			}
		}
		return false;
	}
}
