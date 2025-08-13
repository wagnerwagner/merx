<?php

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;

return [
	'fields' => [
		'key' => fn (ListItem $listItem): string => $listItem->key,
		'title' => fn (ListItem $listItem): ?string => $listItem->title,
		'type' => fn (ListItem $listItem): string => $listItem->type,
		'thumb' => function (ListItem $listItem): ?array {
			if ($listItem->page === null) {
				return null;
			}

			/** @var \Kirby\Cms\File $thumb */
			$thumb = $listItem->page->thumb() instanceof File ? $listItem->page->thumb() : $listItem->page->thumb()->toFile();

			return $thumb ? [
				'alt' => (string)$thumb->alt(),
				'src' => $thumb->thumb('thumb')->url(),
				'srcset' => $thumb->srcset('thumb'),
			] : null;
		},
		'url' => fn (ListItem $listItem): ?string => $listItem->page?->url(),
		'price' => fn (ListItem $listItem): ?Price => $listItem->price,
		'total' => fn (ListItem $listItem): ?Price => $listItem->total(),
		'quantity' => fn (ListItem $listItem): float => $listItem->quantity,
		'page' => fn (ListItem $listItem): ?Page => $listItem->page,
		'data' => fn (ListItem $listItem): ?array => $listItem->data,
	],
	'type' => ListItem::class,
	'views' => [
		'compact' => [
			'key',
			'title',
			'type',
			'price',
			'total',
			'quantity',
		],
		'default' => [
			'key',
			'title',
			'type',
			'thumb',
			'url',
			'price',
			'total',
			'quantity',
			'data',
			'page' => [
				'url',
				'template',
				'uuid',
			],
		],
	],
];
