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
	public string $key = '';

	public string $name = '';

	/** @var callable */
	public $rule;

	public function __construct(string $key, ?string $name = null, ?callable $rule = null)
	{
		$this->key = $key;

		$this->name = $name ?? $key;

		$this->rule = $rule;
	}

	public function taxRate(?App $kirby = null): ?float
	{
		if (is_callable($this->rule)) {
			$kirby = $kirby ?? App::instance();
			return call_user_func($this->rule, $kirby) / 100;
		}

		return null;
	}

	public function toArray(): array
	{
		$array = parent::toArray();
		$array['rule'] = $this->taxRate();
		return $array;
	}

}
