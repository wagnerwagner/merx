<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\OrderPage;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\PricingRule;
use Wagnerwagner\Merx\ProductList;

/**
 * Merx writes every key of an item itself, so `cart()` always reads back its
 * own output. An order migrated from Merx 1 or edited by hand carries fewer
 * keys, and has to be read all the same.
 */
final class OrderPageCartTest extends TestCase
{
	protected App $app;
	protected App $kirby;

	/**
	 * Reads items, and turns the warning a missing key raises into a failure.
	 * The suite silences warnings, `debug` turns them into exceptions.
	 */
	protected function cart(string $items): ProductList
	{
		set_error_handler(function (int $no, string $message): bool {
			throw new RuntimeException($message);
		});

		try {
			$orderPage = new OrderPage([
				'slug' => 'test-order',
				'content' => ['items' => $items],
			]);

			return $orderPage->cart();
		} finally {
			restore_error_handler();
		}
	}

	public function setUp(): void
	{
		App::$enableWhoops = false;
		$this->app = App::instance();

		$this->kirby = new App([
			'roots' => ['index' => __DIR__ . '/kirby'],
			'site' => [
				'children' => [
					[
						'slug' => 'home',
						'content' => ['title' => 'Home'],
					],
					[
						'slug' => 'nice-shoes',
						'template' => 'product',
						'content' => ['title' => 'Nice Shoes', 'price' => 100],
					],
				],
			],
		]);
	}

	public function tearDown(): void
	{
		App::$enableWhoops = true;
		App::instance($this->app);
	}

	/**
	 * An empty page means no page. `page('')` returns the home page.
	 */
	public function testItemWithEmptyPage(): void
	{
		$cart = $this->cart("-\n  key: shipping\n  page: ''\n  type: shipping\n  price: 4.9\n");

		$this->assertNull($cart->first()->page);
	}

	/**
	 * An item without a page falls back to the page named by its key
	 */
	public function testItemWithoutPage(): void
	{
		$cart = $this->cart("-\n  key: nice-shoes\n  type: product\n  price: 100\n");

		$this->assertSame('nice-shoes', $cart->first()->page?->id());
	}

	/**
	 * An item without a price has none. It must not take the page’s.
	 */
	public function testItemWithoutPrice(): void
	{
		$cart = $this->cart("-\n  key: note\n  page: nice-shoes\n  type: custom\n");

		$this->assertNull($cart->first()->price);
	}

	/**
	 * A page Merx wrote itself is still used
	 */
	public function testItemWithPage(): void
	{
		$cart = $this->cart("-\n  key: other-key\n  page: nice-shoes\n  type: product\n  price: 100\n");

		$this->assertSame('nice-shoes', $cart->first()->page?->id());
	}

	/**
	 * An order placed under a pricing rule with net prices shows net prices
	 */
	public function testNetPricesStayNet(): void
	{
		$listItems = new ProductList([
			new ListItem(
				key: 'board',
				price: new Price(
					price: 45.0,
					tax: 0.08,
					pricingRule: new PricingRule(key: 'us', currency: 'USD', taxIncluded: false),
				),
				quantity: 2.0,
			),
		]);

		$cart = $this->cart($listItems->toYaml());
		$price = $cart->first()->price;

		$this->assertFalse($price->taxIncluded());
		$this->assertSame(45.0, $price->priceNet);
		$this->assertSame(48.6, $price->price);
		$this->assertSame('USD', $price->currency);
		$this->assertSame(97.2, $cart->total()->price);
		$this->assertSame(90.0, $cart->total()->priceNet);
	}

	public function testPricesIncludingTaxStayGross(): void
	{
		$listItems = new ProductList([
			new ListItem(key: 'board', price: new Price(price: 49.0, tax: 0.19, currency: 'EUR')),
		]);

		$price = $this->cart($listItems->toYaml())->first()->price;

		$this->assertNotFalse($price->taxIncluded());
		$this->assertSame(49.0, $price->price);
		$this->assertSame(41.18, $price->priceNet);
	}

	/**
	 * An order stores each price with its quantifier already applied, so
	 * reading it back must not apply the quantifier again. The payment amount
	 * is checked against this total.
	 */
	public function testQuantifierIsAppliedOnce(): void
	{
		$productList = new ProductList([
			new ListItem(key: 'fabric', title: 'Fabric', price: 10.0, quantifier: 1.5, type: 'custom'),
		]);

		$cart = $this->cart($productList->toYaml());

		$this->assertSame(15.0, $cart->first()->price?->price);
		$this->assertSame(1.5, $cart->first()->quantifier);
		$this->assertSame($productList->total()->price, $cart->total()?->price);
	}

	/**
	 * An item without a price does not stop the others from adding up
	 */
	public function testTotalWithPricelessItem(): void
	{
		$cart = $this->cart(
			"-\n  key: nice-shoes\n  page: nice-shoes\n  type: product\n  price: 100\n  currency: EUR\n" .
			"-\n  key: note\n  page: ''\n  type: custom\n",
		);

		$this->assertSame(100.0, $cart->total()?->price);
	}
}
