<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use OrderPage;

function completeStripePayment(OrderPage $virtualOrderPage, array $data): OrderPage
{
    // check if user canceled payment
    if (isset($data['source']) && Payment::getStatusOfSource($data['source']) === 'failed') {
        throw new Exception([
            'key' => 'merx.paymentCanceled',
            'httpCode' => 400,
        ]);
    }
    // charge payment
    $sourceString = $data['source'] ?? $virtualOrderPage->stripeToken()->toString();
    $stripeCharge = Payment::createStripeCharge($virtualOrderPage->cart()->getSum(), $sourceString);
    $virtualOrderPage->content()->update([
        'paymentDetails' => (array)$stripeCharge,
        'paymentComplete' => true,
        'paidDate' => date('c'),
    ]);
    return $virtualOrderPage;
}
/**
 * Gatway Class dummy holder
 *
 * This Class only holds the static $gateway Array.
 *
 * @category Gatway Class dummy holder
 * @package  Gatway Class dummy holder
 * @author   Alexander Kovac <a.kovac@wagnerwagner.de>
 * @license  https://wagnerwagner.de Copyright
 * @link     https://wagnerwagner.de
 */
class Gateways
{
    public static $gateways = [];
}

/**
 *  Definition of the initializePayment and complete Payment Methods stored in the $gateway Array
 */
Gateways::$gateways['paypal'] = [
    'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
        if (option('ww.merx.production') === true) {
            if (option('ww.merx.paypal.live.clientID') === null && option('ww.merx.paypal.live.secret') === null) {
                throw new Exception('Missing PayPal live keys');
            }
        } else {
            if (option('ww.merx.paypal.sandbox.clientID') === null && option('ww.merx.paypal.sandbox.secret') === null) {
                throw new Exception('Missing PayPal sandbox keys');
            }
        }
        $response = PayPalPayment::createPayPalPayment($virtualOrderPage);
        $virtualOrderPage->content()->update([
            'orderId' => $response['id'],
            'redirect' => $response['links'][1]['href'],
        ]);
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        // check if user canceled payment
        if (!isset($data['paymentId']) && !isset($data['PayerID'])) {
            throw new Exception([
                'key' => 'merx.paymentCanceled',
                'httpCode' => 400,
            ]);
        }
        // execute payment
        $paypalResponse = PayPalPayment::executePayPalPayment((string)$virtualOrderPage->orderId());
        $virtualOrderPage->content()->update([
            'paymentDetails' => (string) $paypalResponse,
            'paymentComplete' => true,
            'paidDate' => date('c'),
        ]);
        return $virtualOrderPage;
    }
];

/**
 * Credit Card Payment Gateway
 */
Gateways::$gateways['credit-card'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];

/**
 *  Credit Card Payment Gateway using Stripe
 */
Gateways::$gateways['credit-card-sca'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        $stripePaymentIntentId = $virtualOrderPage->stripePaymentIntentId()->toString();
        $paymentIntent = Payment::getStripePaymentIntent($stripePaymentIntentId);
        $paymentIntent->capture();
        $virtualOrderPage->content()->update([
            'paymentComplete' => true,
            'paidDate' => date('c'),
        ]);
        return $virtualOrderPage;
    },
];

Gateways::$gateways['sepa-debit'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];

Gateways::$gateways['sofort'] = [
    'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
        $data = [
            "sofort" => [
                "country" => "DE",
            ],
        ];
        $source = Payment::createStripeSource($virtualOrderPage->cart()->getSum(), 'sofort', $data);
        $virtualOrderPage->content()->update([
            'redirect' => $source->redirect->url,
        ]);
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];
