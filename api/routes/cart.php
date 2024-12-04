<?php

use Kirby\Cms\Pages;
use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\Cart;

return [
	[
		'pattern' => 'merx/cart',
		'auth' => false,
		'method' => 'POST',
		'action' => function (): Cart {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$allowedKeys = ['id', 'key', 'quantity', 'data'];

			$cartData = array_filter($this->requestBody(), function($key) use ($allowedKeys) {
				return in_array($key, $allowedKeys);
			}, ARRAY_FILTER_USE_KEY);

			$cart = cart();
			$cart->add($cartData);

			return $cart;
		},
	],
	[
		'pattern' => 'merx/cart',
		'auth' => false,
		'method' => 'GET',
		'action' => function (): Cart {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			return cart();
		},
	],
	[
		'pattern' => 'merx/pages',
		'auth' => false,
		'method' => 'GET',
		'action' => function (): Pages {
			return site()->index();
		},
	],
];
