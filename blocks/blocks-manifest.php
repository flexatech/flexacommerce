<?php
/**
 * Block metadata for wp_register_block_types_from_metadata_collection().
 * Mirrors each block.json in this folder; BlocksManifestTest fails when they differ.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return [
	'product-price' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-price',
		'version' => '0.1.0',
		'title' => 'Product price',
		'category' => 'widgets',
		'icon' => 'tag',
		'description' => 'The price of the current product, or a price range for a product with options.',
		'textdomain' => 'flexacommerce',
		'usesContext' => [
			'postId',
			'postType',
		],
		'attributes' => [
			'productId' => [
				'type' => 'integer',
				'default' => 0,
			],
		],
		'supports' => [
			'html' => false,
			'align' => true,
			'color' => [
				'text' => true,
				'background' => false,
			],
			'typography' => [
				'fontSize' => true,
			],
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'product-add-to-cart' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-add-to-cart',
		'version' => '0.1.0',
		'title' => 'Add to cart',
		'category' => 'widgets',
		'icon' => 'cart',
		'description' => 'Option selectors, quantity and the add-to-cart button for the current product.',
		'textdomain' => 'flexacommerce',
		'usesContext' => [
			'postId',
			'postType',
			'queryId',
		],
		'attributes' => [
			'productId' => [
				'type' => 'integer',
				'default' => 0,
			],
		],
		'supports' => [
			'html' => false,
			'interactivity' => true,
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'cart' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/cart',
		'version' => '0.1.0',
		'title' => 'Cart',
		'category' => 'widgets',
		'icon' => 'cart',
		'description' => 'The shopper\'s cart with quantities and totals.',
		'textdomain' => 'flexacommerce',
		'attributes' => [],
		'supports' => [
			'html' => false,
			'multiple' => false,
			'align' => [
				'wide',
				'full',
			],
			'interactivity' => true,
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'mini-cart' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/mini-cart',
		'version' => '0.1.0',
		'title' => 'Mini cart',
		'category' => 'widgets',
		'icon' => 'cart',
		'description' => 'A link to the cart with the number of items in it.',
		'textdomain' => 'flexacommerce',
		'attributes' => [],
		'supports' => [
			'html' => false,
			'interactivity' => true,
			'typography' => [
				'fontSize' => true,
			],
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'checkout' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/checkout',
		'version' => '0.1.0',
		'title' => 'Checkout',
		'category' => 'widgets',
		'icon' => 'money-alt',
		'description' => 'The checkout form, and the order confirmation and payment pages after it.',
		'textdomain' => 'flexacommerce',
		'attributes' => [],
		'supports' => [
			'html' => false,
			'multiple' => false,
			'align' => [
				'wide',
				'full',
			],
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
];
