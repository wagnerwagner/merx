<?php

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;

return [
	'fields' => [
		'title' => fn (ListItem $listItem): ?string => $listItem->title,
		'type' => fn (ListItem $listItem): string => $listItem->type,
		'thumb' => function (ListItem $listItem): ?array {
			if ($listItem->page === null) {
				return null;
			}

			/** @var File $thumb */
			$thumb = $listItem->page->thumb() instanceof File ? $listItem->page->thumb() : $listItem->page->thumb()->toFile();

			return $thumb ? [
				'alt' => (string)$thumb->alt(),
				'src' => $thumb->thumb('thumb')->url(),
				'srcset' => $thumb->srcset('thumb'),
			] : null;
		},
		'url' => fn (ListItem $listItem): ?string => $listItem->page?->url(),
		'price' => fn (ListItem $listItem): ?Price => $listItem->price,
		'priceTotal' => fn (ListItem $listItem): ?Price => $listItem->priceTotal(),
		'quantity' => fn (ListItem $listItem): float => $listItem->quantity,
		'page' => fn (ListItem $listItem): ?Page => $listItem->page,
	],
	'type' => ListItem::class,
	'views' => [
		'compact' => [
			'title',
			'type',
			'price',
			'priceTotal',
			'quantity',
		],
		'default' => [
			'title',
			'type',
			'thumb',
			'url',
			'price',
			'priceTotal',
			'quantity',
			'page' => [
				'url',
				'template',
				'uuid',
			],
		],
	],
];
