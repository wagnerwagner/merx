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
	 * @throws \Kirby\Exception\Exception|\Stripe\Exception\ApiErrorException Kirby exception, when user canceled the payment or Stripe API Exception
	 *
	 * @return OrderPage Virtual order page with updated payment details
	 */
	public static function completeStripePayment(OrderPage $virtualOrderPage, array $data): OrderPage
	{
		// Check if user canceled payment
		if (isset($data['redirect_status']) ? $data['redirect_status'] === 'failed' : false) {
			throw new Exception([
				'key' => 'merx.paymentCanceled',
				'httpCode' => 400,
			]);
		}

		// Retrieve Payment Intent
		$paymentIntentId = (string)($data['payment_intent'] ?? $virtualOrderPage->stripePaymentIntentId()->toString());
		$paymentIntent = StripePayment::retrieveStripePaymentIntent($paymentIntentId);

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

		// Update content of VirtualOrderPage
		if ($paymentIntent->status === 'succeeded') {
			$virtualOrderPage->version()->update([
				'paymentComplete' => true,
				'datePaid' => date('c'),
			]);
		}

		return $virtualOrderPage;
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
		$virtualOrderPage->version()->update([
			'paymentDetails' => (array)$paypalResponse,
			'paymentComplete' => true,
			'datePaid' => date('c'),
		]);
		return $virtualOrderPage;
	}
];

Gateways::$gateways['stripe-elements'] = [
	'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
		$virtualOrderPage = Gateways::completeStripePayment($virtualOrderPage, $data);
		return $virtualOrderPage;
	},
];
