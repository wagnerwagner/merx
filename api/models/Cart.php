<?php

use Kirby\Cms\Page;
use Wagnerwagner\Merx\Cart;

$productList = include 'ProductList.php';
return [
	'fields' => array_merge($productList['fields'], [
		'checkout' => fn (): ?Page => site()->checkoutPage(),
	]),
	'type' => Cart::class,
	'views' => array_merge_recursive($productList['views'], [
		'default' => [
			'checkout' => [
				'url',
			],
		]
	]),
];
