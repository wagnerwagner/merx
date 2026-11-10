<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\PricingRule;
use Wagnerwagner\Merx\ProductList;
use Wagnerwagner\Merx\ProductPage;

/**
 * A desk whose price depends on the wood the customer selected
 */
class DeskPage extends ProductPage
{
	public function price(string|PricingRule|null $pricingRule = null, float|null $quantity = null, array|null $data = null): Price|null
	{
		$surcharge = ($data['wood'] ?? null) === 'walnut' ? 150.0 : 0.0;

		return new Price(price: 400.0 + $surcharge, currency: 'EUR');
	}
}

/**
 * The price of a cart line may depend on its data, e.g. a configuration
 */
final class PriceFromDataTest extends TestCase
{
	protected App $app;
	protected string $root;

	/**
	 * An instance with a folder of its own: Kirby caches the UUID of a page on
	 * disk, which must not end up wherever the test before left its instance.
	 */
	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/price-from-data';
		Dir::remove($this->root);

		new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
		]);
	}

	public function tearDown(): void
	{
		App::$enableWhoops = true;
		Dir::remove($this->root);
		App::instance($this->app);
	}

	private static function desk(): DeskPage
	{
		// With a UUID of its own, so Kirby does not create one and write it to disk
		return new DeskPage([
			'slug' => 'desk',
			'template' => 'product',
			'content' => ['uuid' => 'desk'],
		]);
	}

	public function testChangingTheDataChangesThePrice(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'desk', 'page' => self::desk(), 'data' => ['wood' => 'oak']]);
		$this->assertSame(400.0, $productList->get('desk')->price->price);

		$productList->updateItem('desk', ['data' => ['wood' => 'walnut']]);
		$this->assertSame(550.0, $productList->get('desk')->price->price);
	}

	public function testChangingTheQuantityKeepsThePriceOfTheData(): void
	{
		$productList = new ProductList();
		$productList->add(['key' => 'desk', 'page' => self::desk(), 'data' => ['wood' => 'walnut']]);

		$productList->updateItem('desk', ['quantity' => 2.0]);

		$this->assertSame(550.0, $productList->get('desk')->price->price);
		$this->assertSame(1100.0, $productList->total()->toFloat());
	}

	public function testPriceDependsOnTheDataOfTheLine(): void
	{
		$oak = new ListItem(key: 'desk-oak', page: self::desk(), data: ['wood' => 'oak']);
		$walnut = new ListItem(key: 'desk-walnut', page: self::desk(), data: ['wood' => 'walnut']);
		$plain = new ListItem(key: 'desk', page: self::desk());

		$this->assertSame(400.0, $oak->price->price);
		$this->assertSame(550.0, $walnut->price->price);
		$this->assertSame(400.0, $plain->price->price);
	}

	/**
	 * The session stores the data, not the price, of an item with a page. The
	 * price is worked out again from both with every request.
	 */
	public function testPriceIsRebuiltFromTheSession(): void
	{
		$listItem = new ListItem(key: 'desk-walnut', page: self::desk(), data: ['wood' => 'walnut']);
		$stored = $listItem->toSessionArray();

		$this->assertArrayNotHasKey('price', $stored);
		$this->assertSame(['wood' => 'walnut'], $stored['data']);

		$stored['page'] = self::desk();
		$restored = new ListItem(...$stored);

		$this->assertSame(550.0, $restored->price->price);
	}
}
