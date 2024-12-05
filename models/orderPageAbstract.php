<?php

use Kirby\Cms\Page;
use Kirby\Form\Form;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\ProductList;

abstract class OrderPageAbstract extends Page
{
	/**
	 * Returns all content validation errors
	 * This is required since otherwise errors won’t show for virtual pages.
	 *
	 * @return array
	 */
	public function errors(): array
	{
		$kirby = $this->kirby();
		if ($kirby->multilang() === true) {
			Kirby\Toolkit\I18n::$locale = $kirby->language()->code();
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
			return new ListItem(
				key: $item['key'],
				title: $item['title'] ?? null,
				page: $item['page'][0] ?? null,
				price: $item['price'] ?? null,
				priceNet: $item['pricenet'] ?? null,
				tax: $item['taxrate'] ?? null,
				currency: $item['currency'] ?? null,
				quantity: $item['quantity'] ?? 1.0,
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

	public function formattedSum(): string
	{
		return Merx::formatPrice($this->cart()->total()->price);
	}


	/**
	 * 5 character long string based on sorting number.
	 */
	public function invoiceNumber(): string
	{
		if ($this->num()) {
			return str_pad($this->num(), 5, 0, STR_PAD_LEFT);
		} else {
			return '';
		}
	}
}
