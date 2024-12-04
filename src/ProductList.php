<?php

namespace Wagnerwagner\Merx;

class ProductList extends ListItems
{
	public function add(
		string|array|ListItem $data
	): static
	{
		if (is_string($data)) {
			$listItem = new ListItem(
				key: $data,
				id: $data,
			);
		} else if (is_array($data)) {
			$key = $data['key'] ?? $data['id'] ?? null;
			$id = $data['id'] ?? null;
			$listItem = new ListItem(
				key: $key,
				id: $id ?? null,
				title: $data['title'] ?? null,
				page: $data['page'] ?? null,
				price: $data['price'] ?? null,
				priceNet: $data['priceNet'] ?? null,
				taxRate: $data['taxRate'] ?? null,
				currency: $data['currency'] ?? null,
				type: $data['type'] ?? null,
				data: $data['data'] ?? null,
			);
		} else if (($data instanceof ListItem)) {
			$listItem = $data;
		}

		if ($existingItem = $this->get($listItem->key)) {
			$listItem->quantity += $existingItem->quantity;
		}

		$this->set($listItem->key, $listItem);

		return $this;
	}
}
