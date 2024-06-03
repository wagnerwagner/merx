<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Kirby\Http\Remote;
use OrderPage;

/**
 * Payment Class for PayPal Payments using PayPal REST API v2
 * For further information about the PayPal REST API, please visit https://developer.paypal.com/api/rest/
 */
class PayPalPayment
{
    private static string $paypalLiveApiEntry = 'https://api-m.paypal.com';
    private static string $paypalSandboxApiEntry = 'https://api-m.sandbox.paypal.com';

    /**
     * Handles the request for the gateway. Use 'use Kirby\Http\Remote'
     *
     * @param string $endpoint
     * @param array  $params Request parameters as described in https://getkirby.com/docs/reference/objects/http/remote/request#params-array
     *
     * @return array PayPal REST API response
     */
    private static function request(string $endpoint, array $params = []): array
    {
        if (option('ww.merx.production') === true) {
            $baseUrl = self::$paypalLiveApiEntry;
        } else {
            $baseUrl = self::$paypalSandboxApiEntry;
        }
        $endpoint = $baseUrl . $endpoint;
        $response = Remote::request(
            $endpoint,
            $params,
        );
        if (in_array(substr($response->code(), 0, 1), ['4', '5'])) {
            throw new Exception([
                'key' => 'merx.paypalError',
                'httpCode' => $response->code(),
                'details' => [
                    'paypalResponse' => $response->json(),
                ],
            ]);
        }
        return (array)$response->json();
    }

    /**
     * Create OAuth 2.0 access tokens
     *
     * @see https://developer.paypal.com/api/rest/authentication/ PayPal REST API Documentation
     *
     * @return array Contains the authentication information with the following structure:
     *     - 'scope': (string) A space-separated list of permissions granted by the access token.
     *     - 'access_token': (string) The token that can be used to access PayPal APIs.
     *     - 'token_type': (string) Indicates the type of token, typically "Bearer".
     *     - 'app_id': (string) The PayPal application ID to which this token applies.
     *     - 'expires_in': (int) The number of seconds until the token expires.
     *     - 'nonce': (string) A unique nonce value associated with this token, used for validation purposes.
     */
    private static function getAccessToken(): array
    {
        if (option('ww.merx.production') === true) {
            $auth = option('ww.merx.paypal.live.clientID') . ':' . option('ww.merx.paypal.live.secret');
        } else {
            $auth = option('ww.merx.paypal.sandbox.clientID') . ':' . option('ww.merx.paypal.sandbox.secret');
        }
        $endpoint = '/v1/oauth2/token';
        $response = self::request(
            $endpoint,
            [
                'method' => 'POST',
                'basicAuth' => $auth,
                'data' => 'grant_type=client_credentials',
            ],
        );
        return $response;
    }

    /**
     * Create PayPal order from OrderPage
     *
     * @see https://developer.paypal.com/docs/api/orders/v2/#orders_create PayPal REST API Documentation
     *
     * @param \OrderPage $orderPage
     *
     * @return array PayPal order details.
     *     - 'id': (string) The ID of the order.
     *     - 'status': (string) The order status.
     *     - 'payment_source': (array) The payment source used to fund the payment.
     *     - 'links': (array) An array of request-related HATEOAS links. To complete payer approval, use the approve link to redirect the payer.
     */
    public static function createPayPalPayment(OrderPage $orderPage): array
    {
        $siteTitle = (string)site()->title();
        $access = self::getAccessToken();

        if (option('ww.merx.paypal.purchaseUnits')) {
            $purchaseUnits = option('ww.merx.paypal.purchaseUnits')();
        } else {
            $purchaseUnits = [
                [
                    'description' => $siteTitle,
                    'amount' => [
                        'value' => number_format($orderPage->cart()->getSum(), 2, '.', ''),
                        'currency_code' => option('ww.merx.currency'),
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
        $response = self::request(
            $endpoint,
            [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $access['token_type'] . ' ' . $access['access_token'],
                ],
                'data' => json_encode($data),
            ],
        );
        return $response;
    }

    /**
     * Capture PayPal payment for order
     *
     * @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture PayPal REST API Documentation
     *
     * @param string $payPalOrderId The ID of the PayPal order for which to capture a payment.
     *
     * @return array Captured PayPal payment details
     *     - 'id': (string) The ID of the order.
     *     - 'status': (string) The order status.
     *     - 'payment_source': (array) The payment source used to fund the payment.
     *     - 'purchase_units': (array) An array of purchase units.
     *     - 'payer': (array) The customer who approves and pays for the order. The customer is also known as the payer.
     *     - 'links': (array) An array of request-related HATEOAS links.
     */
    public static function executePayPalPayment(string $payPalOrderId): array
    {
        $access = self::getAccessToken();
        $endpoint = '/v2/checkout/orders/' . $payPalOrderId . '/capture';
        $response = self::request(
            $endpoint,
            [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $access['token_type'] . ' ' . $access['access_token'],
                ],
            ],
        );
        return $response;
    }
}
