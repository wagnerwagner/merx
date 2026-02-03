<?php

namespace Wagnerwagner\Merx;

use Kirby\Data\Yaml;
use Kirby\Toolkit\Collection;

/**
 * Collection of ListItem objects.
 * Base for Cart and ProductList.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @extends \Kirby\Cms\Collection<ListItem>
 */
class ListItems extends Collection
{
	/**
	 * Filters items by given type
	 *
	 * See ListItem::$allowedTypes for allowed types.
	 */
	public function filterByType(string $type): ListItems
	{
		return new ListItems(array_filter($this->data, fn (ListItem $listItem) => $listItem->type === $type));
	}

	/**
	 * Quantity of items of given type
	 *
	 * See ListItem::$allowedTypes for allowed types.
	 */
	public function quantity(?string $type = 'product'): float
	{
		$quantity = 0.0;
		$items = $type === null ? $this : $this->filterByType($type);
		foreach ($items as $listItem) {
			/** @var ListItem $listItem */
			$quantity += $listItem->quantity;
		}
		return $quantity;
	}

	/**
	 * Total price of the ListItems
	 */
	public function total(): ?Price
	{
		$price = 0.0;
		$priceNet = 0.0;
		$taxRate = 0.0;
		$currency = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			$listItemTotal = $listItem->total();

			if ($currency !== null && $listItemTotal !== null && $currency !== $listItemTotal?->currency) {
				return null;
			}

			$tax = $listItemTotal?->tax ?? null;

			$price += (float)$listItemTotal?->price;
			$priceNet += (float)$listItemTotal?->priceNet;
			$taxRate += (float)$tax?->rate;
			$currency = $currency ?? $listItemTotal?->currency;
		}

		$tax = new Tax(priceNet: $priceNet, rate: $taxRate, currency: $currency);

		return new Price(
			price: $price,
			tax: $tax,
			currency: $currency,
		);
	}

	/**
	 * Determines if the list contains a ListItem with a null total price.
	 *
	 * A list is from price if it contains a ListItem with a null total price.
	 */
	public function isFromPrice(): bool
	{
		$isFromPrice = false;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			if ($listItem->total() === null) {
				$isFromPrice = true;
			}
		}
		return $isFromPrice;
	}

	/**
	 * Checks whether the list can be ordered.
	 *
	 * A list is orderable if it contains no ListItem with a null total price and the total price is greater than 0.
	 */
	public function isOrderable(): bool
	{
		return $this->isFromPrice() === false && $this->total()->price > 0;
	}

	/**
	 * List of Tax items
	 *
	 * @return Wagnerwagner\Merx\Tax[]	List of `Tax` items sorted by tax rate with the total price for each tax rate.
	 */
	public function taxRates(): array
	{
		/** @var Tax[] $taxRates */
		$taxRates = [];
		/** @var Price[] $prices */
		$prices = array_unique($this->pluck('total'));

		foreach ($prices as $price) {
			$tax = $price?->tax ?? null;
			if ($tax !== null) {
				$rate = (string)$tax->rate;
				if (isset($taxRates[$rate])) {
					$taxRates[$rate]->price += $price->price;
				} else {
					$taxRates[$rate] = $tax;
				}
			}
		}

		uasort($taxRates, fn (Tax $a, Tax $b) => $a->rate < $b->rate);

		return $taxRates;
	}

	/**
	 * Gets the currency of this List
	 *
	 * @return string|bool|null
	 * Three-letter ISO currency code, in uppercase. E.g. EUR or USD.
	 * Returns false if the currencies are mixed.
	 * Returns null if none of the ListItems have a price with a currency.
	 */
	public function currency(): string|bool|null
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
	 * Set a ListItem in the list
	 *
	 * @param string $key
	 * @param ListItem $value
	 */
	public function __set(string $key, $value): void
	{
		$listItem = ListItem::factory($value);
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
