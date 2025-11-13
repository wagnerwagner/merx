<?php

use Kirby\Cms\App;

return [
	'ordersPage' => 'orders',
	'production' => false,
	'api.endpoint' => 'shop',
	'taxRules' => [
		'default' => [
			'name' => fn (): string => t('taxRule.default'),
			'rule' => fn (?App $kirby): float => 19,
		],
	],
	'pricingRules' => [
		'default' => [
			'name' => 'default',
			'currency' => 'EUR',
			'rule' => fn (?App $kirby): bool => true,
			'taxIncluded' => true,
		],
	],
	'stripe.test.publishable_key' => '',
	'stripe.test.secret_key' => '',
	'stripe.live.publishable_key' => '',
	'stripe.live.secret_key' => '',
	'paypal.sandbox.clientID' => '',
	'paypal.sandbox.secret' => '',
	'paypal.live.clientID' => '',
	'paypal.live.secret' => '',
	'paypal.applicationContext' => [],
	'paypal.purchaseUnits' => null, /** fn () => [] */
	'stripe.webhook_signing_secret' => '',
	'gateways' => [],
];
