<?php

use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'rate' => fn (Tax $tax): string => $tax->rate(),
		'rateRaw' => fn (Tax $tax): ?float => $tax->rate,
		'price' => fn (Tax $tax): ?string => $tax->rate === null ? null : $tax->toString(),
		'priceRaw' => fn (Tax $tax): ?float => $tax->price,
	],
	'type' => Tax::class,
	'views' => [
		'compact' => [
			'rate',
			'price',
		],
		'formatted' => [
			'rate',
			'price',
		],
		'raw' => [
			'rateRaw',
			'priceRaw',
		],
		'default' => [
			'rate',
			'rateRaw',
			'price',
			'priceRaw',
		],
	],
];
