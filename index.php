<?php

use Kirby\Cms\App;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\Cart;
use Kirby\Cms\Page;
use Kirby\Content\Field;
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
	name: 'wagnerwagner/merx',
	extends: [
		'api' => [
			'collections' => array_merge(
				include __DIR__ . '/api/collections/listItems.php',
			),
			'data' => array_merge(
				include __DIR__ . '/api/data/cart.php',
			),
			'routes' => function (App $kirby) {
				$endpoint = (string)$kirby->option('wagnerwagner.merx.api.endpoint', 'shop');
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
			'order' => __DIR__ . '/templates/order.php',
			'orders' => __DIR__ . '/templates/orders.php',
		],
		'blueprints' => [
			'fields/list-items' => __DIR__ . '/blueprints/fields/list-items.yml',
			'wagnerwagner.merx.fields/list-items' => __DIR__ . '/blueprints/fields/list-items.yml',
			'fields/price' => __DIR__ . '/blueprints/fields/price.yml',
			'wagnerwagner.merx.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
			'fields/prices' => __DIR__ . '/blueprints/fields/prices.yml',
			'wagnerwagner.merx.fields/prices' => __DIR__ . '/blueprints/fields/prices.yml',
			'fields/tax-rule' => __DIR__ . '/blueprints/fields/tax-rule.yml',
			'wagnerwagner.merx.fields/tax-rule' => __DIR__ . '/blueprints/fields/tax-rule.yml',
			'layouts/order' => __DIR__ . '/blueprints/layouts/order.yml',
			'wagnerwagner.merx.layouts/order' => __DIR__ . '/blueprints/layouts/order.yml',
			'pages/checkout' => __DIR__ . '/blueprints/pages/checkout.yml',
			'wagnerwagner.merx.pages/checkout' => __DIR__ . '/blueprints/pages/checkout.yml',
			'pages/order' => __DIR__ . '/blueprints/pages/order.yml',
			'wagnerwagner.merx.pages/order' => __DIR__ . '/blueprints/pages/order.yml',
			'pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
			'wagnerwagner.merx.pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
			'pages/product' => __DIR__ . '/blueprints/pages/product.yml',
			'wagnerwagner.merx.pages/product' => __DIR__ . '/blueprints/pages/product.yml',
			'sections/order' => __DIR__ . '/blueprints/sections/order.yml',
			'wagnerwagner.merx.sections/order' => __DIR__ . '/blueprints/sections/order.yml',
			'sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
			'wagnerwagner.merx.sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
			'sections/payment' => __DIR__ . '/blueprints/sections/payment.yml',
			'wagnerwagner.merx.sections/payment' => __DIR__ . '/blueprints/sections/payment.yml',
			'sections/personal-data' => __DIR__ . '/blueprints/sections/personal-data.yml',
			'wagnerwagner.merx.sections/personal-data' => __DIR__ . '/blueprints/sections/personal-data.yml',
			'tabs/orders' => __DIR__ . '/blueprints/tabs/orders.yml',
			'wagnerwagner.merx.tabs/orders' => __DIR__ . '/blueprints/tabs/orders.yml',
			'tabs/shop-settings' => __DIR__ . '/blueprints/tabs/shop-settings.yml',
			'wagnerwagner.merx.tabs/shop-settings' => __DIR__ . '/blueprints/tabs/shop-settings.yml',
		],
		'translations' => [
			'de' => include __DIR__ . '/translations/de.php',
			'en' => include __DIR__ . '/translations/en.php',
		],
		'hooks' => include __DIR__ . '/config/hooks.php',
		'fieldMethods' => [
			'toFormattedPrice' =>
			/**
			 * Convert field to formatted price
			 *
			 * @param \Kirby\Content\Field $field Field
			 * @param ?string $currency Three-letter ISO currency code. When null, currency from pricing rule is used
			 */
			function (Field $field, string|null $currency = null): string
			{
				return Merx::formatCurrency($field->toFloat(), $currency ?? Merx::pricingRule()?->currency);
			},
		],
		'pageModels' => [
			'product' => \Wagnerwagner\Merx\ProductPage::class,
			'order' => \Wagnerwagner\Merx\OrderPage::class,
		],
		'siteMethods' => [
		  'cart' =>
				/** Current cart of user */
				fn (): Cart => cart(),
			'checkoutPage' =>
				/** First page with template “checkout” */
				fn (): ?Page => /** @var \Kirby\Cms\Site $this */ $this->children()->template('checkout')->first(),
			'merx' =>
				/** Current Merx instance */
				fn (): Merx => merx(),
			'ordersPage' =>
				/** Parent page of all orders */
				fn (): ?Page => /** @var \Kirby\Cms\Site $this */ $this->page(option('wagnerwagner.merx.ordersPage')),
			'pricingRules' =>
				/** Pricing rules as defined in wagnerwagner.merx.pricingRules */
				fn (): PricingRules => Merx::pricingRules(),
			'taxRules' =>
				/** Pricing rules as defined in wagnerwagner.merx.taxRules */
				fn (): TaxRules => Merx::taxRules(),
	  ]
	],
	license: function (Plugin $plugin) {
		return new License($plugin);
	},
);
