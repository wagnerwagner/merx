<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
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
	 * Verifies a Stripe webhook request and turns it into an event
	 *
	 * The reason a webhook is rejected is only written to the log. It must not
	 * reach the caller, who is unauthenticated on this endpoint.
	 *
	 * @see https://docs.stripe.com/webhooks#verify-official-libraries
	 * @param $payload Payload from stripe webhook
	 * @throws Exception merx.stripeWebhook when the signing secret is missing, the signature is invalid or the payload cannot be parsed
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
				$payload,
				$sig_header,
				$endpoint_secret,
			);
		} catch (\UnexpectedValueException | SignatureVerificationException $ex) {
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
	 * The PaymentIntent $id with the new amount, or a new one
	 *
	 * An intent nothing was paid with yet is updated, so a checkout which asks
	 * for the client secret again, e.g. after the cart changed, does not leave
	 * an abandoned intent behind with every call. One which is authorized,
	 * paid or canceled stays as it is.
	 *
	 * @see https://docs.stripe.com/api/payment_intents/create
	 * @param $id The intent of the visitor’s session
	 */
	public static function createStripePaymentIntent(float $amount, array $params = [], $options = [], string|null $id = null): PaymentIntent
	{
		self::setStripeApiKey();
		$amount = (int)round($amount * 100);

		if ($id !== null) {
			try {
				if (PaymentIntent::retrieve($id, $options)->status === 'requires_payment_method') {
					return PaymentIntent::update($id, array_filter([
						'amount' => $amount,
						'currency' => $params['currency'] ?? null,
					]), $options);
				}
			} catch (ApiErrorException) {
				// Gone, or of another account
			}
		}

		return PaymentIntent::create(['amount' => $amount, ...$params], $options);
	}

	/**
	 * Writes the reason a webhook was rejected to the log
	 */
	private static function logWebhookError(string $message): void
	{
		Logger::log('Stripe webhook rejected: ' . $message, 'error');
	}

	/**
	 * Get the Stripe Payment informations
	 */
	public static function retrieveStripePaymentIntent(string $paymentIntentId): PaymentIntent
	{
		self::setStripeApiKey();

		$intent = PaymentIntent::retrieve($paymentIntentId);
		return $intent;
	}

	/**
	 * Configure Stripe Payment connection settings
	 */
	private static function setStripeApiKey(): void
	{
		if (option('wagnerwagner.merx.production') === true) {
			Stripe::setApiKey(option('wagnerwagner.merx.stripe.live.secret_key'));
		} else {
			Stripe::setApiKey(option('wagnerwagner.merx.stripe.test.secret_key'));
		}
	}
}
