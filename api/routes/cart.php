<?php

use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\Cart;

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'GET',
		'action' => function (): Cart
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			/** @var \Wagnerwagner\Merx\Cart $cart */
			$cart = $this->cart();
			return $cart;
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'POST',
		'action' => function (): Cart
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$allowedKeys = ['key', 'page', 'quantity', 'data'];

			$cartData = array_filter($this->requestBody(), function($key) use ($allowedKeys) {
				return in_array($key, $allowedKeys);
			}, ARRAY_FILTER_USE_KEY);

			/** @var \Wagnerwagner\Merx\Cart $cart */
			$cart = $this->cart();
			$cart->add($cartData);

			return $cart;
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'PATCH',
		'action' => function (): Cart
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$key = $this->requestBody('key');
			$allowedKeys = ['key', 'page', 'quantity', 'data'];

			$patchData = array_filter($this->requestBody(), function($key) use ($allowedKeys) {
				return in_array($key, $allowedKeys);
			}, ARRAY_FILTER_USE_KEY);

			/** @var Cart */
			$cart = $this->cart();
			$cart->updateItem($key, $patchData);

			return $cart;
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'DELETE',
		'action' => function (): Cart
		{
			/** @var \Kirby\Cms\Api*/
			I18n::$locale = $this->language();

			$key = $this->requestBody('key');

			/** @var \Wagnerwagner\Merx\Cart $cart */
			$cart = $this->cart();
			$cart->remove($key);

			return $cart;
		},
	],
];
