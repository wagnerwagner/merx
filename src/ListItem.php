<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\Obj;

/**
 * Represents a single item in a Cart, ProductList or ListItems
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class ListItem extends Obj
{
	public static $allowedTypes = [
		'credit',
		'custom',
		'discount',
		'product',
		'promotion',
		'shipping',
	];

	public array|null $data = null;
	public string $key;
	public Page|ProductPage|null $page = null;
	public Price|null $price = null;
	public float|null $quantifier = null;
	public float $quantity = 1.0;
	public string|null $title = null;
	public string $type = 'product';

	/**
	 * Create a new ListItem
	 *
	 * @param $title Will be overwritten by $page’s title
	 * @param $page Page object or page slug
	 * @param $price Will be overwritten by $page’s price when $price is float or null
	 * @param $quantity Quantity of the ListItem. E.g. 3.0 for 3 items.
	 * @param $quantifier Multiplier for the price. Could be used when the item has a price per meter.
	 * @param $type Type of ListItem. See ListItem::$allowedTypes for allowed types.
	 * @param $data Additional data for the ListItem. Could be used to store additional information about the ListItem, e.g. a color variant.
	 * @param $refreshPrice Will update prices and tax with recent prices and tax from page when $refreshPrice is true.
	 * @throws InvalidArgumentException when $type is not in ListItem::$allowedTypes.
	 */
	public function __construct(
		string $key,
		string|null $title = null,
		string|Page|null $page = null,
		float|Price|null $price = null,
		float $quantity = 1.0,
		float|null $quantifier = null,
		string|null $type = 'product',
		array|null $data = null,
		bool $refreshPrice = true,
	) {
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
		} elseif (is_float($price)) {
			$this->price = new Price(price: $price);
		} else {
			// Update price by page’s definition
			if ($this->page instanceof ProductPage && $refreshPrice === true) {
				$this->price = $this->page->price(quantity: $quantity);
			}
		}

		if (is_float($quantifier)) {
			$price = $this->price;
			$this->price = $price->quantify($quantifier);
		}

		// Set title from page
		if ($this->title === null && $this->page instanceof Page) {
			if (is_string($this->page->title())) {
				$this->title = $this->page->title();
			} elseif (
				$this->page->title() instanceof Field &&
				$this->page->title()->isNotEmpty()
			) {
				$this->title = (string)$this->page->title();
			}
		}

		// Set max amount to data from page
		if ($this->page instanceof Page) {
			if (is_float($this->page->maxQuantity())) {
				$data = array_merge($data ?? [], [
					'maxQuantity' => $this->page->maxQuantity(),
				]);
			}
		}

		// Set type
		$type ??= (string)$this->page?->type();
		if (is_string($type)) {
			if (!in_array($type, static::$allowedTypes)) {
				throw new InvalidArgumentException(
					message: 'Type ' . $type . ' is not allowed. Allowed types: ' . implode(', ', static::$allowedTypes),
				);
			}
			$this->type = $type;
		}

		$this->quantity = $quantity;
		$this->quantifier = $quantifier;
		$this->data = $data;
	}

	/**
	 * Convert mixed $data to ListItem
	 */
	public static function factory(string|array|ListItem $data): ListItem
	{
		if (is_string($data)) {
			$listItem = new ListItem(key: $data);
		} elseif (is_array($data)) {
			if (!array_key_exists('key', $data)) {
				// Use page id or uuid as key
				$page = $data['page'] ?? null;
				$data['key'] = ($page instanceof Page) ? $page->uuid() : $page;
			}
			$listItem = new ListItem(...$data);
		} elseif ($data instanceof ListItem) {
			$listItem = $data;
		}

		return $listItem;
	}

	/**
	 * Price per item
	 */
	public function price(): Price|null
	{
		return $this->price;
	}

	/**
	 * Used to store ListItem in OrderPage
	 */
	public function toOrderArray(): array
	{
		$array = (array)$this;

		// remove price definition, when page is present
		if ($array['page'] instanceof Page) {
			$page = $array['page'];
			$array['page'] = (string)$page->uuid() ?? $page->id();
		}

		if ($array['price'] instanceof Price) {
			/** @var Price $price */
			$price = $array['price'];
			$array['price'] = (float)$price->price;
			$array['pricenet'] = (float)$price->priceNet;
			$array['taxrate'] = (float)$price->tax?->rate;
			$array['currency'] = (string)$price->currency;
		}

		return $array;
	}

	/**
	 * Convert ListItem to session array
	 */
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
	 * Total price
	 * Price per item × quantity
	 */
	public function total(): Price|null
	{
		// A price without a pricing rule includes its tax, the same way `Price`
		// itself treats it. Without the fallback the total would be built from the
		// net price while the item’s own price is a gross one.
		$taxIncluded = $this->price?->pricingRule?->taxIncluded ?? true;
		$pricePerItem = $taxIncluded ? $this->price?->price : $this->price?->priceNet;

		if (!is_float($pricePerItem)) {
			return null;
		}

		$tax = $this->price?->tax ?? null;

		return new Price(
			price: $pricePerItem * $this->quantity,
			tax: $tax?->toFloat(),
			currency: $this->price?->currency,
			pricingRule: $this->price?->pricingRule,
		);
	}

	/**
	 * Reads the price from the page’s price definition again, and applies the quantifier to it
	 */
	public function refreshPrice(): self
	{
		if ($this->page instanceof ProductPage) {
			$this->price = $this->page->price(pricingRule: $this->price->pricingRule, quantity: $this->quantity);
			if (is_float($this->quantifier)) {
				$this->price = $this->price?->quantify($this->quantifier);
			}
		}
		return $this;
	}
}
