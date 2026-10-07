<?php

namespace Wagnerwagner\Merx;

use Kirby\Toolkit\Obj;

/**
 * Represents a tax with a price and a rate in percent
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 * @see https://merx.wagnerwagner.de/guide/configuration/taxes
 */
class Tax extends Obj
{
	/** Three-letter ISO currency code, in uppercase. E.g. EUR */
	public string|null $currency = null;

	/** Tax price. E.g. 21.5 */
	public float $price;

	/** Tax value. E.g. 0.19 (19 %) */
	public float|null $rate = null;

	/**
	 * Creates a new tax
	 *
	 * @param $priceNet Net price the tax is calculated from
	 * @param $rate E.g. 0.19 (for 19 %)
	 * @param $currency Three-letter ISO currency code, in uppercase. E.g. EUR or USD.
	 * @param $price Tax amount, when it does not follow from a single rate. E.g. the tax of a list whose items carry different tax rates. $priceNet is unused then.
	 */
	public function __construct(float $priceNet, float|null $rate = null, string|null $currency = null, float|null $price = null)
	{
		$this->currency = $currency;

		$this->price = round($price ?? $priceNet * ($rate ?? 0), Price::roundingPrecision);

		$this->rate = $rate;
	}

	public function __toString(): string
	{
		return (string)$this->toString();
	}

	/**
	 * Converts the tax rate to a formatted string
	 *
	 * E.g. 19 %. Empty when the tax has no single rate.
	 */
	public function rate(): string
	{
		if ($this->rate === null) {
			return '';
		}

		return Merx::formatPercent($this->rate, maxFractionDigits: 1);
	}

	/**
	 * Converts the tax to a float
	 */
	public function toFloat(): float
	{
		return $this->rate ?? 0.0;
	}

	/**
	 * Converts the price to a formatted string
	 */
	public function toString(): string
	{
		return Merx::formatCurrency($this->price ?? 0, $this->currency);
	}
}
