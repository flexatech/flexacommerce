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
	// Allowed growth of measured memory over the recorded baseline.
	'memory_band' => 0.05,
];
