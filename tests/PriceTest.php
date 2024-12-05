<?php

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\Tax;
use Kirby\Exception\InvalidArgumentException;

class PriceTest extends TestCase
{
	public function testConstructorInitializesCorrectlyWithGrossPrice()
	{
		$price = new Price(price: 119.0, tax: 0.19, currency: 'eur');

		$this->assertEquals(119.0, $price->price);
		$this->assertEquals(100.0, $price->priceNet);
		$this->assertEquals('EUR', $price->currency);
		$this->assertInstanceOf(Tax::class, $price->tax);
	}

	public function testConstructorInitializesCorrectlyWithNetPrice()
	{
		$price = new Price(priceNet: 100.0, tax: 0.19, currency: 'eur');

		$this->assertEquals(100.0, $price->priceNet);
		$this->assertEquals(119.0, $price->price);
		$this->assertEquals('EUR', $price->currency);
		$this->assertInstanceOf(Tax::class, $price->tax);
	}

	public function testConstructorThrowsExceptionIfNoPriceProvided()
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('$price or $priceNet must be give');

		new Price();
	}

	public function testCurrencyIsUppercase()
	{
		$price = new Price(price: 119.0, tax: 0.19, currency: 'usd');

		$this->assertEquals('USD', $price->currency);
	}

	public function testToFloatReturnsPrice()
	{
		$price = new Price(price: 119.0);

		$this->assertEquals(119.0, $price->toFloat());
	}

	public function testToStringFormatsPriceWithCurrency()
	{
		$price = new Price(price: 119.0, currency: 'EUR');

		$formattedPrice = $price->__toString();
		$this->assertStringContainsString('€', $formattedPrice); // Check for currency symbol
		$this->assertStringContainsString('119', $formattedPrice); // Check for price value
	}

	public function testToArrayExcludesNullValues()
	{
		$price = new Price(priceNet: 100.0, tax: 0.19);

		$array = $price->toArray();

		$this->assertArrayHasKey('priceNet', $array);
		$this->assertArrayNotHasKey('currency', $array); // Currency is null and should not be included
	}

	public function testNumberFormatReturnsCurrencyFormatter()
	{
		$price = new Price(price: 119.0, currency: 'EUR');

		$formatter = $price->numberFormat();
		$this->assertInstanceOf(NumberFormatter::class, $formatter);
	}
}
