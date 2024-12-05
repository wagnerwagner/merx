<?php

use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\Tax;

return [
	'fields' => [
		'rate' => function (Tax $tax): string {
			$format = new NumberFormatter(I18n::locale(), NumberFormatter::PERCENT);
			return $format->format($tax->rate);
		},
		'rateRaw' => fn (Tax $tax): ?float => $tax->rate,
		'price' => fn (Tax $tax): ?string => $tax->price->price !== null ? $tax->price->toString() : null,
		'priceRaw' => fn (Tax $tax): ?float => $tax->price->price,
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
