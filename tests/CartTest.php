<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
	public function testPayPalPurchaseUnits(): void
	{
		$cart = new Cart([
			['key' => 'nice-shoes', 'price' => new Price(price: 99.99, currency: 'EUR'), 'quantity' => 2.0],
			['key' => 'nice-socks', 'price' => new Price(price: 10.0, currency: 'EUR')],
		]);

		$purchaseUnits = $cart->payPalPurchaseUnits();
		$this->assertCount(1, $purchaseUnits);

		$purchaseUnit = $purchaseUnits[0];

		// PayPal charges the amount of the purchase unit, which has to be the
		// total of the cart.
		$this->assertSame('209.98', $purchaseUnit['amount']['value']);
		$this->assertSame('EUR', $purchaseUnit['amount']['currency_code']);

		// … and PayPal adds the items up itself, so the breakdown has to match.
		$this->assertSame('209.98', $purchaseUnit['amount']['breakdown']['item_total']['value']);
		$this->assertSame('0.00', $purchaseUnit['amount']['breakdown']['discount']['value']);

		$this->assertSame(
			[
				[
					'name' => 'nice-shoes',
					'unit_amount' => ['value' => '99.99', 'currency_code' => 'EUR'],
					'quantity' => '2',
				],
				[
					'name' => 'nice-socks',
					'unit_amount' => ['value' => '10.00', 'currency_code' => 'EUR'],
					'quantity' => '1',
				],
			],
			$purchaseUnit['items'],
		);
	}


	public function testPayPalPurchaseUnitsWithDiscount(): void
	{
		$cart = new Cart([
			['key' => 'nice-shoes', 'price' => new Price(price: 100.0, currency: 'EUR')],
			['key' => 'voucher', 'price' => new Price(price: -10.0, currency: 'EUR'), 'type' => 'discount'],
		]);

		$purchaseUnit = $cart->payPalPurchaseUnits()[0];

		$this->assertSame('90.00', $purchaseUnit['amount']['value']);
		$this->assertSame('100.00', $purchaseUnit['amount']['breakdown']['item_total']['value']);
		$this->assertSame('10.00', $purchaseUnit['amount']['breakdown']['discount']['value']);

		// A discount is not an item of its own; PayPal would add it up.
		$this->assertCount(1, $purchaseUnit['items']);
		$this->assertSame('nice-shoes', $purchaseUnit['items'][0]['name']);
	}


	public function testPayPalPurchaseUnitsUsesTitle(): void
	{
		$cart = new Cart([
			['key' => 'nice-shoes', 'title' => 'Nice Shoes', 'price' => new Price(price: 10.0, currency: 'EUR')],
		]);

		$this->assertSame('Nice Shoes', $cart->payPalPurchaseUnits()[0]['items'][0]['name']);
	}


	public function testPayPalPurchaseUnitsWithoutListableItems(): void
	{
		// PayPal only accepts whole quantities. A cart which can not be expressed
		// as items is sent as its total alone, rather than as a breakdown which
		// does not add up.
		$cart = new Cart([
			['key' => 'nice-cable', 'price' => new Price(price: 10.0, currency: 'EUR'), 'quantity' => 1.5],
		]);

		$purchaseUnit = $cart->payPalPurchaseUnits()[0];

		$this->assertSame('15.00', $purchaseUnit['amount']['value']);
		$this->assertArrayNotHasKey('breakdown', $purchaseUnit['amount']);
		$this->assertArrayNotHasKey('items', $purchaseUnit);
	}
}
