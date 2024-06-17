<?php

namespace Wagnerwagner\Merx;

use Stripe\Stripe;
use Stripe\ApiResource;
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

    private static function createStripeClient()
    {
        if (option('ww.merx.production') === true) {
            return new \Stripe\StripeClient(option('ww.merx.stripe.live.secret_key'));
        } else {
            return new \Stripe\StripeClient(option('ww.merx.stripe.test.secret_key'));
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
    public static function retriveStripePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        self::setStripeApiKey();

        $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        return $intent;
    }
}
