<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Obj;
use Kirby\Toolkit\Str;
use NumberFormatter;

class Price extends Obj
{
	/** Floating point precision for price calculations */
	const roundingPrecision = 2;

	/** E.g. 100.0 */
	public float|null $priceNet = null;

	/** Gross price, including tax. E.g. 119.0 */
	public float|null $price = null;

	/** E.g. 19.0 */
	public Tax|null $tax = null;

	/** Three-letter ISO currency code, in uppercase. E.g. EUR */
	public string|null $currency = null;

	/**
	 * Calculates prices given on net or gross price and tax.
	 *
	 * @param array|float $data Possible keys: price, priceNet, tax, currency
	 * @throws \Kirby\Exception\InvalidArgumentException $data
	 */
	public function __construct(
		null|float $price = null,
		null|float $priceNet = null,
		null|float $taxRate = null,
		null|string $currency = null,
	)
	{
		$roundingPrecision = self::roundingPrecision;

		if (is_float($price)) {
			$this->price = round($price, $roundingPrecision);
		} else {
			if (!is_float($price) && !is_float($priceNet)) {
				throw new InvalidArgumentException(
					message: '$price or $priceNet must be give'
				);
			}

			$this->price = is_float($price)
				? round((float)$price, $roundingPrecision)
				: null;
		}

		$this->priceNet = is_float($priceNet)
			? round((float)$priceNet, $roundingPrecision)
			: null;

		$this->currency = $currency ?? option('ww.merx.currency.default', null);
		if (is_string($this->currency)) {
			$this->currency = Str::upper($this->currency);
		}

		if ($this->priceNet === null && is_float($this->price) && is_float($taxRate)) {
			$this->priceNet = round($this->price / (1 + $taxRate ?? 0), $roundingPrecision);
		}

		if ($this->price === null && is_float($this->priceNet)) {
			$this->price = round($this->priceNet * (1 + $taxRate ?? 0), $roundingPrecision);
		}

		if ($taxRate !== null) {
			$this->tax = new Tax(
				priceNet: $this->priceNet,
				rate: $taxRate,
				currency: $currency,
			);
		}
	}

	public function numberFormat(): NumberFormatter
	{
		$style = is_string($this->currency) ?
			NumberFormatter::CURRENCY : NumberFormatter::DECIMAL;
		return new NumberFormatter(I18n::locale(), $style);
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

	public function toString(?string $key = null): string
	{
		if ($key === null) {
			return $this->__toString();
		}

		return $this->numberFormat()->formatCurrency($this->$key, $this->currency ?? null);
	}

	public function __toString(): string
	{
		return $this->numberFormat()->formatCurrency($this->price, $this->currency ?? null);
	}
}
