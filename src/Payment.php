<?php

namespace Wagnerwagner\Merx;

use PayPalHttp\HttpResponse;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use Stripe\Stripe;
use Stripe\Charge;
use Stripe\Source;
use Stripe\ApiResource;

class Payment
{
    private static function getPayPalClient(): PayPalHttpClient
    {
        if (option('ww.merx.production') === true) {
            $environment = new ProductionEnvironment(option('ww.merx.paypal.live.clientID'), option('ww.merx.paypal.live.secret'));
        } else {
            $environment = new SandboxEnvironment(option('ww.merx.paypal.sandbox.clientID'), option('ww.merx.paypal.sandbox.secret'));
        }

        return new PayPalHttpClient($environment);
    }


    private static function setStripeApiKey(): void
    {
        if (option('ww.merx.production') === true) {
            Stripe::setApiKey(option('ww.merx.stripe.live.secret_key'));
        } else {
            Stripe::setApiKey(option('ww.merx.stripe.test.secret_key'));
        }
    }


    public static function createPayPalPayment(float $total): HttpResponse
    {
        $client = self::getPayPalClient();
        $siteTitle = (string)site()->title();

        $request = new OrdersCreateRequest();

        $applicationContext = array_merge([
            'cancel_url' => url(option('ww.merx.successPage')),
            'return_url' => url(option('ww.merx.successPage')),
            'user_action' => 'PAY_NOW',
            'shipping_preference' => 'NO_SHIPPING',
            'brand_name' => $siteTitle,
        ], option('ww.merx.paypal.applicationContext', []));

        if (option('ww.merx.paypal.purchaseUnits')) {
            $purchaseUnits = option('ww.merx.paypal.purchaseUnits')();
        } else {
            $purchaseUnits = [
                [
                    "description" => $siteTitle,
                    "amount" => [
                        "value" => number_format($total, 2, '.', ''),
                        "currency_code" => option('ww.merx.currency'),
                    ],
                ],
            ];
        }
        $request->body = [
            "intent" => "CAPTURE",
            "purchase_units" => $purchaseUnits,
            "application_context" => $applicationContext,
        ];

        $response = $client->execute($request);
        return $response;
    }


    public static function executePayPalPayment(string $orderId): HttpResponse
    {
        $client = self::getPayPalClient();
        $request = new OrdersCaptureRequest($orderId);
        $response = $client->execute($request);
        return $response;
    }


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


    public static function getStripePaymentIntent(string $paymentIntentId): ApiResource
    {
        self::setStripeApiKey();

        $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        return $intent;
    }


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


    public static function getStatusOfSource(string $sourceString): string
    {
        self::setStripeApiKey();

        $source = \Stripe\Source::retrieve($sourceString);
        return $source->status;
    }


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
