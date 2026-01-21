<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class MerxTest extends TestCase
{
	public function testFormatCurrency(): void
	{
		$this->assertEquals(
			'€10.20',
			Merx::formatCurrency(10.20, 'EUR')
		);
	}
	public function testFormatCurrencyDE(): void
	{
		setlocale(LC_ALL, 'de_DE');
		$this->assertEquals(
			'€10.20',
			Merx::formatCurrency(10.20, 'EUR')
		);
	}
	public function testFormatCurrency1(): void
	{
		setlocale(LC_ALL, 'en_US');
		$this->assertEquals(
			'$11.99',
			Merx::formatCurrency(11.99, 'USD')
		);
	}

	public function testFormatIBAN(): void
	{
		$this->assertEquals(
			'DE89 3704 0044 0532 0130 00',
			Merx::formatIBAN('DE89370400440532013000')
		);
	}

	public function testCalculateTax(): void
	{
		$this->assertEquals(
			31.932773109243698,
			Merx::calculateTax(200, 0.19)
		);
	}

	public function testCart(): void
	{
		$merx = new Merx();
		$this->assertInstanceOf(
			Cart::class,
			$merx->cart()
		);
	}

	public function testinitializeOrderEmpty(): void
	{
		$merx = new Merx();
		$this->expectExceptionCode('error.merx.noPaymentMethod');
		$merx->initializeOrder([]);
	}
}
