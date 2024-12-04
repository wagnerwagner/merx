<?php

use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'currency' => fn (Price $price): ?string => $price->currency,
		'price' => fn (Price $price): string => $price->__toString(),
		'priceRaw' => fn (Price $price): float => $price->price,
		'priceNet' => fn (Price $price): string => $price->toString('priceNet'),
		'priceNetRaw' => fn (Price $price): ?float => $price->priceNet,
		'tax' => fn (Price $price): ?Tax => $price->tax,
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
			'tax',
		],
	],
];
