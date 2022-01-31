<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testCartException1(): void
    {
        $this->expectExceptionCode('error.merx.cart.add');
        $cart = new Cart();
        $cart->add([]);
    }


    public function testCartException2(): void
    {
        $this->expectExceptionCode('error.merx.cart.add');
        $cart = new Cart();
        $cart->add(['id' => 'nice-shoes']);
    }
}
