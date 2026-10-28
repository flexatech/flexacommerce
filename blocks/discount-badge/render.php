<?php
/**
 * Server render of flexacommerce/discount-badge.
 *
 * @var array<string,mixed> $attributes
 * @var string              $content
 * @var WP_Block            $block
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

echo \Flexa\Commerce\Storefront\Storefront::instance()->render_block( 'discount-badge', $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
