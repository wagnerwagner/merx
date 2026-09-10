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
 * @extends \Wagnerwagner\Merx\ListItems<Wagnerwagner\Merx\ListItem>
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
	 * @see \Wagnerwagner\Merx\ListItem
	 */
	public function add(
		string|array|ListItem $data
	): static
	{
		$listItem = ListItem::factory($data);

		if ($existingItem = $this->get($listItem->key)) {
			$listItem->quantity += $existingItem->quantity;
		}

		static::validateQuantity($listItem);

		$this->set($listItem->key, $listItem);

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
				data: [
					'key' => $key,
				],
			);
		}

		// `maxQuantity` is derived from the item’s page and must survive a `data` update.
		$maxQuantity = $listItem->data['maxQuantity'] ?? null;

		// Work on a copy, so a rejected update cannot leave the item behind with a
		// quantity which never passed validation.
		$updatedItem = clone $listItem;

		foreach ($data as $dataKey => $val) {
			$updatedItem->$dataKey = $val;
		}

		if (array_key_exists('data', $data) && $maxQuantity !== null) {
			$updatedItem->data = array_merge($updatedItem->data ?? [], [
				'maxQuantity' => $maxQuantity,
			]);
		}

		// Update the price according to the quantity. Required for volume-based pricing.
		if (!array_key_exists('price', $data) && $updatedItem->page instanceof ProductPage) {
			$updatedItem->updatePrice();
		}

		static::validateQuantity($updatedItem);

		if ($updatedItem->quantity === 0.0) {
			$this->remove($key);

			return $this;
		}

		$this->set($key, $updatedItem);

		return $this;
	}

	/**
	 * Makes sure the item’s quantity is a usable, non-negative number which does
	 * not exceed the `maxQuantity` defined by the item’s page.
	 *
	 * @throws Exception error.merx.cart.quantity when the quantity is negative or not a finite number
	 * @throws Exception error.merx.cart.maxQuantity when the quantity exceeds the item’s `maxQuantity`
	 */
	protected static function validateQuantity(ListItem $listItem): void
	{
		if (is_finite($listItem->quantity) === false || $listItem->quantity < 0) {
			throw new Exception(
				key: 'merx.cart.quantity',
				data: [
					'title' => $listItem->title,
				],
			);
		}

		$maxQuantity = $listItem->data['maxQuantity'] ?? null;
		if (is_float($maxQuantity) && $listItem->quantity > $maxQuantity) {
			throw new Exception(
				key: 'merx.cart.maxQuantity',
				data: [
					'title' => $listItem->title,
					'maxQuantity' => $maxQuantity,
				],
			);
		}
	}
}
