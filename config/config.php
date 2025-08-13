<?php

use Kirby\Cms\App;

return [
	'ordersPage' => 'orders',
	'production' => false,
	'api.endpoint' => 'shop',
	// 'taxRules' => [
	// 	'default' => [
	// 		'name' => fn (): string => t('taxRule.default'),
	// 		'rule' => fn (?App $kirby): float => 19,
	// 	],
	// ],
	// 'pricingRules' => [
	// 	'default' => [
	// 		'name' => 'default',
	// 		'currency' => 'EUR',
	// 		'rule' => fn (?App $kirby): bool => true,
	// 		'taxIncluded' => true,
	// 	],
	// ],
];
