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

	public function testToStringMethodFormatsPrice()
	{
		$price = new Price(price: 119.0, priceNet: 100.0, tax: 0.19, currency: 'EUR');
		
		// Test gross price formatting
		$this->assertStringContainsString('119', $price->toString('price'));
		
		// Test net price formatting
		$this->assertStringContainsString('100', $price->toString('priceNet'));
	}

	public function testConstructorWithTaxObject()
	{
		$tax = new Tax(priceNet: 100.0, rate: 0.19, currency: 'EUR');
		$price = new Price(priceNet: 100.0, tax: $tax, currency: 'EUR');

		$this->assertSame($tax, $price->tax);
		$this->assertEquals(119.0, $price->price);
	}

	public function testRoundingPrecision()
	{
		$price = new Price(price: 119.999, tax: 0.19);
		$this->assertEquals(120.00, $price->price);
		
		$price = new Price(priceNet: 100.666, tax: 0.19);
		$this->assertEquals(100.67, $price->priceNet);
		$this->assertEquals(119.80, $price->price);
	}

	public function testNullHandling()
	{
		// Test with only price
		$price = new Price(price: 119.0);
		$this->assertNull($price->priceNet);
		$this->assertNull($price->tax);
		$this->assertNull($price->currency);
		
		// Test with only net price and tax
		$price = new Price(priceNet: 100.0, tax: 0.19);
		$this->assertNotNull($price->price);
		$this->assertNotNull($price->priceNet);
		$this->assertNotNull($price->tax);
		$this->assertNull($price->currency);
	}
}
