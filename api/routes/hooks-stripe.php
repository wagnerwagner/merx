<?php

use Wagnerwagner\Merx\StripePayment;

return [
    'pattern' => 'merx/hooks/stripe',
    'auth' => false,
    'method' => 'GET|POST',
    'action'  => function () {
        /** @var \Kirby\Cms\Api $this */
        $payload = @file_get_contents('php://input');

        $event = StripePayment::constructEvent($payload);
        $this->kirby()->trigger('ww.merx.stripe-hooks', [
            'stripeEvent' => $event,
        ]);

        return [
            'type' => $event->type,
        ];
    },
];
