<?php

use Kirby\Toolkit\I18n;

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
			/** @var \Kirby\Cms\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$data = $this->requestBody();
			$paymentIntentId = kirby()->session()->get('wagnerwagner.merx.stripePaymentIntentId', '');
			$data = array_merge($data, [
				'stripePaymentIntentId' => $paymentIntentId,
			]);

			$merx = merx();
			$data['paymentMethod'] = $data['paymentMethod'] ?? $data['paymentmethod'] ?? $data['payment-method'] ?? null;

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
