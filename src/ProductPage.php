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
 * @method \Kirby\Content\Field taxRule() Content field: Key of the tax rule that applies to this product
 *
 * @see https://merx.wagnerwagner.de/guide/getting-started/adding-products
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class ProductPage extends Page
{
	/** Orders including this product */
	protected Pages $orders;

	/**
	 * Max amount that could be added to ListItems
	 */
	public function maxQuantity(): float|null
	{
		$maxQuantity = $this->content()->get('maxQuantity')->or($this->site()->maxQuantity());
		return $maxQuantity->isEmpty() ? null : $maxQuantity->toFloat();
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
		$quantity = 0;
		$count = $this->orders()->count();
		foreach ($this->orders() as $order) {
			$quantity += $order->quantity()->toFloat();
		}
		$quantityPerOrder = round($quantity / $count, 1);
		return tt('section.orders.info', null, compact('quantity', 'count', 'quantityPerOrder'));
	}

	/**
	 * Orders including this product with enriched cart information
	 *
	 * @return Pages<OrderPage>
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
	 * Price for a pricing rule
	 *
	 * A price belongs to exactly one pricing rule. A product without a price
	 * for the rule returns null, not the price of another rule.
	 *
	 * @param $pricingRule Pricing rule to use, or its key as string, or null for the pricing rule that applies to the current visitor
	 * @param $quantity Quantity used for volume-based pricing
	 * @return ?Price Price object, or null if no price is found
	 */
	public function price(string|PricingRule|null $pricingRule = null, float|null $quantity = null): Price|null
	{
		if (is_string($pricingRule)) {
			$pricingRule = Merx::pricingRules()->getRuleByKey($pricingRule);
		}
		$pricingRule ??= Merx::pricingRule();

		if ($pricingRule === null) {
			return $this->prices()->first();
		}

		return $this->prices()->findBy('pricingRule', $pricingRule);
	}

	/**
	 * Returns a collection of Price objects created from the price field
	 *
	 * The field holds one price per pricing rule. A plain number, as in
	 * `Price: 45`, is the price of the `default` pricing rule.
	 *
	 * @return Structure<Price>
	 */
	public function prices(): Structure
	{
		$field = $this->content()->get('price');
		$rows = is_numeric($field->value())
			? [['price' => $field->value(), 'pricingRule' => 'default']]
			: $field->yaml();

		$pricingRules = Merx::pricingRules();
		$taxRule = Merx::taxRule($this->taxRule()->value());

		return Structure::factory($rows, ['parent' => $this])
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
	public function tax(): Tax|null
	{
		return $this->price()?->tax();
	}
}
