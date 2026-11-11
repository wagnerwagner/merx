<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\OrderPage;

/**
 * `initializeOrder()` writes the order as a draft, `createOrder()` publishes
 * it. Nothing of the order lives in the session, so a customer may return
 * from the payment in another browser or after their session has ended.
 */
final class OrderDraftTest extends TestCase
{
	/** How often the gateway completed a payment */
	public int $completed = 0;

	protected App $app;
	protected App $kirby;
	protected string $root;

	protected function boot(array $languages = []): void
	{
		$test = $this;

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'languages' => $languages,
			'options' => [
				'wagnerwagner.merx.logging' => false,
				'wagnerwagner.merx.gateways' => [
					'probe' => [
						'completePayment' => function (OrderPage $virtualOrderPage) use ($test): OrderPage {
							$test->completed++;
							return $virtualOrderPage;
						},
					],
				],
			],
		]);
	}

	/**
	 * Starts a checkout
	 *
	 * @return array{order: string} What the return url names the order by
	 */
	protected function checkout(string $email = 'customer@example.com'): array
	{
		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);

		$redirect = merx()->initializeOrder([
			'paymentGateway' => 'probe',
			'email' => $email,
		]);

		$query = [];
		parse_str(parse_url($redirect, PHP_URL_QUERY) ?? '', $query);

		return [
			Merx::$orderParameterName => $query[Merx::$orderParameterName],
		];
	}

	protected function drafts(): array
	{
		$ordersPage = $this->kirby->site()->ordersPage();
		$ordersPage?->purge();

		return $ordersPage?->drafts()->keys() ?? [];
	}

	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/order-draft';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		$this->completed = 0;
		$this->boot();
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

	public function testACheckoutAfterACompletedOrderIsANewOrder(): void
	{
		$first = merx()->createOrder($this->checkout());
		$second = merx()->createOrder($this->checkout());

		$this->assertNotSame($first->uid(), $second->uid());
		$this->assertSame(2, $this->kirby->site()->ordersPage()->children()->listed()->count());
		$this->assertSame([], $this->drafts());
	}

	public function testANewCheckoutReplacesTheDraftOfTheSession(): void
	{
		$first = $this->checkout();
		$second = $this->checkout();

		$this->assertSame(['orders/' . $second['order']], $this->drafts());

		// The return url of the first attempt completes nothing any more
		$this->expectException(Exception::class);
		$this->expectExceptionCode('error.merx.invalidReturn');
		merx()->createOrder($first);
	}

	public function testAReturnInAnotherSessionCompletesTheOrder(): void
	{
		$return = $this->checkout();

		// Another browser: no cookie, no cart, no session
		$this->kirby->session()->destroy();
		$this->boot();

		$orderPage = merx()->createOrder($return);

		$this->assertSame($return['order'], $orderPage->uid());
		$this->assertSame('listed', $orderPage->status());
		$this->assertSame('customer@example.com', $orderPage->email()->value());
		$this->assertSame(1, $this->completed);
	}

	public function testAReturnToACompletedOrderGetsThatOrder(): void
	{
		$return = $this->checkout();

		$first = merx()->createOrder($return);
		$second = merx()->createOrder($return);

		$this->assertSame($first->uid(), $second->uid());
		$this->assertSame(1, $this->completed);
		$this->assertSame(1, $this->kirby->site()->ordersPage()->children()->count());
	}

	public function testAnOrderWhichDoesNotExistIsRefused(): void
	{
		$this->checkout();

		foreach (['../../site', 'aaaabbbbccccdddd', ''] as $uid) {
			try {
				merx()->createOrder([Merx::$orderParameterName => $uid]);
				$this->fail('An order was created for “' . $uid . '”');
			} catch (Exception $exception) {
				$this->assertSame('error.merx.invalidReturn', $exception->getKey());
				$this->assertSame(400, $exception->getHttpCode());
			}
		}

		$this->assertSame(0, $this->completed);
	}

	/**
	 * Orders are written in the default language, whatever the customer browses in
	 */
	public function testCheckoutInAnotherLanguage(): void
	{
		$this->kirby->session()->destroy();
		$this->boot([
			['code' => 'de', 'default' => true],
			['code' => 'en'],
		]);
		$this->kirby->setCurrentLanguage('en');

		$return = $this->checkout();

		// Not mistaken for an old draft, which has no content in English
		$this->assertSame(['orders/' . $return['order']], $this->drafts());

		$orderPage = merx()->createOrder($return);

		$this->assertSame('listed', $orderPage->status());
		$this->assertFileExists($orderPage->root() . '/order.de.txt');
		$this->assertFileDoesNotExist($orderPage->root() . '/order.en.txt');
	}

	public function testInitializeOrderWritesADraft(): void
	{
		$return = $this->checkout();

		$draft = $this->kirby->site()->ordersPage()->draft($return['order']);

		$this->assertInstanceOf(OrderPage::class, $draft);
		$this->assertSame('customer@example.com', $draft->email()->value());
		$this->assertCount(1, $draft->cart());

		// The order is not in the session, and not among the orders yet
		$this->assertNull($this->kirby->session()->get('wagnerwagner.merx.virtualOrderPage'));
		$this->assertSame(0, $this->kirby->site()->ordersPage()->children()->count());
	}

	public function testOldDraftsAreDeleted(): void
	{
		$abandoned = $this->checkout('abandoned@example.com');

		// Another customer, a week and a day later
		$this->kirby->session()->destroy();
		$this->boot();
		$draft = $this->kirby->site()->ordersPage()->draft($abandoned['order']);
		touch($draft->root() . '/order.txt', time() - 8 * 24 * 60 * 60);

		$recent = $this->checkout();

		$this->assertSame(['orders/' . $recent['order']], $this->drafts());
	}
}
