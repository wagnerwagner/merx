<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Stripe\PaymentIntent;
use Stripe\Util\RequestOptions;

/**
 * Storage for cart items
 *
 * Get the visitor’s cart with the global helper `cart()`. It also takes items like the [constructor](/reference/classes/cart/__construct), which replace the cart in the session.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @extends \Wagnerwagner\Merx\ProductList<ListItem>
 */
class Cart extends ProductList
{
	protected string $sessionName = 'wagnerwagner.merx.cartItems';

	/**
	 * Constructor
	 *
	 * @param $data Items in any form `add()` takes: the id or UUID of a product page, an array with `key` or `page` and optionally `quantity`, `title`, `price`, `type`, `data`, or a `ListItem`. Without items, the cart is read from the session.
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
	 * $cart->add(new ListItem(key: 'nice-socks', price: 10.0)); // Custom ListItem
	 * ```
	 *
	 * A quantity of 0 adds nothing, and one which leaves an item at 0 removes it, as `updateItem()` does.
	 *
	 * @throws Exception error.merx.cart.add
	 * @throws Exception error.merx.mixedCurrencies.add when the item has another currency than the cart
	 */
	public function add(string|array|ListItem $data): static
	{
		$kirby = App::instance();
		$listItem = null;
		try {
			// `apply()`: a hook may return the item it wants added instead,
			// e.g. with a key or a quantifier derived from its `data`
			$data = $kirby->apply('wagnerwagner.merx.cart.add:before', ['cart' => $this, 'data' => $data], 'data');
			$listItem = ListItem::factory($data);
			$this->validateCurrencyOf($listItem);
			parent::add($listItem);
			$this->save();
			$kirby->trigger('wagnerwagner.merx.cart.add:after', ['cart' => $this]);
			return $this;
		} catch (\Throwable $ex) {
			if ($ex instanceof Exception) {
				throw $ex;
			}
			Logger::log($ex, 'error');
			throw new Exception(
				key: 'merx.cart.add',
				data: [
					'key' => $listItem?->key ?? (is_string($data) ? $data : ''),
				],
				details: Merx::exceptionDetails($ex),
				previous: $ex,
			);
		}
	}

	/**
	 * Removes the cart from the visitor’s session.
	 */
	public function delete(): void
	{
		$kirby = App::instance();
		$kirby->trigger('wagnerwagner.merx.cart.delete:before', ['cart' => $this]);
		$kirby->session()->remove($this->sessionName);
		$this->data = [];
		$kirby->trigger('wagnerwagner.merx.cart.delete:after', ['cart' => $this]);
	}

	/**
	 * Stripe’s PaymentIntent for the cart
	 *
	 * The intent of the visitor’s session, with the cart’s total, as long as
	 * nothing was paid with it. Otherwise a new one.
	 *
	 * @param $params Additional parameters used by \Stripe\PaymentIntent::create().
	 * @param null|array|RequestOptions $options Additional options used by \Stripe\PaymentIntent::create().
	 * @throws Exception merx.emptycart, or merx.cart.notOrderable when the cart has no total, e.g. with prices in two currencies
	 * @return PaymentIntent
	 */
	public function getStripePaymentIntent(array|null $params = [], $options = []): object
	{
		$total = $this->total();
		if ($total === null) {
			throw new Exception(
				key: 'merx.cart.notOrderable',
				httpCode: 400,
			);
		}
		if ($total->price === 0.0) {
			throw new Exception(
				key: 'merx.emptycart',
				httpCode: 400,
			);
		}

		$params = array_merge([
			'currency' => $this->currency(),
		], $params ?? []);

		$id = App::instance()->session()->get('wagnerwagner.merx.stripePaymentIntentId');

		return StripePayment::createStripePaymentIntent($total->price, $params, $options, is_string($id) ? $id : null);
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
	 * `discount` of the breakdown. Items of the type `shipping` go into its
	 * `shipping`.
	 *
	 * @since 1.3.0
	 *
	 * @return array Returns an array in the format of PayPal’s purchase_unit_request
	 */
	public function payPalPurchaseUnits(): array
	{
		$currencyCode = $this->currency();
		$total = $this->total()?->price ?? 0.0;

		$purchaseUnit = [
			'description' => (string)site()->title(),
			'amount' => [
				'value' => number_format($total, 2, '.', ''),
				'currency_code' => $currencyCode,
			],
		];

		$items = [];
		$itemTotal = 0.0;
		$shipping = 0.0;

		foreach ($this->values() as $listItem) {
			/** @var ListItem $listItem */
			$unitAmount = $listItem->price?->price;

			if ($unitAmount === null || $unitAmount <= 0) {
				continue;
			}

			if ($listItem->type === 'shipping') {
				$shipping += round($unitAmount * $listItem->quantity, 2);
				continue;
			}

			// PayPal only accepts whole quantities. A cart with any other is
			// sent as its total alone.
			$quantity = $listItem->quantity;
			if ($quantity !== floor($quantity)) {
				return [$purchaseUnit];
			}

			$items[] = [
				'name' => $listItem->title ?? $listItem->key,
				'unit_amount' => [
					'value' => number_format($unitAmount, 2, '.', ''),
					'currency_code' => $currencyCode,
				],
				'quantity' => (string)(int)$quantity,
			];

			// PayPal recalculates the item total from the rounded unit amount and
			// the quantity, so Merx has to add it up the same way.
			$itemTotal += round($unitAmount, 2) * $quantity;
		}

		$discount = round($itemTotal + $shipping - $total, 2);

		// The breakdown has to add up to the amount which is charged, and a
		// discount can not be negative. Otherwise the total is sent alone.
		if (count($items) === 0 || $discount < 0) {
			return [$purchaseUnit];
		}

		$purchaseUnit['amount']['breakdown'] = [
			'item_total' => [
				'value' => number_format($itemTotal, 2, '.', ''),
				'currency_code' => $currencyCode,
			],
			'shipping' => [
				'value' => number_format($shipping, 2, '.', ''),
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
	 * Removes item from Cart by key
	 *
	 * @param $key the name of the key
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
		} catch (\Throwable $ex) {
			// A rejected update (invalid quantity, not enough stock) is a client error and
			// must keep its own message instead of being hidden behind merx.cart.update.
			if ($ex instanceof Exception) {
				throw $ex;
			}
			Logger::log($ex, 'error');
			throw new Exception(
				key: 'merx.cart.update',
				details: Merx::exceptionDetails($ex),
				previous: $ex,
			);
		}
	}

	/**
	 * Makes sure the price of $listItem is in the currency of the cart
	 *
	 * A cart is paid in one currency. A product list, e.g. a wishlist, may
	 * hold more than one.
	 *
	 * @throws Exception error.merx.mixedCurrencies.add
	 */
	protected function validateCurrencyOf(ListItem $listItem): void
	{
		$currency = $this->currency();
		$newCurrency = $listItem->price?->currency;

		if (is_string($currency) === true && is_string($newCurrency) === true && $currency !== $newCurrency) {
			throw new Exception(
				key: 'merx.mixedCurrencies.add',
				httpCode: 400,
				data: [
					'key' => $listItem->key,
					'currency' => $currency,
					'newCurrency' => $newCurrency,
				],
			);
		}
	}
}
