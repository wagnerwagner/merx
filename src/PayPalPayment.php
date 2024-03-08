<?php

namespace Wagnerwagner\Merx;

use Kirby\Http\Remote;
use OrderPage;

/**
 * Payment Class for PayPal Payments unsing PayPal rest api v2
 * For further information about the PayPal REST API, please visit https://developer.paypal.com/api/rest/
 *
 */
class PayPalPayment
{
    private static $paypalLiveApiEntry = 'https://api-m.paypal.com';
    private static $paypalSandboxApiEntry = 'https://api-m.sandbox.paypal.com';

    /**
     * Handles the request for the gateway. Use 'use Kirby\Http\Remote'
     *
     * @param  string $endpoint
     * @param  string $data
     * @param  array  $auth
     * @param  array  $requestOptions
     *
     * @return \Kirby\Http\Remote
     */
    private static function request(string $endpoint, string $data, array $auth = [], array $requestOptions = []): Remote
    {
        if (option('ww.merx.production') === true) {
            $baseUrl = self::$paypalLiveApiEntry;
        } else {
            $baseUrl = self::$paypalSandboxApiEntry;
        }
        $endpoint = $baseUrl . $endpoint;
        $options = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'data' => $data,
        ];
        $options = array_replace_recursive(
            $options,
            $auth,
            $requestOptions
        );
        $requestClient = Remote::request(
            $endpoint,
            $options
        );
        return $requestClient;
    }

    /**
     * Create an authorization token for the next request
     *
     * @return array contains the auth-informations provided by PayPal
     */
    private static function getAccessToken(): array
    {
        /**
         * Rquest Example
         * curl -v -X POST "https://api-m.sandbox.paypal.com/v1/oauth2/token"\
         *  -u "CLIENT_ID:CLIENT_SECRET"\
         *  -H "Content-Type: application/x-www-form-urlencoded"\
         *  -d "grant_type=client_credentials"
         */
        if (option('ww.merx.production') === true) {
            $auth = option('ww.merx.paypal.live.clientID').':'.option('ww.merx.paypal.live.secret');
        } else {
            $auth = option('ww.merx.paypal.sandbox.clientID').':'.option('ww.merx.paypal.sandbox.secret');
        }
        $endpoint = '/v1/oauth2/token';
        $token = self::request(
            $endpoint,
            'grant_type=client_credentials',
            [
                'basicAuth' => $auth,
            ],
        );
        return $token->json();
    }

    /**
     * Create an order, send it to PayPal and returns the result as an array to initializePayment of the Gateway
     *
     * @param  \OrderPage $orderPage
     *
     * @return array with the orderinformations provided by PayPal.
     */
    public static function createPayPalPayment(OrderPage $orderPage): array
    {
        /**
         * Request Example
         * curl -v -X POST https://api-m.sandbox.paypal.com/v2/checkout/orders \
         *  -H 'Content-Type: application/json' \
         *  -H 'PayPal-Request-Id: 7b92603e-77ed-4896-8e78-5dea2050476a' \
         *  -H 'Authorization: Bearer [AUTHTOKEN]-g' \
         *  -d '{
         *    "intent": "CAPTURE",
         *    "purchase_units": [
         *      {
         *        "reference_id": "d9f80740-38f0-11e8-b467-0ed5f89f718b",
         *        "amount": {
         *          "currency_code": "USD",
         *          "value": "100.00"
         *        }
         *      }
         *    ],
         *    "payment_source": {
         *      "paypal": {
         *        "experience_context": {
         *          "payment_method_preference": "IMMEDIATE_PAYMENT_REQUIRED",
         *          "brand_name": "EXAMPLE INC",
         *          "locale": "en-US",
         *          "landing_page": "LOGIN",
         *          "shipping_preference": "SET_PROVIDED_ADDRESS",
         *          "user_action": "PAY_NOW",
         *          "return_url": "https://example.com/returnUrl",
         *          "cancel_url": "https://example.com/cancelUrl"
         *        }
         *      }
         *    }
         *  }'
         */
        $siteTitle = (string)site()->title();
        $access = self::getAccessToken();

        if (option('ww.merx.paypal.purchaseUnits')) {
            $purchaseUnits = option('ww.merx.paypal.purchaseUnits')();
        } else {
            $purchaseUnits = [
                [
                    "description" => $siteTitle,
                    "amount" => [
                        "value" => number_format($orderPage->cart()->getSum(), 2, '.', ''),
                        "currency_code" => option('ww.merx.currency'),
                    ],
                ],
            ];
        }
        $applicationContext = array_merge([
            'cancel_url' => url(option('ww.merx.successPage')),
            'return_url' => url(option('ww.merx.successPage')),
            'user_action' => 'PAY_NOW',
            'shipping_preference' => 'NO_SHIPPING',
            'brand_name' => $siteTitle,
        ], option('ww.merx.paypal.applicationContext', []));

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => $purchaseUnits,
            'payment_source' => [
                'paypal' => [
                    'experience_context' => $applicationContext
                ]
            ]
        ];
        $endpoint = '/v2/checkout/orders';
        $paypalOrder = self::request(
            $endpoint,
            json_encode($data),
            [],
            ['headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $access['token_type'].' '.$access['access_token'],
            ]]
        );
        return $paypalOrder->json();
    }

    /**
     * Capture the payment for order with id $orderId.
     *
     * @param  string $orderId is the Id of the order that was delivered by calling initializePayment
     *
     * @return array with the capture informations provided by PayPal
     */
    public static function executePayPalPayment(string $orderId) : array
    {
        /**
         * Request Example
         * curl -v -X POST https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture \
         *  -H 'PayPal-Request-Id: 7b92603e-77ed-4896-8e78-5dea2050476a' \
         *  -H 'Authorization: Bearer access_token[AUTHTOKEN]-g'
         *
         */
        $access = self::getAccessToken();
        $endpoint = "/v2/checkout/orders/$orderId/capture";
        $response = self::request(
            $endpoint,
            '',
            [],
            [
                'method' => 'POST',
                'headers' =>
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => $access['token_type'].' '.$access['access_token'],
                ]
            ]
        );
        return $response->json();
    }
}
