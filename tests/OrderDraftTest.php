<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use Kirby\Http\Url;
use Kirby\Toolkit\I18n;
use PHPUnit\Framework\TestCase;
use Stripe\Event;
use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\OrderPage;
use Wagnerwagner\Merx\Price;

/**
 * `initializeOrder()` writes the order as a draft, `createOrder()` publishes
 * it. Nothing of the order lives in the session, so a customer may return
 * from the payment in another browser or after their session has ended.
 */
final class OrderDraftTest extends TestCase
{
	/** How often the gateway completed a payment */
	public int $completed = 0;

	/** Language a hook of the test ran in */
	public string|null $languageOfHook = null;

	/** Language and translation the hooks of the test ran in, by hook */
	public array $localesOfHooks = [];

	protected App $app;
	protected App $kirby;
	protected string $root;

	protected function boot(array $languages = [], array $hooks = []): void
	{
		$test = $this;

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'languages' => $languages,
			'hooks' => $hooks,
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

	/**
	 * What `/api/shop/hooks/stripe` does with a verified event
	 *
	 * @param $deleteCart false keeps the cart of the session: the webhook is a request of Stripe, which has none
	 * @param $orderUid `order_uid` in the metadata, which the gateway sets when it captures
	 */
	protected function fireWebhook(string $paymentIntentId, bool $deleteCart = true, string|null $orderUid = null): void
	{
		$event = Event::constructFrom([
			'id' => 'evt_1',
			'type' => 'payment_intent.succeeded',
			'data' => [
				'object' => [
					'id' => $paymentIntentId,
					'object' => 'payment_intent',
					'status' => 'succeeded',
					'metadata' => $orderUid === null ? [] : ['order_uid' => $orderUid],
				],
			],
		]);

		$cart = $this->kirby->session()->get('wagnerwagner.merx.cartItems');
		$draft = $this->kirby->session()->get(Merx::$orderDraftSessionKey);

		$this->kirby->trigger('wagnerwagner.merx.stripe-hooks', ['stripeEvent' => $event]);

		if ($deleteCart === false) {
			$this->kirby->session()->set('wagnerwagner.merx.cartItems', $cart);
			$this->kirby->session()->set(Merx::$orderDraftSessionKey, $draft);
		}
	}

	protected function linkPaymentIntent(string $uid, string $paymentIntentId): void
	{
		$this->kirby->impersonate('kirby', function () use ($uid, $paymentIntentId): void {
			$draft = $this->kirby->site()->ordersPage()->draft($uid);
			// Like the gateway, which writes it before the draft is saved in the default language
			$draft->save(['stripePaymentIntentId' => $paymentIntentId], $this->kirby->defaultLanguage()?->code());
		});
		$this->kirby->site()->ordersPage()->purge();
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
		$this->localesOfHooks = [];
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

	/**
	 * A draft has no order number yet. The Panel shows it as incomplete, in
	 * the language of the user, instead of with an empty title.
	 */
	public function testADraftIsTitledIncomplete(): void
	{
		$return = $this->checkout();
		$draft = $this->kirby->site()->ordersPage()->draft($return['order']);

		$this->assertSame('Incomplete', $draft->title()->value());

		$this->kirby->setCurrentTranslation('de');
		$this->assertSame('Unvollständig', $draft->title()->value());
		$this->kirby->setCurrentTranslation('en');

		$orderPage = merx()->createOrder($return);
		$this->assertSame((string)$orderPage->orderNumber(), $orderPage->title()->value());
	}

	public function testAFailedCheckoutKeepsTheDraftOfTheSession(): void
	{
		$first = $this->checkout();

		try {
			merx()->initializeOrder(['paymentGateway' => 'probe', 'email' => 'not an email']);
			$this->fail('The checkout should fail');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.fieldsvalidation', $exception->getKey());
		}

		$this->assertSame($first['order'], $this->kirby->session()->get(Merx::$orderDraftSessionKey));

		// Replaced by the next checkout instead of being left behind
		$second = $this->checkout();
		$this->assertSame(['orders/' . $second['order']], $this->drafts());
	}

	/**
	 * A client error, e.g. a return which is refused, is a warning
	 */
	public function testAFailedOrderIsLoggedByWhatWentWrong(): void
	{
		$return = $this->checkout();
		$this->kirby->session()->destroy();
		$this->kirby = $this->kirby->clone([
			'roots' => ['logs' => $this->root . '/logs'],
			'options' => [
				'wagnerwagner.merx.logging' => true,
				'wagnerwagner.merx.gateways' => ['probe' => ['completePayment' => function () {
				}]],
			],
		]);
		$log = fn (): string => implode('', array_map('file_get_contents', glob($this->root . '/logs/*-merx.log')));

		try {
			merx()->createOrder([Merx::$orderParameterName => 'doesnotexist']);
		} catch (Exception) {
		}
		$this->assertStringContainsString('] WARNING ', $log());
		$this->assertStringNotContainsString('] ERROR ', $log());

		try {
			merx()->createOrder($return);
		} catch (Exception) {
		}
		$this->assertStringContainsString('] ERROR ', $log());
	}

	public function testAForgedFieldNameDoesNotPayTheOrder(): void
	{
		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);

		// Kirby writes a key as its slug, `datePaid_` as `datePaid`
		$redirect = merx()->initializeOrder([
			'paymentGateway' => 'probe',
			'email' => 'customer@example.com',
			'datePaid_' => '2026-01-01 00:00:00',
			'paymentDetails-' => 'status: succeeded',
			'items_' => 'forged cart',
		]);
		$query = [];
		parse_str(parse_url($redirect, PHP_URL_QUERY) ?? '', $query);
		$orderPage = merx()->createOrder($query);

		$this->assertFalse($orderPage->isPaid());
		$this->assertTrue($orderPage->content()->get('paymentDetails')->isEmpty());
		$this->assertSame('socks', $orderPage->cart()->first()->key);
		$this->assertSame('probe', $orderPage->paymentGateway()->value());
	}

	/**
	 * A coupon may cover the whole cart
	 */
	public function testAFreeOrderIsCompleted(): void
	{
		new Cart([
			['key' => 'socks', 'title' => 'Socks', 'price' => 10.0],
			['key' => 'coupon', 'title' => 'Coupon', 'type' => 'discount', 'price' => -10.0],
		]);

		$redirect = merx()->initializeOrder(['paymentGateway' => 'probe', 'email' => 'customer@example.com']);
		$query = [];
		parse_str(parse_url($redirect, PHP_URL_QUERY) ?? '', $query);
		$orderPage = merx()->createOrder($query);

		$this->assertSame('listed', $orderPage->status());
		$this->assertSame(0.0, $orderPage->total()->price);
	}

	public function testAGatewayWhichReturnsNothingIsAnInternalError(): void
	{
		$return = $this->checkout();
		$this->kirby->session()->destroy();
		$this->kirby = $this->kirby->clone(['options' => [
			'wagnerwagner.merx.gateways' => [
				'probe' => ['completePayment' => function () {
				}],
			],
		]]);

		try {
			merx()->createOrder($return);
			$this->fail('The order was completed');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.createOrder', $exception->getKey());
			$this->assertSame(500, $exception->getHttpCode());
		}
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

	/**
	 * `prev()` and `next()` in an order template must not lead to the
	 * orders of other customers
	 */
	public function testAnOrderHasNoSiblingsForAVisitor(): void
	{
		$first = merx()->createOrder($this->checkout());
		$second = merx()->createOrder($this->checkout());

		$this->assertSame([$second->id()], $second->siblings()->keys());
		$this->assertNull($second->prev());
		$this->assertNull($first->next());

		$siblings = $this->kirby->impersonate('kirby', fn () => $second->siblings()->keys());
		$this->assertSame([$first->id(), $second->id()], $siblings);
	}

	/**
	 * A hook runs in the language of the customer. What it writes belongs
	 * into the one content file of the order.
	 */
	public function testAnOrderIsUpdatedInTheDefaultLanguage(): void
	{
		$this->kirby->session()->destroy();
		$this->boot(
			[
				['code' => 'de', 'default' => true],
				['code' => 'en'],
			],
			[
				'wagnerwagner.merx.createOrder:after' => function (OrderPage $orderPage): OrderPage {
					return $orderPage->update(['datePaid' => '2026-11-11 12:00:00', 'note' => 'From the hook']);
				},
			],
		);
		$this->kirby->setCurrentLanguage('en');
		cart()->add(['key' => 'gloves', 'title' => 'Gloves', 'price' => ['price' => 20.0, 'tax' => 0.19]]);

		$orderPage = merx()->createOrder($this->checkout());

		$this->assertSame('en', $this->kirby->languageCode());
		$this->assertTrue($orderPage->isPaid());
		$this->assertFileDoesNotExist($orderPage->root() . '/order.en.txt');
		$this->assertSame('From the hook', $orderPage->content('de')->get('note')->value());
		// Kept by the update of the hook, which runs in the customer’s language
		$this->assertSame(0.19, $orderPage->cart()->get('gloves')->price->tax?->rate);
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
	 * The webhook was faster than the customer. They get their order, and
	 * the cart they ordered is emptied.
	 */
	public function testAReturnAfterTheWebhookEmptiesTheCart(): void
	{
		$return = $this->checkout();
		$this->linkPaymentIntent($return['order'], 'pi_paid');

		$this->fireWebhook('pi_paid', deleteCart: false);
		$this->assertSame(1, cart()->count());

		$orderPage = merx()->createOrder($return);

		$this->assertSame($return['order'], $orderPage->uid());
		$this->assertSame(1, $this->completed);
		$this->assertSame(0, cart()->count());
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

	/**
	 * The return url names the order and nothing else. The customer gets
	 * the order in the language they ordered in.
	 */
	public function testAReturnUsesTheLanguageOfTheOrder(): void
	{
		$languages = [
			['code' => 'de', 'default' => true],
			['code' => 'en'],
		];

		$this->kirby->session()->destroy();
		$this->boot($languages);
		$this->kirby->setCurrentLanguage('en');

		$return = $this->checkout();
		$this->assertSame(['order'], array_keys($return + Url::toObject(Merx::returnUrl())->query()->toArray()));

		$this->kirby->session()->destroy();
		$this->boot($languages);
		$this->assertSame('de', $this->kirby->languageCode());

		$orderPage = merx()->createOrder($return);
		$this->assertSame('en', $this->kirby->languageCode());
		$this->assertStringContainsString('/en/', $orderPage->url());

		// And again, to the order which is completed
		$this->kirby->session()->destroy();
		$this->boot($languages);
		merx()->createOrder($return);
		$this->assertSame('en', $this->kirby->languageCode());
	}

	/**
	 * A payment which fails in the browser, e.g. at 3-D Secure, leaves the
	 * PaymentIntent in place. Submitting the checkout again uses it as well.
	 */
	public function testASecondCheckoutKeepsTheStripePaymentIntent(): void
	{
		$this->boot();
		$this->kirby->session()->set('wagnerwagner.merx.stripePaymentIntentId', 'pi_retry');
		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);

		foreach ([1, 2] as $attempt) {
			merx()->initializeOrder(['paymentGateway' => 'stripe', 'email' => 'customer@example.com']);
			$draft = $this->kirby->site()->ordersPage()->drafts()->first();
			$this->assertSame('pi_retry', $draft->stripePaymentIntentId()->value(), 'Attempt ' . $attempt);
		}
	}

	/**
	 * The payment was captured for the order, and writing the order failed
	 * afterwards. The webhook completes the draft rather than leaving it behind.
	 */
	public function testAStripeWebhookCompletesADraftWhosePaymentWasCaptured(): void
	{
		$return = $this->checkout();
		$this->linkPaymentIntent($return['order'], 'pi_paid');

		$this->fireWebhook('pi_paid', orderUid: $return['order']);

		$this->assertSame(1, $this->completed);
		$this->assertSame([], $this->drafts());
		$this->assertSame('listed', $this->kirby->site()->ordersPage()->find($return['order'])?->status());
	}

	/**
	 * The customer paid and closed the browser: Stripe’s webhook is the only
	 * one who tells the shop
	 */
	public function testAStripeWebhookCompletesTheDraftOfItsPayment(): void
	{
		$return = $this->checkout();
		$this->linkPaymentIntent($return['order'], 'pi_paid');

		// Stripe has no cookie
		$this->kirby->session()->destroy();
		$this->boot();

		$this->fireWebhook('pi_of_another_shop');
		$this->assertSame(0, $this->completed);

		$this->fireWebhook('pi_paid');
		$this->fireWebhook('pi_paid');

		$this->assertSame(1, $this->completed);
		$this->assertSame([], $this->drafts());
		$this->assertSame('listed', $this->kirby->site()->ordersPage()->find($return['order'])?->status());
	}

	/**
	 * Stripe’s request has no language. The hooks of an order the webhook
	 * completes run in the one the customer ordered in.
	 */
	public function testAStripeWebhookCompletesTheOrderInTheLanguageOfTheCustomer(): void
	{
		$languages = [
			['code' => 'de', 'default' => true],
			['code' => 'en'],
		];

		$this->kirby->session()->destroy();
		$this->boot($languages);
		$this->kirby->setCurrentLanguage('en');

		$return = $this->checkout();
		$this->linkPaymentIntent($return['order'], 'pi_paid');

		$test = $this;

		$this->kirby->session()->destroy();
		$this->boot($languages, [
			// Kirby binds a hook to the App, so `$this` is not the test
			'wagnerwagner.merx.createOrder:after' => function () use ($test) {
				$test->languageOfHook = kirby()->languageCode();
			},
		]);
		$this->assertSame('de', $this->kirby->languageCode());

		$this->fireWebhook('pi_paid');

		$this->assertSame('en', $this->languageOfHook);
		$this->assertSame('en', $this->kirby->site()->ordersPage()->find($return['order'])->content('de')->get('language')->value());
	}

	/**
	 * A hook may hand back the item it wants added, e.g. one line per name
	 */
	public function testCartAddBeforeMayChangeTheItem(): void
	{
		$this->kirby->session()->destroy();
		$this->boot(hooks: [
			'wagnerwagner.merx.cart.add:before' => function ($cart, $data) {
				if (is_array($data) === true && isset($data['data']['name']) === true) {
					$data['key'] = $data['key'] . '-' . strtolower($data['data']['name']);
					return $data;
				}
			},
		]);

		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0, 'data' => ['name' => 'Anna']]);
		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0, 'data' => ['name' => 'Ben']]);
		// A hook which returns nothing leaves the item as it is
		cart()->add(['key' => 'shirt', 'title' => 'Shirt', 'price' => 20.0]);

		$this->assertSame(['socks-anna', 'socks-ben', 'shirt'], cart()->keys());
	}

	/**
	 * A price in another currency is refused, not dropped
	 */
	public function testCartRefusesAnItemInAnotherCurrency(): void
	{
		$cart = cart();
		$cart->add(['key' => 'socks', 'title' => 'Socks', 'price' => new Price(price: 10.0, currency: 'EUR')]);
		$cart->add(['key' => 'gift-wrap', 'title' => 'Gift wrap', 'type' => 'custom']);

		try {
			$cart->add(['key' => 'shipping', 'title' => 'Shipping', 'type' => 'shipping', 'price' => new Price(price: 5.0, currency: 'USD')]);
			$this->fail('A price in another currency was added');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.mixedCurrencies.add', $exception->getKey());
			$this->assertSame(400, $exception->getHttpCode());
		}

		$this->assertSame(['socks', 'gift-wrap'], cart()->keys());
		$this->assertSame('EUR', cart()->currency());
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

	/**
	 * `t()` in a hook answers in the language of the customer, too. For the
	 * sorting number of an order Kirby clones the App, which sets the
	 * translation back to the default language.
	 */
	public function testHooksRunInTheTranslationOfTheCustomer(): void
	{
		$languages = [
			['code' => 'de', 'default' => true],
			['code' => 'en'],
		];

		$this->kirby->session()->destroy();
		$this->boot($languages);
		$this->kirby->setCurrentLanguage('en');

		$return = $this->checkout();

		$test = $this;
		$locales = fn (): array => [kirby()->languageCode(), I18n::locale()];

		// The return url has no language: the request starts in the default one
		$this->kirby->session()->destroy();
		$this->boot($languages, [
			// Paid right away, the way PayPal and Stripe complete a payment
			'wagnerwagner.merx.createOrder:before' => function (OrderPage $virtualOrderPage) {
				$virtualOrderPage->version()->update(['datePaid' => date('c')]);
			},
			'wagnerwagner.merx.orderPaid' => function () use ($test, $locales) {
				$test->localesOfHooks['orderPaid'] = $locales();
			},
			'wagnerwagner.merx.createOrder:after' => function () use ($test, $locales) {
				$test->localesOfHooks['createOrder:after'] = $locales();
			},
		]);
		$this->assertSame(['de', 'de'], $locales());

		merx()->createOrder($return);

		$this->assertSame([
			'orderPaid' => ['en', 'en'],
			'createOrder:after' => ['en', 'en'],
		], $this->localesOfHooks);
		$this->assertSame(['en', 'en'], $locales());
	}

	/**
	 * Two currencies, e.g. a shipping line which was added before the
	 * customer switched the currency, or a discount worth more than the cart
	 */
	public function testInitializeOrderRefusesACartWhichIsNotOrderable(): void
	{
		$carts = [
			[
				['key' => 'socks', 'title' => 'Socks', 'price' => new Price(price: 10.0, currency: 'EUR')],
				['key' => 'shipping', 'title' => 'Shipping', 'type' => 'shipping', 'price' => new Price(price: 5.0, currency: 'USD')],
			],
			[
				['key' => 'socks', 'title' => 'Socks', 'price' => 10.0],
				['key' => 'coupon', 'title' => 'Coupon', 'type' => 'discount', 'price' => -15.0],
			],
		];

		foreach ($carts as $items) {
			cart()->delete();
			new Cart($items);

			try {
				merx()->initializeOrder(['paymentGateway' => 'probe', 'email' => 'customer@example.com']);
				$this->fail('A cart which is not orderable was ordered');
			} catch (Exception $exception) {
				$this->assertSame('error.merx.cart.notOrderable', $exception->getKey());
				$this->assertSame(400, $exception->getHttpCode());
			}
		}

		$this->assertSame([], $this->drafts());
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

	/**
	 * An API request without a language answers in the default language of
	 * the site, like its content does, and not in English.
	 */
	public function testTheApiAnswersInTheDefaultLanguage(): void
	{
		$this->boot([
			['code' => 'de', 'default' => true],
			['code' => 'en'],
		]);

		$this->kirby->api()->call('shop/cart', 'GET');
		$this->assertSame('de', I18n::locale());

		$this->kirby->api()->call('shop/cart', 'GET', ['query' => ['language' => 'en']]);
		$this->assertSame('en', I18n::locale());

		// The `locale` option only counts on a site without languages
		$this->kirby = $this->kirby->clone(['options' => ['locale' => 'fr_FR.utf-8']]);
		$this->kirby->api()->call('shop/cart', 'GET');
		$this->assertSame('de', I18n::locale());
	}

	/**
	 * A site without languages has no default one: its `locale` option decides
	 */
	public function testTheApiOfASiteWithoutLanguagesAnswersInTheLanguageOfItsLocale(): void
	{
		$expected = [
			'de_DE.utf-8' => 'de',
			'pt_BR.utf-8' => 'pt_BR',
			'en_US.utf-8' => 'en',
			// No translation for it
			'C' => 'en',
		];

		foreach ($expected as $locale => $translation) {
			$this->kirby = $this->kirby->clone(['options' => ['locale' => $locale]]);
			$this->kirby->api()->call('shop/cart', 'GET');
			$this->assertSame($translation, I18n::locale(), $locale);
		}

		$this->kirby->api()->call('shop/cart', 'GET', ['query' => ['language' => 'fr']]);
		$this->assertSame('fr', I18n::locale());
	}

	public function testWhatCompletePaymentReturnsIsWritten(): void
	{
		$this->kirby->session()->destroy();
		$this->kirby = $this->kirby->clone(['options' => [
			'wagnerwagner.merx.gateways' => [
				'probe' => [
					// A copy, not the page it got
					'completePayment' => fn (OrderPage $virtualOrderPage): OrderPage => $virtualOrderPage->clone([
						'content' => ['datePaid' => '2026-11-11 12:00:00'] + $virtualOrderPage->content()->toArray(),
					]),
				],
			],
		]]);

		$orderPage = merx()->createOrder($this->checkout());

		$this->assertTrue($orderPage->isPaid());
	}
}
