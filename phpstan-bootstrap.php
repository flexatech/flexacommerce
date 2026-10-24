<?php
/**
 * PHPStan analysis bootstrap, never loaded at runtime. The FLEXACOMMERCE_*
 * constants are defined in flexacommerce.php after the PHP guard, which the
 * analyser cannot follow.
 */

declare(strict_types=1);

defined( 'FLEXACOMMERCE_VERSION' ) || define( 'FLEXACOMMERCE_VERSION', '0.1.0' );
defined( 'FLEXACOMMERCE_FILE' ) || define( 'FLEXACOMMERCE_FILE', __DIR__ . '/flexacommerce.php' );
defined( 'FLEXACOMMERCE_PATH' ) || define( 'FLEXACOMMERCE_PATH', __DIR__ . '/' );
defined( 'FLEXACOMMERCE_URL' ) || define( 'FLEXACOMMERCE_URL', 'https://example.test/wp-content/plugins/flexacommerce/' );
defined( 'FLEXACOMMERCE_BASENAME' ) || define( 'FLEXACOMMERCE_BASENAME', 'flexacommerce/flexacommerce.php' );
defined( 'FLEXACOMMERCE_REST_NAMESPACE' ) || define( 'FLEXACOMMERCE_REST_NAMESPACE', 'flexacommerce/v1' );
defined( 'FLEXACOMMERCE_TEXT_DOMAIN' ) || define( 'FLEXACOMMERCE_TEXT_DOMAIN', 'flexacommerce' );
