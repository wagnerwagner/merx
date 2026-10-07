<?php

namespace Wagnerwagner\Merx\Tests;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\OrderPage;

/**
 * The default `orderNumber` counts on from the last listed order, so the order
 * folders have to sort in the order the orders were created. With a sorting
 * number in seconds, orders created in the same second sorted by their random
 * slug, and the next order got a number which was already taken.
 */
final class OrderNumberTest extends TestCase
{
	protected App $app;
	protected App $kirby;
	protected string $root;

	/**
	 * Runs a whole checkout, the way `/api/shop/success` completes it
	 */
	protected function checkout(): OrderPage
	{
		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);

		$merx = merx();
		$merx->initializeOrder(['paymentGateway' => 'invoice', 'email' => 'customer@example.com']);

		$orderPage = $merx->createOrder([
			Merx::$returnNonceParameterName => $this->kirby->session()->get(Merx::$returnNonceSessionKey),
		]);

		// The next checkout is another request, which reads the orders from disk
		$this->kirby->site()->purge();

		return $orderPage;
	}

	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/order-number';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'options' => [
				'wagnerwagner.merx.logging' => false,
			],
		]);
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

	public function testOrdersCreatedInQuickSuccessionGetConsecutiveNumbers(): void
	{
		$numbers = [];
		for ($i = 0; $i < 10; $i++) {
			$numbers[] = $this->checkout()->orderNumber()->toInt();
		}

		$this->assertSame(range(1, 10), $numbers);
	}

	public function testOrdersSortInTheOrderTheyWereCreated(): void
	{
		for ($i = 0; $i < 10; $i++) {
			$this->checkout();
		}

		$numbers = $this->kirby->site()->ordersPage()->children()->listed()->values(
			fn (OrderPage $orderPage): int => $orderPage->orderNumber()->toInt(),
		);

		$this->assertSame(range(1, 10), $numbers);
	}

	public function testSortingNumberIsReadFromItsField(): void
	{
		$orderPage = new OrderPage([
			'slug' => 'order',
			'template' => 'order',
			'content' => [
				'dateCreated' => '2026-09-23T17:08:08+02:00',
				'sortingNumber' => 20260923150808123,
			],
		]);

		$this->assertSame(20260923150808123, $orderPage->sortingNumber());
	}

	public function testSortingNumberIsTheCreationTimeInUtcToTheMillisecond(): void
	{
		$date = new DateTimeImmutable('2026-09-23T17:08:08.123+02:00');

		$this->assertSame(20260923150808123, OrderPage::sortingNumberFromDate($date));
	}

	public function testSortingNumberOfOrderWithoutFieldComesFromDateCreated(): void
	{
		$orderPage = new OrderPage([
			'slug' => 'order',
			'template' => 'order',
			'content' => [
				'dateCreated' => '2026-09-23T17:08:08+02:00',
			],
		]);

		$this->assertSame(20260923150808000, $orderPage->sortingNumber());

		// Orders listed before the sorting number had milliseconds keep their
		// 14-digit folder number, and even the largest of those is smaller.
		$this->assertGreaterThan(99991231235959, $orderPage->sortingNumber());
	}
}
