<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Cart;


final class CartTest extends TestCase
{
    public function testCartException1(): void
    {
        $cart = new Cart();
        $this->expectExceptionCode('error.merx.cart.add');
        $cart->add([]);
    }


    public function testCartException2(): void
    {
        $cart = new Cart();
        $this->expectExceptionCode('error.merx.cart.add');
        $cart->add(['id' => 'nice-shoes']);
    }
}
