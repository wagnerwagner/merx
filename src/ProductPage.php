<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Structure;

class ProductPage extends Page
{
	/** Orders including this product */
	protected Pages $orders;

	/** Orders including this product */
	protected Structure $prices;

	/**
	 * Converts price content field to Price class
	 */
	public function price(?string $currency = null): ?Price
	{
		$currency ??= Merx::currentCurrency();

		if ($this->prices()->count() > 0) {
			return $this->prices()->findBy('currency', $currency);
		}

		if ($currency === option('ww.merx.currency.default')) {
			return new Price(price: $this->content()->price()->toFloat(), currency: $currency);
		}

		return null;
	}

	/**
	 * Converts prices content field to a Structure of Price objects
	 * 
	 * @return \Kirby\Cms\Structure<Price> A Structure collection containing Price objects
	 */
	public function prices(): Structure
	{
		/** @var \Kirby\Cms\Structure $prices */
		$prices = $this->content()->prices()->toStructure();
		return $this->prices ?? $prices->map(fn ($item) => new Price(
			price: $item->price()->toFloat(),
			currency: $item->currency()->value(),
		));
	}

	public function orders(): Pages
	{
			/** @var Page $ordersPage */
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
}
