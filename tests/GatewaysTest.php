<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Exception\Exception;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

final class GatewaysTest extends TestCase
{
	protected App $app;

	private static function orderPageWithCart(float $price, string $currency = 'EUR'): OrderPage
	{
		$listItems = new ListItems([
			'nice-shoes' => new ListItem(
				key: 'nice-shoes',
				price: new Price(price: $price, currency: $currency),
			),
		]);

		return new OrderPage([
			'slug' => 'probe-order',
			'template' => 'order',
			'content' => ['items' => $listItems->toYaml()],
		]);
	}

	private static function payPalOrderWithUnits(array ...$units): array
	{
		return [
			'id' => '5O190127TN364715T',
			'status' => 'APPROVED',
			'purchase_units' => array_map(
				fn (array $u): array => ['amount' => ['value' => $u[0], 'currency_code' => $u[1] ?? 'EUR']],
				$units,
			),
		];
	}

	private static function payPalResponse(string $status, array $captureStatus = ['COMPLETED']): array
	{
		return [
			'id' => '5O190127TN364715T',
			'status' => $status,
			'purchase_units' => [
				[
					'payments' => [
						'captures' => array_map(fn (string $state) => ['status' => $state], $captureStatus),
					],
				],
			],
		];
	}

	/**
	 * Answers every request to the Stripe API with one PaymentIntent
	 *
	 * @param $orderUid `order_uid` in the metadata of the PaymentIntent
	 * @return OrderPage Order which the PaymentIntent pays for
	 */
	private function stripePaymentIntent(string|null $orderUid): OrderPage
	{
		$this->app->clone(['options' => [
			'wagnerwagner.merx.production' => false,
			'wagnerwagner.merx.stripe.test.secret_key' => 'sk_test_probe',
		]]);

		$body = json_encode([
			'id' => 'pi_probe',
			'object' => 'payment_intent',
			'amount' => 4999,
			'currency' => 'eur',
			'status' => 'succeeded',
			'livemode' => false,
			'metadata' => $orderUid === null ? [] : ['order_uid' => $orderUid],
		]);

		ApiRequestor::setHttpClient(new class ($body) implements ClientInterface {
			public function __construct(private string $body)
			{
			}

			public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
			{
				return [$this->body, 200, new CaseInsensitiveArray([])];
			}
		});

		$orderPage = self::orderPageWithCart(49.99);
		$orderPage->version()->update(['stripePaymentIntentId' => 'pi_probe']);

		return $orderPage;
	}

	/**
	 * Some tests clone the App to set options. `clone()` makes the clone the
	 * global instance, so it has to be put back — a leftover clone with
	 * `production` on sends later tests at the live payment APIs. Kirby also
	 * installs Whoops’ handlers with every instance and leaves them behind,
	 * which PHPUnit reports as risky.
	 */
	public function testAGatewaySetToFalseIsSwitchedOff(): void
	{
		$this->assertSame(['invoice', 'paypal', 'stripe'], array_keys(Gateways::enabled()));

		$this->app->clone(['options' => [
			'wagnerwagner.merx.gateways' => ['invoice' => false, 'prepayment' => true],
		]]);

		$this->assertSame(['paypal', 'stripe', 'prepayment'], array_keys(Gateways::enabled()));
	}

	public function setUp(): void
	{
		App::$enableWhoops = false;
		$this->app = App::instance();
	}

	public function tearDown(): void
	{
		ApiRequestor::setHttpClient(null);
		App::instance($this->app);
		App::$enableWhoops = true;
	}

	/**
	 * The payment was captured for this order, and writing the order failed
	 * afterwards. Reloading the return url has to complete it.
	 */
	public function testAStripePaymentCapturedForThisOrderCompletesIt(): void
	{
		$orderPage = Gateways::completeStripePayment($this->stripePaymentIntent('probe-order'), []);

		$this->assertTrue($orderPage->isPaid());
	}

	public function testAStripePaymentOfAnotherOrderIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::completeStripePayment($this->stripePaymentIntent('another-order'), []);
	}

	public function testAStripePaymentWithoutAnOrderCompletesIt(): void
	{
		$orderPage = Gateways::completeStripePayment($this->stripePaymentIntent(null), []);

		$this->assertTrue($orderPage->isPaid());
	}

	public function testAMissingPayPalClientIdIsRefused(): void
	{
		$this->app->clone(['options' => [
			'wagnerwagner.merx.production' => false,
			'wagnerwagner.merx.paypal.sandbox.clientID' => '',
			'wagnerwagner.merx.paypal.sandbox.secret' => 'secret',
		]]);

		$this->expectException(Exception::class);
		Gateways::validatePayPalCredentials();
	}

	public function testAMissingPayPalSecretIsRefused(): void
	{
		// One key alone is not enough — the old check only complained about both
		$this->app->clone(['options' => [
			'wagnerwagner.merx.production' => false,
			'wagnerwagner.merx.paypal.sandbox.clientID' => 'id',
			'wagnerwagner.merx.paypal.sandbox.secret' => '',
		]]);

		$this->expectException(Exception::class);
		Gateways::validatePayPalCredentials();
	}

	public function testCapturedPaymentIsPaid(): void
	{
		$this->assertTrue(
			Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED')),
		);
	}

	public function testConfiguredPayPalCredentialsPass(): void
	{
		$this->app->clone(['options' => [
			'wagnerwagner.merx.production' => false,
			'wagnerwagner.merx.paypal.sandbox.clientID' => 'id',
			'wagnerwagner.merx.paypal.sandbox.secret' => 'secret',
		]]);

		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalCredentials();
	}

	public function testCurrencyMismatchIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(4999, 'usd', self::orderPageWithCart(49.99, 'EUR'));
	}

	public function testDeclinedCaptureIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED', ['DECLINED']));
	}

	public function testFailedCaptureIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED', ['COMPLETED', 'FAILED']));
	}

	public function testMatchingAmountPasses(): void
	{
		$this->expectNotToPerformAssertions();
		Gateways::validatePaymentAmount(4999, 'eur', self::orderPageWithCart(49.99));
	}

	public function testMissingStatusIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture([]);
	}

	public function testOffByOneCentIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(4998, 'eur', self::orderPageWithCart(49.99));
	}

	public function testPartlyPendingCaptureIsNotPaidYet(): void
	{
		$this->assertFalse(
			Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED', ['COMPLETED', 'PENDING'])),
		);
	}

	public function testPayerActionRequiredOrderIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('PAYER_ACTION_REQUIRED'));
	}

	public function testPayPalCurrencyMismatchIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['49.99', 'USD']),
			self::orderPageWithCart(49.99, 'EUR'),
		);
	}

	public function testPayPalOrderChargingTooLittleIsRefused(): void
	{
		// A `paypal.purchaseUnits` callback which does not match the cart
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['10.00']),
			self::orderPageWithCart(110.00),
		);
	}

	public function testPayPalOrderCoveringTheCartPasses(): void
	{
		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['49.99']),
			self::orderPageWithCart(49.99),
		);
	}

	public function testPayPalOrderWithoutPurchaseUnitsIsRefused(): void
	{
		// Fail closed rather than capturing an order Merx cannot read
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderAmount(
			['id' => '5O190127TN364715T', 'status' => 'APPROVED'],
			self::orderPageWithCart(49.99),
		);
	}

	public function testPayPalPurchaseUnitsAreAddedUp(): void
	{
		// PayPal charges the sum, so the sum is what has to match
		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['40.00'], ['9.99']),
			self::orderPageWithCart(49.99),
		);
	}

	public function testPendingCaptureIsNotPaidYet(): void
	{
		$this->assertFalse(
			Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED', ['PENDING'])),
		);
	}

	public function testPendingOrderIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('PENDING'));
	}

	public function testProductionChecksTheLiveKeys(): void
	{
		// Sandbox configured, live not — production must still complain
		$this->app->clone(['options' => [
			'wagnerwagner.merx.production' => true,
			'wagnerwagner.merx.paypal.sandbox.clientID' => 'id',
			'wagnerwagner.merx.paypal.sandbox.secret' => 'secret',
		]]);

		$this->expectException(Exception::class);
		Gateways::validatePayPalCredentials();
	}

	public function testTheDefaultEmptyKeysAreRefused(): void
	{
		// Merx defaults both to '', which the old `=== null` check never caught
		$this->app->clone(['options' => ['wagnerwagner.merx.production' => false]]);

		$this->expectException(Exception::class);
		Gateways::validatePayPalCredentials();
	}

	public function testTooHighAmountIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(11000, 'eur', self::orderPageWithCart(49.99));
	}

	public function testTooLowAmountIsRefused(): void
	{
		// Cart grew after the client secret was fetched
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(1000, 'eur', self::orderPageWithCart(110.00));
	}

	public function testVoidedOrderIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('VOIDED'));
	}
}
