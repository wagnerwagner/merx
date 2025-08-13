<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Collection;

/**
 * @extends \Kirby\Cms\Collection<TaxRule>
 */
class TaxRules extends Collection
{
	public function __construct(array $options = [])
	{
		if (count($options) === 0) {
			$rule = new TaxRule(
				key: 'default',
			);
			$this->append($rule);
		}

		foreach ($options as $key => $option) {
			$name = is_callable($option['name']) ? call_user_func($option['name'], App::instance()) : $option['name'] ?? $key;
			$this->append($key, new TaxRule(
				key: $key,
				name: $name,
				rule: $option['rule'] ?? null,
			));
		}
	}

	public function getRuleByKey(null|string $key): ?TaxRule
	{
		if ($key === null) {
			return null;
		}
		return $this->findByKey($key);
	}
}
