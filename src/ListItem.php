<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Exception\Exception;
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
	/** What a list item can be */
	public static array $allowedTypes = [
		'credit',
		'custom',
		'discount',
		'product',
		'promotion',
		'shipping',
	];

	/** Your own values for the item, e.g. a name to embroider */
	public array|null $data = null;

	/** Identifies the item in its list. The UUID of the page, unless you set one. */
	public string $key;

	/** The product page, or null for an item without one, e.g. shipping */
	public Page|ProductPage|null $page = null;

	/** Price per item, or null for a product without a price */
	public Price|null $price = null;

	/** Multiplier of the price, e.g. the length of a product sold by the meter */
	public float|null $quantifier = null;

	/** How many of the item. E.g. 3.0 */
	public float $quantity = 1.0;

	/** Title of the page, or the one you set for an item without a page */
	public string|null $title = null;

	/** One of `ListItem::$allowedTypes` */
	public string $type = 'product';

	/**
	 * Create a new ListItem
	 *
	 * @param $title Title of an item without a page. The cart reads the title of an item with a page from the page.
	 * @param $page Page object or page id. Without one, the key names the page, unless the item has a price of its own.
	 * @param $price Read from $page when null. An array is a price as `toSessionArray()` stores it.
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
		float|Price|array|null $price = null,
		float $quantity = 1.0,
		float|null $quantifier = null,
		string|null $type = 'product',
		array|null $data = null,
		bool $refreshPrice = true,
	) {
		$this->key = $key;
		$this->quantity = $quantity;
		$this->quantifier = $quantifier;
		$this->data = $data;

		// Without a page, the key names it. Not for an item with a price of its
		// own, e.g. shipping: on a site with a page of that name, the item would
		// lose its price to the page’s.
		$page ??= $price === null ? $key : null;
		$this->page = is_string($page) ? page($page) : $page;

		if (is_array($price)) {
			// The price of the line as the session stored it, quantifier included
			$this->price = new Price(...$price);
		} elseif ($price !== null) {
			$this->price = is_float($price) ? new Price(price: $price) : $price;
			if (is_float($quantifier)) {
				$this->price = $this->price->quantify($quantifier);
			}
		} elseif ($refreshPrice === true) {
			$this->refreshPrice();
		}

		$this->title = $title ?? $this->page?->title()->value();

		$type ??= (string)$this->page?->type();
		if (!in_array($type, static::$allowedTypes)) {
			throw new InvalidArgumentException(
				message: 'Type ' . $type . ' is not allowed. Allowed types: ' . implode(', ', static::$allowedTypes),
			);
		}
		$this->type = $type;
	}

	/**
	 * Convert mixed $data to ListItem
	 */
	public static function factory(string|array|ListItem $data): ListItem
	{
		if (is_string($data)) {
			$data = ['page' => $data];
		}

		if (is_array($data)) {
			if (!array_key_exists('key', $data)) {
				// The uuid of the page is the key, whether the page is named by
				// its id, its uuid or as an object. Otherwise the same product
				// would get one line per spelling.
				$page = $data['page'] ?? null;
				if (is_string($page)) {
					$page = page($page) ?? $page;
				}
				if ($page instanceof Page) {
					$data['page'] = $page;
					$data['key'] = $page->uuid()?->toString() ?? $page->id();
				} else {
					$data['key'] = $page;
				}
			}
			if ($data['key'] === null || $data['key'] === '') {
				// Neither `key` nor `page`: nothing to add
				throw new Exception(
					key: 'merx.cart.add',
					httpCode: 400,
					data: [
						'key' => '',
					],
				);
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
	 * Reads the price from the page’s price definition again, and applies the quantifier to it
	 */
	public function refreshPrice(): self
	{
		if ($this->page instanceof ProductPage) {
			$this->price = $this->page->price(pricingRule: $this->price?->pricingRule, quantity: $this->quantity, data: $this->data);
			if (is_float($this->quantifier)) {
				$this->price = $this->price?->quantify($this->quantifier);
			}
		}
		return $this;
	}

	/**
	 * Used to store ListItem in OrderPage
	 */
	public function toOrderArray(): array
	{
		$array = (array)$this;

		// A page by its UUID, or its id where UUIDs are switched off
		if ($array['page'] instanceof Page) {
			$page = $array['page'];
			$array['page'] = $page->uuid()?->toString() ?? $page->id();
		}

		if ($array['price'] instanceof Price) {
			$price = $array['price'];
			$array['price'] = (float)$price->price;
			$array['pricenet'] = (float)$price->priceNet;
			$array['taxrate'] = $price->tax?->rate;
			$array['currency'] = $price->currency;
			// Whether the customer saw net or gross prices. The pricing rule
			// itself may be gone by the time the order is looked at.
			$array['taxincluded'] = $price->taxIncluded();
		}

		return $array;
	}

	/**
	 * Convert ListItem to session array
	 */
	public function toSessionArray(): array
	{
		$array = (array)$this;

		// Price and title of an item with a page are read from the page with
		// every request, so they follow its content and the current language
		if ($array['page'] instanceof Page) {
			unset($array['price'], $array['title']);
			$array['page'] = (string)$array['page']->uuid();
		} elseif ($array['price'] instanceof Price) {
			// An item without a page, e.g. shipping, has no other source for its
			// price. Kirby reads the session without classes, so a `Price` object
			// would come back as `__PHP_Incomplete_Class`, and its pricing rule
			// holds a closure, which cannot be written at all. Stored as the
			// arguments `Price` is built from instead.
			$price = $array['price'];
			$array['price'] = [
				'price' => $price->toFloat(),
				'tax' => $price->tax?->rate,
				'pricingRule' => $price->pricingRule?->key,
				'currency' => $price->currency,
			];
		}

		return $array;
	}

	/**
	 * Total price
	 * Price per item × quantity
	 */
	public function total(): Price|null
	{
		return $this->price?->quantify($this->quantity);
	}
}
