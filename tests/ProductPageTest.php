<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductPage;

class ProductPageTest extends TestCase
{
    protected App $kirby;
    protected ProductPage $product;

    public function setUp(): void
    {
        $this->kirby = new App([
            'options' => [
                'ww.merx.currency.default' => 'EUR',
            ],
            'site' => [
                'children' => [
                    [
                        'slug' => 'products',
                        'template' => 'products',
                        'children' => [
                            [
                                'slug' => 'test-product',
                                'template' => 'product',
                                'model' => ProductPage::class,
                                'content' => [
                                    'price' => '19.99',
                                    'prices' => [
                                        [
                                            'price' => '22.99',
                                            'currency' => 'USD'
                                        ],
                                        [
                                            'price' => '19.99',
                                            'currency' => 'EUR'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->product = $this->kirby->page('products/test-product');
    }

    public function testPrice(): void
    {
        $price = $this->product->price();
        $this->assertInstanceOf(Price::class, $price);
        $this->assertEquals(19.99, $price->price);
        $this->assertEquals('EUR', $price->currency);

        $usdPrice = $this->product->price('USD');
        $this->assertInstanceOf(Price::class, $usdPrice);
        $this->assertEquals(22.99, $usdPrice->price);
        $this->assertEquals('USD', $usdPrice->currency);
    }

    public function testPrices(): void
    {
        $prices = $this->product->prices();
        $this->assertEquals(2, $prices->count());
        
        $firstPrice = $prices->first();
        $this->assertInstanceOf(Price::class, $firstPrice);
        $this->assertEquals(22.99, $firstPrice->price);
        $this->assertEquals('USD', $firstPrice->currency);
    }

    public function testOrders(): void
    {
        $orders = $this->product->orders();
        $this->assertInstanceOf(\Kirby\Cms\Pages::class, $orders);
    }
}
