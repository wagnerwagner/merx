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
		 */
		'action' => function ()
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$data = $this->requestBody();
			$paymentIntentId = kirby()->session()->get('wagnerwagner.merx.stripePaymentIntentId', '');
			$data = array_merge($data, [
				'stripePaymentIntentId' => $paymentIntentId,
			]);

			$merx = merx();
			$data['paymentMethod'] = $data['paymentMethod'] ?? $data['paymentmethod'] ?? $data['payment-method'] ?? null;

			$redirect = $merx->initializeOrder($data);

			go($redirect);
		},
	],
];
