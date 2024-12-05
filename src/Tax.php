<?php

namespace Wagnerwagner\Merx;

use Kirby\Toolkit\Obj;

class Tax extends Obj
{
	/** Tax price. E.g. 19.0 */
	public Price $price;

	/** Tax value. E.g. 0.19 */
	public ?float $rate = null;

	public function __construct(float $priceNet, ?float $rate = null, null|string $currency = null)
	{
		$this->price = new Price(
			price: $priceNet * ($rate ?? 1),
			currency: $currency,
		);
		$this->rate = $rate;
	}

	public function toFloat(): float
	{
		return $this->rate ?? 0.0;
	}

	public function __toString(): string
	{
		return $this->price;
	}
}
