<?php
/**
 * T1 budgets (doc 10 §4.1). Raising a number needs the reason and the
 * before/after measurement in the same pull request (doc 10 §5.4).
 */

declare(strict_types=1);

return [
	// Blog post and home page with no FlexaCommerce content, against core.
	'P1'          => [
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
	'R-REST'      => [
		'queries' => 0,
		'classes' => [ 'Flexa\\Commerce\\Api\\Admin\\', 'Flexa\\Commerce\\Api\\Endpoint' ],
	],
	// Autoloaded options owned by FlexaCommerce, on any request: the
	// settings and the badge, price rule and product option lists
	// (empty here).
	'P10'         => [
		'options' => 4,
		'bytes'   => 10240,
	],
	// Product archive at 12 and 48 per page, against a core archive of the
	// same posts. Queries must also be equal for both sizes.
	'P2'          => [
		'queries' => 2,
		// Measured 1 282 992 B at 48 per page (Darwin, PHP 8.5, warm OPcache), + 10 %.
		'memory'  => 1411292,
	],
	// Simple product page, against a core single post.
	'P3a'         => [
		'queries' => 3,
	],
	// Variable product with 6 and 250 variants: queries equal for both, and
	// the inlined variant state (JSON bytes) at 250.
	'P3b'         => [
		'queries' => 3,
		// Measured 37 211 B at 250 variants, + 10 % (doc limit 60 KB).
		'state'   => 40933,
	],
	// Simple and variable product pages (P3a, P3b at 6 and 250 variants).
	'P3'          => [
		// Measured 1 316 960 B at 250 variants (Darwin, PHP 8.5, warm OPcache), + 10 %.
		// Phase 5: was 1 048 848 B (phase 3). The phase 4 template measures
		// 1 295 800 B on the same code, so the new product blocks add ~21 KB;
		// the rest came in between and is not attributed yet (doc 10 §7.4).
		'memory' => 1448656,
	],
	// A page with the Product list Query Loop at 12 and 48 products, against
	// the same block of plain posts. Queries must be equal for both sizes.
	'P1h'         => [
		'queries' => 3,
	],
	// Our JS and CSS on a product page, gzip bytes, as the page loads them.
	'P8'          => [
		'js'  => 15360,
		'css' => 10240,
	],
	// GET store/cart with 1 and 10 lines; equal for both.
	'P4q'         => [
		'queries' => 4,
	],
	// POST store/cart/items for a guest without a cart yet.
	'P5q'         => [
		// Measured 6, + 10 % rounded up (doc limit 8).
		'queries' => 7,
	],
	// POST store/checkout with cash on delivery and deferred emails, at 1 and
	// 10 lines: a fixed part plus exactly `per_line` queries per line.
	'P6q'         => [
		// Measured 54 fixed (2026-10-25, Darwin, PHP 8.5), + 10 % rounded up.
		// Raised once from the provisional 30 (doc 10 §4.1); see docs/benchmarks.
		'fixed'    => 60,
		'per_line' => 1,
	],
	// Extra peak memory of the same checkout at 10 lines, against core.
	'P6m'         => [
		// Measured 953 600 B at 10 lines (Darwin, PHP 8.5, warm OPcache), + 10 %.
		'memory' => 1048960,
	],
	// Phase 4.5 taxes (PT-*): the same request with taxes on against off.
	// Catalog pages with display incl: base rates come from the settings option.
	'PT-cat'      => [
		'queries' => 0,
	],
	// The settings option with 5 classes and 4 stacked rates each (within P10).
	'PT-opt'      => [
		'bytes' => 10240,
	],
	// GET store/cart with a known address, 1 and 10 lines: the rate match.
	'PT-cart'     => [
		'queries' => 1,
	],
	// POST store/checkout with 4 stacked rates, 1 and 10 lines.
	'PT-q'        => [
		'queries' => 1,
	],
	// Extra peak memory of that checkout at 10 lines. Recorded 84 KB on
	// 2026-10-25 (doc 10 said 64 KB provisional): 10 lines x 4 rates of tax
	// meta, 4 tax rows and the order read back, plus ~10 KB of query log.
	// Raised 2026-10-26 when store requests stopped building the admin
	// endpoints: the taxes-off peak used to fall while routes were
	// registered and hid part of the tax cost. Peaks off/on went from
	// 6 271 752/6 359 872 B (delta 88 120) to 6 009 568/6 163 224 B
	// (delta 153 656); both requests use less memory. 153 656 + 10 %.
	'PT-m'        => [
		'memory' => 169984,
	],
	// Handler rows read by one match on a 50 000-location table.
	'PT-rows'     => [
		'rows' => 200,
	],
	// Allowed growth of measured memory over the recorded baseline.
	'memory_band' => 0.05,
];
