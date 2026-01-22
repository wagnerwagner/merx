<?php

use Kirby\Toolkit\I18n;

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/success',
		'auth' => false,
		'method' => 'GET',
		'action' => function ()
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			try {
				$merx = merx();
				$orderPage = $merx->createOrder($_GET);
				go($orderPage->secureUrl());
			} catch (Exception $exception) {
				// Only throw exception for json requests, otherwise redirect to checkout page.
				if ($this->kirby()->visitor()->acceptsMimeType('application/json')) {
					throw $exception;
				}
				go($this->site()->checkoutPage());
			}
		},
	],
];
