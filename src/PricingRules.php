<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Collection;

/**
 * List of `PricingRule` objects
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @extends \Kirby\Cms\Collection<PricingRule>
 */
class PricingRules extends Collection
{
	/**
	 * Creates a new pricing rules collection
	 *
	 * If no options are provided, a default pricing rule is created with currency EUR.
	 *
	 * @param array $options
	 * Key of the array is the key of the pricing rule.
	 * Value of the array is an array with the following keys:
	 * - `name`: The name of the pricing rule.
	 * - `rule`: The rule to apply to the pricing rule.
	 *   It is passed the Kirby instance as first argument. It must return true when the rule applies, or false when it does not.
	 * - `currency`: Three-letter ISO currency code, in uppercase. E.g. EUR or USD.
	 * - `taxIncluded`: Whether the price is including tax or not.
	 */
	public function __construct(array $options = [])
	{
		if (count($options) === 0) {
			$rule = new PricingRule(
				key: 'default',
				currency: 'EUR',
			);
			$this->append('default', $rule);
		}

		foreach ($options as $key => $option) {
			$name = is_callable($option['name']) ? call_user_func($option['name'], App::instance()) : $option['name'] ?? $key;
			$this->append($key, new PricingRule(
				key: $key,
				name: $name,
				rule: $option['rule'] ?? null,
				currency: $option['currency'] ?? null,
				taxIncluded: $option['taxIncluded'] ?? null,
			));
		}
	}

	/**
	 * Gets the pricing rule by its key
	 */
	public function getRuleByKey(null|string $key): ?PricingRule
	{
		if ($key === null) {
			return null;
		}
		return $this->findByKey($key);
	}

  /**
   * Finds the pricing rule that applies to the current context
   *
   * Pricing rules are defined in the config and are checked in the order they are defined.
   * The first rule that returns true for `$rule->checkRule()` is returned.
   */
	public function findRule(): ?PricingRule
	{
		$kirby = App::instance();

		foreach ($this->data as $rule) {
			if ($rule->checkRule($kirby) === true) {
				return $rule;
			}
		}

		return null;
	}
}
