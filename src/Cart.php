<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Wagnerwagner\Merx\ProductList;
use Kirby\Exception\Exception;
use stdClass;
use Throwable;

/**
 * Storage for cart items
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class Cart extends ProductList
{
	protected string $sessionName = 'wagnerwagner.merx.cartItems';

	/**
	 * Constructor
	 *
	 * @param array $data List of product items. Product items must contain `id`. `quantity`, `title`, `price`, `tax` are optional.
	 */
	public function __construct(array $data = [])
	{
		$kirby = kirby();
		if (count($data) === 0 && is_array($kirby->session()->get($this->sessionName))) {
			$data = $kirby->session()->get($this->sessionName);
		}
		parent::__construct($data, true);
		kirby()->trigger('wagnerwagner.merx.cart.create:before', ['cart' => $this, 'data' => $data]);
		$this->save();
		kirby()->trigger('wagnerwagner.merx.cart.create:after', ['cart' => $this]);
	}

	/**
	 * Adds item to cart.
	 *
	 * ```php
	 * $cart->add('products/nice-shoes'); // ID of a ProductPage
	 * $cart->add('page://L1cJEiOOQI3VzljV'); // UUID of a ProductPage
	 * $cart->add(['key' => 'individual-shoes', 'page' => 'products/nice-shoes']); // Array including key
	 * $cart->add(new ListItem(key: 'nice-socks', price: 10])); // Custom LitItem
	 * ```
	 *
	 * @throws Exception error.merx.cart.add
	 */
	public function add(string|array|ListItem $data): static
	{
		try {
			kirby()->trigger('wagnerwagner.merx.cart.add:before', ['cart' => $this, 'data' => $data]);
			parent::add($data);

			// if ($this->currency() === false) {
			// 	throw new Exception(
			// 		key: 'merx.mixedCurrencies.currency',
			// 		data: [
			// 			'key' => $this->key,
			// 		],
			// 	);
			// }

			$this->save();
			kirby()->trigger('wagnerwagner.merx.cart.add:after', ['cart' => $this]);
			return $this;
		} catch (\Exception $ex) {
			$key = null;
			try {
				$key = $data['key'] ?? $data->key ?? (string)$data ?? '';
			} catch (Throwable) {}
			throw new Exception([
				'key' => 'merx.cart.add',
				'data' => [
					'key' => $key,
				],
				'details' => [
					'previous' => $ex->getMessage(),
				],
				'previous' => $ex,
			]);
		}
	}


	/**
	 * Removes item from Cart by key
	 *
	 * @param string $key the name of the key
	 * @return $this
	 */
	public function remove(string $key): static
	{
		kirby()->trigger('wagnerwagner.merx.cart.remove:before', ['cart' => $this, 'key' => $key]);
		parent::remove($key);
		$this->save();
		kirby()->trigger('wagnerwagner.merx.cart.remove:after', ['cart' => $this, 'key' => $key]);
		return $this;
	}


	/**
	 * Updates existing item.
	 */
	public function updateItem(string $key, array $data): static
	{
		try {
			kirby()->trigger('wagnerwagner.merx.cart.updateItem:before', ['cart' => $this, 'key' => $key, 'data' => $data]);
			parent::updateItem($key, $data);
			$this->save();
			kirby()->trigger('wagnerwagner.merx.cart.updateItem:after', ['cart' => $this, 'key' => $key, 'data' => $data]);
			return $this;
		} catch (\Exception $ex) {
			throw new Exception([
				'key' => 'merx.cart.update',
				'details' => [
					'message' => $ex->getMessage(),
					'code' => $ex->getCode(),
					'file' => $ex->getFile(),
					'line' => $ex->getLine(),
				],
				'previous' => $ex,
			]);
		}
	}

	/**
	 * Get Stripe’s PaymentIntent.
	 *
	 * @param null|array $params Additional parameters used by \Stripe\PaymentIntent::create().
	 * @param null|array|\Stripe\Util\RequestOptions $options Additional options used by \Stripe\PaymentIntent::create().
	 * @throws \Kirby\Exception\Exception merx.emptycart
	 * @return \Stripe\PaymentIntent
	 */
	public function getStripePaymentIntent(?array $params = [], $options = []): object
	{
		$amount = $this->total()->toFloat();
		if ($amount === 0.0) {
			throw new Exception([
				'key' => 'merx.emptycart',
				'httpCode' => 400,
			]);
		}

		$params = array_merge([
			'currency' => $this->currency(),
		], $params);

		return StripePayment::createStripePaymentIntent($amount, $params, $options);
	}


	/**
	 * Removes Cart from user’s session.
	 */
	public function delete(): void
	{
		$kirby = App::instance();
		$kirby->trigger('wagnerwagner.merx.cart.delete:before', ['cart' => $this]);
		$kirby->session()->remove($this->sessionName);
		$kirby->trigger('wagnerwagner.merx.cart.delete:after', ['cart' => $this]);
		$this->data = [];
	}


	private function save(): static
	{
		if ($this->count() === 0) {
			kirby()->session()->remove($this->sessionName);
		} else {
			$sessionData = $this->toArray(fn (ListItem $item) => $item->toSessionArray());
			kirby()->session()->set($this->sessionName, $sessionData);
		}
		return $this;
	}

	/**
	 * Could be used for wagnerwagner.merx.paypal.purchaseUnits
	 *
	 * @since 1.3.0
	 *
	 * @return array Returns an array in the format of PayPal’s purchase_unit_request
	 */
	public function payPalPurchaseUnits(): array
	{
		$siteTitle = site()->title();
		$total = $this->total()->toFloat();
		$currencyCode = $this->currency();
		$discount = 0;
		foreach ($this->values() as $cartItem) {
			if ($cartItem['price'] <= 0) {
				$discount += $cartItem['sum'];
			}
		}
		$discount = $discount * -1;
		$itemTotal = $total + $discount;
		$items = array_filter($this->values(), function ($cartItem) {
			return $cartItem['price'] > 0;
		});
		return [
			[
				'description' => (string)$siteTitle,
				'amount' => [
					'value' => number_format($total, 2, '.', ''),
					'currency_code' => $currencyCode,
					'breakdown' => [
						'item_total' => [
							'value' => number_format($itemTotal, 2, '.', ''),
							'currency_code' => $currencyCode,
						],
						'discount' => [
							'value' => number_format($discount, 2, '.', ''),
							'currency_code' => $currencyCode,
						],
					],
				],
				'items' => array_map(function ($cartItem) use ($currencyCode) {
					$cartUnitAmount = new stdClass;
					$cartUnitAmount->value = number_format($cartItem['price'], 2, '.', '');
					$cartUnitAmount->currency_code = $currencyCode;

					return [
						'name' => $cartItem['title'] ?? $cartItem['id'],
						'unit_amount' => $cartUnitAmount,
						'quantity' => $cartItem['quantity'],
					];
				}, $items),
			],
		];
	}

	/**
	 * @param string $key
	 * @param ListItem $value
	 * @return void
	 * @internal
	 * @throws Exception When currency of new item does not match existing currency
	 */
	public function __set(string $key, $value): void
	{
		$listItem = ListItem::factory($value);
		$currency = $this->currency();

		// Check currencies
		if (is_string($currency) && $listItem->price?->currency !== $currency) {
			$listItem->price = null;
			parent::__set($key, $listItem);
			// throw new Exception([
			// 	'key' => 'merx.mixedCurrencies.add',
			// 	'data' => [
			// 		'key' => $listItem->key,
			// 		'currency' => $currency,
			// 		'newCurrency' => $listItem->price?->currency,
			// 	],
			// ]);
		} else {
			parent::__set($key, $listItem);
		}
	}
}
