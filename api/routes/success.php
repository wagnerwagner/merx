<?php

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/success',
		'auth' => false,
		'method' => 'GET',
		'action' => function ()
		{
			/** @var \Kirby\Cms\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			try {
				$merx = merx();
				$orderPage = $merx->createOrder($_GET);
				go($orderPage->url());
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
