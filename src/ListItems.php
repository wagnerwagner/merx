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
	 *
	 * The tax of the total keeps the tax rate the items share. Items with
	 * different tax rates have no rate in common: `tax->rate` is `null` then and
	 * `tax->price` the sum of their tax amounts. Use `taxRates()` for the
	 * breakdown of such a list.
	 */
	public function total(): ?Price
	{
		$price = 0.0;
		$priceNet = 0.0;
		$taxPrice = 0.0;
		$taxRate = null;
		$mixedTaxRates = false;
		$currency = null;
		$pricingRule = null;
		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			$listItemTotal = $listItem->total();

			if ($currency !== null && $listItemTotal !== null && $currency !== $listItemTotal?->currency) {
				return null;
			}

			$tax = $listItemTotal?->tax ?? null;
			// An item without a tax is an item taxed at 0 %. A list of taxed and
			// untaxed items does not share a rate either.
			$listItemTaxRate = (float)$tax?->rate;

			$price += (float)$listItemTotal?->price;
			$priceNet += (float)$listItemTotal?->priceNet;
			$taxPrice += (float)$tax?->price;
			$mixedTaxRates = $mixedTaxRates || ($taxRate !== null && $taxRate !== $listItemTaxRate);
			$taxRate ??= $listItemTaxRate;
			$currency = $currency ?? $listItemTotal?->currency;
			$pricingRule = $pricingRule ?? $listItemTotal?->pricingRule;
		}

		// A shared rate is handed to `Price` as a rate, which derives the net price
		// from it, the same way a single item’s price is built. Mixed rates are
		// handed over as the sum of the items’ tax amounts instead.
		$tax = $mixedTaxRates === true
			? new Tax(priceNet: $priceNet, rate: null, currency: $currency, price: $taxPrice)
			: $taxRate ?? 0.0;

		return new Price(
			// As in `ListItem::total()`, a list without a pricing rule adds up the
			// gross prices, which is what `Price` expects to be handed.
			price: ($pricingRule?->taxIncluded ?? true) ? $price : $priceNet,
			tax: $tax,
			currency: $currency,
			pricingRule: $pricingRule,
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
	 * @return Wagnerwagner\Merx\Tax[]	List of `Tax` items sorted by tax rate, lowest first, with the total tax amount for each tax rate.
	 */
	public function taxRates(): array
	{
		/** @var Tax[] $taxRates */
		$taxRates = [];

		foreach ($this as $listItem) {
			/** @var ListItem $listItem */
			$tax = $listItem->total()?->tax ?? null;
			if ($tax === null || $tax->rate === null) {
				continue;
			}

			$rate = (string)$tax->rate;
			if (isset($taxRates[$rate])) {
				// `ListItem::total()` builds its `Tax` on every call, so the tax
				// added up here is never an item’s own one.
				$taxRates[$rate]->price = round($taxRates[$rate]->price + $tax->price, Price::roundingPrecision);
			} else {
				$taxRates[$rate] = $tax;
			}
		}

		uasort($taxRates, fn (Tax $a, Tax $b) => $a->rate <=> $b->rate);

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
