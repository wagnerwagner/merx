<?php

namespace Wagnerwagner\Merx;

use Kirby\Toolkit\Obj;

/**
 * Represents a tax with a price and a rate in percent
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class Tax extends Obj
{
	/** Tax price. E.g. 21.5 */
	public float $price;

	/** Tax value. E.g. 0.19 (19 %) */
	public ?float $rate = null;

	/** Three-letter ISO currency code, in uppercase. E.g. EUR */
	public string|null $currency = null;

	public function __construct(float $priceNet, ?float $rate = null, null|string $currency = null)
	{
		$this->currency = $currency;

		$this->price = round($priceNet * ($rate ?? 0), Price::roundingPrecision);

		$this->rate = $rate;
	}

	public function toFloat(): float
	{
		return $this->rate ?? 0.0;
	}

	/**
	 * @property string $key Use `price` to get net price as formatted currency
	 *
	 * @return string  Formatted currency
	 */
	public function toString(string $key = 'price'): string
	{
		if ($key === 'rate') {
			return Merx::formatPercent($this->rate, maxFractionDigits: 1);
		} else {
			return Merx::formatCurrency($this->$key ?? 0, $this->currency);
		}
	}

	public function __toString(): string
	{
		return (string)$this->toString();
	}
}
