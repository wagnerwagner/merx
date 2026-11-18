<?php

use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductList;
use Wagnerwagner\Merx\Tax;

/**
 * The items of one type with their total, or null when the list has none
 */
$itemsOfType = fn (string $type): Closure =>
	/** @return array{items: list<ListItem>, total: Price}|null */
	function (ProductList $productList) use ($type): array|null {
		/** @var \Kirby\Api\Api $this */
		$listItems = $productList->filterByType($type);
		return $listItems->count() === 0 ? null : [
			'items' => $this->collection('listitems', $listItems)->toArray(),
			'total' => $this->model('price', $listItems->total())->toArray(),
		];
	};

return [
	'fields' => [
		'products' => $itemsOfType('product'),
		'shippings' => $itemsOfType('shipping'),
		'discounts' => $itemsOfType('discount'),
		'quantity' => fn (ProductList $productList): float => $productList->quantity(),
		/** @return list<Tax> */
		'taxRates' => fn (ProductList $productList): array => array_values(array_map(function (Tax $tax) {
			/** @var \Kirby\Api\Api $this */
			return $this->model('tax', $tax)->toArray();
		}, $productList->taxRates())),
		'total' => fn (ProductList $productList): Price|null => $productList->total(),
		'isFromPrice' => fn (ProductList $productList): bool => $productList->isFromPrice(),
		'isOrderable' => fn (ProductList $productList): bool => $productList->isOrderable(),
	],
	'type' => ProductList::class,
	'views' => [
		'compact' => [
			'quantity',
		],
		'default' => [
			'products',
			'total',
			'taxRates',
			'quantity',
			'shippings',
			'discounts',
			'isFromPrice',
			'isOrderable',
		],
	],
];
