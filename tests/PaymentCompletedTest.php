<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\OrderPage;

/**
 * `wagnerwagner.merx.paymentCompleted` has to fire for both ways an order can
 * be paid: right away, when the gateway completes the payment before the order
 * page is written, and later, when the payment provider reports the payment
 * through a webhook.
 */
final class PaymentCompletedTest extends TestCase
{
	protected App $kirby;

	protected string $root;

	/** Slugs of the orders the hook was triggered for */
	public array $triggered = [];

	public function setUp(): void
	{
		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/payment-completed';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		$this->triggered = [];
		$test = $this;

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'hooks' => [
				'wagnerwagner.merx.paymentCompleted' => function (OrderPage $orderPage) use ($test): void {
					$test->triggered[] = $orderPage->slug();
				},
			],
		]);
	}

	public function tearDown(): void
	{
		App::$enableWhoops = true;
		Dir::remove($this->root);
	}

	/**
	 * Writes an order page the way `Merx::createOrder()` does
	 */
	protected function createOrder(string $slug, array $content): OrderPage
	{
		return $this->kirby->impersonate('kirby', function () use ($slug, $content): OrderPage {
			$ordersPage = $this->kirby->site()->ordersPage() ?? $this->kirby->site()->createChild([
				'slug' => 'orders',
				'template' => 'orders',
				'draft' => false,
				'content' => ['title' => 'Orders'],
			]);

			/** @var OrderPage $orderPage */
			$orderPage = $ordersPage->createChild([
				'slug' => $slug,
				'template' => 'order',
				'model' => 'order',
				'draft' => false,
				'content' => [
					'dateCreated' => date('c'),
					...$content,
				],
			]);

			return $orderPage->changeStatus('listed');
		});
	}

	public function testFiresForOrderWhichIsPaidOnCreation(): void
	{
		// PayPal and Stripe Elements complete the payment before the order page
		// is written, so the order is created as a paid one.
		$this->createOrder('paid-on-creation', [
			'paymentGateway' => 'paypal',
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]);

		$this->assertSame(['paid-on-creation'], $this->triggered);
	}

	public function testFiresOnceForOrderWhichIsPaidOnCreation(): void
	{
		$orderPage = $this->createOrder('paid-on-creation', [
			'paymentGateway' => 'stripe-elements',
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]);

		// A Stripe webhook for a payment which was already completed updates the
		// order a second time. It must not be reported as a second payment.
		$this->kirby->impersonate('kirby', fn () => $orderPage->update([
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]));

		$this->assertSame(['paid-on-creation'], $this->triggered);
	}

	public function testFiresForOrderWhichIsPaidLater(): void
	{
		$orderPage = $this->createOrder('paid-later', [
			'paymentGateway' => 'invoice',
		]);

		$this->assertSame([], $this->triggered);

		$this->kirby->impersonate('kirby', fn () => $orderPage->update([
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]));

		$this->assertSame(['paid-later'], $this->triggered);
	}
}
