<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\ProductList;


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

        $productList->append(['id' => 'nice-socks', 'price' => 10]);
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


    public function testGetFormattedItems(): void
    {
        $productList = new ProductList(['nice-shoes' => ['price' => 99.99]]);

        $this->assertEquals(
            'nice-shoes',
            $productList->getFormattedItems()[0]['id']
        );

        $this->assertEquals(
            '€ 99.99',
            $productList->getFormattedItems()[0]['price']
        );

        $this->assertEquals(
            '€ 0.00',
            $productList->getFormattedItems()[0]['tax']
        );
    }
}
