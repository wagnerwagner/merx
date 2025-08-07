<?php

use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\ProductList;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;

@include_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/models/orderPageAbstract.php';


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

function formatPrice(float $price, null|bool $currencyPositionPrecedes = null, null|bool $currencySeparateBySpace = null, null|string $currencySymbol = null): string
{
    return Merx::formatPrice($price, $currencyPositionPrecedes, $currencySeparateBySpace, $currencySymbol);
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

function crossfoot(int $int): string
{
    $r = 0;
    foreach (str_split($int) as $v) {
        $r += $v;
    }
    return $r;
}

Kirby::plugin('ww/merx', [
    'api' => [
        'routes' => [
            require_once(__DIR__ . '/api/routes/hooks-stripe.php'),
        ],
    ],
    'options' => [
        'successPage' => 'success',
        'ordersPage' => 'orders',
        'currency' => 'EUR',
        'currencySymbol' => '€',
        'currencySymbols' => [
            'EUR' => '€',
            'USD' => '$',
            'CHF' => 'CHF',
        ],
        'production' => false,
    ],
    'templates' => [
        'success' => __DIR__ . '/templates/success.php',
        'orders' => __DIR__ . '/templates/orders.php',
    ],
    'blueprints' => [
        'fields/price' => __DIR__ . '/blueprints/fields/price.yml',
        'ww.merx.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
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
    ],
    'translations' => [
        'en' => [
            'error.merx.initializePayment' => 'The payment could not be initialized.',
            'error.merx.noPaymentMethod' => 'No payment method provided.',
            'error.merx.fieldsvalidation' => 'Field validation failed.',
            'error.merx.emptycart' => 'Cart is empty.',
            'error.merx.completePayment' => 'The payment could not be completed.',
            'error.merx.paymentCanceled' => 'You canceled the payment.',
            'error.merx.paypalError' => 'PayPal error',
            'error.merx.cart.add' => 'Item "{id}" could not be added to cart.',
            'error.merx.cart.update' => 'Cart items could not be updated.',
            'error.merx.order.changeNum' => 'Sorting number of a complete order cannot be changed.',
            'error.merx.order.changeStatus' => 'Status of a complete order cannot be changed.',
            'field.key' => 'Key',
            'field.id' => 'ID',
            'field.info' => 'Info',
            'field.payment' => 'Payment',
            'field.paymentMethod' => 'Payment method',
            'field.paymentComplete' => 'Payment complete',
            'field.price' => 'Price',
            'field.tax' => 'Tax',
            'field.items' => 'Cart items',
            'field.quantity' => 'Quantity',
            'field.overview' => 'Overview',
            'field.order' => 'Order',
            'page.title.product' => 'Product',
            'page.status.error' => 'Error',
            'page.status.incomplete' => 'Incomplete',
            'page.status.complete' => 'Complete',
            'section.headline.orders' => 'Orders',
        ],
        'de' => [
            'error.merx.initializePayment' => 'Die Bezahlung konnte nicht initialisiert werden.',
            'error.merx.noPaymentMethod' => 'Keine Zahlungsmethode angegeben.',
            'error.merx.fieldsvalidation' => 'Felder sind invalide.',
            'error.merx.emptycart' => 'Der Warenkorb ist leer.',
            'error.merx.completePayment' => 'Die Bezahlung konnte nicht abgeschlossen werden.',
            'error.merx.paymentCanceled' => 'Die Bezahlung wurde abgebrochen.',
            'error.merx.paypalError' => 'PayPal Fehler',
            'error.merx.cart.add' => 'Produkt "{id}" konnte nicht zum Warenkorb hinzugefügt werden.',
            'error.merx.cart.update' => 'Produkte konnten nicht aktualisiert werden.',
            'error.merx.order.changeNum' => 'Die Position einer vollständigen Bestellung kann nicht geändert werden.',
            'error.merx.order.changeStatus' => 'Der Status einer vollständigen Bestellung kann nicht geändert werden.',
            'field.key' => 'Key',
            'field.id' => 'ID',
            'field.info' => 'Info',
            'field.payment' => 'Bezahlung',
            'field.paymentMethod' => 'Bezahlmethode',
            'field.paymentComplete' => 'Bezahlung vollständig',
            'field.price' => 'Preis',
            'field.tax' => 'Steuer',
            'field.items' => 'Warenkorb',
            'field.quantity' => 'Menge',
            'field.overview' => 'Übersicht',
            'field.order' => 'Bestellung',
            'page.title.product' => 'Produkt',
            'page.status.error' => 'Fehler',
            'page.status.incomplete' => 'Unvollständig',
            'page.status.complete' => 'Vollständig',
            'section.headline.orders' => 'Bestellungen',
        ],
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
        'route:before' => function ($route, $path, $method) {
            $successPage = new Page([
                'slug' => option('ww.merx.successPage'),
                'template' => 'success',
            ]);
            site()->children()->add($successPage);
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
        'toFormattedPrice' => function ($field, null|bool $currencyPositionPrecedes = null, null|bool $currencySeparateBySpace = null, null|string $currencySymbol = null) {
            return Merx::formatPrice($field->toFloat(), $currencyPositionPrecedes, $currencySeparateBySpace, $currencySymbol);
        },
    ],
]);
