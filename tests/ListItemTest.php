<?php

use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;

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
			price: $price,
		);

		$this->assertSame($key, $listItem->key);
		$this->assertSame($title, $listItem->title);
		$this->assertSame($quantity, $listItem->quantity);
		$this->assertInstanceOf(Price::class, $listItem->price);
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

	public function testPriceTotalCalculation()
	{
		$key = 'item4';
		$price = new Price(100.0, 0.2, null, 'USD');
		$quantity = 3.0;

		$listItem = new ListItem(
			key: $key,
			price: $price,
			quantity: $quantity,
		);

		$totalPrice = $listItem->total();

		$this->assertInstanceOf(Price::class, $totalPrice);
		$this->assertEquals(300.0, $totalPrice->toFloat());
	}

	/**
	 * What the cart writes to the session, read the way Kirby reads a session
	 */
	private static function throughSession(ListItem $listItem): ListItem
	{
		$data = unserialize(serialize($listItem->toSessionArray()), ['allowed_classes' => false]);

		return new ListItem(...$data);
	}

	public function testPriceOfItemWithoutPageSurvivesSession()
	{
		$listItem = new ListItem(
			key: 'shipping',
			title: 'Shipping',
			price: new Price(price: 5.9, tax: 0.19, currency: 'EUR'),
			type: 'shipping',
		);

		$restored = self::throughSession($listItem);

		$this->assertInstanceOf(Price::class, $restored->price);
		$this->assertSame(5.9, $restored->price->price);
		$this->assertSame($listItem->price->priceNet, $restored->price->priceNet);
		$this->assertSame(0.19, $restored->price->tax->rate);
		$this->assertSame('EUR', $restored->price->currency);
		$this->assertSame('shipping', $restored->type);
	}

	public function testQuantifierIsNotAppliedAgainAfterSession()
	{
		$listItem = new ListItem(
			key: 'cut',
			price: new Price(price: 10.0, currency: 'EUR'),
			quantifier: 2.5,
		);
		$this->assertSame(25.0, $listItem->price->price);

		$restored = self::throughSession(self::throughSession($listItem));

		$this->assertSame(25.0, $restored->price->price);
		$this->assertSame(2.5, $restored->quantifier);
	}

	public function testSessionArrayHoldsNoObjects()
	{
		$listItem = new ListItem(
			key: 'discount',
			price: new Price(price: -10.0, tax: 0.07, currency: 'EUR'),
			type: 'discount',
		);

		array_walk_recursive($listItem->toSessionArray(), function (mixed $value): void {
			$this->assertIsNotObject($value);
		});
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
