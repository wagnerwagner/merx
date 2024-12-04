<?php

namespace Wagnerwagner\Merx;

use Kirby\Toolkit\Obj;

class Tax extends Obj
{
	/** Tax price. E.g. 19.0 */
	public Price $price;

	/** Tax value. E.g. 0.19 */
	public float $rate;

	public function __construct(float $priceNet, float $rate, null|string $currency = null)
	{
		$this->price = new Price(
			price: $priceNet * $rate,
			currency: $currency,
		);
		$this->rate = $rate;
	}

	public function toFloat(): float
	{
		return $this->rate ?? 0.0;
	}
}
