<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
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
    if (version_compare(App::version(), '5.0.0', '>=')) {
        $virtualOrderPage->version('latest')->update([
            'paymentDetails' => (array)$paymentIntent->toArray(),
        ]);
    } else {
        $virtualOrderPage->content()->update([
            'paymentDetails' => (array)$paymentIntent->toArray(),
        ]);
    }

    // Prepare meta data
    $metadata = [
        'order_uid' => (string)$virtualOrderPage->uid(),
    ];

    if ($paymentIntent->status === 'requires_capture') {
        // Capture Payment Intent
        $paymentIntent = $paymentIntent->capture([
            'metadata' => $metadata,
        ]);
    } else {
        // Update Payment Intent
        $paymentIntent = $paymentIntent->update($paymentIntentId, [
            'metadata' => $metadata,
        ]);
    }

    // Update content of VirtualOrderPage
    if ($paymentIntent->status === 'succeeded') {
        if (version_compare(App::version(), '5.0.0', '>=')) {
            $virtualOrderPage->version('latest')->update([
                'paymentComplete' => true,
                'paidDate' => date('c'),
            ]);
        } else {
            $virtualOrderPage->content()->update([
                'paymentComplete' => true,
                'paidDate' => date('c'),
            ]);
        }
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

        if (version_compare(App::version(), '5.0.0', '>=')) {
            $virtualOrderPage->version('latest')->update([
                'payPalOrderId' => $response['id'],
                'redirect' => $response['links'][1]['href'],
            ]);
        } else {
            $virtualOrderPage->content()->update([
                'payPalOrderId' => $response['id'],
                'redirect' => $response['links'][1]['href'],
            ]);
        }
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        // check if user canceled payment
        if (!isset($data['PayerID'])) {
            throw new Exception([
                'key' => 'merx.paymentCanceled',
                'httpCode' => 400,
            ]);
        }

        // execute payment
        $paypalResponse = PayPalPayment::executePayPalPayment((string)$virtualOrderPage->payPalOrderId());
        if (version_compare(App::version(), '5.0.0', '>=')) {
            $virtualOrderPage->version('latest')->update([
                'paymentDetails' => Data::encode($paypalResponse, 'yaml'),
                'paymentComplete' => true,
                'paidDate' => date('c'),
            ]);
        } else {
            $virtualOrderPage->content()->update([
                'paymentDetails' => Data::encode($paypalResponse, 'yaml'),
                'paymentComplete' => true,
                'paidDate' => date('c'),
            ]);
        }
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

/** @deprecated Use Klarna instead. More information: https://support.stripe.com/questions/sofort-is-being-deprecated-as-a-standalone-payment-method */
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

        $redirect = $paymentIntent->next_action->redirect_to_url->url;
        if (version_compare(App::version(), '5.0.0', '>=')) {
            $virtualOrderPage->version('latest')->update([
                'redirect' => $redirect,
            ]);
        } else {
            $virtualOrderPage->content()->update([
                'redirect' => $redirect,
            ]);
        }
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

        $redirect = $paymentIntent->next_action->redirect_to_url->url;
        if (version_compare(App::version(), '5.0.0', '>=')) {
            $virtualOrderPage->version('latest')->update([
                'redirect' => $redirect,
            ]);
        } else {
            $virtualOrderPage->content()->update([
                'redirect' => $redirect,
            ]);
        }
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        $virtualOrderPage = completeStripePayment($virtualOrderPage, $data);
        return $virtualOrderPage;
    },
];

Gateways::$gateways['ideal'] = [
    'initializePayment' => function (OrderPage $virtualOrderPage): OrderPage {
        $cart = new Cart();
        $paymentIntent = $cart->getStripePaymentIntent([
            'payment_method_types' => ['ideal'],
            'capture_method' => 'automatic',
            'confirm' => true,
            'payment_method_data' => [
                'type' => 'ideal',
            ],
            'return_url' => url(option('ww.merx.successPage')),
        ]);

        $redirect = $paymentIntent->next_action->redirect_to_url->url;
        if (version_compare(App::version(), '5.0.0', '>=')) {
            $virtualOrderPage->version('latest')->update([
                'redirect' => $redirect,
            ]);
        } else {
            $virtualOrderPage->content()->update([
                'redirect' => $redirect,
            ]);
        }
        return $virtualOrderPage;
    },
    'completePayment' => function (OrderPage $virtualOrderPage, array $data): OrderPage {
        $virtualOrderPage = completeStripePayment($virtualOrderPage, $data);
        return $virtualOrderPage;
    },
];
