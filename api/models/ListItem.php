<?php

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;

return [
	'fields' => [
		'key' => fn (ListItem $listItem): string => $listItem->key,
		'title' => fn (ListItem $listItem): string|null => $listItem->title,
		'type' => fn (ListItem $listItem): string => $listItem->type,
		'thumb' => function (ListItem $listItem): array|null {
			if (!$listItem->page) {
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
		'url' => fn (ListItem $listItem): string|null => $listItem->page?->url(),
		'price' => fn (ListItem $listItem): Price|null => $listItem->price,
		'total' => fn (ListItem $listItem): Price|null => $listItem->total(),
		'quantity' => fn (ListItem $listItem): float => $listItem->quantity,
		'page' => fn (ListItem $listItem): Page|null => $listItem->page,
		'data' => fn (ListItem $listItem): array|null => $listItem->data,
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
