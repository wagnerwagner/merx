<?php

namespace Wagnerwagner\Merx;

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
}
