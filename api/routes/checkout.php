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
		 * @see https://merx.wagnerwagner.de/guide/getting-started/create-checkout
		 * @return array Array with redirect url when json is the preferred mime type, otherwise redirects with code 303.
		 */
		'action' => function (): array
		{
			/** @var \Kirby\Cms\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$data = $this->requestBody();

			$merx = merx();

			$data['paymentGateway'] = $data['paymentGateway'] ?? $data['paymentgateway'] ?? $data['payment-gateway'] ?? $data['payment_gateway'] ?? null;

			$redirectUrl = $merx->initializeOrder($data);

			// The preferred type, not acceptsMimeType(): a browser's `*/*` fallback
			// accepts json too, which would answer a plain form post with json.
			if ($this->kirby()->visitor()->acceptedMimeType()?->type() === 'application/json') {
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
