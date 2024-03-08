<?php

namespace Wagnerwagner\Merx;

use Stripe\Stripe;
use Stripe\Charge;
use Stripe\Source;
use Stripe\ApiResource;

/**
 * Payment class for Stripe payment.
 *
 */
class StripePayment
{

    /**
     * configure stripe Payment connection settings
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
     * create stripe Payment
     *
     * @param  float $amount
     * @param  array $params
     * @param  array $options
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return \Stripe\ApiResource
     */
    public static function createStripePaymentIntent(float $amount, array $params = [], $options = []): ApiResource
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
     * get the stripe payment informations
     *
     * @param  string $paymentIntentId
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return \Stripe\ApiResource
     */
    public static function getStripePaymentIntent(string $paymentIntentId): ApiResource
    {
        self::setStripeApiKey();

        $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        return $intent;
    }

    /**
     * send the request to Stripe
     *
     * @param  float               $amount
     * @param  string              $type
     * @param  array               $data
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return \Stripe\ApiResource
     */
    public static function createStripeSource(float $amount, string $type = 'sofort', array $data = []): ApiResource
    {
        self::setStripeApiKey();

        $params = array_merge([
            "type" => $type,
            "amount" => round($amount * 100),
            "currency" => option('ww.merx.currency'),
            "redirect" => [
                "return_url" => url(option('ww.merx.successPage')),
            ]
        ], $data);

        $source = Source::create($params);
        return $source;
    }

    /**
     * get status of request from stripe
     *
     * @param  string $sourceString
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return string
     */
    public static function getStatusOfSource(string $sourceString): string
    {
        self::setStripeApiKey();

        $source = \Stripe\Source::retrieve($sourceString);
        return $source->status;
    }

    /**
     * create charge
     *
     * @param  float               $amount
     * @param  string              $source
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return \Stripe\ApiResource
     */
    public static function createStripeCharge(float $amount, string $source): ApiResource
    {
        self::setStripeApiKey();
        $charge = Charge::create(array(
            'amount'   => round($amount * 100),
            'currency' => option('ww.merx.currency'),
            'source' => $source,
        ));
        return $charge;
    }
}
