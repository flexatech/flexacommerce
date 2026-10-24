<?php

declare(strict_types=1);

namespace Flexa\Commerce\Concerns;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per request. Used by hook registrars and REST endpoints only;
 * domain services are built by Support\Services (doc 02 §4.2).
 */
trait HasInstance {
	private static ?self $instance = null;

	public static function instance(): static {
		if ( static::$instance === null ) {
			static::$instance = new static();
		}
		/** @var static */
		return static::$instance;
	}

	private function __construct() {}

	private function __clone() {}
}
