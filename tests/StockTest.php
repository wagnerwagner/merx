<?php

namespace Wagnerwagner\Merx\Tests;

use Closure;
use Kirby\Cms\App;
use Kirby\Data\Data;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\OrderPage;
use Wagnerwagner\Merx\ProductList;

/**
 * A product with a `stock` field cannot be put into the cart, or ordered, more
 * often than the field allows. In production, every order lowers it.
 */
final class StockTest extends TestCase
{
	/** Whether the gateway’s `completePayment` ran */
	public bool $paymentCompleted = false;

	protected App $app;
	protected App $kirby;
	protected string $root;

	/**
	 * Asserts that $callback throws a stock error with the given key and message
	 */
	protected function assertStockError(string $key, string $message, Closure $callback): void
	{
		try {
			$callback();
			$this->fail($key . ' was not thrown.');
		} catch (Exception $exception) {
			$this->assertSame('error.' . $key, $exception->getCode());
			$this->assertSame(400, $exception->getHttpCode());
			$this->assertSame($message, $exception->getMessage());
		}
	}

	/**
	 * Builds the test site
	 *
	 * Socks have three in stock, the shirt has no `stock` field, and the mug
	 * has one which is not a number.
	 */
	protected function boot(bool $production = false, bool $logging = false): void
	{
		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'options' => [
				'wagnerwagner.merx.logging' => $logging,
				'wagnerwagner.merx.production' => $production,
				'wagnerwagner.merx.gateways' => [
					'recorded' => [
						'completePayment' => function (OrderPage $virtualOrderPage): OrderPage {
							$this->paymentCompleted = true;
							return $virtualOrderPage;
						},
					],
				],
			],
		]);
	}

	/**
	 * Runs a whole checkout, the way `/api/shop/success` completes it
	 *
	 * @param $beforeReturn Runs while the customer is at the payment provider
	 */
	protected function checkout(Closure|null $beforeReturn = null): OrderPage
	{
		$merx = merx();
		$merx->initializeOrder(['paymentGateway' => 'recorded', 'email' => 'customer@example.com']);

		if ($beforeReturn !== null) {
			$beforeReturn();
		}

		return $merx->createOrder([
			Merx::$returnNonceParameterName => $this->kirby->session()->get(Merx::$returnNonceSessionKey),
		]);
	}

	/**
	 * Changes the stock the way an editor in the Panel would
	 */
	protected function setStock(string $slug, float $stock): void
	{
		$this->kirby->impersonate('kirby', fn () => $this->kirby->page($slug)->update(['stock' => $stock]));
	}

	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/stock';
		Dir::remove($this->root);

		$products = [
			'socks' => "Title: Socks\n\n----\n\nPrice: 10\n\n----\n\nStock: 3\n\n----\n\nUuid: socks",
			'shirt' => "Title: Shirt\n\n----\n\nPrice: 20\n\n----\n\nUuid: shirt",
			'mug' => "Title: Mug\n\n----\n\nPrice: 5\n\n----\n\nStock: on request\n\n----\n\nUuid: mug",
		];

		foreach ($products as $slug => $content) {
			F::write($this->root . '/content/' . $slug . '/product.txt', $content);
		}

		$this->paymentCompleted = false;
		$this->boot();
	}

	/**
	 * Stock as written in the product’s content file
	 */
	protected function stockOnDisk(string $slug): string|null
	{
		return Data::read($this->root . '/content/' . $slug . '/product.txt')['stock'] ?? null;
	}

	public function tearDown(): void
	{
		// The cart lives in the session, which Kirby would otherwise write to the
		// removed folder once the test is over
		$this->kirby->session()->destroy();

		App::$enableWhoops = true;
		Dir::remove($this->root);
		App::instance($this->app);
	}

	/**
	 * A personalized product has one line per name. The stock is for the
	 * product, not for each line.
	 */
	public function testAddCountsEveryLineOfAProduct(): void
	{
		$cart = cart();
		$cart->add(['key' => 'socks-anna', 'page' => 'socks', 'quantity' => 2.0, 'data' => ['name' => 'Anna']]);

		$this->assertStockError(
			'merx.cart.stock',
			'Only 3 of “Socks” left.',
			fn () => $cart->add(['key' => 'socks-ben', 'page' => 'socks', 'quantity' => 2.0, 'data' => ['name' => 'Ben']]),
		);

		$this->assertSame(['socks-anna'], $cart->keys());
	}

	/**
	 * The limit comes from the page, so nothing a client sends can lift it
	 */
	public function testAddIgnoresALimitInTheItemData(): void
	{
		$this->assertStockError(
			'merx.cart.stock',
			'Only 3 of “Socks” left.',
			fn () => cart()->add(['page' => 'socks', 'quantity' => 4.0, 'data' => ['stock' => 99, 'maxQuantity' => 99]]),
		);
	}

	public function testAddRefusesASoldOutProduct(): void
	{
		$this->setStock('socks', 0);

		$this->assertStockError(
			'merx.cart.soldOut',
			'“Socks” is sold out.',
			fn () => cart()->add(['page' => 'socks']),
		);
	}

	public function testAddRefusesMoreThanTheStock(): void
	{
		$cart = cart();
		$cart->add(['page' => 'socks', 'quantity' => 3.0]);

		$this->assertStockError(
			'merx.cart.stock',
			'Only 3 of “Socks” left.',
			fn () => $cart->add(['page' => 'socks', 'quantity' => 1.0]),
		);

		$this->assertSame(3.0, $cart->quantity());
	}

	public function testCreateOrderChecksTheStockBeforeThePaymentIsCompleted(): void
	{
		cart()->add(['page' => 'socks', 'quantity' => 3.0]);

		// Somebody else buys one while the customer is at the payment provider
		$this->assertStockError(
			'merx.cart.stock',
			'Only 2 of “Socks” left.',
			fn () => $this->checkout(fn () => $this->setStock('socks', 2)),
		);

		$this->assertFalse($this->paymentCompleted);
		$this->assertSame(0, $this->kirby->site()->ordersPage()?->children()->count() ?? 0);

		// The customer can correct the cart and try again
		$this->assertSame(3.0, cart()->quantity());
	}

	public function testCreateOrderLeavesTheStockAloneOutsideProduction(): void
	{
		cart()->add(['page' => 'socks', 'quantity' => 2.0]);
		$this->checkout();

		$this->assertTrue($this->paymentCompleted);
		$this->assertSame('3', $this->stockOnDisk('socks'));
	}

	public function testCreateOrderReducesTheStockInProduction(): void
	{
		$this->boot(production: true);

		$cart = cart();
		$cart->add(['key' => 'socks-anna', 'page' => 'socks', 'quantity' => 1.0]);
		$cart->add(['key' => 'socks-ben', 'page' => 'socks', 'quantity' => 1.0]);
		$cart->add(['page' => 'shirt', 'quantity' => 5.0]);

		$orderPage = $this->checkout();

		$this->assertSame(1.0, (float)$this->stockOnDisk('socks'));
		$this->assertNull($this->stockOnDisk('shirt'));
		$this->assertCount(3, $orderPage->cart());
	}

	public function testEmptyOrNonNumericStockIsUnlimited(): void
	{
		$this->assertNull($this->kirby->page('shirt')->stock());
		$this->assertNull($this->kirby->page('mug')->stock());

		$cart = cart();
		$cart->add(['page' => 'shirt', 'quantity' => 100.0]);
		$cart->add(['page' => 'mug', 'quantity' => 100.0]);

		$this->assertSame(200.0, $cart->quantity());
	}

	public function testInitializeOrderRefusesMoreThanTheStock(): void
	{
		cart()->add(['page' => 'socks', 'quantity' => 3.0]);

		// The cart sat in the session while the stock ran low
		$this->setStock('socks', 2);

		$this->assertStockError(
			'merx.cart.stock',
			'Only 2 of “Socks” left.',
			fn () => merx()->initializeOrder(['paymentGateway' => 'recorded']),
		);
	}

	/**
	 * An order lists what was ordered, even when the product is sold out now
	 */
	public function testOrderOfASoldOutProductCanBeRead(): void
	{
		$this->setStock('socks', 0);

		$orderPage = new OrderPage([
			'slug' => 'order',
			'content' => ['items' => "-\n  key: socks\n  page: page://socks\n  type: product\n  quantity: 5\n  price: 10\n"],
		]);

		$this->assertSame(5.0, $orderPage->cart()->quantity());
	}

	public function testReduceStockLogsAnOversoldProduct(): void
	{
		$this->boot(production: true, logging: true);

		$productList = new ProductList([
			['key' => 'socks', 'page' => 'socks', 'quantity' => 4.0],
		]);
		$this->kirby->impersonate('kirby', fn () => $productList->reduceStock());

		$this->assertSame(-1.0, (float)$this->stockOnDisk('socks'));

		$log = F::read($this->root . '/site/logs/' . date('Y-m-d') . '-merx.log');
		$this->assertStringContainsString('Stock of “Socks” is -1: more were sold than there were.', $log);
	}

	/**
	 * Another checkout may have lowered the stock since this request read it
	 */
	public function testReduceStockReadsTheStockFromDisk(): void
	{
		$productList = new ProductList([
			['key' => 'socks', 'page' => 'socks', 'quantity' => 1.0],
		]);
		$this->assertSame(3.0, $productList->first()->page->stock());

		F::write(
			$this->root . '/content/socks/product.txt',
			"Title: Socks\n\n----\n\nPrice: 10\n\n----\n\nStock: 2\n\n----\n\nUuid: socks",
		);

		$this->kirby->impersonate('kirby', fn () => $productList->reduceStock());

		$this->assertSame(1.0, (float)$this->stockOnDisk('socks'));
	}

	public function testUpdateItemWhichDoesNotRaiseTheQuantityGoesThrough(): void
	{
		$cart = cart();
		$cart->add(['key' => 'socks-anna', 'page' => 'socks', 'quantity' => 3.0]);

		$this->setStock('socks', 1);

		// The next request reads the cart from the session again
		$cart = cart();

		// The customer has to be able to bring the cart back within the stock
		$cart->updateItem('socks-anna', ['data' => ['name' => 'Anna']]);
		$cart->updateItem('socks-anna', ['quantity' => 2.0]);
		$this->assertSame(2.0, $cart->quantity());

		$this->assertStockError(
			'merx.cart.stock',
			'Only 1 of “Socks” left.',
			fn () => $cart->updateItem('socks-anna', ['quantity' => 3.0]),
		);

		$this->assertSame(2.0, $cart->quantity());
	}
}
