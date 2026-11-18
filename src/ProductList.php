<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Kirby\Exception\InvalidArgumentException;

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
	 * How large the `data` of an item may be, in bytes of its JSON
	 */
	public static int $maxDataSize = 4096;

	/**
	 * Adds a new product to the list.
	 *
	 * ```php
	 * $productList->add('products/nice-shoes'); // ID of a ProductPage
	 * $productList->add('page://L1cJEiOOQI3VzljV'); // UUID of a ProductPage
	 * $productList->add(['key' => 'individual-shoes', 'page' => 'products/nice-shoes']); // Array including key
	 * $productList->add(new ListItem(key: 'nice-socks', price: 10.0)); // Custom ListItem
	 * ```
	 *
	 * A quantity of 0 adds nothing, and one which leaves an item at 0 removes it, as `updateItem()` does.
	 *
	 * @see ListItem
	 */
	public function add(
		string|array|ListItem $data,
	): static {
		$listItem = ListItem::factory($data);

		if ($existingItem = $this->get($listItem->key)) {
			$listItem->quantity += $existingItem->quantity;
			// The price may depend on the quantity, e.g. a volume discount. The
			// next request reads it from the page for the whole line anyway.
			$listItem->refreshPrice();
		}

		static::validateQuantity($listItem);

		// Nothing to add, or nothing left of the line, as with `updateItem()`.
		// A line without a quantity would make the list orderable for nothing.
		if ($listItem->quantity === 0.0) {
			return $existingItem ? $this->remove($listItem->key) : $this;
		}

		static::validateProduct($listItem);
		static::validateData($listItem);
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
	 * `wagnerwagner.merx.reduceStock` is true. The order exists by then, so a
	 * product which cannot be updated is logged and skipped rather than failing
	 * the order.
	 *
	 * @see https://merx.wagnerwagner.de/guide/getting-started/stock
	 */
	public function reduceStock(): static
	{
		foreach ($this->quantitiesPerProduct() as ['page' => $page, 'quantity' => $quantity]) {
			try {
				// A copy reads the stock from disk. The page itself may hold a stock
				// read earlier in this request, which another checkout has lowered since.
				$page = $page->clone()->reduceStock($quantity);
			} catch (\Throwable $ex) {
				Logger::log('Stock of “' . $page->title() . '” not reduced: ' . $ex->getMessage(), 'error');
				continue;
			}

			// Two checkouts can pass the stock check for the last item before
			// either order is written. Both orders exist by then, so this is only reported.
			if ($page->stock() < 0) {
				Logger::log('Stock of “' . $page->title() . '” is ' . $page->stock() . ': more were sold than there were.', 'error');
			}
		}

		return $this;
	}

	/**
	 * Updates existing item
	 *
	 * @param $data What `new ListItem()` takes, except `key`, which stays
	 * @throws InvalidArgumentException when $data has a key the constructor does not take, or a value of the wrong type
	 */
	public function updateItem(string $key, array $data): self
	{
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

		// Built anew, as from the session, so the constructor checks $data and
		// reads the price of a page for the new quantity, e.g. a volume discount.
		// The page as it is, which a virtual page needs. A rejected update leaves
		// the item as it was.
		$updatedItem = ListItem::factory([...$listItem->toSessionArray(), 'page' => $listItem->page, ...$data, 'key' => $key]);

		static::validateQuantity($updatedItem);
		static::validateData($updatedItem);

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
	 * Makes sure the `data` of $listItem stays small
	 *
	 * `data` comes from visitors who are not logged in and is kept in their
	 * session, and later in the order.
	 *
	 * @throws Exception error.merx.cart.data when `data` is larger than `ProductList::$maxDataSize` or nested deeper than 8 levels
	 */
	protected static function validateData(ListItem $listItem): void
	{
		if ($listItem->data === null) {
			return;
		}

		// False when nested too deep, or when it cannot be written at all
		$json = json_encode($listItem->data, depth: 8);

		if ($json === false || strlen($json) > static::$maxDataSize) {
			throw new Exception(
				key: 'merx.cart.data',
				httpCode: 400,
				data: [
					'title' => $listItem->title,
				],
			);
		}
	}

	/**
	 * Makes sure a product is something that can be sold
	 *
	 * A key or page which is no product page, a typo for example, would be
	 * added as a line without title and price, which keeps the whole list from
	 * being ordered. Items of your own are fine as long as they have a price,
	 * and so is every other type, e.g. shipping.
	 *
	 * @throws Exception error.merx.cart.add when the item is a product without product page and without price
	 */
	protected static function validateProduct(ListItem $listItem): void
	{
		if (
			$listItem->type === 'product' &&
			$listItem->page instanceof ProductPage === false &&
			$listItem->price === null
		) {
			throw new Exception(
				key: 'merx.cart.add',
				httpCode: 400,
				data: [
					'key' => $listItem->key,
				],
			);
		}
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
