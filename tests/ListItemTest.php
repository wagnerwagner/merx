<?php

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;
use Kirby\Cms\Page;

class ListItemTest extends TestCase
{
	public function testConstructorInitializesCorrectly()
	{
		$key = 'item1';
		$title = 'Test Item';
		$quantity = 2.0;
		$price = new Price(100.0, 0.2, currency: 'USD');

		$listItem = new ListItem(
			key: $key,
			title: $title,
			quantity: $quantity,
			price: $price
		);

		$this->assertSame($key, $listItem->key);
		$this->assertSame($title, $listItem->title);
		$this->assertSame($quantity, $listItem->quantity);
		$this->assertInstanceOf(Price::class, $listItem->price);
	}

	public function testPriceTotalCalculation()
	{
		$key = 'item4';
		$price = new Price(100.0, 0.2, null, 'USD');
		$quantity = 3.0;

		$listItem = new ListItem(
			key: $key,
			price: $price,
			quantity: $quantity
		);

		$totalPrice = $listItem->total();

		$this->assertInstanceOf(Price::class, $totalPrice);
		$this->assertEquals(300.0, $totalPrice->toFloat());
	}

	public function testConstructorWithDefaults()
	{
		$key = 'item5';

		$listItem = new ListItem(key: $key);

		$this->assertSame($key, $listItem->key);
		$this->assertNull($listItem->title);
		$this->assertNull($listItem->price);
		$this->assertEquals(1.0, $listItem->quantity);
		$this->assertNull($listItem->data);
	}

	public function testInvalidPriceInitialization()
	{
		$this->expectException(TypeError::class);

		$key = 'item6';
		$invalidPrice = 'invalid'; // Price should be a float or Price object

		new ListItem(key: $key, price: $invalidPrice);
	}

	public function testTitleFallbackToPageTitle()
	{
		$key = 'item3';
		$page = new Page([
			'slug' => $key,
			'content' => [
				'title' => 'Nice shoes',
				'price' => 200,
			],
		]);

		$listItem = new ListItem(key: $key, page: $page);

		$this->assertSame('Nice shoes', $listItem->title);
	}
}
