<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\OrderPage;
use Wagnerwagner\Merx\PayPalPayment;

final class PayPalPaymentTest extends TestCase
{
	public function tearDown(): void
	{
		App::$enableWhoops = true;
	}

	/**
	 * Kirby instance with the given `wagnerwagner.merx.paypal.purchaseUnits`
	 */
	protected function app(array|null $purchaseUnits = null): App
	{
		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		return new App([
			'options' => $purchaseUnits === null ? [] : [
				'wagnerwagner.merx.paypal.purchaseUnits' => fn (): array => $purchaseUnits,
			],
		]);
	}

	/**
	 * Order page with a single 100.00 EUR item
	 */
	protected function orderPage(): OrderPage
	{
		return new OrderPage([
			'slug' => 'test-order',
			'content' => [
				'items' => Yaml::encode([
					[
						'key' => 'nice-shoes',
						'title' => 'Nice Shoes',
						'page' => '',
						'price' => 100.0,
						'currency' => 'EUR',
						'quantity' => 1.0,
						'type' => 'product',
					],
				]),
			],
		]);
	}

	/**
	 * The amount PayPal charges: the sum of all purchase units
	 */
	protected function chargedAmount(array $purchaseUnits): float
	{
		return array_sum(array_map(
			fn (array $purchaseUnit): float => (float)$purchaseUnit['amount']['value'],
			$purchaseUnits,
		));
	}

	public function testPurchaseUnitIsCartTotal(): void
	{
		$this->app();

		$purchaseUnits = PayPalPayment::purchaseUnits($this->orderPage(), 'EUR');

		$this->assertCount(1, $purchaseUnits);
		$this->assertSame('100.00', $purchaseUnits[0]['amount']['value']);
		$this->assertSame('EUR', $purchaseUnits[0]['amount']['currency_code']);
		$this->assertSame(100.0, $this->chargedAmount($purchaseUnits));
	}

	public function testOptionReplacesPurchaseUnit(): void
	{
		// What `Cart::payPalPurchaseUnits()` returns: a complete purchase unit,
		// total included. Merging it with Merx’ own unit would have the payer
		// approve 200.00 for a cart of 100.00.
		$this->app([
			[
				'description' => 'Custom purchase unit',
				'amount' => [
					'value' => '100.00',
					'currency_code' => 'EUR',
				],
			],
		]);

		$purchaseUnits = PayPalPayment::purchaseUnits($this->orderPage(), 'EUR');

		$this->assertCount(1, $purchaseUnits);
		$this->assertSame('Custom purchase unit', $purchaseUnits[0]['description']);
		$this->assertSame(100.0, $this->chargedAmount($purchaseUnits));
	}

	public function testEmptyOptionKeepsMerxPurchaseUnit(): void
	{
		// The default option returns an empty array
		$this->app([]);

		$purchaseUnits = PayPalPayment::purchaseUnits($this->orderPage(), 'EUR');

		$this->assertCount(1, $purchaseUnits);
		$this->assertSame(100.0, $this->chargedAmount($purchaseUnits));
	}

	public function testCustomIdNamesTheOrder(): void
	{
		$this->app();

		$purchaseUnits = PayPalPayment::purchaseUnits($this->orderPage(), 'EUR');

		$this->assertSame('test-order', $purchaseUnits[0]['custom_id']);
	}

	public function testCustomIdIsSetOnPurchaseUnitsFromOption(): void
	{
		// `Gateways::validatePayPalOrderIsUnused()` refuses a returning payer whose
		// PayPal order does not name this order, so a `custom_id` of the option
		// must not be able to break the replay protection.
		$this->app([
			[
				'custom_id' => 'something-else',
				'amount' => ['value' => '60.00', 'currency_code' => 'EUR'],
			],
			[
				'amount' => ['value' => '40.00', 'currency_code' => 'EUR'],
			],
		]);

		$purchaseUnits = PayPalPayment::purchaseUnits($this->orderPage(), 'EUR');

		$this->assertCount(2, $purchaseUnits);
		$this->assertSame('test-order', $purchaseUnits[0]['custom_id']);
		$this->assertSame('test-order', $purchaseUnits[1]['custom_id']);
	}
}
