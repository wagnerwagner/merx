<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\OrderPage;

/**
 * `wagnerwagner.merx.paymentCompleted` has to fire for both ways an order can
 * be paid: right away, when the gateway completes the payment before the order
 * page is written, and later, when the payment provider reports the payment
 * through a webhook.
 */
final class PaymentCompletedTest extends TestCase
{
	/** Whether the hooks write to the order they are given */
	public bool $hooksUpdateOrder = false;

	/** Orders the hook was triggered for, with their status at that moment */
	public array $triggered = [];

	protected App $app;
	protected App $kirby;
	protected string $root;

	/**
	 * Runs a whole checkout, the way `/api/shop/success` completes it
	 */
	protected function checkout(string $paymentGateway): OrderPage
	{
		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);

		$merx = merx();
		$merx->initializeOrder(['paymentGateway' => $paymentGateway, 'email' => 'customer@example.com']);

		return $merx->createOrder([
			Merx::$returnNonceParameterName => $this->kirby->session()->get(Merx::$returnNonceSessionKey),
		]);
	}

	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/payment-completed';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		$this->triggered = [];
		$this->hooksUpdateOrder = false;
		$test = $this;

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'options' => [
				'wagnerwagner.merx.logging' => false,
				'wagnerwagner.merx.gateways' => [
					// Completes the payment before the order page is written, the way
					// PayPal and Stripe Elements do
					'paid-right-away' => [
						'completePayment' => function (OrderPage $virtualOrderPage): OrderPage {
							$virtualOrderPage->version()->update([
								'paymentComplete' => true,
								'datePaid' => date('c'),
							]);
							return $virtualOrderPage;
						},
					],
				],
			],
			'hooks' => [
				'wagnerwagner.merx.paymentCompleted' => [
					function (OrderPage $orderPage) use ($test): void {
						$test->triggered[] = $orderPage->status();
					},
					function (OrderPage $orderPage) use ($test): void {
						if ($test->hooksUpdateOrder === true) {
							$orderPage->update(['invoiceNumber' => 'R-1']);
						}
					},
					function (OrderPage $orderPage) use ($test): void {
						if ($test->hooksUpdateOrder === true) {
							$orderPage->update(['stockReduced' => 'true']);
						}
					},
				],
				'wagnerwagner.merx.createOrder:after' => function (OrderPage $orderPage) use ($test): void {
					if ($test->hooksUpdateOrder === true) {
						$orderPage->update(['mailSent' => 'true']);
					}
				},
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

	public function testCreateOrderReturnsTheOrderWhichTheHooksUpdated(): void
	{
		$this->hooksUpdateOrder = true;

		$orderPage = $this->checkout('paid-right-away');

		$this->assertSame('true', $orderPage->content()->get('mailSent')->value());

		// Updating an outdated copy would remove the order from disk
		$orderPage = $this->kirby->impersonate('kirby', fn () => $orderPage->update([
			'shipped' => 'true',
		]));

		$this->assertFileExists($orderPage->root() . '/order.txt');
		$this->assertStringContainsString('Shipped: true', file_get_contents($orderPage->root() . '/order.txt'));
	}

	public function testFiresForOrderWhichIsPaidLater(): void
	{
		$orderPage = $this->checkout('invoice');

		$this->assertSame([], $this->triggered);

		$this->kirby->impersonate('kirby', fn () => $orderPage->update([
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]));

		$this->assertSame(['listed'], $this->triggered);
	}

	public function testFiresForOrderWhichIsPaidOnCreation(): void
	{
		$this->checkout('paid-right-away');

		// The order is listed by then: the hook runs after the order is written,
		// not while other checkouts wait for it
		$this->assertSame(['listed'], $this->triggered);
	}

	public function testFiresOnceForOrderWhichIsPaidOnCreation(): void
	{
		$orderPage = $this->checkout('paid-right-away');

		// A Stripe webhook for a payment which was already completed updates the
		// order a second time. It must not be reported as a second payment.
		$this->kirby->impersonate('kirby', fn () => $orderPage->update([
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]));

		$this->assertSame(['listed'], $this->triggered);
	}

	public function testKeepsOrderWhichTheHooksUpdate(): void
	{
		// Each hook updates the order it is given. When a hook is handed a copy
		// which an earlier hook has already updated, Kirby throws and removes the
		// order’s content from disk while it does.
		$this->hooksUpdateOrder = true;

		$orderPage = $this->checkout('paid-right-away');

		$this->assertFileExists($orderPage->root() . '/order.txt');

		$content = file_get_contents($orderPage->root() . '/order.txt');
		$this->assertStringContainsString('Invoicenumber: R-1', $content);
		$this->assertStringContainsString('Stockreduced: true', $content);
		$this->assertStringContainsString('Mailsent: true', $content);

		$this->assertSame(['listed'], $this->triggered);
	}

	public function testKeepsOrderWhichTheHooksUpdateWhenPaidLater(): void
	{
		$this->hooksUpdateOrder = true;

		$orderPage = $this->checkout('invoice');

		// The Stripe webhook reports the payment in a request of its own, which
		// reads the order from disk
		$this->kirby->site()->purge();
		$orderPage = $this->kirby->page($orderPage->id());

		$orderPage = $this->kirby->impersonate('kirby', fn () => $orderPage->update([
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]));

		$this->assertFileExists($orderPage->root() . '/order.txt');

		$content = file_get_contents($orderPage->root() . '/order.txt');
		$this->assertStringContainsString('Invoicenumber: R-1', $content);
		$this->assertStringContainsString('Stockreduced: true', $content);

		// `update()` returns the order with what the hooks wrote
		$this->assertSame('R-1', $orderPage->content()->get('invoiceNumber')->value());

		$this->assertSame(['listed'], $this->triggered);
	}
}
