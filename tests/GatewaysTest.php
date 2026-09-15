<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

final class GatewaysTest extends TestCase
{
	protected App $app;

	/**
	 * Some tests clone the App to set options. `clone()` makes the clone the
	 * global instance, so it has to be put back — a leftover clone with
	 * `production` on sends later tests at the live payment APIs. Kirby also
	 * installs Whoops’ handlers with every instance and leaves them behind,
	 * which PHPUnit reports as risky.
	 */
	public function setUp(): void
	{
		App::$enableWhoops = false;
		$this->app = App::instance();
	}

	public function tearDown(): void
	{
		App::instance($this->app);
		App::$enableWhoops = true;
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

	public function testCapturedPaymentIsPaid(): void
	{
		$this->assertTrue(
			Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED'))
		);
	}

	public function testPendingOrderIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('PENDING'));
	}

	public function testVoidedOrderIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('VOIDED'));
	}

	public function testPayerActionRequiredOrderIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture(self::payPalResponse('PAYER_ACTION_REQUIRED'));
	}

	public function testMissingStatusIsRejected(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalCapture([]);
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

	public function testPendingCaptureIsNotPaidYet(): void
	{
		$this->assertFalse(
			Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED', ['PENDING']))
		);
	}

	public function testPartlyPendingCaptureIsNotPaidYet(): void
	{
		$this->assertFalse(
			Gateways::validatePayPalCapture(self::payPalResponse('COMPLETED', ['COMPLETED', 'PENDING']))
		);
	}


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

	public function testMatchingAmountPasses(): void
	{
		$this->expectNotToPerformAssertions();
		Gateways::validatePaymentAmount(4999, 'eur', self::orderPageWithCart(49.99));
	}

	public function testTooLowAmountIsRefused(): void
	{
		// Cart grew after the client secret was fetched
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(1000, 'eur', self::orderPageWithCart(110.00));
	}

	public function testTooHighAmountIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(11000, 'eur', self::orderPageWithCart(49.99));
	}

	public function testOffByOneCentIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(4998, 'eur', self::orderPageWithCart(49.99));
	}

	public function testCurrencyMismatchIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePaymentAmount(4999, 'usd', self::orderPageWithCart(49.99, 'EUR'));
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

	public function testTheDefaultEmptyKeysAreRefused(): void
	{
		// Merx defaults both to '', which the old `=== null` check never caught
		$this->app->clone(['options' => ['wagnerwagner.merx.production' => false]]);

		$this->expectException(Exception::class);
		Gateways::validatePayPalCredentials();
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

	public function testPayPalOrderCoveringTheCartPasses(): void
	{
		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['49.99']),
			self::orderPageWithCart(49.99)
		);
	}

	public function testPayPalOrderChargingTooLittleIsRefused(): void
	{
		// A `paypal.purchaseUnits` callback which does not match the cart
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['10.00']),
			self::orderPageWithCart(110.00)
		);
	}

	public function testPayPalPurchaseUnitsAreAddedUp(): void
	{
		// PayPal charges the sum, so the sum is what has to match
		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['40.00'], ['9.99']),
			self::orderPageWithCart(49.99)
		);
	}

	public function testPayPalCurrencyMismatchIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderAmount(
			self::payPalOrderWithUnits(['49.99', 'USD']),
			self::orderPageWithCart(49.99, 'EUR')
		);
	}

	public function testPayPalOrderWithoutPurchaseUnitsIsRefused(): void
	{
		// Fail closed rather than capturing an order Merx cannot read
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderAmount(
			['id' => '5O190127TN364715T', 'status' => 'APPROVED'],
			self::orderPageWithCart(49.99)
		);
	}

	private static function virtualOrderPage(string $uid = 'aaaabbbbccccdddd'): OrderPage
	{
		return new OrderPage([
			'slug' => $uid,
			'template' => 'order',
		]);
	}

	private static function payPalOrder(string $status, ?string $customId = 'aaaabbbbccccdddd'): array
	{
		$purchaseUnit = ['amount' => ['value' => '49.99', 'currency_code' => 'EUR']];
		if ($customId !== null) {
			$purchaseUnit['custom_id'] = $customId;
		}

		return [
			'id' => '5O190127TN364715T',
			'status' => $status,
			'purchase_units' => [$purchaseUnit],
		];
	}

	public function testApprovedOrderMayBeCaptured(): void
	{
		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalOrderIsUnused(self::payPalOrder('APPROVED'), self::virtualOrderPage());
	}

	public function testAlreadyCapturedOrderIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderIsUnused(self::payPalOrder('COMPLETED'), self::virtualOrderPage());
	}

	public function testOrderOfAnotherOrderPageIsRefused(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderIsUnused(
			self::payPalOrder('APPROVED', 'someoneelsesorder'),
			self::virtualOrderPage()
		);
	}

	public function testOrderWithoutCustomIdIsAllowed(): void
	{
		// Created before `custom_id` was set; the status check still covers it.
		$this->expectNotToPerformAssertions();
		Gateways::validatePayPalOrderIsUnused(
			self::payPalOrder('APPROVED', null),
			self::virtualOrderPage()
		);
	}

	public function testOrderWithoutCustomIdIsStillRefusedWhenCaptured(): void
	{
		$this->expectException(Exception::class);
		Gateways::validatePayPalOrderIsUnused(
			self::payPalOrder('COMPLETED', null),
			self::virtualOrderPage()
		);
	}
}
