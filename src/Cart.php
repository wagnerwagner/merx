<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Wagnerwagner\Merx\ProductList;
use Kirby\Exception\Exception;

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
		$kirby = App::instance();
		if (count($data) === 0 && is_array($kirby->session()->get($this->sessionName))) {
			$data = $kirby->session()->get($this->sessionName);
		}
		parent::__construct($data, true);
		$kirby->trigger('wagnerwagner.merx.cart.create:before', ['cart' => $this, 'data' => $data]);
		$this->save();
		$kirby->trigger('wagnerwagner.merx.cart.create:after', ['cart' => $this]);
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
		$kirby = App::instance();
		try {
			$kirby->trigger('wagnerwagner.merx.cart.add:before', ['cart' => $this, 'data' => $data]);
			parent::add($data);
			$this->save();
			$kirby->trigger('wagnerwagner.merx.cart.add:after', ['cart' => $this]);
			return $this;
		} catch (\Exception $ex) {
			if ($ex instanceof Exception) {
				throw $ex;
			}
			if (option('wagnerwagner.merx.logging') === true) {
				Logger::log($ex, 'error');
			}
			throw new Exception(
				key: 'merx.cart.add',
				data: [
					'key' => $data['key'] ?? $data['page'] ?? $data->key ?? (string)$data ?? '',
				],
				details: Merx::exceptionDetails($ex),
				previous: $ex,
			);
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
		$kirby = App::instance();
		$kirby->trigger('wagnerwagner.merx.cart.remove:before', ['cart' => $this, 'key' => $key]);
		parent::remove($key);
		$this->save();
		$kirby->trigger('wagnerwagner.merx.cart.remove:after', ['cart' => $this, 'key' => $key]);
		return $this;
	}

	/**
	 * Updates existing item.
	 */
	public function updateItem(string $key, array $data): static
	{
		$kirby = App::instance();
		try {
			$kirby->trigger('wagnerwagner.merx.cart.updateItem:before', ['cart' => $this, 'key' => $key, 'data' => $data]);
			parent::updateItem($key, $data);
			$this->save();
			$kirby->trigger('wagnerwagner.merx.cart.updateItem:after', ['cart' => $this, 'key' => $key, 'data' => $data]);
			return $this;
		} catch (\Exception $ex) {
			// A rejected update (invalid quantity, `maxQuantity`) is a client error and
			// must keep its own message instead of being hidden behind merx.cart.update.
			if ($ex instanceof Exception) {
				throw $ex;
			}
			if (option('wagnerwagner.merx.logging') === true) {
				Logger::log($ex, 'error');
			}
			throw new Exception(
				key: 'merx.cart.update',
				details: Merx::exceptionDetails($ex),
				previous: $ex,
			);
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
			throw new Exception(
				key: 'merx.emptycart',
				httpCode: 400,
			);
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
		$this->data = [];
		$kirby->trigger('wagnerwagner.merx.cart.delete:after', ['cart' => $this]);
	}


	private function save(): static
	{
		$kirby = App::instance();
		if ($this->count() === 0) {
			$kirby->session()->remove($this->sessionName);
		} else {
			$sessionData = $this->toArray(fn (ListItem $item) => $item->toSessionArray());
			$kirby->session()->set($this->sessionName, $sessionData);
		}
		return $this;
	}

	/**
	 * Cart as PayPal purchase unit
	 *
	 * Could be used for wagnerwagner.merx.paypal.purchaseUnits. The option
	 * replaces Merx’ own purchase unit, so the returned unit carries the total
	 * of the whole cart.
	 *
	 * Items without a price and discounts (items with a negative price) are not
	 * listed as items. PayPal adds the items up itself and rejects an order
	 * whose numbers do not match, so discounts are settled through the
	 * `discount` of the breakdown.
	 *
	 * @since 1.3.0
	 *
	 * @return array Returns an array in the format of PayPal’s purchase_unit_request
	 */
	public function payPalPurchaseUnits(): array
	{
		$currencyCode = $this->currency();
		$total = $this->total()?->toFloat() ?? 0.0;

		$items = [];
		$itemTotal = 0.0;

		foreach ($this->values() as $listItem) {
			/** @var ListItem $listItem */
			$unitAmount = $listItem->price?->toFloat();

			if ($unitAmount === null || $unitAmount <= 0) {
				continue;
			}

			// PayPal only accepts whole quantities.
			$quantity = (int)$listItem->quantity;

			$items[] = [
				'name' => $listItem->title ?? $listItem->key,
				'unit_amount' => [
					'value' => number_format($unitAmount, 2, '.', ''),
					'currency_code' => $currencyCode,
				],
				'quantity' => (string)$quantity,
			];

			// PayPal recalculates the item total from the rounded unit amount and
			// the quantity, so Merx has to add it up the same way.
			$itemTotal += round($unitAmount, 2) * $quantity;
		}

		$purchaseUnit = [
			'description' => (string)site()->title(),
			'amount' => [
				'value' => number_format($total, 2, '.', ''),
				'currency_code' => $currencyCode,
			],
		];

		$discount = round($itemTotal - $total, 2);

		// The breakdown has to add up to the amount which is charged, and a
		// discount can not be negative. A cart PayPal can not express as items —
		// fractional quantities, for example — is sent as its total alone.
		if (count($items) === 0 || $discount < 0) {
			return [$purchaseUnit];
		}

		$purchaseUnit['amount']['breakdown'] = [
			'item_total' => [
				'value' => number_format($itemTotal, 2, '.', ''),
				'currency_code' => $currencyCode,
			],
			'discount' => [
				'value' => number_format($discount, 2, '.', ''),
				'currency_code' => $currencyCode,
			],
		];
		$purchaseUnit['items'] = $items;

		return [$purchaseUnit];
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
