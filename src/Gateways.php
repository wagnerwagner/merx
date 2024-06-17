<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Kirby\Data\Data;
use OrderPage;

/**
 * Captures stripe payment intent
 *
 * @param OrderPage $virtualOrderPage Virtual order page which may contain `stripePaymentIntentId` field (Credit Card Payment)
 * @param array $data Additional data from get request which may contain `payment_intent` (Klarna Payment)
 * @throws \Kirby\Exception\Exception|\Stripe\Exception\ApiErrorException Kirby exception, when user canceled the payment or Stripe API Exception
 *
 * @return OrderPage Virtual order page with updated payment details
 */
function completeStripePayment(OrderPage $virtualOrderPage, array $data): OrderPage
{
    // Check if user canceled payment
    if (isset($data['redirect_status']) ? $data['redirect_status'] === 'failed' : false) {
        throw new Exception([
            'key' => 'merx.paymentCanceled',
            'httpCode' => 400,
        ]);
    }

    // Retrieve Payment Intent
    $paymentIntentId = (string)($data['payment_intent'] ?? $virtualOrderPage->stripePaymentIntentId()->toString());
    $paymentIntent = StripePayment::retrieveStripePaymentIntent($paymentIntentId);

    // Update content of VirtualOrderPage
    $content = [
        'paymentDetails' => (array)$paymentIntent->toArray(),
    ];
    if ($paymentIntent->status === 'succeeded') {
        $content = array_merge($content, [
            'paymentComplete' => true,
            'paidDate' => date('c'),
        ]);
    }
    $virtualOrderPage->content()->update($content);

    // Prepare meta data
    $metadata = [
        'order_uid' => (string)$virtualOrderPage->uid(),
    ];
    // Capture Payment Intent
    if ($paymentIntent->status === 'requires_capture') {
        $paymentIntent = $paymentIntent->capture([
            'metadata' => $metadata,
        ]);
    } else {
        $paymentIntent->update($paymentIntentId, [
            'metadata' => $metadata,
        ]);
    }

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
 *  Credit Card payment gateway using Stripe
 */
Gateways::$gateways['credit-card-sca'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];

Gateways::$gateways['sepa-debit'] = [
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        return completeStripePayment($virtualOrderPage, $data);
    },
];

Gateways::$gateways['sofort'] = [
    'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
        $country = $virtualOrderPage->country()->toString();

        $cart = new Cart();
        $paymentIntent = $cart->getStripePaymentIntent([
            'payment_method_types' => ['sofort'],
            'capture_method' => 'automatic',
            'confirm' => true,
            'payment_method_data' => [
                'type' => 'sofort',
                'sofort' => [
                  'country' => $country,
                ],
            ],
            'return_url' => url(option('ww.merx.successPage')),
        ]);

        $virtualOrderPage->content()->update([
            'redirect' => $paymentIntent->next_action->redirect_to_url->url,
        ]);
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        $virtualOrderPage = completeStripePayment($virtualOrderPage, $data);
        return $virtualOrderPage;
    },
];

Gateways::$gateways['klarna'] = [
    'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
        $email = $virtualOrderPage->email()->toString();
        $country = $virtualOrderPage->country()->toString();

        $cart = new Cart();
        $paymentIntent = $cart->getStripePaymentIntent([
            'payment_method_types' => ['klarna'],
            'confirm' => true,
            'payment_method_data' => [
                'type' => 'klarna',
                'billing_details' => [
                  'email' => $email,
                  'address' => [
                      'country' => $country,
                  ],
                ],
            ],
            'return_url' => url(option('ww.merx.successPage')),
        ]);

        $virtualOrderPage->content()->update([
            'redirect' => $paymentIntent->next_action->redirect_to_url->url,
        ]);
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        $virtualOrderPage = completeStripePayment($virtualOrderPage, $data);
        return $virtualOrderPage;
    },
];
