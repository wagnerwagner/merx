<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\Obj;

class ListItem extends Obj
{
	static $allowedTypes = ['product', 'credit', 'custom', 'promotion', 'discount', 'shipping'];

	public string $key;

	public float $quantity = 1.0;

	public ?string $title = null;

	public ProductPage|null $page = null;

	public Price|null $price = null;

	public string $type = 'product';

	public array|null $data = null;

	/**
	 * @param null|float|Price $price Will be overwritten by $page’s price when $price is float or null
	 * @param null|float $priceNet Will be overwritten by $page’s priceNet (if not empty)
	 * @param null|string $title Will be overwritten by $page’s title
	 * @param null|float|Tax $tax Will be overwritten by $page’s taxRate when $tax is float or null
	 * @param bool $priceUpdate Will update prices and tax with recent prices and tax from page
	 *
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		string $key,
		null|string $title = null,
		null|string|Page $page = null,
		null|float|Price $price = null,
		null|float $priceNet = null,
		null|float|Tax $tax = null,
		null|string $currency = null,
		float $quantity = 1.0,
		null|string $type = 'product',
		array|null $data = null,
		bool $priceUpdate = true,
	)
	{
		$this->key = $key;
		$this->title = $title;

		// Set page
		if ($page instanceof Page) {
			$this->page = $page;
		} elseif (is_string($page)) {
			$this->page = page($page);
		} else {
			$this->page = page($this->key);
		}

		// Set price
		if ($price instanceof Price) {
			$this->price = $price;
		} else {
			// Update price by page’s definition
			if ($this->page instanceof ProductPage && $priceUpdate === true) {
				$this->price = $this->page->price($currency);

				if (!($tax instanceof Tax)) {
					if (is_numeric($this->page->taxRate())) {
						$tax = (float)$this->page->taxRate();
					} else if (
						$this->page->taxRate() instanceof Field &&
						$this->page->taxRate()->isNotEmpty()
					) {
						$tax = $this->page->taxRate()->toFloat();
					}
				}
			}

			$currency = $currency ?? Merx::currentCurrency();

			if (is_float($price) || is_float($priceNet)) {
				$this->price = new Price(
					price: $price,
					priceNet: $priceNet,
					tax: $tax,
					currency: $currency,
				);
			}
		}

		// Set title from page
		if ($this->title === null && $this->page instanceof Page) {
			if (is_string($this->page->title())) {
				$this->title = $this->page->title();
			} else if (
				$this->page->title() instanceof Field &&
				$this->page->title()->isNotEmpty()
			) {
				$this->title = (string)$this->page->title();
			}
		}

		// Set max amount to data from page
		if ($this->page instanceof Page) {
			if (is_float($this->page->maxAmount())) {
				$data = array_merge($data ?? [], [
					'maxAmount' => $this->page->maxAmount(),
				]);
			}
		}

		// Set type
		if (is_string($type)) {
			if (!in_array($type, static::$allowedTypes)) {
				throw new InvalidArgumentException(
					message: 'Type ' . $type . ' is not allowed. Allowed types: ' . implode(static::$allowedTypes),
				);
			}
			$this->type = $type;
		}

		$this->quantity = $quantity;
		$this->data = $data;
	}

	/**
	 * Convert mixed $data to ListItem
	 */
	static function dataToListItem(string|array|ListItem $data): ListItem
	{
		if (is_string($data)) {
			$listItem = new ListItem(
				key: $data,
			);
		} else if (is_array($data)) {
			$listItem = new ListItem(...$data);
		} else if ($data instanceof ListItem) {
			$listItem = $data;
		}

		return $listItem;
	}

	public function total(): ?Price
	{
		$priceSingle = $this->price?->price;
		$priceSingleNet = $this->price?->priceNet;

		if (!is_float($priceSingle)) {
			return null;
		}

		$tax = $this->price?->tax ?? null;

		return new Price(
			price: $priceSingle * $this->quantity,
			priceNet: $priceSingleNet * $this->quantity,
			tax: $tax?->toFloat(),
			currency: $this->price?->currency,
		);
	}

	public function toSessionArray(): array
	{
		$array = (array)$this;

		// remove price definition, when page is present
		if ($array['page'] instanceof Page) {
			unset($array['price']);
			$array['page'] = (string)$array['page']->uuid();
		}

		return $array;
	}

	/**
	 * Used to store ListItem in OrderPage
	 */
	public function toOrderArray(): array
	{
		$array = (array)$this;

		// remove price definition, when page is present
		if ($array['page'] instanceof Page) {
			/** @var Page $page */
			$page = $array['page'];
			$array['page'] = (string)$page->uuid() ?? $page->id();
		}

		if ($array['price'] instanceof Price) {
			/** @var Price $price */
			$price = $array['price'];
			$array['price'] = (float)$price->price;
			$array['pricenet'] = (float)$price->priceNet;
			$array['taxrate'] = (float)$price->tax->rate;
			$array['currency'] = (string)$price->currency;
		}

		return $array;
	}
}
