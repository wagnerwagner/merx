<?php

namespace Wagnerwagner\Merx;

use Kirby\Data\Yaml;
use Kirby\Exception\Exception;
use Kirby\Toolkit\Collection;

/**
 * Collection of ListItem
 */
class ListItems extends Collection
{
	public function filterByType(string $type): ListItems
	{
		return new ListItems(array_filter($this->data, fn (ListItem $listItem) => $listItem->type === $type));
	}

	public function quantity(string $type = 'product'): float
	{
		$quantity = 0.0;
		foreach ($this->filterByType($type) as $listItem) {
			/** @var ListItem $listItem */
			$quantity += $listItem->quantity;
		}
		return $quantity;
	}

	/**
	 * @throws Exception When items do have different currencies
	 */
	public function total(): ?Price
	{
		$price = 0.0;
		$priceNet = 0.0;
		$taxPrice = 0.0;
		$currency = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			$listItemTotal = $listItem->total();

			if ($currency !== null && $listItemTotal !== null && $currency !== $listItemTotal?->currency) {
				return null;
				// throw new Exception(
				// 	key: 'merx.mixedCurrencies.total',
				// 	data: [
				// 		'key' => $listItem->key,
				// 		'currency' => $currency,
				// 		'newCurrency' => $listItemTotal?->currency,
				// 	],
				// );
			}

			$tax = $listItemTotal?->tax ?? null;

			$price += (float)$listItemTotal?->price;
			$priceNet += (float)$listItemTotal?->priceNet;
			$taxPrice += (float)$tax?->price->toFloat();
			$currency = $listItemTotal?->currency;
		}

		$tax = new Tax(priceNet: $taxPrice, currency: $currency);

		return new Price(
			price: $price,
			priceNet: $priceNet,
			currency: $currency,
			tax: $tax,
		);
	}

	/**
	 *
	 * @return Tax[]  Array of Tax items
	 */
	public function taxRates(): array
	{
		/** @var Tax[] $taxRates */
		$taxRates = [];
		/** @var Prices[] $prices */
		$prices = array_unique($this->pluck('total'));

		foreach ($prices as $price) {
			/** @var ?Price $price */
			$tax = $price?->tax ?? null;
			if ($tax !== null) {
				$rate = (string)$tax->rate;
				if (isset($taxRates[$rate])) {
					$taxRates[$rate]->price->price += $price->price;
				} else {
					$taxRates[$rate] = $tax;
				}
			}
		}

		uasort($taxRates, fn (Tax $a, Tax $b) => $a->rate < $b->rate);

		return $taxRates;
	}

	/**
	 * Get the currency of this List
	 *
	 * @return string|null Three-letter ISO currency code, in uppercase
	 * @throws Exception When items do have different currencies
	 */
	public function currency(): null|false|string
	{
		$currency = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			$price = $listItem->price;
			if (is_string($currency) && $currency !== $price?->currency) {
				return false;
			}
			$currency = $price?->currency;
		}
		return $currency;
	}

	/**
	 * @param string $key
	 * @param ListItem $value
	 * @return void
	 */
	public function __set(string $key, $value): void
	{
		$listItem = ListItem::dataToListItem($value);
		parent::__set($key, $listItem);
	}

	/**
	 * Used to store Cart in OrderPage
	 */
	public function toYaml(): string
	{
		return Yaml::encode($this->toArray(function (ListItem $listItem): array {
			return $listItem->toOrderArray();
		}));
	}
}
