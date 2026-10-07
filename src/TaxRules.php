<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Collection;

/**
 * List of `TaxRule` objects
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 * @see https://merx.wagnerwagner.de/guide/configuration/taxes
 * @extends \Kirby\Cms\Collection<TaxRule>
 */
class TaxRules extends Collection
{
	/**
	 * Creates a new tax rules collection
	 *
	 * If no options are provided, a default tax rule is created.
	 *
	 * @param $options The options for the tax rules
	 */
	public function __construct(array $options = [])
	{
		if (count($options) === 0) {
			$rule = new TaxRule(
				key: 'default',
			);
			$this->append('default', $rule);
		}

		foreach ($options as $key => $option) {
			$name = $option['name'] ?? null;
			if (is_callable($name)) {
				$name = call_user_func($name, App::instance());
			}
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
	 * @param $key The key of the tax rule
	 * @return null|TaxRule The tax rule or null if not found
	 */
	public function getRuleByKey(string|null $key): TaxRule|null
	{
		if ($key === null) {
			return null;
		}
		return $this->findByKey($key);
	}
}
