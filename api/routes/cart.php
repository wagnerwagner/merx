<?php

use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\Merx;

/** @var string $endpoint ww.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'GET',
		'action' => function (): Cart {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			Merx::setCurrency($this->requestQuery('currency') ?? $this->requestHeaders('x-currency'));

			return cart();
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'POST',
		'action' => function (): Cart {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			Merx::setCurrency($this->requestQuery('currency') ?? $this->requestHeaders('x-currency'));

			$allowedKeys = ['key', 'page', 'quantity', 'data', 'currency'];

			$cartData = array_filter($this->requestBody(), function($key) use ($allowedKeys) {
				return in_array($key, $allowedKeys);
			}, ARRAY_FILTER_USE_KEY);

			$cartData['currency'] ??= $this->requestHeaders('x-currency');

			$cart = cart();
			$cart->add($cartData);

			return $cart;
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'PATCH',
		'action' => function (): Cart {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			Merx::setCurrency($this->requestQuery('currency') ?? $this->requestHeaders('x-currency'));

			$key = $this->requestBody('key');
			$allowedKeys = ['key', 'page', 'quantity', 'data', 'currency'];

			$patchData = array_filter($this->requestBody(), function($key) use ($allowedKeys) {
				return in_array($key, $allowedKeys);
			}, ARRAY_FILTER_USE_KEY);

			$cart = cart();
			$cart->updateItem($key, $patchData);

			return $cart;
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'DELETE',
		'action' => function (): Cart {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			Merx::setCurrency($this->requestQuery('currency') ?? $this->requestHeaders('x-currency'));

			$key = $this->requestBody('key');

			$cart = cart();
			$cart->remove($key);

			return $cart;
		},
	],
];
