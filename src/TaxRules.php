<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Collection;

/**
 * List of `TaxRule` objects
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @extends \Kirby\Cms\Collection<TaxRule>
 */
class TaxRules extends Collection
{
	/**
	 * Creates a new tax rules collection
	 *
	 * If no options are provided, a default tax rule is created.
	 *
	 * @param array $options The options for the tax rules
	 */
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

	/**
	 * Gets the tax rule by its key
	 *
	 * @param null|string $key The key of the tax rule
	 * @return null|TaxRule The tax rule or null if not found
	 */
	public function getRuleByKey(null|string $key): ?TaxRule
	{
		if ($key === null) {
			return null;
		}
		return $this->findByKey($key);
	}
}
