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
}
