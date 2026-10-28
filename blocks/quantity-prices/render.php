<?php
/**
 * Server render of flexacommerce/quantity-prices.
 *
 * @var array<string,mixed> $attributes
 * @var string              $content
 * @var WP_Block            $block
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

echo \Flexa\Commerce\Storefront\Storefront::instance()->render_block( 'quantity-prices', $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
