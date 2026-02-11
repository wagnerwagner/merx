<?php

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/stripe-client-secret',
		'auth' => false,
		'action' => function (): array
		{
				/** @var \Kirby\Api\Api $this */
				$params = option('wagnerwagner.merx.stripe.paymentIntentParameters', []);

				/** @var \Wagnerwagner\Merx\Cart $cart */
				$cart = $this->cart();
				$paymentIntent = $cart->getStripePaymentIntent($params);
				kirby()->session()->set('wagnerwagner.merx.stripePaymentIntentId', $paymentIntent->id);
				return [
					'clientSecret' => $paymentIntent->client_secret,
				];
		},
	]
];
