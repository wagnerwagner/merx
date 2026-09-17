<?php

namespace Wagnerwagner\Merx;

use Kirby\Toolkit\Str;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

/**
 * Builds the record stored in an order’s `paymentDetails` field
 *
 * A provider’s raw response holds personal data (email, name, phone, address)
 * and, in Stripe’s case, the PaymentIntent’s `client_secret`, which Stripe
 * states must not be stored. Order pages are plain content files, so only the
 * fields needed to find the transaction again are kept.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class PaymentDetails
{
	/**
	 * Keys of the stored record, in the order they are written
	 *
	 * - `provider` Name of the payment provider, e.g. `stripe`
	 * - `id` Id of the transaction at the provider
	 * - `status` Status as reported by the provider
	 * - `amount` Amount the provider processed, in the currency’s major unit
	 * - `currency` Three-letter ISO currency code, in uppercase
	 * - `created` Date of the transaction as ISO 8601 string
	 * - `reference` Secondary id, e.g. Stripe’s charge or PayPal’s capture
	 * - `payment_method` Which payment method is used (via Stripe). E.g. card or ideal
	 * - `card_brand` Card brand
	 * - `livemode` Whether the transaction was made against the live API
	 */
	public static array $keys = [
		'provider',
		'id',
		'status',
		'amount',
		'currency',
		'created',
		'reference',
		'payment_method',
		'card_brand',
		'livemode',
	];

	/**
	 * Builds a record, dropping everything which is not an allowed key
	 *
	 * Use this in a custom payment gateway instead of storing the provider’s
	 * response as it is.
	 *
	 * The `paymentDetails` field is a textarea, so the record has to be encoded
	 * before it is written.
	 *
	 * ```php
	 * 'paymentDetails' => Yaml::encode(PaymentDetails::create([
	 *     'provider' => 'my-gateway',
	 *     'id' => $response['transaction_id'],
	 *     'status' => $response['state'],
	 * ])),
	 * ```
	 *
	 * @see \Wagnerwagner\Merx\PaymentDetails::$keys
	 */
	public static function create(array $data): array
	{
		$details = [];

		foreach (static::$keys as $key) {
			$value = $data[$key] ?? null;
			if ($value !== null) {
				$details[$key] = $value;
			}
		}

		return $details;
	}

	/**
	 * Builds a record from a Stripe PaymentIntent
	 *
	 * Stripe reports the amount in the currency’s minor unit. It is converted
	 * back with the same factor `StripePayment::createStripePaymentIntent()`
	 * uses to create the intent.
	 */
	public static function fromStripePaymentIntent(PaymentIntent $paymentIntent): array
	{
		$data = $paymentIntent->toArray();
		$latestCharge = $data['latest_charge'] ?? null;
		$payment_method = PaymentMethod::retrieve($data['payment_method']);

		return static::create([
			'provider' => 'stripe',
			'id' => $data['id'] ?? null,
			'status' => $data['status'] ?? null,
			'amount' => isset($data['amount']) ? $data['amount'] / 100 : null,
			'currency' => isset($data['currency']) ? Str::upper((string)$data['currency']) : null,
			'created' => isset($data['created']) ? date('c', (int)$data['created']) : null,
			'reference' => is_array($latestCharge) ? ($latestCharge['id'] ?? null) : $latestCharge,
			'payment_method' => $payment_method['type'],
			'card_brand' => $payment_method->card?->brand ?? null,
			'livemode' => $data['livemode'] ?? null,
		]);
	}

	/**
	 * Builds a record from a captured PayPal order
	 *
	 * PayPal reports one amount per capture. An order may hold more than one,
	 * so their values are added up.
	 *
	 * @param array $paypalResponse Response of `PayPalPayment::executePayPalPayment()`
	 */
	public static function fromPayPalOrder(array $paypalResponse): array
	{
		$captures = [];
		foreach ($paypalResponse['purchase_units'] ?? [] as $purchaseUnit) {
			foreach ($purchaseUnit['payments']['captures'] ?? [] as $capture) {
				$captures[] = $capture;
			}
		}

		$amount = null;
		$currency = null;
		foreach ($captures as $capture) {
			$value = $capture['amount']['value'] ?? null;
			if ($value !== null) {
				$amount = (float)$amount + (float)$value;
				$currency ??= $capture['amount']['currency_code'] ?? null;
			}
		}

		$created = $captures[0]['create_time'] ?? $paypalResponse['create_time'] ?? null;
		$timestamp = is_string($created) ? strtotime($created) : false;

		return static::create([
			'provider' => 'paypal',
			'id' => $paypalResponse['id'] ?? null,
			'status' => $paypalResponse['status'] ?? null,
			'amount' => $amount,
			'currency' => is_string($currency) ? Str::upper($currency) : null,
			'created' => $timestamp !== false ? date('c', $timestamp) : null,
			'reference' => $captures[0]['id'] ?? null,
			'livemode' => option('wagnerwagner.merx.production') === true,
		]);
	}
}
