<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Collection;

/**
 * @extends \Kirby\Cms\Collection<PricingRule>
 */
class PricingRules extends Collection
{
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

	public function getRuleByKey(null|string $key): ?PricingRule
	{
		if ($key === null) {
			return null;
		}
		return $this->findByKey($key);
	}

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
