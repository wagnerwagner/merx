<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Toolkit\Obj;
use Kirby\Toolkit\Str;

/**
 * Rule defined in config to apply to a price
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class PricingRule extends Obj {
	public string $key;

	/** Name of the pricing rule */
	public string $name = '';

	/** Tax is included in price */
	public bool $taxIncluded = true;

	/** Three-letter ISO currency code, in uppercase. E.g. EUR or USD */
	public string|null $currency = null;

	/**
	 * @param callable|null $rule A function that returns true if the rule applies, or false otherwise.
	 */
	public function __construct(
		string $key,
		?string $name = null,
		?string $currency = null,
		?callable $rule = null,
		bool $taxIncluded = true,
	)
	{
		$this->key = $key;

		$this->name = $name ?? $key;

		$this->currency = $currency;
		if (is_string($this->currency)) {
			$this->currency = Str::upper($this->currency);
		}

		$this->rule = $rule;

		$this->taxIncluded = $taxIncluded;
	}

  /**
   * Checks if the pricing rule applies to the current context
   *
   * Kirby instance is passed to the rule function if it is a callable.
   * If no rule is set, true is returned.
   *
   * @param App|null $kirby The Kirby instance
   * @return bool
   */
	public function checkRule(?App $kirby = null): bool
	{
		if ($this->rule === null) {
			return true;
		}

		$kirby = $kirby ?? App::instance();

		if (is_callable($this->rule)) {
			return call_user_func($this->rule, $kirby);
		}
		return false;
	}

	public function toArray(): array
	{
		$array = parent::toArray();
		unset($array['rule']);
		return $array;
	}

	public function __toString(): string
	{
		return $this->key;
	}
}
