<?php

use Kirby\Cms\App;
use Wagnerwagner\Merx\OrderPage;

return [
	'ordersPage' => 'orders',
	'production' => false,
	'api.endpoint' => 'shop',
	'taxRules' => [
		'default' => [
			'name' => 'default',
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
	'invoiceNumber' => function (OrderPage $virtualOrderPage): int
	{
		$lastOrder = $virtualOrderPage->siblings()->listed()->last();
		return ($lastOrder?->invoiceNumber()->toInt() ?? 0) + 1;
	},
	'paypal.applicationContext' => [],
	'paypal.live.clientID' => '',
	'paypal.live.secret' => '',
	'paypal.purchaseUnits' => null, /** fn (): array */
	'paypal.sandbox.clientID' => '',
	'paypal.sandbox.secret' => '',
	'stripe.live.publishable_key' => '',
	'stripe.live.secret_key' => '',
	'stripe.test.publishable_key' => '',
	'stripe.test.secret_key' => '',
	'stripe.webhook_signing_secret' => '',
	'gateways' => [],
];
