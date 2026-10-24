<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Every permission check goes through here. Each capability passes a
 * `flexacommerce.capabilities.*` filter so sites and add-ons can re-gate it.
 */
final class Capabilities {
	public const MANAGE        = 'manage_flexacommerce';
	public const VIEW_ORDERS   = 'view_fxc_orders';
	public const EDIT_ORDERS   = 'edit_fxc_orders';
	public const REFUND_ORDERS = 'refund_fxc_orders';

	public const EDIT_PRODUCTS        = 'edit_fxc_products';
	public const MANAGE_PRODUCT_TERMS = 'manage_fxc_product_terms';
	public const EDIT_PRODUCT_TERMS   = 'edit_fxc_product_terms';
	public const DELETE_PRODUCT_TERMS = 'delete_fxc_product_terms';
	public const ASSIGN_PRODUCT_TERMS = 'assign_fxc_product_terms';

	/** Primitive caps of the fxc_product post type (capability_type fxc_product, map_meta_cap). */
	public const PRODUCT_CAPS = [
		self::EDIT_PRODUCTS,
		'edit_others_fxc_products',
		'edit_private_fxc_products',
		'edit_published_fxc_products',
		'publish_fxc_products',
		'read_private_fxc_products',
		'delete_fxc_products',
		'delete_others_fxc_products',
		'delete_private_fxc_products',
		'delete_published_fxc_products',
		self::MANAGE_PRODUCT_TERMS,
		self::EDIT_PRODUCT_TERMS,
		self::DELETE_PRODUCT_TERMS,
		self::ASSIGN_PRODUCT_TERMS,
	];

	/** @var list<string> */
	public const ALL = [ self::MANAGE, self::VIEW_ORDERS, self::EDIT_ORDERS, self::REFUND_ORDERS, ...self::PRODUCT_CAPS ];

	public static function can_manage(): bool {
		return self::check( 'manage', self::MANAGE );
	}

	public static function can_view_orders(): bool {
		return self::check( 'view_orders', self::VIEW_ORDERS );
	}

	public static function can_edit_orders(): bool {
		return self::check( 'edit_orders', self::EDIT_ORDERS );
	}

	public static function can_refund_orders(): bool {
		return self::check( 'refund_orders', self::REFUND_ORDERS );
	}

	public static function can_edit_products(): bool {
		return self::check( 'edit_products', self::EDIT_PRODUCTS );
	}

	public static function can_manage_product_terms(): bool {
		return self::check( 'manage_product_terms', self::MANAGE_PRODUCT_TERMS );
	}

	/** Per-product check through core map_meta_cap (edit_post maps to the fxc_product caps). */
	public static function can_edit_product( int $product_id ): bool {
		$filtered = apply_filters( 'flexacommerce.capabilities.edit_product', 'edit_post', $product_id );
		return current_user_can( is_string( $filtered ) && $filtered !== '' ? $filtered : 'edit_post', $product_id );
	}

	private static function check( string $name, string $capability ): bool {
		$filtered = apply_filters( 'flexacommerce.capabilities.' . $name, $capability );
		return current_user_can( is_string( $filtered ) && $filtered !== '' ? $filtered : $capability );
	}
}
