<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductListTest extends TestCase
{
	public function testAddAcceptsAnItemWithoutPageWhichHasAPrice(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'gift-wrap', 'price' => 0.0]);
		$productList->add(['key' => 'shipping', 'type' => 'shipping']);

		$this->assertSame(['gift-wrap', 'shipping'], $productList->keys());
	}

	/**
	 * Only a cart is bound to one currency. A wishlist may hold several.
	 */
	public function testAddAcceptsAnotherCurrency(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'socks', 'price' => new Price(price: 10.0, currency: 'EUR')]);
		$productList->add(['key' => 'shirt', 'price' => new Price(price: 20.0, currency: 'USD')]);

		$this->assertSame(['socks', 'shirt'], $productList->keys());
		$this->assertSame('USD', $productList->get('shirt')->price->currency);
		$this->assertFalse($productList->currency());
		$this->assertNull($productList->total());
	}

	/**
	 * Adding to a line prices it at its new quantity, as the next request would
	 */
	public function testAddingToALineReadsItsVolumePrice(): void
	{
		$app = App::instance();
		$models = Page::$models;
		App::$enableWhoops = false;
		Page::$models['volume-product'] = VolumeProductPage::class;

		new App([
			'roots' => ['index' => __DIR__ . '/kirby'],
			'site' => [
				'children' => [
					[
						'slug' => 'socks',
						'template' => 'volume-product',
						'content' => ['title' => 'Socks', 'price' => 10],
					],
				],
			],
		]);

		try {
			$productList = new ProductList();
			$productList->add(['page' => 'socks', 'quantity' => 5.0]);
			$productList->add(['page' => 'socks', 'quantity' => 5.0]);

			$this->assertSame(10.0, $productList->first()->quantity);
			$this->assertSame(9.5, $productList->first()->price->price);
		} finally {
			Page::$models = $models;
			App::$enableWhoops = true;
			App::instance($app);
		}
	}

	public function testAddRejectsAnUnknownPage(): void
	{
		$productList = new ProductList();

		try {
			$productList->add('no-such-product');
			$this->fail('An unknown page was added');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.cart.add', $exception->getKey());
			$this->assertSame(400, $exception->getHttpCode());
		}

		$this->assertCount(0, $productList);
	}

	public function testAddRejectsAPageWhichIsNoProduct(): void
	{
		$productList = new ProductList();

		try {
			// A page, but nothing to buy
			$productList->add(['key' => 'about', 'page' => new Page(['slug' => 'about'])]);
			$this->fail('A page which is no product was added');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.cart.add', $exception->getKey());
			$this->assertSame(400, $exception->getHttpCode());
		}

		$this->assertCount(0, $productList);
	}

	/**
	 * `data` comes from visitors and is kept in their session
	 */
	public function testAddRejectsDataWhichIsTooLargeOrTooDeep(): void
	{
		$productList = new ProductList();

		$deep = 'name';
		for ($level = 0; $level < 9; $level++) {
			$deep = ['level' => $deep];
		}

		foreach ([['text' => str_repeat('a', 5000)], $deep] as $data) {
			try {
				$productList->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0, 'data' => $data]);
				$this->fail('Data which is too extensive was added');
			} catch (Exception $exception) {
				$this->assertSame('error.merx.cart.data', $exception->getKey());
				$this->assertSame(400, $exception->getHttpCode());
			}
		}

		$productList->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0, 'data' => ['name' => 'Anna', 'options' => ['thread' => 'red']]]);
		$this->assertCount(1, $productList);

		try {
			$productList->updateItem('socks', ['data' => ['text' => str_repeat('a', 5000)]]);
			$this->fail('Data which is too extensive was stored');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.cart.data', $exception->getKey());
		}

		$this->assertSame('Anna', $productList->get('socks')->data['name']);
	}

	public function testAddRejectsInputWithoutKeyAndPage(): void
	{
		$productList = new ProductList();

		$this->expectException(Exception::class);
		$this->expectExceptionCode('error.merx.cart.add');
		$productList->add(['quantity' => 2.0]);
	}

	public function testAddRejectsNegativeQuantity(): void
	{
		$productList = new ProductList();

		$this->expectException(Exception::class);
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99, 'quantity' => -1.0]);
	}

	/**
	 * A quantity of 0 adds no line, and empties one, as `updateItem()` does
	 */
	public function testAddWithoutQuantityAddsNoLine(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'gift-wrap', 'price' => 2.0, 'quantity' => 0.0]);
		$this->assertSame([], $productList->keys());

		$productList->add(['key' => 'gift-wrap', 'price' => 2.0, 'quantity' => 2.0]);
		$productList->add(['key' => 'gift-wrap', 'price' => 2.0, 'quantity' => -2.0]);
		$this->assertSame([], $productList->keys());
	}

	/**
	 * An item with a plain float price has no currency, and the order of the
	 * items must not decide whether the list has a total
	 */
	public function testAnItemWithoutCurrencyDoesNotMixCurrencies(): void
	{
		$euros = new ListItem(key: 'socks', price: new Price(price: 10.0, currency: 'EUR'));
		$plain = new ListItem(key: 'gift-wrap', price: 2.0, type: 'custom');

		foreach ([[$euros, $plain], [$plain, $euros]] as $items) {
			$productList = new ProductList();
			foreach ($items as $item) {
				$productList->add($item);
			}
			$this->assertSame(12.0, $productList->total()?->price);
			$this->assertSame('EUR', $productList->total()?->currency);
		}
	}

	public function testAppend(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$this->assertEquals(
			99.99,
			$productList->first()->price()->toFloat(),
		);
		$this->assertEquals(
			99.99,
			$productList->total()->toFloat(),
		);

		$productList->add(['key' => 'nice-socks', 'price' => 10]);
		$this->assertEquals(
			109.99,
			$productList->total()->toFloat(),
		);
	}

	public function testConstructKeepsAssociativeKeys(): void
	{
		$productList = new ProductList([
			'custom-key' => ['key' => 'nice-shoes', 'price' => 99.99],
		]);

		$this->assertSame(['custom-key'], $productList->keys());
	}

	public function testConstructKeysListByItemKey(): void
	{
		$productList = new ProductList([
			['key' => 'nice-shoes', 'price' => 99.99],
			['page' => 'nice-socks', 'price' => 10],
		]);

		$this->assertSame(['nice-shoes', 'nice-socks'], $productList->keys());

		$productList->updateItem('nice-shoes', ['quantity' => 2.0]);
		$this->assertSame(2.0, $productList->get('nice-shoes')->quantity);
	}

	/**
	 * A coupon may cover the whole cart, but not more
	 */
	public function testIsOrderable(): void
	{
		$productList = new ProductList();
		$this->assertFalse($productList->isOrderable());

		$productList->add(['key' => 'socks', 'price' => new Price(price: 10.0, currency: 'EUR')]);
		$this->assertTrue($productList->isOrderable());

		$productList->add(['key' => 'coupon', 'type' => 'discount', 'price' => new Price(price: -10.0, currency: 'EUR')]);
		$this->assertSame(0.0, $productList->total()->price);
		$this->assertTrue($productList->isOrderable());

		$productList->add(['key' => 'credit', 'type' => 'credit', 'price' => new Price(price: -5.0, currency: 'EUR')]);
		$this->assertFalse($productList->isOrderable());

		// Two currencies have no total
		$mixed = new ProductList();
		$mixed->add(['key' => 'socks', 'price' => new Price(price: 10.0, currency: 'EUR')]);
		$mixed->add(['key' => 'shirt', 'price' => new Price(price: 20.0, currency: 'USD')]);
		$this->assertFalse($mixed->isOrderable());

		// An item without a price
		$open = new ProductList();
		$open->add(['key' => 'gift-wrap', 'type' => 'custom']);
		$this->assertFalse($open->isOrderable());
	}

	/**
	 * Bad input from the API is a client error. Without an explicit code Kirby
	 * answers 500, which tells the frontend the server broke.
	 */
	public function testRejectedInputIsReportedAsAClientError(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$cases = [
			'merx.cart.quantity' => fn () => $productList->updateItem('nice-shoes', ['quantity' => -1.0]),
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

	public function testUpdateItem(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$productList->updateItem('nice-shoes', ['price' => new Price(89.99)]);
		$this->assertEquals(
			89.99,
			$productList->total()->toFloat(),
		);

		$productList->updateItem('nice-shoes', ['quantity' => 2]);
		$this->assertEquals(
			179.98,
			$productList->total()->toFloat(),
		);
	}

	/**
	 * A new quantity reads the price from the page again, for volume-based
	 * pricing. The item’s quantifier has to apply to that price as well.
	 */
	public function testUpdateItemKeepsTheQuantifier(): void
	{
		$app = App::instance();
		App::$enableWhoops = false;

		new App([
			'roots' => ['index' => __DIR__ . '/kirby'],
			'site' => [
				'children' => [
					[
						'slug' => 'fabric',
						'template' => 'product',
						'content' => ['title' => 'Fabric', 'price' => 10],
					],
				],
			],
		]);

		try {
			$productList = new ProductList();
			$productList->add(['key' => 'fabric', 'page' => 'fabric', 'quantifier' => 1.5]);
			$this->assertSame(15.0, $productList->first()->price->price);

			$productList->updateItem('fabric', ['quantity' => 2.0]);
			$this->assertSame(15.0, $productList->first()->price->price);
			$this->assertSame(30.0, $productList->total()->price);
		} finally {
			App::$enableWhoops = true;
			App::instance($app);
		}
	}

	/**
	 * What `new ListItem()` would not take is refused, before it reaches the session
	 */
	public function testUpdateItemRefusesWhatItCannotStore(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);

		foreach ([
			['color' => 'red'],
			['type' => 'bogus'],
			['quantity' => 'many'],
		] as $data) {
			try {
				$productList->updateItem('socks', $data);
				$this->fail('Updated with ' . json_encode($data));
			} catch (InvalidArgumentException $exception) {
				$this->assertSame(400, $exception->getHttpCode());
			}
		}

		$this->assertSame('Socks', $productList->first()->title);
		$this->assertSame('product', $productList->first()->type);

		// The key makes the line and stays
		$productList->updateItem('socks', ['key' => 'other', 'price' => 5.0]);
		$this->assertSame(['socks'], $productList->keys());
		$this->assertSame('socks', $productList->first()->key);
		$this->assertSame(5.0, $productList->first()->price->toFloat());
	}

	public function testUpdateItemRejectsInfiniteQuantity(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'nice-shoes', 'price' => 99.99]);

		$this->expectException(Exception::class);
		$productList->updateItem('nice-shoes', ['quantity' => INF]);
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

	public function testUpdateItemThrowsOnUnknownKey(): void
	{
		$productList = new ProductList();

		$this->expectException(Exception::class);
		$productList->updateItem('does-not-exist', ['quantity' => 1.0]);
	}
}

/** 5 % off from 10 pieces, as in the volume-based pricing cookbook */
class VolumeProductPage extends ProductPage
{
	public function price(string|PricingRule|null $pricingRule = null, float|null $quantity = null, array|null $data = null): Price|null
	{
		$price = parent::price($pricingRule, $quantity, $data);
		if ($price !== null && $quantity >= 10) {
			$price->updatePrice($price->price * 0.95);
		}
		return $price;
	}
}
