<?php

use Kirby\Cms\App;
use Wagnerwagner\Merx\OrderPage;

return [
	'ordersPage' => 'orders',
	'production' => false,
	'logging' => true,
	'license' => '',
	'api.endpoint' => 'shop',
	'taxRules' => [
		'default' => [
			'name' => 'default',
			'rule' => fn (?App $kirby): ?float => null,
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
	'orderNumber' => function (OrderPage $virtualOrderPage): int
	{
		$lastOrder = $virtualOrderPage->siblings()->listed()->last();
		return ($lastOrder?->orderNumber()->toInt() ?? 0) + 1;
	},
	'paypal.applicationContext' => [],
	'paypal.live.clientID' => '',
	'paypal.live.secret' => '',
	'paypal.purchaseUnits' => fn (): array => [],
	'paypal.sandbox.clientID' => '',
	'paypal.sandbox.secret' => '',
	'stripe.live.publishable_key' => '',
	'stripe.live.secret_key' => '',
	'stripe.test.publishable_key' => '',
	'stripe.test.secret_key' => '',
	'stripe.webhook_signing_secret' => '',
	'stripe.paymentIntentParameters' => [
		'capture_method' => 'manual',
		'automatic_payment_methods' => ['enabled' => true],
	],
	'gateways' => [],
];
