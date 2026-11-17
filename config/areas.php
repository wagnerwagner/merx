<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Wagnerwagner\Merx\OrderPage;
use Wagnerwagner\Merx\SystemView;

$isAdmin = fn (): bool => App::instance()->user()?->isAdmin() === true;

/**
 * Merx’ Panel area and view buttons
 *
 * Kirby merges the `buttons` of every area into one list, so the button only
 * has to be registered somewhere. The area has to be keyed by an existing id,
 * otherwise Kirby registers an area of its own and the next plugin returning
 * an unkeyed area overwrites it.
 */
return [
	/**
	 * Version, the keys in use and security checks. Only for admins, because
	 * it shows the end of the keys.
	 */
	'merx' => fn (): array => [
		'label' => 'Merx',
		'icon' => 'cart',
		'menu' => $isAdmin,
		'views' => [
			'merx' => [
				'pattern' => 'merx',
				'when' => $isAdmin,
				'action' => fn (): array => [
					'component' => 'k-merx-view',
					'title' => 'Merx',
					'props' => SystemView::props(),
				],
			],
		],
	],
	'site' => fn (): array => [
		'buttons' => [
			/**
			 * Opens the order’s transaction at the payment provider
			 *
			 * Enable it per blueprint, see `blueprints/pages/order.yml`.
			 */
			'paymentProvider' => function (Page $page): array|null {
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
