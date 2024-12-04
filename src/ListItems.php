<?php

namespace Wagnerwagner\Merx;

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
		$currency = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */

			if ($currency !== null && $currency !== $listItem->priceTotal()->currency) {
				throw new Exception(
					message: 'Mixed currencies. Could not calculate total sum with mixed currencies.',
				);
			}

			$price += (float)$listItem->priceTotal()->price;
			$priceNet += (float)$listItem->priceTotalNet()->price;
			$currency = $listItem->priceTotal()->currency;
		}
		return new Price(
			price: $price,
			priceNet: $priceNet,
			currency: $currency,
		);
	}

	public function taxRates(): array
	{
		$taxRates = [];
		$prices = array_unique($this->pluck('price'));
		$currency = null;

		foreach ($prices as $price) {
			/** @var ?Price $price */
			if ($price?->tax !== null) {

				if ($currency !== null && $currency !== $price->currency) {
					throw new Exception(
						message: 'Mixed currencies. Could not calculate total sum with mixed currencies.',
					);
				}
				$currency = $price->currency;

				$rate = (string)$price->tax->rate;
				if (isset($taxRates[$rate])) {
					$price = $taxRates[$rate]->price->price + $price->price;
					$taxRates[$rate]->price = new Price($price);
				} else {
					$taxRates[$rate] = $price->tax;
				}
			}
		}

		uasort($taxRates, fn (Tax $a, Tax $b) => $a->rate < $b->rate);

		return $taxRates;
	}

	/**
	 * @param string $key
	 * @param ListItem $value
	 * @return void
	 */
	public function __set(string $key, $value): void
	{
		if (!($value instanceof ListItem)) {
			throw new InvalidArgumentException(
				message: '$value must be an ListItem. ' . gettype($value) . ' given.',
			);
		}
		parent::__set($key, $value);
	}
}
