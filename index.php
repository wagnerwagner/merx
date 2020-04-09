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

function formatPrice(float $price, bool $currencyPositionPrecedes = null, bool $currencySeparateBySpace = null): string
{
    return Merx::formatPrice($price, $currencyPositionPrecedes, $currencySeparateBySpace);
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

function crossfoot(int $int){
    $r = 0;
    foreach(str_split($int) as $v) $r += $v;
    return $r;
}

Kirby::plugin('ww/merx', [
    'options' => [
        'successPage' => 'success',
        'ordersPage' => 'orders',
        'currency' => 'EUR',
        'currencySymbol' => '€',
        'email' => 'admin@website.com',
        'production' => false,
    ],
    'templates' => [
        'success' => __DIR__ . '/templates/success.php',
        'orders' => __DIR__ . '/templates/orders.php',
        'order' => __DIR__ . '/templates/order.php',
    ],
    'blueprints' => [
        'pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
        'pages/order' => __DIR__ . '/blueprints/pages/order.yml',
        'pages/product' => __DIR__ . '/blueprints/pages/product.yml',
        'sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
        'sections/order' => __DIR__ . '/blueprints/sections/order.yml',
        'sections/payment-method' => __DIR__ . '/blueprints/sections/payment-method.yml',
    ],
    'translations' => [
        'en' => [
            'error.merx.initializePayment' => 'The payment could not be initialized.',
            'error.merx.noPaymentMethod' => 'No payment method provided.',
            'error.merx.fieldsvalidation' => 'Field validation failed.',
            'error.merx.emptycart' => 'Cart is empty.',
            'error.merx.completePayment' => 'The payment could not be completed.',
            'error.merx.paymentCanceled' => 'You canceled the payment.',
            'error.merx.cart.add' => 'Item "{id}" could not be added to cart.',
            'error.merx.cart.update' => 'Cart items could not be updated.',
            'error.merx.order.changeNum' => 'Sorting number of a complete order cannot be changed.',
            'error.merx.order.changeStatus' => 'Status of a complete order cannot be changed.',
        ],
        'de' => [
            'error.merx.initializePayment' => 'Die Bezahlung konnte nicht initialisiert werden.',
            'error.merx.noPaymentMethod' => 'Keine Zahlungsmethode angegeben.',
            'error.merx.fieldsvalidation' => 'Felder sind invalide.',
            'error.merx.emptycart' => 'Der Warenkorb ist leer.',
            'error.merx.completePayment' => 'Die Bezahlung konnte nicht abgeschlossen werden.',
            'error.merx.paymentCanceled' => 'Die Bezahlung wurde abgebrochen.',
            'error.merx.cart.add' => 'Produkt "{id}" konnte nicht zum Warenkorb hinzugefügt werden.',
            'error.merx.cart.update' => 'Produkte konnten nicht aktualisiert werden.',
            'error.merx.order.changeNum' => 'Die Position einer vollständigen Bestellung kann nicht geändert werden.',
            'error.merx.order.changeStatus' => 'Der Status einer vollständigen Bestellung kann nicht geändert werden.',
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
    ],
]);
