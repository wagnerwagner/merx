<?php

use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\StripePayment;

/** @var string $endpoint ww.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/success',
		'auth' => false,
		'method' => 'GET|POST',
		'action' => function () {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$orderPage = merx()->completePayment($_GET);
			go($orderPage->url());

			return [
				'redirect' => $orderPage->url(),
			];
		},
	],
];
