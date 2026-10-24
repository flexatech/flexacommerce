<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\SameSite;
use Flexa\Commerce\Tests\Unit\TestCase;

final class SameSiteTest extends TestCase {
	public function test_origin_must_name_this_site(): void {
		self::assertTrue( SameSite::check( 'https://shop.example.test', '' ) );
		self::assertTrue( SameSite::check( 'https://SHOP.example.test:8443', '' ) );
		self::assertFalse( SameSite::check( 'https://evil.test', 'https://shop.example.test/cart' ) );
	}

	public function test_referer_is_the_fallback(): void {
		self::assertTrue( SameSite::check( '', 'https://shop.example.test/product/mug/' ) );
		self::assertFalse( SameSite::check( '', 'https://evil.test/' ) );
		self::assertFalse( SameSite::check( '', 'not a url' ) );
	}

	public function test_null_origin_is_refused_and_no_headers_pass(): void {
		self::assertFalse( SameSite::check( 'null', '' ) );
		self::assertTrue( SameSite::check( '', '' ) );
	}

	public function test_filter_adds_hosts(): void {
		add_filter( 'flexacommerce.rest.store.allowed_hosts', static fn ( array $hosts ): array => [ ...$hosts, 'front.example.test' ] );
		self::assertTrue( SameSite::check( 'https://front.example.test', '' ) );
	}
}
