<?php

namespace Wagnerwagner\Merx;

use Kirby\Toolkit\Obj;

class Price extends Obj
{
	/** Floating point precision for price calculations */
	const roundingPrecision = 2;

	/** Gross price, including tax. E.g. 119.00 */
	public float $price;

	/** E.g. 100.00 */
	public float $priceNet;

	/** E.g. 0.19 for 19 % */
	public Tax|null $tax = null;

	/** Three-letter ISO currency code, in uppercase. E.g. EUR */
	public string|null $currency = null;

	/** Pricing rule used for this price */
	public PricingRule|null $pricingRule = null;

	/**
	 * Price constructor.
	 *
	 * @param float $price Gross or net price, depending on tax inclusion, defined by $pricingRule (default is gross [tax included]).
	 * @param Tax|float|null $tax Tax object or tax rate as float (e.g. 0.19 for 19%), or null.
	 * @param PricingRule|string|null $pricingRule PricingRule object, its key as string, or null.
	 * @param string|null $currency Three-letter ISO currency code, or null.
	 */
	public function __construct(
		float $price,
		Tax|float|null $tax = null,
		PricingRule|string|null $pricingRule = null,
		string|null $currency = null,
	)
	{
		$roundingPrecision = self::roundingPrecision;

		$this->price = round($price, $roundingPrecision);
		$this->priceNet = $this->price;

		if (is_string($pricingRule)) {
			$this->pricingRule = Merx::pricingRules()->getRuleByKey($pricingRule);
		} else if ($pricingRule instanceof PricingRule) {
			$this->pricingRule = $pricingRule;
		}

		$this->currency = $pricingRule?->currency ?? $currency;

		$taxRate = ($tax instanceof Tax) ? $tax->rate : $tax;
		$taxIncluded = $this->pricingRule?->taxIncluded ?? true;

		if ($taxRate) {
			if ($taxIncluded === true) {
				$this->priceNet = round($this->price / (1 + $taxRate), $roundingPrecision);
				$this->price = round($this->price, $roundingPrecision);
			} else {
				$this->priceNet = $this->price;
				$this->price = round($this->priceNet * (1 + $taxRate), $roundingPrecision);
			}
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

  public function quantify(float $quantifier = 1): self
  {
		$basePrice = $this->price;
		if ($this->pricingRule?->taxIncluded === false) {
			$basePrice = $this->priceNet;
		}
		$price = $basePrice * $quantifier;
		return new self(
			price: $price,
			tax: $this->tax?->rate,
			pricingRule: $this->pricingRule,
			currency: $this->currency,
		);
  }

	public function taxIncluded(): ?bool
	{
		return $this->pricingRule?->taxIncluded;
	}

	/**
	 * Converts the object to an array, removing null values.
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
	 * @param string $key Use `priceNet` to get net price as formatted currency. When not set, `price` or `priceNet` is used, depending on tax inclusion of pricing rule.
	 *
	 * @return string  Formatted price as string, e.g. "119,00 €"
	 */
	public function toString(?string $key = null): string
	{
		if ($key === null) {
			$key = 'price';
			if ($this->pricingRule?->taxIncluded === false) {
				$key = 'priceNet';
			}
		}
		return Merx::formatCurrency($this->$key ?? 0, $this->currency);
	}

	public function __toString(): string
	{
		return (string)$this->price;
	}
}
