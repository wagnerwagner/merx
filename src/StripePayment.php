<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Stripe\Event;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Webhook;

/**
 * Payment class for Stripe Payment.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @internal
 */
class StripePayment
{
	/**
	 * Configure Stripe Payment connection settings
	 *
	 * @return void
	 */
	private static function setStripeApiKey(): void
	{
		if (option('wagnerwagner.merx.production') === true) {
			Stripe::setApiKey(option('wagnerwagner.merx.stripe.live.secret_key'));
		} else {
			Stripe::setApiKey(option('wagnerwagner.merx.stripe.test.secret_key'));
		}
	}

	/**
	 * Verifies a Stripe webhook request and turns it into an event
	 *
	 * The reason a webhook is rejected is only written to the log. It must not
	 * reach the caller, who is unauthenticated on this endpoint.
	 *
	 * @see https://docs.stripe.com/webhooks#verify-official-libraries
	 * @param string $payload Payload from stripe webhook
	 * @throws \Kirby\Exception\Exception merx.stripeWebhook when the signing secret is missing, the signature is invalid or the payload cannot be parsed
	 *
	 * @return \Stripe\Event
	 */
	public static function constructEvent(string $payload): Event
	{
		self::setStripeApiKey();

		$endpoint_secret = option('wagnerwagner.merx.stripe.webhook_signing_secret', false);
		if ($endpoint_secret === false || empty($endpoint_secret)) {
			self::logWebhookError('No Stripe webhook signing secret configured.');
			throw new Exception(
				key: 'merx.stripeWebhook',
				httpCode: 500,
			);
		}

		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
		if (is_string($sig_header) === false || $sig_header === '') {
			self::logWebhookError('Missing Stripe-Signature header.');
			throw new Exception(
				key: 'merx.stripeWebhook',
				httpCode: 400,
			);
		}

		try {
			return Webhook::constructEvent(
				$payload, $sig_header, $endpoint_secret
			);
		} catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $ex) {
			// Invalid payload or invalid signature
			self::logWebhookError($ex->getMessage());
			throw new Exception(
				key: 'merx.stripeWebhook',
				httpCode: 400,
				previous: $ex,
			);
		}
	}

	/**
	 * Writes the reason a webhook was rejected to the log
	 */
	private static function logWebhookError(string $message): void
	{
		if (option('wagnerwagner.merx.logging') === true) {
			Logger::log('Stripe webhook rejected: ' . $message, 'error');
		}
	}

	/**
	 * Create Stripe Payment
	 *
	 * @see https://docs.stripe.com/api/payment_intents/create
	 * @param float $amount
	 * @param array $params
	 * @param array $options
	 * @return \Stripe\PaymentIntent
	 */
	public static function createStripePaymentIntent(float $amount, array $params = [], $options = []): PaymentIntent
	{
		self::setStripeApiKey();

		$intent = PaymentIntent::create(array_merge([
			'amount' => round($amount * 100),
		], $params), $options);

		return $intent;
	}

	/**
	 * Get the Stripe Payment informations
	 *
	 * @param string $paymentIntentId
	 *
	 * @return \Stripe\PaymentIntent
	 */
	public static function retrieveStripePaymentIntent(string $paymentIntentId): PaymentIntent
	{
		self::setStripeApiKey();

		$intent = PaymentIntent::retrieve($paymentIntentId);
		return $intent;
	}
}
