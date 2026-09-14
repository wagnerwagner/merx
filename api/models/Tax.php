<?php

use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'rate' => fn (Tax $tax): string => $tax->rate(),
		'rateRaw' => fn (Tax $tax): ?float => $tax->rate,
		// A tax without a rate still has an amount when it belongs to a list whose
		// items carry different tax rates
		'price' => fn (Tax $tax): ?string => $tax->rate === null && $tax->price === 0.0 ? null : $tax->toString(),
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
