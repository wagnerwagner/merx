<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class ProductListTest extends TestCase
{
    public function testAppend(): void
    {
        $productList = new ProductList();
        $productList->append(['id' => 'nice-shoes', 'price' => 99.99]);

        $this->assertInstanceOf(
            ProductList::class,
            $productList
        );

        $this->assertEquals(
            99.99,
            $productList->first()['price']
        );
        $this->assertEquals(
            99.99,
            $productList->getSum()
        );

        $productList = $productList->append(['id' => 'nice-socks', 'price' => 10]);
        $this->assertEquals(
            109.99,
            $productList->getSum()
        );
    }


    public function testUpdateItem(): void
    {
        $productList = new ProductList(['nice-shoes' => ['price' => 99.99]]);

        $productList->updateItem(['id' => 'nice-shoes', 'price' => 89.99, 'tax' => 10]);
        $this->assertEquals(
            89.99,
            $productList->getSum()
        );
        $this->assertEquals(
            10,
            $productList->getTax()
        );

        $productList->updateItem(['id' => 'nice-shoes', 'quantity' => 2]);
        $this->assertEquals(
            179.98,
            $productList->getSum()
        );
        $this->assertEquals(
            20,
            $productList->getTax()
        );
    }


    public function testTax(): void
    {
        $productList = new ProductList([
            'nice-shoes' => [
                'price' => 99.99,
                'taxRate' => 19,
            ],
            'nice-socks' => [
                'price' => 14.99,
                'quantity' => 2,
                'taxRate' => 7,
            ],
            't-shirt' => [
                'price' => 19.99,
                'quantity' => 2,
                'tax' => 5,
            ],
            'shipping' => [
                'price' => 4.99,
                'taxRate' => 0,
            ],
        ]);

        $this->assertEquals(
            99.99 / (19 + 100) * 19,
            $productList->get('nice-shoes')['sumTax']
        );

        $this->assertEquals(
            14.99 * 2 / (7 + 100) * 7,
            $productList->get('nice-socks')['sumTax']
        );

        $this->assertEquals(
            5 * 2,
            $productList->get('t-shirt')['sumTax']
        );
        $this->assertEquals(
            0.0,
            $productList->get('t-shirt')['taxRate']
        );

        $this->assertEquals(
            [
                [
                    'taxRate' => 7,
                    'sum' => 14.99 * 2 / (7 + 100) * 7,
                ],
                [
                    'taxRate' => 19,
                    'sum' => 99.99 / (19 + 100) * 19,
                ],
            ],
            $productList->getTaxRates()
        );
    }


    public function testGetFormattedItems(): void
    {
        $productList = new ProductList(['nice-shoes' => ['price' => 99.99, 'quantity' => 2]]);

        $this->assertEquals(
            'nice-shoes',
            $productList->getFormattedItems()[0]['id']
        );

        $this->assertEquals(
            '€ 99.99',
            $productList->getFormattedItems()[0]['price']
        );

        $this->assertEquals(
            '€ 199.98',
            $productList->getFormattedItems()[0]['sum']
        );


        $this->assertEquals(
            '€ 0.00',
            $productList->getFormattedItems()[0]['tax']
        );
    }
}
