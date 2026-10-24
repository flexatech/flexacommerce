<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		parent::setUp();
		\WP_Test_State::reset();
	}
}
