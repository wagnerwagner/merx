<?php

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/checkout',
		'auth' => false,
		'method' => 'POST',
		/** Required post data keys:
		 * `paymentMethod` or `paymentmethod` or `payment-method`
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

			$data['paymentMethod'] = $data['paymentMethod'] ?? $data['paymentmethod'] ?? $data['payment-method'] ?? null;

			if ($data['paymentMethod'] === 'stripe-elements') {
				$paymentIntentId = kirby()->session()->get('wagnerwagner.merx.stripePaymentIntentId', '');
				$data = array_merge($data, [
					'stripePaymentIntentId' => $paymentIntentId,
				]);
			}

			$redirectUrl = $merx->initializeOrder($data);

			if ($this->kirby()->visitor()->acceptsMimeType('application/json')) {
				return [
					'status' => 'redirect',
					'message' => 'redirect',
					'code' => 303,
					'redirectUrl' => $redirectUrl,
				];
			}

			go($redirectUrl, 303);
		},
	],
];
