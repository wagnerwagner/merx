<?php

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/client-secret',
		'auth' => false,
		'action' => function (): array
		{
				/** @var \Kirby\Api\Api $this */
				$paymentMethod = $this->requestQuery('payment-method', 'card');
				$params = [
						'payment_method_types' => [$paymentMethod],
				];

				if ($paymentMethod === 'sepa_debit') {
					$params['capture_method'] = 'automatic';
				}

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
