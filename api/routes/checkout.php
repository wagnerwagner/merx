<?php

use Kirby\Toolkit\I18n;

/** @var string $endpoint ww.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/checkout',
		'auth' => false,
		'method' => 'POST',
		'action' => function (): array
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$data = $this->requestBody();
			$paymentIntentId = kirby()->session()->get('ww.site.paymentIntentId', '');
			$data = array_merge($data, [
				'stripePaymentIntentId' => $paymentIntentId,
			]);

			$merx = merx();
			$data['paymentMethod'] = $data['paymentMethod'] ?? $data['paymentmethod'] ?? $data['payment-method'] ?? null;

			$redirect = $merx->initializePayment($data);

			go($redirect);
		},
	],
];
