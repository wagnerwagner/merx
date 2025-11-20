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
	static $allowedTypes = [
		'credit',
		'custom',
		'discount',
		'product',
		'promotion',
		'shipping',
	];

	public string $key;

	public float $quantity = 1.0;

	public ?float $quantifier = null;

	public ?string $title = null;

	public Page|ProductPage|null $page = null;

	public Price|null $price = null;

	public string $type = 'product';

	public array|null $data = null;

	/**
	 * Create a new ListItem
	 *
	 * @param null|string $title Will be overwritten by $page’s title
	 * @param null|string|Page $page Page object or page slug
	 * @param null|float|Price $price Will be overwritten by $page’s price when $price is float or null
	 * @param float $quantity Quantity of the ListItem. E.g. 3.0 for 3 items.
	 * @param null|float $quantifier Multiplier for the price. Could be used when the item has a price per meter.
	 * @param null|string $type Type of ListItem. See ListItem::$allowedTypes for allowed types.
	 * @param null|array $data Additional data for the ListItem. Could be used to store additional information about the ListItem, e.g. a color variant.
	 * @param bool $priceUpdate Will update prices and tax with recent prices and tax from page when $priceUpdate is true.
	 * @throws InvalidArgumentException when $type is not in ListItem::$allowedTypes.
	 */
	public function __construct(
		string $key,
		null|string $title = null,
		null|string|Page $page = null,
		null|float|Price $price = null,
		float $quantity = 1.0,
		null|float $quantifier = null,
		null|string $type = 'product',
		array|null $data = null,
		bool $priceUpdate = true,
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
			if ($this->page instanceof ProductPage && $priceUpdate === true) {
				$this->price = $this->page->price();
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
				$this->title = (string) $this->page->title();
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
		$type = $type ?? (string) $this->page?->type();
		if (is_string($type)) {
			if (!in_array($type, static::$allowedTypes)) {
				throw new InvalidArgumentException(
					message: 'Type ' . $type . ' is not allowed. Allowed types: ' . implode(static::$allowedTypes),
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
	static function factory(string|array|ListItem $data): ListItem
	{
		if (is_string($data)) {
			$listItem = new ListItem(key: $data);
		} elseif (is_array($data)) {
			$listItem = new ListItem(...$data);
		} elseif ($data instanceof ListItem) {
			$listItem = $data;
		}

		return $listItem;
	}

	/**
	 * Total price of the ListItem
	 */
	public function total(): ?Price
	{
		$priceSingle = $this->price?->price;

		if (!is_float($priceSingle)) {
			return null;
		}

		$tax = $this->price?->tax ?? null;

		return new Price(
			price: $priceSingle * $this->quantity,
			tax: $tax?->toFloat(),
			currency: $this->price?->currency,
		);
	}

	/**
	 * Convert ListItem to session array
	 */
	public function toSessionArray(): array
	{
		$array = (array) $this;

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
			$array['taxrate'] = (float)$price->tax?->rate;
			$array['currency'] = (string)$price->currency;
		}

		return $array;
	}
}
