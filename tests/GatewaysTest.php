<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use PHPUnit\Framework\TestCase;

final class GatewaysTest extends TestCase
{
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
