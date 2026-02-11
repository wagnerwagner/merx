<?php

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/checkout',
		'auth' => false,
		'method' => 'POST',
		/** Required post data keys:
		 * `paymentGateway` or `paymentgateway` or `payment-gateway`
		 * and all fields required by the order blueprint
		 *
		 * @return array Array with redirect url when request header accepts json, otherwise redirects with code 303.
		 */
		'action' => function (): array
		{
			/** @var \Kirby\Api\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$data = $this->requestBody();

			$merx = merx();
			$cart = $merx->cart();

			$data['paymentGateway'] = $data['paymentGateway'] ?? $data['paymentgateway'] ?? $data['payment-gateway'] ?? null;

			$stripePaymentIntent = ($data['paymentGateway'] === 'stripe-elements') ?  $cart->getStripePaymentIntent(option('wagnerwagner.merx.stripe.paymentIntentParameters', [])) : null;
			$data['stripePaymentIntentId'] = $stripePaymentIntent->id;
			$redirectUrl = $merx->initializeOrder($data);

			if ($this->kirby()->visitor()->acceptsMimeType('application/json')) {
				return [
					'status' => 'redirect',
					'message' => 'redirect',
					'code' => 303,
					'stripeClientSecret' => $stripePaymentIntent->client_secret,
					'redirectUrl' => $redirectUrl,
				];
			}

			go($redirectUrl, 303);
		},
	],
];
