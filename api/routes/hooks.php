<?php

use Kirby\Exception\InvalidArgumentException;
use Wagnerwagner\Merx\StripePayment;

/** @var string $endpoint wagnerwagner.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/hooks/stripe',
		'auth' => false,
		'method' => 'POST',
		/**
		 * @see https://merx.wagnerwagner.de/guide/configuration/stripe-webhooks
		 * @return array Array with the `type` of the received Stripe event.
		 */
		'action' => function (): array
		{
			/** @var \Kirby\Api\Api $this */
			$this->kirby()->setCurrentTranslation($this->language());

			$payload = file_get_contents('php://input');
			if (is_string($payload) === false || $payload === '') {
				throw new InvalidArgumentException(
					key: 'merx.stripeWebhook',
					httpCode: 400,
				);
			}

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
