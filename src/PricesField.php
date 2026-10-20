<?php

namespace Wagnerwagner\Merx;

use Kirby\Form\Field\ObjectField;
use Kirby\Reflection\Attributes\Prop;

/**
 * Panel field with one price per pricing rule
 *
 * Shows a number field for every rule in `wagnerwagner.merx.pricingRules`,
 * so a product cannot have two prices for the same rule. The prices are
 * stored as a list of `price` and `pricingRule`, which is what
 * `ProductPage::prices()` reads.
 *
 * @see https://merx.wagnerwagner.de/guide/configuration/currencies
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class PricesField extends ObjectField
{
	#[Prop(computed: true)]
	protected array|string|null $label = 'field.price';

	/**
	 * Pricing rules set the currency, so prices are the same in every language
	 */
	#[Prop(export: false)]
	protected bool $translate = false;

	public function __construct(mixed ...$args)
	{
		parent::__construct(...$args);

		$this->fields = [];
		foreach (Merx::pricingRules() as $rule) {
			$this->fields[$rule->key] = [
				'type' => 'number',
				'label' => $rule->name,
				'after' => $rule->currency,
				'step' => 0.01,
			];
		}
	}

	/**
	 * Turns the stored list into one price per pricing rule
	 *
	 * A plain number is the price of the `default` pricing rule. The Panel
	 * submits one price per rule already, so only a list is converted.
	 */
	public function fill(mixed $value): static
	{
		if (is_numeric($value) === true) {
			$value = ['default' => $value];
		}

		parent::fill($value);

		if (array_is_list($this->value) === true) {
			$prices = [];
			foreach ($this->value as $row) {
				$row = array_change_key_case((array)$row);
				if (isset($row['pricingrule']) === true) {
					$prices[strtolower($row['pricingrule'])] = $row['price'] ?? null;
				}
			}
			$this->value = $prices;
		}

		return $this;
	}

	/**
	 * Stores a row for every pricing rule that has a price
	 */
	public function toStoredValue(): array
	{
		$prices = [];

		foreach (parent::toStoredValue() as $key => $price) {
			if ($price === null || $price === '') {
				continue;
			}

			// Kirby lower cases field names, the rule keeps the key as configured
			$rule = Merx::pricingRules()
				->filter(fn (PricingRule $rule): bool => strtolower($rule->key) === $key)
				->first();

			$prices[] = [
				'price' => $price,
				'pricingrule' => $rule?->key ?? $key,
			];
		}

		return $prices;
	}

	/**
	 * Kirby runs validations with `Closure::call()`, which cannot rebind the
	 * parent’s `$this->validateObject(...)` to a subclass
	 */
	protected function validations(): array
	{
		return [
			'object' => fn (array|string|null $value) => $this->validateObject($value),
		];
	}
}
