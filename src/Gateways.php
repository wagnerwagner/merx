<?php

namespace Wagnerwagner\Merx;

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

		// Update content of VirtualOrderPage
		$virtualOrderPage->version()->update([
			'paymentDetails' => (array)$paymentIntent->toArray(),
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
			throw new Exception(
				key: 'merx.stripeError',
				httpCode: 400,
				details: $paymentIntent->toArray(),
			);
		}

		// Update content of VirtualOrderPage
		if ($paymentIntent->status === 'succeeded') {
			$virtualOrderPage->version()->update([
				'paymentComplete' => true,
				'datePaid' => date('c'),
			]);
		}

		return $virtualOrderPage;
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

		// execute payment
		$paypalResponse = PayPalPayment::executePayPalPayment((string)$virtualOrderPage->payPalOrderId());

		// Store the response, even when the capture was not successful
		$virtualOrderPage->version()->update([
			'paymentDetails' => (array)$paypalResponse,
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
