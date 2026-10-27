<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;

/**
 * Collection of ListItem objects for products
 *
 * Base for `Cart`.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @extends \Wagnerwagner\Merx\ListItems<\Wagnerwagner\Merx\ListItem>
 */
class ProductList extends ListItems
{
	/**
	 * Adds a new product to the list.
	 *
	 * ```php
	 * $productList->add('products/nice-shoes'); // ID of a ProductPage
	 * $productList->add('page://L1cJEiOOQI3VzljV'); // UUID of a ProductPage
	 * $productList->add(['key' => 'individual-shoes', 'page' => 'products/nice-shoes']); // Array including key
	 * $productList->add(new ListItem(key: 'nice-socks', price: 10])); // Custom LitItem
	 * ```
	 *
	 * @see ListItem
	 */
	public function add(
		string|array|ListItem $data,
	): static {
		$listItem = ListItem::factory($data);

		if ($existingItem = $this->get($listItem->key)) {
			$listItem->quantity += $existingItem->quantity;
		}

		static::validateQuantity($listItem);
		$this->validateStockOf($listItem);

		$this->set($listItem->key, $listItem);

		return $this;
	}

	/**
	 * Quantity of every product in the list, across all its lines
	 *
	 * A personalized product, for example, has one line per name.
	 *
	 * @return array<string, array{page: ProductPage, quantity: float}> Keyed by the page’s UUID
	 */
	protected function quantitiesPerProduct(): array
	{
		$quantities = [];

		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			if ($listItem->page instanceof ProductPage === false) {
				continue;
			}

			$uuid = (string)$listItem->page->uuid();
			$quantities[$uuid] ??= ['page' => $listItem->page, 'quantity' => 0.0];
			$quantities[$uuid]['quantity'] += $listItem->quantity;
		}

		return $quantities;
	}

	/**
	 * Lowers the stock of every product in the list by the quantity ordered
	 *
	 * Called by `Merx::createOrder()` once an order is written, while
	 * `wagnerwagner.merx.production` is true. The order exists by then, so a
	 * product which cannot be updated is logged and skipped rather than failing
	 * the order.
	 */
	public function reduceStock(): static
	{
		$logging = option('wagnerwagner.merx.logging') === true;

		foreach ($this->quantitiesPerProduct() as ['page' => $page, 'quantity' => $quantity]) {
			try {
				// A copy reads the stock from disk. The page itself may hold a stock
				// read earlier in this request, which another checkout has lowered since.
				$page = $page->clone()->reduceStock($quantity);
			} catch (\Throwable $ex) {
				if ($logging === true) {
					Logger::log('Stock of “' . $page->title() . '” not reduced: ' . $ex->getMessage(), 'error');
				}
				continue;
			}

			// Two checkouts can pass the stock check for the last item before
			// either order is written. Both orders exist by then, so this is only reported.
			if ($logging === true && $page->stock() < 0) {
				Logger::log('Stock of “' . $page->title() . '” is ' . $page->stock() . ': more were sold than there were.', 'error');
			}
		}

		return $this;
	}

	/**
	 * Updates existing item
	 */
	public function updateItem(string $key, array $data): self
	{
		/** @var ?ListItem $listItem */
		$listItem = $this->get($key);

		if ($listItem === null) {
			throw new Exception(
				key: 'merx.cart.missingItem',
				httpCode: 400,
				data: [
					'key' => $key,
				],
			);
		}

		// Work on a copy, so a rejected update cannot leave the item behind with a
		// quantity which never passed validation.
		$updatedItem = clone $listItem;

		foreach ($data as $dataKey => $val) {
			$updatedItem->$dataKey = $val;
		}

		// Update the price according to the quantity. Required for volume-based pricing.
		if (!array_key_exists('price', $data) && $updatedItem->page instanceof ProductPage) {
			$updatedItem->refreshPrice();
		}

		static::validateQuantity($updatedItem);

		// A change which does not raise the quantity always goes through, so a
		// cart can be brought back within a stock which has dropped since.
		if ($updatedItem->quantity > $listItem->quantity) {
			$this->validateStockOf($updatedItem);
		}

		if ($updatedItem->quantity === 0.0) {
			$this->remove($key);

			return $this;
		}

		$this->set($key, $updatedItem);

		return $this;
	}

	/**
	 * Makes sure the item’s quantity is a usable, non-negative number
	 *
	 * @throws Exception error.merx.cart.quantity when the quantity is negative or not a finite number
	 */
	protected static function validateQuantity(ListItem $listItem): void
	{
		if (is_finite($listItem->quantity) === false || $listItem->quantity < 0) {
			throw new Exception(
				key: 'merx.cart.quantity',
				httpCode: 400,
				data: [
					'title' => $listItem->title,
				],
			);
		}
	}

	/**
	 * Makes sure no product is in the list more often than its stock allows
	 *
	 * The lines of a product are added up first, so a product with one line per
	 * name cannot get past its stock one name at a time.
	 *
	 * @param $page Checks only this product rather than every product in the list
	 * @throws Exception error.merx.cart.soldOut when a product is sold out
	 * @throws Exception error.merx.cart.stock when a product exceeds its stock
	 */
	public function validateStock(ProductPage|null $page = null): static
	{
		foreach ($this->quantitiesPerProduct() as ['page' => $product, 'quantity' => $quantity]) {
			if ($page !== null && $product->is($page) === false) {
				continue;
			}

			$stock = $product->stock();

			if ($stock === null || $quantity <= $stock) {
				continue;
			}

			throw new Exception(
				key: $stock > 0 ? 'merx.cart.stock' : 'merx.cart.soldOut',
				httpCode: 400,
				data: [
					'title' => $product->title()->value(),
					'stock' => $stock,
				],
			);
		}

		return $this;
	}

	/**
	 * Makes sure $listItem, together with the other lines of its product, stays
	 * within the product’s stock
	 *
	 * @throws Exception error.merx.cart.soldOut|error.merx.cart.stock
	 */
	protected function validateStockOf(ListItem $listItem): void
	{
		if ($listItem->page instanceof ProductPage === false) {
			return;
		}

		// Checked on a copy, so a rejected item never reaches the list
		$productList = clone $this;
		$productList->set($listItem->key, $listItem);
		$productList->validateStock($listItem->page);
	}
}
