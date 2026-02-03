<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Structure;

/**
 * Base for own product pages
 *
 * Used in Cart, ProductList and ListItems.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @method \Kirby\Content\Field prices()
 * @method \Kirby\Content\Field taxRule()
 * @method \Kirby\Content\Field stock()
 */
class ProductPage extends Page
{
	/** Orders including this product */
	protected Pages $orders;

	/** Orders including this product */
	protected Structure $prices;

	/**
	 * Converts price content field to Price class
	 */
	public function price(null|string|PricingRule $pricingRule = null): ?Price
	{
		if (is_string($pricingRule)) {
			$pricingRule = Merx::pricingRules()->getRuleByKey($pricingRule);
		} else if ($pricingRule instanceof PricingRule) {
			$pricingRule = $pricingRule;
		}
		$pricingRule = $pricingRule ?? Merx::pricingRule();

		if ($pricingRule === null) {
			return $this->prices()->first();
		}

		if ($this->prices()->count() > 0) {
			return $this->prices()->findBy('pricingRule', $pricingRule);
		}

		// Fallback to price field
		if ($this->content()->get('price')->exists()) {
			$taxRule = Merx::taxRule($this->taxRule()->value());
			return new Price(
				price: $this->content()->get('price')->toFloat(),
				pricingRule: Merx::pricingRules()->getRuleByKey('default'),
				tax: $taxRule?->taxRate(),
			);
		}

		return null;
	}

	/**
	 * Returns a collection of Price objects created from the prices field
	 *
	 * @return \Kirby\Cms\Structure<\Wagnerwagner\Merx\Price>
	 */
	public function prices(): Structure
	{
		$pricingRules = Merx::pricingRules();
		$taxRule = Merx::taxRule($this->taxRule()->value());
		/** @var \Kirby\Cms\Structure $prices */
		$prices = $this->content()->prices()->toStructure();
		return $this->prices ?? $prices
			->filter(fn ($item) => $item->price()->isNotEmpty())
			->map(function ($item) use ($pricingRules, $taxRule) {
				$pricingRule = $pricingRules->getRuleByKey($item->pricingRule()->value());
				return new Price(
					price: $item->price()->toFloat(),
					pricingRule: $pricingRule,
					tax: $taxRule?->taxRate(),
				);
			});
	}

	/**
	 * Returns the Tax object associated with this product.
	 */
	public function tax(): ?Tax
	{
		return $this->price()?->tax();
	}

	/**
	 * Orders including this product with enriched cart information
	 *
	 * @return \Kirby\Cms\Pages<\Wagnerwagner\Merx\OrderPage>
	 */
	public function orders(): Pages
	{
			$ordersPage = $this->site()->ordersPage();
			return $this->orders ?? $this->orders = $ordersPage->children()->filter(function (OrderPage $page) {
					return in_array((string)$this->uuid(), $page->productUuids());
			})->map(function (OrderPage $page) {
					/** @var ?ListItem $listItem */
					$listItem = $page->cart()->filter(function (ListItem $listItem) {
							return (string)$listItem->page?->uuid() === (string)$this->uuid();
					})->first();
					$page->content()->update([
							'quantity' => $listItem?->quantity,
							'price' => $listItem?->price->toString(),
							'productTotal' => $listItem?->total()->toString(),
					]);
					return $page;
			});
	}

	/**
	 * Summary info string about the orders including this product
	 * Used for the orders tab in the product page
	 *
	 * @see /blueprints/tabs/orders.yml
	 * @return string Localized info text
	 */
	public function orderInfo(): string
	{
			$amount = 0;
			$count = $this->orders()->count();
			foreach ($this->orders() as $order) {
					$amount += $order->quantity()->toFloat();
			}
			$amountPerOrder = round($amount / $count, 1);
			return tt('section.orders.info', null, compact('amount', 'count', 'amountPerOrder'));
	}


	/**
	 * Max amount that could be added to ListItems
	 *
	 * @return null|float
	 */
	public function maxAmount(): ?float
	{
		return null;
	}
}
