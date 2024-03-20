<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Kirby\Data\Data;
use OrderPage;

function completeStripePayment(OrderPage $virtualOrderPage, array $data): OrderPage
{
    // check if user canceled payment
    if (isset($data['source']) && StripePayment::getStatusOfSource($data['source']) === 'failed') {
        throw new Exception([
            'key' => 'merx.paymentCanceled',
            'httpCode' => 400,
        ]);
    }
    // charge payment
    $sourceString = $data['source'] ?? $virtualOrderPage->stripeToken()->toString();
    $stripeCharge = StripePayment::createStripeCharge($virtualOrderPage->cart()->getSum(), $sourceString);
    $virtualOrderPage->content()->update([
        'paymentDetails' => (array)$stripeCharge,
        'paymentComplete' => true,
        'paidDate' => date('c'),
    ]);
    return $virtualOrderPage;
}

/**
 * Gateway class dummy holder
 *
 * This class only holds the static $gateways array.
 *
 */
class Gateways
{
    public static array $gateways = [];
}

/**
 *  Definition of the initializePayment and completePayment methods for PayPal stored in the $gateways array
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
            'payPalOrderId' => $response['id'],
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
        $paypalResponse = PayPalPayment::executePayPalPayment((string)$virtualOrderPage->payPalOrderId());
        $virtualOrderPage->content()->update([
            'paymentDetails' => Data::encode($paypalResponse, 'yaml'),
            'paymentComplete' => true,
            'paidDate' => date('c'),
        ]);
        return $virtualOrderPage;
    }
];

/**
 * Credit Card payment gateway
 *
 * @deprecated 1.7.3
 *
 */
Gateways::$gateways['credit-card'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];

/**
 *  Credit Card payment gateway using Stripe
 */
Gateways::$gateways['credit-card-sca'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        $stripePaymentIntentId = $virtualOrderPage->stripePaymentIntentId()->toString();
        $paymentIntent = StripePayment::getStripePaymentIntent($stripePaymentIntentId);
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
        $source = StripePayment::createStripeSource($virtualOrderPage->cart()->getSum(), 'sofort', $data);
        $virtualOrderPage->content()->update([
            'redirect' => $source->redirect->url,
        ]);
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];
