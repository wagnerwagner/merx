<?php

namespace Wagnerwagner\Merx;

use Exception;
use Stripe\Event;
use Stripe\Stripe;
use Stripe\PaymentIntent;

/**
 * Payment class for Stripe Payment.
 *
 */
class StripePayment
{
    /**
     * Configure Stripe Payment connection settings
     *
     *
     * @return void
     */
    private static function setStripeApiKey(): void
    {
        if (option('ww.merx.production') === true) {
            Stripe::setApiKey(option('ww.merx.stripe.live.secret_key'));
        } else {
            Stripe::setApiKey(option('ww.merx.stripe.test.secret_key'));
        }
    }

    /**
     * @see https://docs.stripe.com/webhooks#verify-official-libraries
     * @param string $payload Payload from stripe webhook
     *
     * @return \Stripe\Event
     */
    public static function constructEvent(string $payload): Event
    {
        self::setStripeApiKey();

        $endpoint_secret = option('ww.merx.stripe.webhook_signing_secret', false);
        if ($endpoint_secret === false) {
            throw new Exception('No Stripe Webhook signing secret');
        }

        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
            return $event;
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            echo json_encode(['Error parsing payload: ' => $e->getMessage()]);
            exit();
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            echo json_encode(['Error verifying webhook signature: ' => $e->getMessage()]);
            exit();
        }
    }

    /**
     * Create Stripe Payment
     *
     * @param  float $amount
     * @param  array $params
     * @param  array $options
     *
     * @return \Stripe\PaymentIntent
     */
    public static function createStripePaymentIntent(float $amount, array $params = [], $options = []): PaymentIntent
    {
        self::setStripeApiKey();

        $intent = \Stripe\PaymentIntent::create(array_merge([
            'amount' => round($amount * 100),
            'currency' => option('ww.merx.currency'),
            'capture_method' => 'manual',
            'payment_method_types' => ['card'],
        ], $params), $options);

        return $intent;
    }

    /**
     * Get the Stripe Payment informations
     *
     * @param  string $paymentIntentId
     *
     * @return \Stripe\PaymentIntent
     */
    public static function retrieveStripePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        self::setStripeApiKey();

        $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        return $intent;
    }
}
