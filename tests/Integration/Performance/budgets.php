<?php
/**
 * T1 budgets (doc 10 §4.1). Raising a number needs the reason and the
 * before/after measurement in the same pull request (doc 10 §5.4).
 */

declare(strict_types=1);

return [
	// Blog post and home page with no FlexaCommerce content, against core.
	'P1'     => [
		'queries'   => 0,
		'memory'    => 262144,
		'callbacks' => 30,
		'assets'    => 0,
		// Every FlexaCommerce class, trait or interface allowed to load.
		'classes'   => [
			'Flexa\\Commerce\\Api\\Router',
			'Flexa\\Commerce\\Catalog\\PostTypes',
			'Flexa\\Commerce\\Concerns\\HasInstance',
			'Flexa\\Commerce\\Maintenance\\Cron',
			'Flexa\\Commerce\\Plugin',
			'Flexa\\Commerce\\Setup\\Requirements',
			'Flexa\\Commerce\\Support\\Capabilities',
			'Flexa\\Commerce\\Support\\Currency',
			'Flexa\\Commerce\\Support\\Settings',
		],
	],
	// REST request for another plugin's route: no endpoint object built.
	'R-REST' => [
		'queries' => 0,
		'classes' => [ 'Flexa\\Commerce\\Api\\Admin\\', 'Flexa\\Commerce\\Api\\Endpoint' ],
	],
	// Autoloaded options owned by FlexaCommerce, on any request.
	'P10'    => [
		'options' => 1,
		'bytes'   => 10240,
	],
	// Product archive at 12 and 48 per page, against a core archive of the
	// same posts. Queries must also be equal for both sizes.
	'P2'     => [
		'queries' => 2,
		// Measured 1 282 992 B at 48 per page (Darwin, PHP 8.5, warm OPcache), + 10 %.
		'memory'  => 1411292,
	],
	// Simple product page, against a core single post.
	'P3a'    => [
		'queries' => 3,
	],
	// Variable product with 6 and 250 variants: queries equal for both, and
	// the inlined variant state (JSON bytes) at 250.
	'P3b'    => [
		'queries' => 3,
		// Measured 37 211 B at 250 variants, + 10 % (doc limit 60 KB).
		'state'   => 40933,
	],
	// Simple and variable product pages (P3a, P3b at 6 and 250 variants).
	'P3'     => [
		// Measured 1 048 848 B at 250 variants (Darwin, PHP 8.5, warm OPcache), + 10 %.
		'memory' => 1153733,
	],
	// GET store/cart with 1 and 10 lines; equal for both.
	'P4q'    => [
		'queries' => 4,
	],
	// POST store/cart/items for a guest without a cart yet.
	'P5q'    => [
		// Measured 6, + 10 % rounded up (doc limit 8).
		'queries' => 7,
	],
	// Allowed growth of measured memory over the recorded baseline.
	'memory_band' => 0.05,
];
