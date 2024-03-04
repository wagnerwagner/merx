<?php

namespace Wagnerwagner\Merx;

use Kirby\Http\Remote;
use OrderPage;

/**
 * Payment Class for Paypal Payments unsing Paypal rest api v2
 * For further information, please visit https://developer.paypal.com/api/rest/
 *
 * @category PayPalPayment
 * @package  PayPalPayment
 * @author   Alexander Kovac <a.kovac@wagnerwagner.de>
 * @license  https://wagnerwagner.de Copyright
 * @link     https://wagnerwagner.de
 */
class PayPalPayment
{
    /**
     * handles the request for the gateway. Use 'use Kirby\Http\Remote'
     *
     * @param  string             $endpoint
     * @param  string             $data
     * @param  array              $auth
     * @param  array              $requestoptions
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return \Kirby\Http\Remote
     */
    private static function request(string $endpoint, string $data, array $auth = [], array $requestoptions = []): \Kirby\Http\Remote
    {

        if (option('ww.merx.production') === true) {
            $baseUrl = option('ww.merx.paypal.live.url');
        } else {
            $baseUrl = option('ww.merx.paypal.sandbox.url');
        }

        $endpoint = $baseUrl.$endpoint;
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
            $requestoptions
        );
        $requestClient = Remote::request(
            $endpoint,
            $options
        );
        return $requestClient;
    }

    /**
     * create a authorization token
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return array contains the auth-informations provided by paypal
     */
    private static function getAccessTokken(): array
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
        kirby()->session()->set('ww.merx.auth', $token->json());
        return $token->json();
    }

    /**
     * create an order, send it to paypal and return the result as array to initializePayment of the Gateway
     *
     * @param  \OrderPage $orderPage
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return array with the orderinformations provided by paypal.
     */
    public static function createPayPalPayment(OrderPage $orderPage): array
    {
        /**
         * Request Example
         * curl -v -X POST https://api-m.sandbox.paypal.com/v2/checkout/orders \
         *  -H 'Content-Type: application/json' \
         *  -H 'PayPal-Request-Id: 7b92603e-77ed-4896-8e78-5dea2050476a' \
         *  -H 'Authorization: Bearer 6V7rbVwmlM1gFZKW_8QtzWXqpcwQ6T5vhEGYNJDAAdn3paCgRpdeMdVYmWzgbKSsECednupJ3Zx5Xd-g' \
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
        $access = self::getAccessTokken();

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
     * capture the payment for given order.
     *
     * @param  string $orderId
     *
     * @author  Alexander Kovac <a.kovac@wagnerwagner.de>
     * @license https://wagnerwagner.de Copyright
     *
     * @return array with the capture informations provided by paypal
     */
    public static function executePayPalPayment(string $orderId) : array
    {
        /**
         * Request Example
         * curl -v -X POST https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture \
         *  -H 'PayPal-Request-Id: 7b92603e-77ed-4896-8e78-5dea2050476a' \
         *  -H 'Authorization: Bearer access_token6V7rbVwmlM1gFZKW_8QtzWXqpcwQ6T5vhEGYNJDAAdn3paCgRpdeMdVYmWzgbKSsECednupJ3Zx5Xd-g'
         *
         */
        $access = kirby()->session()->get('ww.merx.auth');
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
