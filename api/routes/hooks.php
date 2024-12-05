<?php

use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\StripePayment;

/** @var string $endpoint ww.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/hooks/stripe',
		'auth' => false,
		'method' => 'GET|POST',
		'action'  => function () {
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$payload = @file_get_contents('php://input');

			$event = StripePayment::constructEvent($payload);
			$this->kirby()->trigger('ww.merx.stripe-hooks', [
				'stripeEvent' => $event,
			]);

			return [
				'type' => $event->type,
			];
		},
	],
];
