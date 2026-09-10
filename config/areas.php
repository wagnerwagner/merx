<?php

use Kirby\Cms\Page;
use Wagnerwagner\Merx\OrderPage;

/**
 * Merx’ Panel view buttons
 *
 * Kirby merges the `buttons` of every area into one list, so the button only
 * has to be registered somewhere. The area has to be keyed by an existing id,
 * otherwise Kirby registers an area of its own and the next plugin returning
 * an unkeyed area overwrites it.
 */
return [
	'site' => fn (): array => [
		'buttons' => [
			/**
			 * Opens the order’s transaction at the payment provider
			 *
			 * Enable it per blueprint, see `blueprints/pages/order.yml`.
			 */
			'paymentProvider' => function (Page $page): ?array {
				if ($page instanceof OrderPage === false) {
					return null;
				}

				$link = $page->paymentProviderUrl();

				if ($link === null) {
					return null;
				}

				return [
					'text' => match ($page->paymentProvider()) {
						'stripe' => 'Stripe',
						'paypal' => 'PayPal',
						default => t('field.payment'),
					},
					'icon' => 'credit-card',
					'theme' => 'blue',
					'link' => $link,
					'target' => '_blank',
				];
			},
		],
	],
];
