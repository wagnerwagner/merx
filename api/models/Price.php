<?php

use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'currency' => fn (Price $price): string|null => $price->currency,
		'price' => fn (Price $price): string => $price->toString('price'),
		'priceRaw' => fn (Price $price): float => $price->price,
		'priceNet' => fn (Price $price): string => $price->toString('priceNet'),
		'priceNetRaw' => fn (Price $price): float|null => $price->priceNet,
		'priceIncludingTax' => fn (Price $price): bool|null => $price->pricingRule?->taxIncluded,
		'tax' => fn (Price $price): Tax|null => $price->tax,
	],
	'type' => Price::class,
	'views' => [
		'compact' => [
			'price',
		],
		'formatted' => [
			'price',
			'priceNet',
			'tax',
		],
		'raw' => [
			'currency',
			'priceRaw',
			'priceNetRaw',
			'tax',
		],
		'default' => [
			'price',
			'priceRaw',
			'priceNet',
			'priceNetRaw',
			'priceIncludingTax',
			'tax',
		],
	],
];
