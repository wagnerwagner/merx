<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Stripe\Event;
use Wagnerwagner\Merx\OrderPage;

/**
 * The Stripe webhook is the only path which completes a payment the customer’s
 * browser never confirms, e.g. SEPA debit. It writes `paymentComplete`, so it
 * has to be sure the event is about the order it is writing to.
 */
final class StripeWebhookTest extends TestCase
{
	protected App $kirby;

	protected string $root;

	public function setUp(): void
	{
		App::$enableWhoops = false;

		$this->root = __DIR__ . '/kirby/stripe-webhook';
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
		App::$enableWhoops = true;
		Dir::remove($this->root);
	}

	protected function createOrder(string $slug, string|null $paymentIntentId): OrderPage
	{
		return $this->kirby->impersonate('kirby', function () use ($slug, $paymentIntentId): OrderPage {
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
					'paymentGateway' => 'stripe-elements',
					'paymentComplete' => false,
					'stripePaymentIntentId' => $paymentIntentId,
				],
			]);

			return $orderPage->changeStatus('listed');
		});
	}

	protected function fireWebhook(string $paymentIntentId, string|null $orderUid): void
	{
		$event = Event::constructFrom([
			'id' => 'evt_1',
			'type' => 'payment_intent.succeeded',
			'data' => [
				'object' => [
					'id' => $paymentIntentId,
					'object' => 'payment_intent',
					'amount' => 4999,
					'currency' => 'eur',
					'status' => 'succeeded',
					'created' => 1757492400,
					'livemode' => false,
					'metadata' => $orderUid === null ? [] : ['order_uid' => $orderUid],
				],
			],
		]);

		$this->kirby->trigger('wagnerwagner.merx.stripe-hooks', ['stripeEvent' => $event]);
	}

	protected function isPaid(string $slug): bool
	{
		$this->kirby->site()->purge();
		return page('orders/' . $slug)?->paymentComplete()->toBool() === true;
	}

	public function testMarksTheOrderOfThePaymentAsPaid(): void
	{
		$this->createOrder('an-order', 'pi_matching');
		$this->fireWebhook('pi_matching', 'an-order');

		$this->assertTrue($this->isPaid('an-order'));
	}

	public function testIgnoresAPaymentIntentWhichBelongsToAnotherOrder(): void
	{
		$this->createOrder('an-order', 'pi_of_this_order');
		$this->fireWebhook('pi_of_a_different_one', 'an-order');

		$this->assertFalse($this->isPaid('an-order'));
	}

	public function testIgnoresAnOrderWithoutAPaymentIntent(): void
	{
		$this->createOrder('an-order', null);
		$this->fireWebhook('pi_matching', 'an-order');

		$this->assertFalse($this->isPaid('an-order'));
	}

	public function testSurvivesAnUnknownOrder(): void
	{
		$this->createOrder('an-order', 'pi_matching');
		$this->fireWebhook('pi_matching', 'no-such-order');

		$this->assertFalse($this->isPaid('an-order'));
	}

	public function testSurvivesAnEventWithoutAnOrderUid(): void
	{
		$this->createOrder('an-order', 'pi_matching');
		$this->fireWebhook('pi_matching', null);

		$this->assertFalse($this->isPaid('an-order'));
	}

	public function testIgnoresAPageWhichIsNotAnOrder(): void
	{
		$this->createOrder('an-order', 'pi_matching');

		$this->kirby->impersonate('kirby', function (): void {
			$this->kirby->site()->ordersPage()->createChild([
				'slug' => 'not-an-order',
				'template' => 'default',
				'draft' => false,
				'content' => ['title' => 'Not an order'],
			])->changeStatus('listed');
		});

		$this->fireWebhook('pi_matching', 'not-an-order');

		$this->kirby->site()->purge();
		$page = page('orders/not-an-order');
		$this->assertNotInstanceOf(OrderPage::class, $page);
		$this->assertNull($page->content()->get('paymentComplete')->value());
	}
}
