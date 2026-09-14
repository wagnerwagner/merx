<?php

use Kirby\Exception\InvalidArgumentException;
use Wagnerwagner\Merx\Cart;

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

/**
 * Keys of a list item’s `data` which are derived from its page and must never
 * be set by the client. `maxQuantity` would otherwise lift its own limit.
 */
$protectedDataKeys = ['maxQuantity'];

/**
 * Reduces the request body to the allowed keys, drops protected `data` keys and
 * makes sure `quantity` is a number before it reaches the cart.
 */
$cartRequestData = function (array $requestBody, array $allowedKeys) use ($protectedDataKeys): array {
	$data = array_filter(
		$requestBody,
		fn ($key) => in_array($key, $allowedKeys, true),
		ARRAY_FILTER_USE_KEY,
	);

	if (isset($data['data']) === true && is_array($data['data']) === true) {
		$data['data'] = array_diff_key($data['data'], array_flip($protectedDataKeys));
	}

	if (array_key_exists('quantity', $data) === true) {
		if (is_numeric($data['quantity']) === false) {
			throw new InvalidArgumentException(
				key: 'merx.cart.quantity',
				httpCode: 400,
			);
		}
		$data['quantity'] = (float)$data['quantity'];
	}

	return $data;
};

return [
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'GET',
		/**
		 * @see https://merx.wagnerwagner.de/guide/getting-started/showing-the-cart
		 * @return Cart The visitor’s current cart.
		 */
		'action' => function (): Cart
		{
			/** @var \Kirby\Api\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			/** @var \Wagnerwagner\Merx\Cart $cart */
			$cart = $this->cart();
			return $cart;
		},
	],
	[
		'pattern' => $endpoint . '/cart',
		'auth' => false,
		'method' => 'POST',
		/**
		 * @see https://merx.wagnerwagner.de/guide/getting-started/adding-products
		 * @return Cart The cart including the added item.
		 */
		'action' => function () use ($cartRequestData): Cart
		{
			/** @var \Kirby\Api\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$cartData = $cartRequestData($this->requestBody(), ['key', 'page', 'quantity', 'data']);

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
		/**
		 * @return Cart The cart including the updated item.
		 */
		'action' => function () use ($cartRequestData): Cart
		{
			/** @var \Kirby\Api\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$key = $this->requestBody('key');

			// `key` and `page` identify the item and must not be rewritten by an update.
			$patchData = $cartRequestData($this->requestBody(), ['quantity', 'data']);

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
		/**
		 * @return Cart The cart without the removed item.
		 */
		'action' => function (): Cart
		{
			/** @var \Kirby\Api\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$key = $this->requestBody('key');

			/** @var \Wagnerwagner\Merx\Cart $cart */
			$cart = $this->cart();
			$cart->remove($key);

			return $cart;
		},
	],
];
