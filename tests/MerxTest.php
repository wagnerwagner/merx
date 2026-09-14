<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class MerxTest extends TestCase
{
	public function testFormatCurrency(): void
	{
		$this->assertEquals(
			'€10.20',
			Merx::formatCurrency(10.20, 'EUR')
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
			Merx::formatCurrency(10.20, 'EUR')
		);
	}
	public function testFormatCurrency1(): void
	{
		setlocale(LC_ALL, 'en_US');
		$this->assertEquals(
			'$11.99',
			Merx::formatCurrency(11.99, 'USD')
		);
	}

	public function testFormatIBAN(): void
	{
		$this->assertEquals(
			'DE89 3704 0044 0532 0130 00',
			Merx::formatIBAN('DE89370400440532013000')
		);
	}

	public function testCalculateTax(): void
	{
		$this->assertEquals(
			31.932773109243698,
			Merx::calculateTax(200, 0.19)
		);
	}

	public function testCart(): void
	{
		$merx = new Merx();
		$this->assertInstanceOf(
			Cart::class,
			$merx->cart()
		);
	}

	public function testinitializeOrderEmpty(): void
	{
		$merx = new Merx();
		$this->expectExceptionCode('error.merx.noPaymentGateway');
		$merx->initializeOrder([]);
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
			'paymentComplete' => true,
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


	public function testFilterOrderDataIsCaseInsensitive(): void
	{
		// Kirby lower cases content keys, so these all address `paymentComplete`.
		$data = Merx::filterOrderData([
			'PaymentComplete' => true,
			'PAYMENTCOMPLETE' => true,
			'paymentcomplete' => true,
			'PayPalOrderId' => 'forged',
			'email' => 'customer@example.com',
		]);

		$this->assertSame(['email' => 'customer@example.com'], $data);
	}


	public function testReturnUrlCarriesASessionTokenAndANonce(): void
	{
		$query = [];
		parse_str(parse_url(Merx::returnUrl(), PHP_URL_QUERY) ?? '', $query);

		$this->assertArrayHasKey(Merx::$sessionTokenParameterName, $query);
		$this->assertArrayHasKey(Merx::$returnNonceParameterName, $query);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $query[Merx::$returnNonceParameterName]);
	}


	public function testReturnUrlKeepsTheNonceWithinOneCheckout(): void
	{
		// `returnUrl()` is called more than once while a payment is set up
		$this->assertSame(
			parse_url(Merx::returnUrl(), PHP_URL_QUERY),
			parse_url(Merx::returnUrl(), PHP_URL_QUERY)
		);
	}


	public function testCreateOrderRefusesAReturnWithoutTheNonce(): void
	{
		$kirby = kirby();
		$kirby->session()->set(Merx::$returnNonceSessionKey, 'a1b2c3');
		$kirby->session()->set('wagnerwagner.merx.virtualOrderPage', [
			'slug' => 'probe-order',
			'template' => 'order',
			'content' => ['paymentGateway' => 'invoice'],
		]);

		$merx = new Merx();
		$this->expectExceptionCode('error.merx.invalidReturn');
		$merx->createOrder(['PayerID' => 'forged']);
	}


	public function testCreateOrderRefusesAReturnWithTheWrongNonce(): void
	{
		$kirby = kirby();
		$kirby->session()->set(Merx::$returnNonceSessionKey, 'a1b2c3');
		$kirby->session()->set('wagnerwagner.merx.virtualOrderPage', [
			'slug' => 'probe-order',
			'template' => 'order',
			'content' => ['paymentGateway' => 'invoice'],
		]);

		$merx = new Merx();
		$this->expectExceptionCode('error.merx.invalidReturn');
		$merx->createOrder([Merx::$returnNonceParameterName => 'wrong']);
	}


	public function testExceptionDetailsAreHiddenWithoutDebug(): void
	{
		$this->assertSame(
			[],
			Merx::exceptionDetails(new \RuntimeException('internal failure'))
		);
	}
}
