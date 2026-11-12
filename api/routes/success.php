<?php

use Kirby\Exception\Exception as KirbyException;
use Wagnerwagner\Merx\Merx;

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/success',
		'auth' => false,
		'method' => 'GET',
		/**
		 * @return void Redirects to the order page, or back to the checkout page when the order could not be completed.
		 */
		'action' => function () {
			/** @var \Kirby\Api\Api $this */
			Merx::setApiTranslation($this);

			try {
				$merx = merx();
				$orderPage = $merx->createOrder($_GET);
				go($orderPage->url());
			} catch (Exception $exception) {
				// Only throw exception for json requests, otherwise redirect to checkout page.
				if ($this->kirby()->visitor()->acceptedMimeType()?->type() === 'application/json') {
					throw $exception;
				}

				// Kept for the checkout page, which shows it with `merx()->error()`
				$this->kirby()->session()->set(Merx::$errorSessionKey, [
					'key' => $exception instanceof KirbyException ? $exception->getKey() : null,
					'message' => $exception->getMessage(),
				]);

				go(site()->checkoutPage());
			}
		},
	],
];
