<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;
use Stripe\PaymentIntent;

final class PaymentDetailsTest extends TestCase
{
	private static function stripePaymentIntent(array $overrides = []): PaymentIntent
	{
		return PaymentIntent::constructFrom(array_merge([
			'id' => 'pi_3ABCdef',
			'object' => 'payment_intent',
			'amount' => 4999,
			'currency' => 'eur',
			'status' => 'succeeded',
			'created' => 1757492400,
			'latest_charge' => 'ch_3ABCdef',
			'livemode' => true,
			// Everything below must never be stored
			'client_secret' => 'pi_3ABCdef_secret_XyZ123',
			'customer' => 'cus_ABC',
			'receipt_email' => 'customer@example.com',
			'description' => 'Order 1234',
			'shipping' => [
				'name' => 'Erika Mustermann',
				'phone' => '+49 151 23456789',
				'address' => ['line1' => 'Musterstr. 1', 'city' => 'Berlin'],
			],
			'metadata' => ['order_uid' => 'abc123'],
		], $overrides));
	}

	private static function payPalOrder(array $captures = null): array
	{
		$captures ??= [[
			'id' => '3C679366HH908993F',
			'status' => 'COMPLETED',
			'amount' => ['value' => '49.99', 'currency_code' => 'EUR'],
			'create_time' => '2026-09-10T08:20:00Z',
		]];

		return [
			'id' => '5O190127TN364715T',
			'status' => 'COMPLETED',
			'purchase_units' => [['payments' => ['captures' => $captures]]],
			// Everything below must never be stored
			'payer' => [
				'name' => ['given_name' => 'Erika', 'surname' => 'Mustermann'],
				'email_address' => 'customer@example.com',
				'payer_id' => 'QYR5Z8XDVJNXQ',
				'address' => ['country_code' => 'DE'],
			],
			'payment_source' => ['paypal' => ['email_address' => 'customer@example.com']],
			'links' => [['href' => 'https://api-m.paypal.com/v2/checkout/orders/5O190127TN364715T']],
		];
	}

	public function testStripeRecordOnlyHoldsAllowedKeys(): void
	{
		$this->assertSame(
			['provider', 'id', 'status', 'amount', 'currency', 'created', 'reference', 'livemode'],
			array_keys(PaymentDetails::fromStripePaymentIntent(self::stripePaymentIntent()))
		);
	}

	public function testStripeRecordDropsClientSecretAndPersonalData(): void
	{
		$details = PaymentDetails::fromStripePaymentIntent(self::stripePaymentIntent());
		$serialized = json_encode($details);

		foreach (['client_secret', 'customer', 'receipt_email', 'shipping', 'metadata', 'description'] as $key) {
			$this->assertArrayNotHasKey($key, $details);
		}

		$this->assertStringNotContainsString('pi_3ABCdef_secret_XyZ123', $serialized);
		$this->assertStringNotContainsString('customer@example.com', $serialized);
		$this->assertStringNotContainsString('Mustermann', $serialized);
		$this->assertStringNotContainsString('+49 151 23456789', $serialized);
	}

	public function testStripeRecordValues(): void
	{
		$details = PaymentDetails::fromStripePaymentIntent(self::stripePaymentIntent());

		$this->assertSame('stripe', $details['provider']);
		$this->assertSame('pi_3ABCdef', $details['id']);
		$this->assertSame('succeeded', $details['status']);
		$this->assertSame(49.99, $details['amount']);
		$this->assertSame('EUR', $details['currency']);
		$this->assertSame(date('c', 1757492400), $details['created']);
		$this->assertSame('ch_3ABCdef', $details['reference']);
		$this->assertTrue($details['livemode']);
	}

	public function testStripeRecordTakesChargeIdFromExpandedCharge(): void
	{
		$details = PaymentDetails::fromStripePaymentIntent(
			self::stripePaymentIntent(['latest_charge' => ['id' => 'ch_expanded', 'object' => 'charge']])
		);

		$this->assertSame('ch_expanded', $details['reference']);
	}

	public function testPayPalRecordDropsPersonalData(): void
	{
		$details = PaymentDetails::fromPayPalOrder(self::payPalOrder());
		$serialized = json_encode($details);

		foreach (['payer', 'payment_source', 'purchase_units', 'links'] as $key) {
			$this->assertArrayNotHasKey($key, $details);
		}

		$this->assertStringNotContainsString('customer@example.com', $serialized);
		$this->assertStringNotContainsString('Mustermann', $serialized);
	}

	public function testPayPalRecordValues(): void
	{
		$details = PaymentDetails::fromPayPalOrder(self::payPalOrder());

		$this->assertSame('paypal', $details['provider']);
		$this->assertSame('5O190127TN364715T', $details['id']);
		$this->assertSame('COMPLETED', $details['status']);
		$this->assertSame(49.99, $details['amount']);
		$this->assertSame('EUR', $details['currency']);
		$this->assertSame('3C679366HH908993F', $details['reference']);
		$this->assertSame(date('c', strtotime('2026-09-10T08:20:00Z')), $details['created']);
	}

	public function testPayPalRecordAddsUpMultipleCaptures(): void
	{
		$details = PaymentDetails::fromPayPalOrder(self::payPalOrder([
			['id' => 'cap_1', 'amount' => ['value' => '10.00', 'currency_code' => 'EUR']],
			['id' => 'cap_2', 'amount' => ['value' => '5.50', 'currency_code' => 'EUR']],
		]));

		$this->assertSame(15.5, $details['amount']);
		$this->assertSame('cap_1', $details['reference']);
	}

	public function testCreateDropsUnknownKeys(): void
	{
		$details = PaymentDetails::create([
			'provider' => 'my-gateway',
			'id' => 'txn_1',
			'client_secret' => 'must not be stored',
			'payer' => ['email' => 'customer@example.com'],
		]);

		$this->assertSame(['provider' => 'my-gateway', 'id' => 'txn_1'], $details);
	}

	public function testCreateDropsNullValues(): void
	{
		$this->assertSame(
			['provider' => 'my-gateway'],
			PaymentDetails::create(['provider' => 'my-gateway', 'id' => null, 'status' => null])
		);
	}
}
