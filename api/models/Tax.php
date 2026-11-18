<?php

use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		// No rate for a list whose items carry different tax rates
		'rate' => fn (Tax $tax): string|null => $tax->rate === null ? null : $tax->rate(),
		'rateRaw' => fn (Tax $tax): float|null => $tax->rate,
		// A tax without a rate still has an amount when it belongs to a list whose
		// items carry different tax rates
		'price' => fn (Tax $tax): string|null => $tax->rate === null && $tax->price === 0.0 ? null : $tax->toString(),
		'priceRaw' => fn (Tax $tax): float|null => $tax->price,
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
