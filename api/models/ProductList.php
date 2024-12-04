<?php

use Kirby\Cms\Api;
use Wagnerwagner\Merx\ListItems;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductList;
use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'products' => fn (ProductList $productList): ListItems => $productList->filterByType('product'),
		'shipping' => fn (ProductList $productList): ListItems => $productList->filterByType('shipping'),
		'quantity' => fn (ProductList $productList): int => $productList->quantity(),
		'taxRates' => fn (ProductList $productList): array => array_values(array_map(function (Tax $tax) {
			/** @var Api $this */
			$apiModel = $this->model('tax', $tax);
			return $apiModel->toArray();
		}, $productList->taxRates())),
		'total' => fn (ProductList $productList): Price => $productList->total(),
	],
	'type' => ProductList::class,
	'views' => [
		'compact' => [
			'quantity',
		],
		'default' => [
			'products',
			'total' => [
				'price',
				'priceRaw',
				'priceNet',
				'priceNetRaw',
			],
			'taxRates',
			'quantity',
			'shipping',
		],
	],
];
