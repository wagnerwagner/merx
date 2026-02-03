<?php

use Kirby\Toolkit\I18n;
use Wagnerwagner\Merx\StripePayment;

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/hooks/stripe',
		'auth' => false,
		'method' => 'POST',
		'action' => function (): array
		{
			/** @var \Kirby\Cms\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$payload = @file_get_contents('php://input');

			$event = StripePayment::constructEvent($payload);
			$this->kirby()->trigger('wagnerwagner.merx.stripe-hooks', [
				'stripeEvent' => $event,
			]);

			return [
				'type' => $event->type,
			];
		},
	],
];
