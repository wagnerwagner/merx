<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\Obj;
use Kirby\Toolkit\Str;

class Price extends Obj
{
	/** Floating point precision for price calculations */
	const roundingPrecision = 2;

	/** Gross price, including tax. E.g. 119.0 */
	public float|null $price = null;

	/** E.g. 100.0 */
	public float|null $priceNet = null;

	/** E.g. 19.0 */
	public Tax|null $tax = null;

	/** Three-letter ISO currency code, in uppercase. E.g. EUR */
	public string|null $currency = null;

	/**
	 * Calculates prices given on net or gross price and tax (rate).
	 *
	 * @throws \Kirby\Exception\InvalidArgumentException $data
	 */
	public function __construct(
		null|float $price = null,
		null|float $priceNet = null,
		null|float|Tax $tax = null,
		null|string $currency = null,
	)
	{
		$roundingPrecision = self::roundingPrecision;

		if (is_float($price)) {
			$this->price = round($price, $roundingPrecision);
		} else {
			if (!is_float($price) && !is_float($priceNet)) {
				throw new InvalidArgumentException(
					message: '$price or $priceNet must be given'
				);
			}

			$this->price = is_float($price)
				? round((float)$price, $roundingPrecision)
				: null;
		}

		$this->currency = $currency;
		if (is_string($this->currency)) {
			$this->currency = Str::upper($this->currency);
		}

		if (is_float($priceNet)) {
			$this->priceNet = round((float)$priceNet, $roundingPrecision);
		}

		if ($priceNet === null && is_float($this->price) && is_float($tax)) {
			$this->priceNet = round($this->price / (1 + $tax), $roundingPrecision);
		}

		if ($this->price === null && is_float($this->priceNet) && is_float($tax)) {
			$this->price = round($this->priceNet * (1 + $tax), $roundingPrecision);
		}

		if (is_float($tax)) {
			$this->tax = new Tax(
				priceNet: $this->priceNet,
				rate: $tax,
				currency: $this->currency,
			);
		} else if ($tax instanceof Tax) {
			$this->tax = $tax;
		}
	}

	/**
	 * Converts the object to an array
	 */
	public function toArray(): array
	{
		$result = parent::toArray();
		$result = array_filter($result, fn ($value) => $value !== null);

		return $result;
	}

	public function toFloat(): ?float
	{
		return $this->price;
	}

	/**
	 * @property string $key Use `priceNet` to get net price as formatted currency
	 *
	 * @return string  Formatted currency
	 */
	public function toString(string $key = 'price'): string
	{
		return Merx::formatCurrency($this->$key ?? 0, $this->currency);
	}

	public function __toString(): string
	{
		return $this->price;
	}
}
