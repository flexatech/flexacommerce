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
	'account' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/account',
		'version' => '0.1.0',
		'title' => 'My account',
		'category' => 'widgets',
		'icon' => 'admin-users',
		'description' => 'Sign-in form for guests; orders, addresses and account details for customers.',
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
	'order-confirmation' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/order-confirmation',
		'version' => '0.1.0',
		'title' => 'Order confirmation',
		'category' => 'widgets',
		'icon' => 'yes-alt',
		'description' => 'The order from the confirmation link: status, items, totals and addresses.',
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
	'product-gallery' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-gallery',
		'version' => '0.1.0',
		'title' => 'Product gallery',
		'category' => 'widgets',
		'icon' => 'format-gallery',
		'description' => 'The product images: a large image and thumbnails that switch it.',
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
			'align' => [
				'wide',
				'full',
			],
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'product-sku' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-sku',
		'version' => '0.1.0',
		'title' => 'Product SKU',
		'category' => 'widgets',
		'icon' => 'tag',
		'description' => 'The SKU of the current product, or of the chosen option.',
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
	'product-stock' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-stock',
		'version' => '0.1.0',
		'title' => 'Product stock',
		'category' => 'widgets',
		'icon' => 'yes-alt',
		'description' => 'Whether the current product is in stock, and how many are left.',
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
			'typography' => [
				'fontSize' => true,
			],
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'product-attributes' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-attributes',
		'version' => '0.1.0',
		'title' => 'Product details',
		'category' => 'widgets',
		'icon' => 'editor-table',
		'description' => 'Weight, dimensions and the visible attributes of the current product.',
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
			'typography' => [
				'fontSize' => true,
			],
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'breadcrumbs' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/breadcrumbs',
		'version' => '0.1.0',
		'title' => 'Store breadcrumbs',
		'category' => 'widgets',
		'icon' => 'arrow-right-alt2',
		'description' => 'The path from the home page to the current shop page.',
		'textdomain' => 'flexacommerce',
		'attributes' => [],
		'supports' => [
			'html' => false,
			'align' => true,
			'color' => [
				'text' => true,
				'link' => true,
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
	'catalog-sorting' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/catalog-sorting',
		'version' => '0.1.0',
		'title' => 'Product sorting',
		'category' => 'widgets',
		'icon' => 'sort',
		'description' => 'A menu to sort the products of the shop page.',
		'textdomain' => 'flexacommerce',
		'attributes' => [],
		'supports' => [
			'html' => false,
			'align' => true,
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'product-filters' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/product-filters',
		'version' => '0.1.0',
		'title' => 'Product filters',
		'category' => 'widgets',
		'icon' => 'filter',
		'description' => 'Category and attribute filters for the shop page.',
		'textdomain' => 'flexacommerce',
		'attributes' => [
			'showCategories' => [
				'type' => 'boolean',
				'default' => true,
			],
			'attributeSlugs' => [
				'type' => 'array',
				'default' => [],
				'items' => [
					'type' => 'string',
				],
			],
		],
		'supports' => [
			'html' => false,
		],
		'editorScript' => 'flexacommerce-blocks-editor',
		'style' => 'flexacommerce-storefront',
		'render' => 'file:./render.php',
	],
	'related-products' => [
		'apiVersion' => 3,
		'name' => 'flexacommerce/related-products',
		'version' => '0.1.0',
		'title' => 'Related products',
		'category' => 'widgets',
		'icon' => 'grid-view',
		'description' => 'Products from the same categories or tags as the current product.',
		'textdomain' => 'flexacommerce',
		'usesContext' => [
			'postId',
			'postType',
		],
		'attributes' => [
			'count' => [
				'type' => 'integer',
				'default' => 4,
				'minimum' => 1,
				'maximum' => 12,
			],
		],
		'supports' => [
			'html' => false,
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
