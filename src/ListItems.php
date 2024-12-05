<?php

namespace Wagnerwagner\Merx;

use Kirby\Data\Yaml;
use Kirby\Exception\Exception;
use Kirby\Exception\InvalidArgumentException;
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
	 * @throws Exception
	 */
	public function total(): Price
	{
		$price = 0.0;
		$priceNet = 0.0;
		$taxPrice = 0.0;
		$currency = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */

			if ($currency !== null && $currency !== $listItem->total()->currency) {
				throw new Exception(
					message: 'Mixed currencies. Could not calculate total sum with mixed currencies.',
				);
			}

			$tax = $listItem->total()?->tax ?? null;

			$price += (float)$listItem->total()?->price;
			$priceNet += (float)$listItem->total()?->priceNet;
			$taxPrice += (float)$tax?->price->toFloat();
			$currency = $listItem->total()?->currency;
		}

		$tax = new Tax(priceNet: $taxPrice);

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
		$taxRates = [];
		$prices = array_unique($this->pluck('total'));

		foreach ($prices as $price) {
			/** @var ?Price $price */
			$tax = $price?->tax ?? null;
			if ($tax !== null) {
				$rate = (string)$tax->rate;
				if (isset($taxRates[$rate])) {
					$price = $taxRates[$rate]->price->price + $price->price;
					$taxRates[$rate]->price = new Price($price);
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
	 */
	public function currency(): ?string
	{
		$currency = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			$price = $listItem->price;
			if (is_string($currency) && $currency !== $price->currency) {
				throw new Exception(
					message: 'Mixed currencies in ListItems. ' . $currency . ' and ' . $price->currency,
				);
			}
			$currency = $price->currency;
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

		// Check currencies
		if (is_string($this->currency()) && $listItem->price->currency !== $this->currency()) {
			throw new Exception(
				fallback: 'Mixed currencies. Could not add item “{ key }” with currency “{currency}”',
				data: [
					'key' => $listItem->key,
					'currency' => $listItem->price?->currency,
				],
			);
		}

		parent::__set($key, $listItem);
	}

	/**
	 * Used to store Cart etc. in OrderPage
	 */
	public function toYaml(): string
	{
		return Yaml::encode($this->toArray(function (ListItem $listItem): array {
			return $listItem->toOrderArray();
		}));
	}
}
