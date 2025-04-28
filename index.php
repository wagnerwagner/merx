<?php

use Kirby\Cms\App;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\ProductList;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Plugin\Plugin;
use Wagnerwagner\Merx\License;

@include_once __DIR__ . '/vendor/autoload.php';

function merx(): Merx
{
	return new Merx();
}

function cart(array $data = []): Cart
{
	return new Cart($data);
}

function productList(array $data = []): ProductList
{
	return new ProductList($data);
}

function formatCurrency(int|float $number, string $currency): string
{
	return Merx::formatCurrency($number, $currency);
}

function formatIBAN(string $iban): string
{
	return Merx::formatIBAN($iban);
}

function calculateTax(float $grossPrice, float $tax): float
{
	return Merx::calculateTax($grossPrice, $tax);
}

function calculateNet(float $grossPrice, float $tax): float
{
	return Merx::calculateNet($grossPrice, $tax);
}

App::plugin(
	name: 'ww/merx',
	extends: [
		'api' => [
			'collections' => array_merge(
				include __DIR__ . '/api/collections/listItems.php',
			),
			'routes' => function (App $kirby) {
				$endpoint = $kirby->option('ww.merx.api.endpoint', 'shop');
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
		'options' => [
			'ordersPage' => 'orders',
			'currency' => fn () => 'EUR',
			'currency.default' => 'EUR',
			'currencies' => [],
			'production' => false,
			'api.endpoint' => 'shop',
		],
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
		],
		'translations' => [
			'en' => include __DIR__ . '/translations/en.php',
			'de' => include __DIR__ . '/translations/de.php',
		],
		'hooks' => [
			'page.changeNum:before' => function (Page $page, ?int $num) {
				if ((string)$page->intendedTemplate() === 'order' && $page->isListed() && $num !== $page->num()) {
					throw new Exception(['key' => 'merx.order.changeNum']);
				}
			},
			'page.changeStatus:before' => function (Page $page) {
				if ((string)$page->intendedTemplate() === 'order' && $page->isListed()) {
					throw new Exception(['key' => 'merx.order.changeStatus']);
				}
			},
			'ww.merx.stripe-hooks' => function (\Stripe\Event $stripeEvent) {
				switch ($stripeEvent->type) {
					case 'payment_intent.succeeded':
						/** @var \Stripe\PaymentIntent $paymentIntent */
						$paymentIntent = $stripeEvent->data->object;
						$orderId = $paymentIntent->metadata->order_uid;
						if ($orderId) {
							try {
								/** @var ?OrderPage $orderPage */
								$orderPage = page(option('ww.merx.ordersPage'). '/' . $orderId);
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
			'toFormattedPrice' => function ($field, string|null $currency = null) {
				return Merx::formatCurrency($field->toFloat(), $currency ?? option('ww.merx.currency.default'));
			},
		],
		'siteMethods' => [
		  'cart' => fn (): Cart => cart(),
			'ordersPage' => fn (): ?Page => /** @var \Kirby\Cms\Site $this */ $this->page(option('ww.merx.ordersPage')),
			'checkoutPage' => fn (): ?Page => /** @var \Kirby\Cms\Site $this */ $this->children()->template('checkout')->first(),
	  ]
	],
	license: function (Plugin $plugin) {
		return new License($plugin);
	},
);
