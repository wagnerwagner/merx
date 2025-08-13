<?php

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\Tax;
use Wagnerwagner\Merx\PricingRule;

final class PriceTest extends TestCase
{
	public function testGrossPriceWithTaxIncluded()
	{
		$tax = new Tax(priceNet: 100.0, rate: 0.19, currency: 'EUR');
		$price = new Price(119.0, $tax);

		$this->assertEquals(119.0, $price->price);
		$this->assertEquals(100.0, $price->priceNet);
		$this->assertInstanceOf(Tax::class, $price->tax);
		$this->assertEquals(0.19, $price->tax->rate);
	}

	public function testNetPriceWithTaxExcluded()
	{
		$pricingRule = $this->createMock(PricingRule::class);
		$pricingRule->taxIncluded = false;
		$pricingRule->currency = 'EUR';

		$price = new Price(100.0, 0.19, $pricingRule);

		$this->assertEquals(119.0, $price->price);
		$this->assertEquals(100.0, $price->priceNet);
		$this->assertInstanceOf(Tax::class, $price->tax);
		$this->assertEquals(0.19, $price->tax->rate);
	}

	public function testToArrayFiltersNullValues()
	{
		$price = new Price(50.0, null);
		$array = $price->toArray();

		$this->assertArrayHasKey('price', $array);
		$this->assertArrayHasKey('priceNet', $array);
		$this->assertArrayNotHasKey('tax', $array);
	}

	public function testToFloatReturnsPrice()
	{
		$price = new Price(42.5, null);
		$this->assertEquals(42.5, $price->toFloat());
	}

	public function testToStringFormatsCurrency()
	{
		$pricingRule = $this->createMock(PricingRule::class);
		$pricingRule->taxIncluded = true;
		$pricingRule->currency = 'EUR';

		$price = new Price(10.0, 0.19, $pricingRule, 'EUR');
		$this->assertStringContainsString('€', $price->toString());
	}

	public function testTaxIncludedReturnsNullIfNoPricingRule()
	{
		$price = new Price(10.0, null, null, 'EUR');
		$this->assertNull($price->taxIncluded());
	}

	public function testToStringUsesPriceNetIfTaxNotIncluded()
	{
		$pricingRule = $this->createMock(PricingRule::class);
		$pricingRule->taxIncluded = false;
		$pricingRule->currency = 'EUR';

		$price = new Price(100.0, 0.19, $pricingRule, 'EUR');
		$this->assertStringContainsString('€', $price->toString('priceNet'));
	}

	public function test__toStringReturnsPriceAsString()
	{
		$price = new Price(77.77, null);
		$this->assertEquals('77.77', (string)$price);
	}
}
