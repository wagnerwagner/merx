<?php

namespace Wagnerwagner\Merx;

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
	public function add(
		string|array|ListItem $data
	): static
	{
		$listItem = ListItem::factory($data);

		if ($existingItem = $this->get($listItem->key)) {
			$listItem->quantity += $existingItem->quantity;
		}

		$this->set($listItem->key, $listItem);

		return $this;
	}

	/**
	 * Updates existing item
	 */
	public function updateItem(string $key, array $data): self
	{
		/** @var ListItem $listItem */
		$listItem = $this->get($key);
		foreach ($data as $dataKey => $val) {
			$listItem->$dataKey = $val;
		}

		if ($listItem->quantity === 0.0) {
			$this->remove($key);
		}

		return $this;
	}
}
