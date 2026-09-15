<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use PHPUnit\Framework\TestCase;

final class ProductListTest extends TestCase
{
	public function testAppend(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$this->assertEquals(
			99.99,
			$productList->first()->price()->toFloat()
		);
		$this->assertEquals(
			99.99,
			$productList->total()->toFloat()
		);

		$productList->add(['key' => 'nice-socks', 'price' => 10]);
		$this->assertEquals(
			109.99,
			$productList->total()->toFloat()
		);
	}


	public function testUpdateItem(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$productList->updateItem('nice-shoes', ['price' => new Price(89.99), 'tax' => 10]);
		$this->assertEquals(
			89.99,
			$productList->total()->toFloat()
		);

		$productList->updateItem('nice-shoes', ['quantity' => 2]);
		$this->assertEquals(
			179.98,
			$productList->total()->toFloat()
		);
	}


	public function testTotalOfItemsWithTheSameTaxRate(): void
	{
		$productList = new ProductList([
			['key' => 'nice-shoes', 'price' => new Price(119.0, 0.19, 'default', 'EUR')],
			['key' => 'nice-socks', 'price' => new Price(119.0, 0.19, 'default', 'EUR')],
		]);

		$total = $productList->total();

		$this->assertEquals(238.0, $total->price);
		$this->assertEquals(200.0, $total->priceNet);
		$this->assertEquals(0.19, $total->tax->rate);
		$this->assertEquals(38.0, $total->tax->price);

		$taxRates = $productList->taxRates();
		$this->assertCount(1, $taxRates);
		$this->assertEquals(38.0, $taxRates['0.19']->price);
	}


	public function testTotalOfItemsWithDifferentTaxRates(): void
	{
		$productList = new ProductList([
			['key' => 'nice-shoes', 'price' => new Price(119.0, 0.19, 'default', 'EUR')],
			['key' => 'nice-socks', 'price' => new Price(107.0, 0.07, 'default', 'EUR')],
		]);

		$total = $productList->total();

		$this->assertEquals(226.0, $total->price);
		$this->assertEquals(200.0, $total->priceNet);
		// The list has no tax rate of its own, `taxRates()` breaks it down
		$this->assertNull($total->tax->rate);
		$this->assertEquals(26.0, $total->tax->price);

		$taxRates = $productList->taxRates();
		$this->assertCount(2, $taxRates);
		$this->assertSame(['0.07', '0.19'], array_keys($taxRates));
		$this->assertEquals(19.0, $taxRates['0.19']->price);
		$this->assertEquals(7.0, $taxRates['0.07']->price);
	}


	public function testTotalOfItemsWithoutTax(): void
	{
		$productList = new ProductList([
			['key' => 'nice-shoes', 'price' => new Price(10.0, null, 'default', 'EUR')],
			['key' => 'nice-socks', 'price' => new Price(20.0, null, 'default', 'EUR')],
		]);

		$total = $productList->total();

		$this->assertEquals(30.0, $total->price);
		$this->assertEquals(30.0, $total->priceNet);
		// Items without a tax add up to no tax, not to a tax of 0 %
		$this->assertNull($total->tax);
		$this->assertCount(0, $productList->taxRates());
	}


	public function testTotalKeepsATaxRateOfZero(): void
	{
		$productList = new ProductList([
			['key' => 'nice-shoes', 'price' => new Price(10.0, 0.0, 'default', 'EUR')],
		]);

		$total = $productList->total();

		// An item taxed at 0 % has a tax, unlike an item without one
		$this->assertInstanceOf(Tax::class, $total->tax);
		$this->assertEquals(0.0, $total->tax->rate);
		$this->assertEquals(0.0, $total->tax->price);
	}


	public function testAddRejectsNegativeQuantity(): void
	{
		$productList = new ProductList();

		$this->expectException(Exception::class);
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99, 'quantity' => -1.0]);
	}


	public function testUpdateItemRejectsNegativeQuantity(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		try {
			$productList->updateItem('nice-shoes', ['quantity' => -5.0]);
			$this->fail('Negative quantity was accepted.');
		} catch (Exception) {
			// The list must keep its original, non-negative total.
			$this->assertEquals(99.99, $productList->total()->toFloat());
		}
	}


	public function testUpdateItemRejectsInfiniteQuantity(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$this->expectException(Exception::class);
		$productList->updateItem('nice-shoes', ['quantity' => INF]);
	}


	public function testUpdateItemEnforcesMaxQuantity(): void
	{
		$productList = new ProductList();
		$productList->add([
			'key' => 'nice-shoes',
			'price' => 99.99,
			'data' => ['maxQuantity' => 2.0],
		]);

		$this->expectException(Exception::class);
		$productList->updateItem('nice-shoes', ['quantity' => 3.0]);
	}


	public function testUpdateItemKeepsMaxQuantityWhenDataIsReplaced(): void
	{
		$productList = new ProductList();
		$productList->add([
			'key' => 'nice-shoes',
			'price' => 99.99,
			'data' => ['maxQuantity' => 2.0],
		]);

		// A client must not be able to lift its own limit by overwriting `data`.
		$this->expectException(Exception::class);
		$productList->updateItem('nice-shoes', [
			'quantity' => 3.0,
			'data' => ['maxQuantity' => 999.0],
		]);
	}


	public function testUpdateItemThrowsOnUnknownKey(): void
	{
		$productList = new ProductList();

		$this->expectException(Exception::class);
		$productList->updateItem('does-not-exist', ['quantity' => 1.0]);
	}

	/**
	 * Bad input from the API is a client error. Without an explicit code Kirby
	 * answers 500, which tells the frontend the server broke.
	 */
	public function testRejectedInputIsReportedAsAClientError(): void
	{
		$productList = new ProductList();
		$productList->add([
			'key' => 'nice-shoes',
			'price' => 99.99,
			'data' => ['maxQuantity' => 2.0],
		]);

		$cases = [
			'merx.cart.quantity' => fn () => $productList->updateItem('nice-shoes', ['quantity' => -1.0]),
			'merx.cart.maxQuantity' => fn () => $productList->updateItem('nice-shoes', ['quantity' => 3.0]),
			'merx.cart.missingItem' => fn () => $productList->updateItem('does-not-exist', ['quantity' => 1.0]),
		];

		foreach ($cases as $key => $case) {
			try {
				$case();
				$this->fail($key . ' was not thrown.');
			} catch (Exception $exception) {
				$this->assertSame('error.' . $key, $exception->getCode());
				$this->assertSame(400, $exception->getHttpCode());
			}
		}
	}
}
