<?php

use Kirby\Cms\Api;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductList;
use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'products' => function (ProductList $productList): ?array
		{
			/** @var Api $this */
			$listItems = $productList->filterByType('product');
			return $listItems->count() === 0 ? null : [
				'items' => $this->collection('listitems', $listItems)->toArray(),
				'total' => $this->model('price', $listItems->total())->toArray(),
			];
		},
		'shippings' => function (ProductList $productList): ?array
		{
			/** @var Api $this */
			$listItems = $productList->filterByType('shipping');
			return $listItems->count() === 0 ? null : [
				'items' => $this->collection('listitems', $listItems)->toArray(),
				'total' => $this->model('price', $listItems->total())->toArray(),
			];
		},
		'quantity' => fn (ProductList $productList): int => $productList->quantity(),
		'taxRates' => fn (ProductList $productList): array => array_values(array_map(function (Tax $tax) {
			/** @var Api $this */
			$apiModel = $this->model('tax', $tax);
			return $apiModel->toArray();
		}, $productList->taxRates())),
		'total' => fn (ProductList $productList): ?Price => $productList->total(),
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
			'shippings',
		],
	],
];
