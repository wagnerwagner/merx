<?php

namespace Wagnerwagner\Merx;

use Kirby\Form\Field\SelectField;
use Kirby\Reflection\Attributes\Prop;

/**
 * Panel field to pick the tax rule of a product
 *
 * Offers every rule in `wagnerwagner.merx.taxRules`, and is hidden while
 * there is only one. An empty value is the `default` rule, as in
 * `ProductPage::prices()`.
 *
 * @see https://merx.wagnerwagner.de/guide/configuration/taxes
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class TaxRulesField extends SelectField
{
	/**
	 * A product has the same tax rule in every language
	 */
	#[Prop(export: false)]
	protected bool $translate = false;

	public function __construct(mixed ...$args)
	{
		parent::__construct(...$args);

		$this->options = [];
		foreach (Merx::taxRules() as $rule) {
			$this->options[] = [
				'value' => $rule->key,
				'text' => $rule->name,
			];
		}
	}

	/**
	 * Shows an empty tax rule as the `default` rule, when there is one
	 *
	 * Not in `fill()`, which Kirby skips for content without the field.
	 */
	public function toFormValue(): mixed
	{
		$value = parent::toFormValue();

		if ($value === '' && Merx::taxRule('default') !== null) {
			return 'default';
		}

		return $value;
	}

	/**
	 * With a single tax rule there is nothing to pick
	 */
	public function isHidden(): bool
	{
		return Merx::taxRules()->count() === 1;
	}
}
