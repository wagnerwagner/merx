<?php

use Kirby\Cms\App;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\Cart;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Exception\Exception;
use Kirby\Plugin\Plugin;
use Wagnerwagner\Merx\License;
use Wagnerwagner\Merx\PricingRules;
use Wagnerwagner\Merx\TaxRules;

@include_once __DIR__ . '/vendor/autoload.php';

function merx(): Merx
{
	return new Merx();
}

function cart(array $data = []): Cart
{
	return new Cart($data);
}

function formatIBAN(string $iban): string
{
	return Merx::formatIBAN($iban);
}

App::plugin(
	name: 'ww/merx',
	extends: [
		'api' => [
			'collections' => array_merge(
				include __DIR__ . '/api/collections/listItems.php',
			),
			'data' => array_merge(
				include __DIR__ . '/api/data/cart.php',
			),
			'routes' => function (App $kirby) {
				$endpoint = (string)$kirby->option('ww.merx.api.endpoint', 'shop');
				return array_merge(
					include __DIR__ . '/api/routes/cart.php',
					include __DIR__ . '/api/routes/checkout.php',
					include __DIR__ . '/api/routes/client-secret.php',
					include __DIR__ . '/api/routes/hooks.php',
					include __DIR__ . '/api/routes/success.php',
				);
			},
			'models' => [
				'Cart' => include __DIR__ . '/api/models/Cart.php',
				'ListItem' => include __DIR__ . '/api/models/ListItem.php',
				'Price' => include __DIR__ . '/api/models/Price.php',
				'ProductList' => include __DIR__ . '/api/models/ProductList.php',
				'Tax' => include __DIR__ . '/api/models/Tax.php',
			],
		],
		'options' => include __DIR__ . '/config/config.php',
		'templates' => [
			'orders' => __DIR__ . '/templates/orders.php',
		],
		'blueprints' => [
			'fields/list-items' => __DIR__ . '/blueprints/fields/list-items.yml',
			'ww.merx.fields/list-items' => __DIR__ . '/blueprints/fields/list-items.yml',
			'fields/price' => __DIR__ . '/blueprints/fields/price.yml',
			'ww.merx.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
			'fields/prices' => __DIR__ . '/blueprints/fields/prices.yml',
			'ww.merx.fields/prices' => __DIR__ . '/blueprints/fields/prices.yml',
			'layouts/order' => __DIR__ . '/blueprints/layouts/order.yml',
			'ww.merx.layouts/order' => __DIR__ . '/blueprints/layouts/order.yml',
			'pages/order' => __DIR__ . '/blueprints/pages/order.yml',
			'ww.merx.pages/order' => __DIR__ . '/blueprints/pages/order.yml',
			'pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
			'ww.merx.pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
			'pages/product' => __DIR__ . '/blueprints/pages/product.yml',
			'ww.merx.pages/product' => __DIR__ . '/blueprints/pages/product.yml',
			'sections/order' => __DIR__ . '/blueprints/sections/order.yml',
			'ww.merx.sections/order' => __DIR__ . '/blueprints/sections/order.yml',
			'sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
			'ww.merx.sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
			'sections/payment' => __DIR__ . '/blueprints/sections/payment.yml',
			'ww.merx.sections/payment' => __DIR__ . '/blueprints/sections/payment.yml',
			'tabs/orders' => __DIR__ . '/blueprints/tabs/orders.yml',
			'ww.merx.tabs/orders' => __DIR__ . '/blueprints/tabs/orders.yml',
			'tabs/shop-settings' => __DIR__ . '/blueprints/tabs/shop-settings.yml',
			'ww.merx.tabs/shop-settings' => __DIR__ . '/blueprints/tabs/shop-settings.yml',
		],
		'translations' => [
			'de' => include __DIR__ . '/translations/de.php',
			'en' => include __DIR__ . '/translations/en.php',
		],
		'hooks' => [
			'ww.merx.stripe-hooks' => function (\Stripe\Event $stripeEvent): void
			{
				switch ($stripeEvent->type) {
					case 'payment_intent.succeeded':
						/** @var \Stripe\PaymentIntent $paymentIntent */
						$paymentIntent = $stripeEvent->data->object;
						$orderUid = $paymentIntent->metadata->order_uid;
						if ($orderUid) {
							try {
								/** @var ?OrderPage $orderPage */
								$orderPage = page(option('ww.merx.ordersPage'). '/' . $orderUid);
								if ($orderPage) {
									$kirby = $orderPage->kirby();
									$kirby->impersonate('kirby', function () use ($orderPage, $paymentIntent) {
										$orderPage?->update([
											'paymentDetails' => (array)$paymentIntent->toArray(),
											'paymentComplete' => true,
											'paidDate' => date('c'),
										]);
									});
								}
							} catch(Exception) {}
						}
						break;
				}
			}
		],
		'fieldMethods' => [
			'toFormattedPrice' => function (Field $field, string|null $currency = null): string
			{
				return Merx::formatCurrency($field->toFloat(), $currency ?? Merx::pricingRule()?->currency);
			},
		],
		'siteMethods' => [
		  'cart' => fn (): Cart => cart(),
			'checkoutPage' => fn (): ?Page => /** @var \Kirby\Cms\Site $this */ $this->children()->template('checkout')->first(),
			'merx' => fn (): Merx => merx(),
			'ordersPage' => fn (): ?Page => /** @var \Kirby\Cms\Site $this */ $this->page(option('ww.merx.ordersPage')),
			'pricingRules' => fn (): PricingRules => Merx::pricingRules(),
			'taxRules' => fn (): TaxRules => Merx::taxRules(),
	  ]
	],
	license: function (Plugin $plugin) {
		return new License($plugin);
	},
);
