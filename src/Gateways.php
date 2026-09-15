<?php

namespace Wagnerwagner\Merx;

use Kirby\Data\Yaml;
use Kirby\Exception\Exception;

/**
 * Gateway class dummy holder
 *
 * This class only holds the static $gateways array.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 * @internal
 */
class Gateways
{
	public static array $gateways = [];

	/**
	 * Captures stripe payment intent
	 *
	 * @param OrderPage $virtualOrderPage Virtual order page which may contain `stripePaymentIntentId` field (Credit Card Payment)
	 * @param array $data Additional data from get request which may contain `payment_intent` (Klarna Payment)
	 * @throws \Kirby\Exception\Exception|\Stripe\Exception\ApiErrorException Kirby exception, when user canceled the payment, payment is incomplete or Stripe API Exception
	 *
	 * @return OrderPage Virtual order page with updated payment details
	 */
	public static function completeStripePayment(OrderPage $virtualOrderPage, array $data): OrderPage
	{
		// Check if user canceled payment
		if (isset($data['redirect_status']) ? $data['redirect_status'] === 'failed' : false) {
			throw new Exception(
				key: 'merx.paymentCanceled',
				httpCode: 400,
			);
		}

		// Retrieve Payment Intent
		$paymentIntentId = $virtualOrderPage->stripePaymentIntentId()->toString();
		$paymentIntent = StripePayment::retrieveStripePaymentIntent($paymentIntentId);

		if (in_array('order_uid', $paymentIntent->metadata->keys())) {
			// Fail payment, when order_uid is already set.
			throw new Exception(
				key: 'merx.stripeError',
				httpCode: 500,
			);
		}

		// The payment has to cover the order before anything is captured
		static::validatePaymentAmount($paymentIntent->amount, $paymentIntent->currency, $virtualOrderPage);

		// Store what is known before capturing, so a failed capture leaves a record
		$virtualOrderPage->version()->update([
			'paymentDetails' => Yaml::encode(PaymentDetails::fromStripePaymentIntent($paymentIntent)),
		]);

		// Prepare meta data
		$metadata = [
			'order_uid' => (string)$virtualOrderPage->uid(),
		];

		if ($paymentIntent->status === 'requires_capture') {
			// Capture Payment Intent
			$paymentIntent = $paymentIntent->capture([
				'metadata' => $metadata,
			]);
		} else {
			// Update Payment Intent
			$paymentIntent = $paymentIntent->update($paymentIntentId, [
				'metadata' => $metadata,
			]);
		}

		if (!in_array($paymentIntent->status, ['succeeded', 'processing'])) {
			// The full PaymentIntent holds the `client_secret` and the customer’s
			// details and must not be handed to the caller.
			throw new Exception(
				key: 'merx.stripeError',
				httpCode: 400,
				details: [
					'status' => $paymentIntent->status,
				],
			);
		}

		// Update content of VirtualOrderPage with the state after the capture
		$update = [
			'paymentDetails' => Yaml::encode(PaymentDetails::fromStripePaymentIntent($paymentIntent)),
		];

		if ($paymentIntent->status === 'succeeded') {
			$update['paymentComplete'] = true;
			$update['datePaid'] = date('c');
		}

		$virtualOrderPage->version()->update($update);

		return $virtualOrderPage;
	}

	/**
	 * Makes sure a payment actually covers the order it is about to complete
	 *
	 * A Stripe PaymentIntent is created when the client secret is fetched and
	 * keeps the cart total of that moment. The cart stays editable until the
	 * checkout, so both can drift apart: the order would list goods the payment
	 * does not pay for. Correcting the amount is the frontend’s job — it has to
	 * fetch a new client secret when the cart changes — so this only refuses.
	 *
	 * @param int $amount Amount of the payment in the currency’s minor unit
	 * @param string|null $currency Three-letter ISO currency code of the payment
	 * @throws \Kirby\Exception\Exception merx.amountMismatch when amount or currency do not match the cart
	 */
	public static function validatePaymentAmount(int $amount, string|null $currency, OrderPage $virtualOrderPage): void
	{
		$total = $virtualOrderPage->cart()->total();

		// Same factor `StripePayment::createStripePaymentIntent()` uses
		if ($amount !== (int)round($total->toFloat() * 100)) {
			throw new Exception(
				key: 'merx.amountMismatch',
				httpCode: 400,
			);
		}

		if (
			is_string($currency) === true &&
			is_string($total->currency) === true &&
			strtoupper($currency) !== strtoupper($total->currency)
		) {
			throw new Exception(
				key: 'merx.amountMismatch',
				httpCode: 400,
			);
		}
	}

	/**
	 * Makes sure a PayPal order charges what the order costs
	 *
	 * The amount is fixed when the PayPal order is created. The default purchase
	 * unit takes it from the cart, but `paypal.purchaseUnits` lets a shop build
	 * its own — a mistake there would charge the wrong amount while the order is
	 * still marked as paid.
	 *
	 * Checked on the retrieved order rather than on the one Merx sent, and before
	 * the capture, so no money moves on a mismatch. The create response cannot be
	 * used: PayPal answers it minimally and leaves the purchase units out.
	 *
	 * @param array $paypalOrder Response of `PayPalPayment::retrievePayPalOrder()`
	 * @throws \Kirby\Exception\Exception merx.amountMismatch
	 */
	public static function validatePayPalOrderAmount(array $paypalOrder, OrderPage $virtualOrderPage): void
	{
		$amount = 0.0;
		$currency = null;

		foreach ($paypalOrder['purchase_units'] ?? [] as $purchaseUnit) {
			$value = $purchaseUnit['amount']['value'] ?? null;

			if ($value !== null) {
				// PayPal charges the sum of the purchase units
				$amount += (float)$value;
				$currency ??= $purchaseUnit['amount']['currency_code'] ?? null;
			}
		}

		static::validatePaymentAmount(
			(int)round($amount * 100),
			is_string($currency) === true ? $currency : null,
			$virtualOrderPage,
		);
	}

	/**
	 * Makes sure a PayPal order has not been used for another order already
	 *
	 * PayPal refuses a second capture itself, but relying on that leaves the
	 * check with the provider and reports it as an unspecific API error. This
	 * is the PayPal counterpart of the `order_uid` check in
	 * `completeStripePayment()`.
	 *
	 * @param array $paypalOrder Response of `PayPalPayment::retrievePayPalOrder()`
	 * @param OrderPage $virtualOrderPage Order the payment is about to complete
	 * @throws \Kirby\Exception\Exception merx.paypalError when the order was captured before or belongs to another order
	 */
	public static function validatePayPalOrderIsUnused(array $paypalOrder, OrderPage $virtualOrderPage): void
	{
		// A `COMPLETED` order has been captured already.
		if (($paypalOrder['status'] ?? null) === 'COMPLETED') {
			throw new Exception(
				key: 'merx.paypalError',
				httpCode: 400,
			);
		}

		// `custom_id` is set when the PayPal order is created and names the order
		// it belongs to.
		$customIds = [];
		foreach ($paypalOrder['purchase_units'] ?? [] as $purchaseUnit) {
			if (isset($purchaseUnit['custom_id']) === true) {
				$customIds[] = (string)$purchaseUnit['custom_id'];
			}
		}

		if ($customIds !== [] && in_array((string)$virtualOrderPage->uid(), $customIds, true) === false) {
			throw new Exception(
				key: 'merx.paypalError',
				httpCode: 400,
			);
		}
	}

	/**
	 * Checks whether a PayPal capture response actually paid for the order
	 *
	 * PayPal answers with HTTP 2xx even when the order was not captured, so the
	 * status has to be checked explicitly before an order is marked as paid.
	 *
	 * @param array $paypalResponse Response of `PayPalPayment::executePayPalPayment()`
	 * @throws \Kirby\Exception\Exception merx.paypalError when the order was not captured or a capture was declined
	 *
	 * @return bool `true` when every capture is settled, `false` when at least one capture is still pending
	 */
	public static function validatePayPalCapture(array $paypalResponse): bool
	{
		// PayPal only considers an order captured when its status is `COMPLETED`.
		// `PENDING`, `VOIDED` and `PAYER_ACTION_REQUIRED` must never complete the order.
		$status = $paypalResponse['status'] ?? null;
		if ($status !== 'COMPLETED') {
			throw new Exception(
				key: 'merx.paypalError',
				httpCode: 400,
				details: [
					'status' => $status,
				],
			);
		}

		// A `COMPLETED` order can still hold captures which are declined or not settled yet.
		$captureStatus = [];
		foreach ($paypalResponse['purchase_units'] ?? [] as $purchaseUnit) {
			foreach ($purchaseUnit['payments']['captures'] ?? [] as $capture) {
				$captureStatus[] = $capture['status'] ?? null;
			}
		}

		if (array_intersect(['DECLINED', 'FAILED'], $captureStatus) !== []) {
			throw new Exception(
				key: 'merx.paypalError',
				httpCode: 400,
				details: [
					'status' => $status,
				],
			);
		}

		// `PENDING` captures are completed by PayPal at a later point.
		return array_filter($captureStatus, fn (?string $state) => $state !== 'COMPLETED') === [];
	}
}

Gateways::$gateways['invoice'] = true;

/**
 * Definition of the initializePayment and completePayment methods for PayPal stored in the $gateways array
 */
Gateways::$gateways['paypal'] = [
	'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
		if (option('wagnerwagner.merx.production') === true) {
			if (option('wagnerwagner.merx.paypal.live.clientID') === null && option('wagnerwagner.merx.paypal.live.secret') === null) {
				throw new Exception('Missing PayPal live keys');
			}
		} else {
			if (option('wagnerwagner.merx.paypal.sandbox.clientID') === null && option('wagnerwagner.merx.paypal.sandbox.secret') === null) {
				throw new Exception('Missing PayPal sandbox keys');
			}
		}
		$currency = $virtualOrderPage->cart()->currency();
		$response = PayPalPayment::createPayPalPayment($virtualOrderPage, $currency);
		$virtualOrderPage->version()->update([
			'payPalOrderId' => $response['id'],
			'redirect' => $response['links'][1]['href'],
		]);
		return $virtualOrderPage;
	},
	'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
		// check if user canceled payment
		if (!isset($data['PayerID'])) {
			throw new Exception(
				key: 'merx.paymentCanceled',
				httpCode: 400,
			);
		}

		$payPalOrderId = (string)$virtualOrderPage->payPalOrderId();

		$payPalOrder = PayPalPayment::retrievePayPalOrder($payPalOrderId);

		// Refuse a PayPal order which was already captured for another order
		Gateways::validatePayPalOrderIsUnused($payPalOrder, $virtualOrderPage);

		// The payment has to cover the order before anything is captured
		Gateways::validatePayPalOrderAmount($payPalOrder, $virtualOrderPage);

		// execute payment
		$paypalResponse = PayPalPayment::executePayPalPayment($payPalOrderId);

		// Store the outcome, even when the capture was not successful
		$virtualOrderPage->version()->update([
			'paymentDetails' => Yaml::encode(PaymentDetails::fromPayPalOrder($paypalResponse)),
		]);

		// Mark the order as paid only when PayPal actually captured it.
		if (Gateways::validatePayPalCapture($paypalResponse) === true) {
			$virtualOrderPage->version()->update([
				'paymentComplete' => true,
				'datePaid' => date('c'),
			]);
		}

		return $virtualOrderPage;
	}
];

Gateways::$gateways['stripe-elements'] = [
	'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
		$paymentIntentId = kirby()->session()->pull('wagnerwagner.merx.stripePaymentIntentId');
		$virtualOrderPage->version()->update([
			'stripePaymentIntentId' => $paymentIntentId,
		]);

		return $virtualOrderPage;
	},
	'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
		$virtualOrderPage = Gateways::completeStripePayment($virtualOrderPage, $data);
		return $virtualOrderPage;
	},
];
