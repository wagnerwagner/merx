<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Toolkit\Obj;

/**
 * Rule defined in config to apply to a tax
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class TaxRule extends Obj {
	/** Unique key */
	public string $key = '';

	/** Readable name*/
	public string $name = '';

	/** @var callable|null */
	public $rule = null;

	/**
	 * Creates a new tax rule
	 *
	 * @param string $key The key of the tax rule
	 * @param string|null $name The name of the tax rule
	 * @param callable|null $rule A function that returns the tax rate as float.
	 * E.g. 0.19 (for 19 %).
	 * It is passed the Kirby instance as first argument.
	 */
	public function __construct(string $key, ?string $name = null, ?callable $rule = null)
	{
		$this->key = $key;

		$this->name = $name ?? $key;

		$this->rule = $rule;
	}

	/**
	 * Gets the tax rate as decimal value
	 *
	 * @param App|null $kirby The Kirby instance
	 * @return float|null The tax rate (e.g. 0.19) or null if no rule is set
	 */
	public function taxRate(?App $kirby = null): ?float
	{
		if (is_callable($this->rule)) {
			$kirby = $kirby ?? App::instance();
			return call_user_func($this->rule, $kirby);
		}

		return null;
	}

	/**
	 * Converts the tax rule to an array
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		$array = parent::toArray();
		$array['rule'] = $this->taxRate();
		return $array;
	}

}
