<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Form\Form;
use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductList;

/**
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
abstract class OrderPage extends Page
{
	/**
	 * Returns all content validation errors
	 *
	 * This is required since otherwise errors won’t show for virtual pages.
	 *
	 * @return array
	 */
	public function errors(): array
	{
		$kirby = $this->kirby();
		if ($kirby->multilang() === true) {
			I18n::$locale = $kirby->language()->code();
		}

		$fields = array_change_key_case($this->blueprint()->fields());
		// add model to each field
		$fields = array_map(function ($field) {
			$field['model'] = $this;
			return $field;
		}, $fields);

		$form = new Form([
			'fields' => $fields,
			'values' => $this->content()->toArray(),
		]);
		return $form->errors();
	}

	/**
	 * Returns invoiceNumber
	 */
	public function title(): Field
	{
		return new Field($this, 'title', $this->invoiceNumber());
	}

	/**
	 * Cart of this Order.
	 */
	public function cart(): ProductList
	{
		$data = $this->items()->yaml();
		$data = array_map(function (mixed $item) {
			$page = is_string($item['page']) ? $item['page'] : $item['page'][0] ?? null;
			return new ListItem(
				key: $item['key'],
				title: $item['title'] ?? null,
				page: $page,
				price: $item['price'] ?? null,
				tax: $item['taxrate'] ?? null,
				currency: $item['currency'] ?? null,
				quantity: $item['quantity'] ?? 1.0,
				quantifier: $item['quantifier'] ?? null,
				type: $item['type'] ?? null,
				data: $item['data'] ?? null,
				priceUpdate: false,
			);
		}, $data);

		return new ProductList($data);
	}

	/**
	 * Page uuids of products in this order
	 *
	 * @return string[]
	 */
	public function productUuids(): array
	{
		return array_map(fn (?Page $page) => (string)$page?->uuid(), $this->cart()->pluck('page'));
	}

	public function total(): ?Price
	{
		return $this->cart()->total();
	}
}
