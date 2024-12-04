<?php

use Kirby\Api\Api;
use Wagnerwagner\Merx\Cart;

$productList = include 'ProductList.php';
return [
	'fields' => array_merge($productList['fields'], [
		'checkoutUrl' => function (): string
		{
			/** @var Api $this */
			return site()->checkoutPage()->url();
		},
	]),
	'type' => Cart::class,
	'views' => [
		'compact' => [
			'quantity',
		],
		'default' => [
			'products',
			'pages',
			'isPriceUponRequest',
			'subtotalNet',
			'sum',
			'sumNet',
			'tax',
			'taxRates',
			'quantity',
			'discount',
			'shipping',
			'shippingNet',
			'shippingDestination',
			'checkoutUrl',
		],
	],
];
