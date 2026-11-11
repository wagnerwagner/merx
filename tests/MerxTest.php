<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class MerxTest extends TestCase
{
	public function testCalculateTax(): void
	{
		$this->assertEquals(
			31.932773109243698,
			Merx::calculateTax(200, 0.19),
		);
	}

	public function testCart(): void
	{
		$merx = new Merx();
		$this->assertInstanceOf(
			Cart::class,
			$merx->cart(),
		);
	}

	public function testErrorIsReadOnce(): void
	{
		$error = ['key' => 'error.merx.paymentCanceled', 'message' => 'Payment canceled.'];
		kirby()->session()->set(Merx::$errorSessionKey, $error);

		$merx = new Merx();

		$this->assertSame($error, $merx->error());
		$this->assertNull($merx->error());
	}

	public function testExceptionDetailsAreHiddenWithoutDebug(): void
	{
		$this->assertSame(
			[],
			Merx::exceptionDetails(new \RuntimeException('internal failure')),
		);
	}

	public function testFilterOrderDataIsCaseInsensitive(): void
	{
		// Kirby lower cases content keys, so these all address `datePaid`.
		$data = Merx::filterOrderData([
			'DatePaid' => '2026-01-01',
			'DATEPAID' => '2026-01-01',
			'datepaid' => '2026-01-01',
			'PayPalOrderId' => 'forged',
			'email' => 'customer@example.com',
		]);

		$this->assertSame(['email' => 'customer@example.com'], $data);
	}

	public function testFilterOrderDataKeepsCustomerFields(): void
	{
		$data = [
			'paymentGateway' => 'paypal',
			'email' => 'customer@example.com',
			'street' => 'Example Street 1',
			'note' => 'Please ring twice',
		];

		$this->assertSame($data, Merx::filterOrderData($data));
	}

	public function testFilterOrderDataRemovesProtectedFields(): void
	{
		$data = Merx::filterOrderData([
			'email' => 'customer@example.com',
			'datePaid' => '2026-01-01T00:00:00+00:00',
			'paymentDetails' => ['amount' => 1],
			'orderNumber' => 9999,
			'dateCreated' => '2026-01-01T00:00:00+00:00',
			'items' => 'forged cart',
			'redirect' => 'https://example.com',
			'stripePaymentIntentId' => 'pi_forged',
			'payPalOrderId' => 'forged',
			'uuid' => 'page://forged',
		]);

		$this->assertSame(['email' => 'customer@example.com'], $data);
	}

	public function testFormatCurrency(): void
	{
		$this->assertEquals(
			'€10.20',
			Merx::formatCurrency(10.20, 'EUR'),
		);
	}

	public function testFormatCurrency1(): void
	{
		setlocale(LC_ALL, 'en_US');
		$this->assertEquals(
			'$11.99',
			Merx::formatCurrency(11.99, 'USD'),
		);
	}

	public function testFormatCurrencyDE(): void
	{
		if (setlocale(LC_ALL, 'de_DE') === false) {
			$this->markTestSkipped('The de_DE locale is not installed.');
		}
		// German writes the symbol last, separated by a no-break space
		$this->assertEquals(
			"10,20\u{00A0}€",
			Merx::formatCurrency(10.20, 'EUR'),
		);
	}

	public function testFormatIBAN(): void
	{
		$this->assertEquals(
			'DE89 3704 0044 0532 0130 00',
			Merx::formatIBAN('DE89370400440532013000'),
		);
	}

	public function testinitializeOrderEmpty(): void
	{
		$merx = new Merx();
		$this->expectExceptionCode('error.merx.noPaymentGateway');
		$merx->initializeOrder([]);
	}

	public function testReturnUrlCarriesTheOrder(): void
	{
		$url = Merx::returnUrl();
		$query = [];
		parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

		$this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $query[Merx::$orderParameterName]);

		// Nothing which would let somebody else take over the visitor’s session
		$this->assertStringNotContainsString((string)kirby()->session()->token(), $url);
	}

	public function testReturnUrlKeepsTheOrderWithinOneCheckout(): void
	{
		// `returnUrl()` is called more than once while a payment is set up
		$this->assertSame(Merx::returnUrl(), Merx::returnUrl());
	}
}
