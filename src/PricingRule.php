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
 * @see https://merx.wagnerwagner.de/guide/configuration/currencies
 */
class PricingRule extends Obj
{
	/** Three-letter ISO currency code, in uppercase. E.g. EUR or USD */
	public string|null $currency = null;
	public string $key;

	/** Name of the pricing rule */
	public string $name = '';

	/** Tax is included in price */
	public bool $taxIncluded = true;

	/**
	 * Constructor
	 *
	 * @param $currency Three-letter ISO currency code, in uppercase. E.g. EUR or USD.
	 * @param $rule A function that returns true when the rule applies, or false when it does not.
	 *             It is passed the Kirby instance as first argument.
	 * @param $taxIncluded Whether the price is including tax or not
	 */
	public function __construct(
		string $key,
		string|null $name = null,
		string|null $currency = null,
		callable|null $rule = null,
		bool $taxIncluded = true,
	) {
		$this->key = $key;

		$this->name = $name ?? $key;

		$this->currency = $currency;
		if (is_string($this->currency)) {
			$this->currency = Str::upper($this->currency);
		}

		$this->rule = $rule;

		$this->taxIncluded = $taxIncluded;
	}

	public function __toString(): string
	{
		return $this->key;
	}

	/**
	 * Checks if the pricing rule applies to the current context
	 *
	 * Kirby instance is passed to the rule function if it is a callable.
	 * If no rule is set, true is returned.
	 *
	 * @param $kirby The Kirby instance
	 */
	public function checkRule(App|null $kirby = null): bool
	{
		if ($this->rule === null) {
			return true;
		}

		$kirby ??= App::instance();

		if (is_callable($this->rule)) {
			return call_user_func($this->rule, $kirby);
		}
		return false;
	}

	/**
	 * Converts the pricing rule to an array. Rule function is removed.
	 */
	public function toArray(): array
	{
		$array = parent::toArray();
		unset($array['rule']);
		return $array;
	}
}
