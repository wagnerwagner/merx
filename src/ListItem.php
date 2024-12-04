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

	public ?string $id = null;

	public ?string $title = null;

	public Page|null $page = null;

	public Price|null $price = null;

	public string $type = 'product';

	public array|null $data = null;

	/**
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		string $key,
		?string $id = null,
		?string $title = null,
		null|string|Page $page = null,
		null|float|Price $price = null,
		?float $priceNet = null,
		null|float|Tax $taxRate = null,
		?string $currency = null,
		float $quantity = 1.0,
		?string $type = 'product',
		array|null $data = null,
	)
	{
		$this->key = $key;
		$this->id = $id ?? $key;
		$this->title = $title;

		// Set page
		if ($page instanceof Page) {
			$this->page = $page;
		} elseif (is_string($page)) {
			$this->page = page($page);
		} else {
			$this->page = page($this->id);
		}

		// Set price
		if ($price instanceof Price) {
			$this->price = $price;
		} elseif (is_float($price) || is_float($priceNet) || $this->page instanceof Page) {
			if (!is_float($price)) {
				if ($this->page instanceof Page) {
					if (
						is_numeric($this->page->price())
					) {
						$price = (float)$this->page->price();
					} else if (
						$this->page->price() instanceof Field &&
						$this->page->price()->isNotEmpty()
					) {
						$price = $this->page->price()->toFloat();
					}
				}
			}
			if (!is_float($priceNet)) {
				if ($this->page instanceof Page) {
					if (
						is_numeric($this->page->priceNet())
					) {
						$priceNet = (float)$this->page->priceNet();
					} else if (
						$this->page->priceNet() instanceof Field &&
						$this->page->priceNet()->isNotEmpty()
					) {
						$priceNet = $this->page->priceNet()->toFloat();
					}
				}
			}
			if (!is_float($taxRate) && !($taxRate instanceof Tax)) {
				if ($this->page instanceof Page) {
					if (
						is_numeric($this->page->taxRate())
					) {
						$taxRate = (float)$this->page->taxRate();
					} else if (
						$this->page->taxRate() instanceof Field &&
						$this->page->taxRate()->isNotEmpty()
					) {
						$taxRate = $this->page->taxRate()->toFloat();
					}
				}
			}
			$this->price = new Price(
				price: $price,
				priceNet: $priceNet,
				taxRate: $taxRate,
				currency: $currency,
			);
		}

		// Set title from page
		if (!is_string($title)) {
			if ($this->page instanceof Page) {
				if (
					is_string($this->page->title())
				) {
					$this->title = $this->page->title();
				} else if (
					$this->page->title() instanceof Field &&
					$this->page->title()->isNotEmpty()
				) {
					$this->title = (string)$this->page->title();
				}
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

	public function priceTotal(): ?Price
	{
		$priceSingle = $this->price?->toFloat();

		if (!is_float($priceSingle)) {
			return null;
		}

		return new Price(
		  price: $priceSingle * $this->quantity,
		  taxRate: $this->price?->tax?->toFloat(),
			currency: $this->price?->currency,
		);
	}

	public function priceTotalNet(): ?Price
	{
		$priceSingle = $this->price->priceNet;

		if (!is_float($priceSingle)) {
			return null;
		}

		return new Price(
		  price: $priceSingle * $this->quantity,
		  taxRate: $this->price?->tax?->toFloat(),
			currency: $this->price?->currency,
		);
	}
}
