<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class MerxTest extends TestCase
{
    public function testFormatPrice(): void
    {
        $this->assertEquals(
            '€ 10.20',
            Merx::formatPrice(10.20)
        );
    }
    public function testFormatPriceDE(): void
    {
        setlocale(LC_ALL, 'de_DE');
        $this->assertEquals(
            '10,20 €',
            Merx::formatPrice(10.20, false, true)
        );
    }
    public function testFormatPrice1(): void
    {
        setlocale(LC_ALL, 'C');
        $this->assertEquals(
            '€ 11.99',
            Merx::formatPrice(11.99, true)
        );
    }
    public function testFormatPrice2(): void
    {
        $this->assertEquals(
            '1984.12 €',
            Merx::formatPrice(1984.12, false)
        );
    }

    public function testFormatIBAN(): void
    {
        $this->assertEquals(
            'DE89 3704 0044 0532 0130 00',
            Merx::formatIBAN('DE89370400440532013000')
        );
    }

    public function testCalculateTax(): void
    {
        $this->assertEquals(
            31.932773109243698,
            Merx::calculateTax(200, 19)
        );
    }

    public function testCart(): void
    {
        $merx = new Merx();
        $this->assertInstanceOf(
            Cart::class,
            $merx->cart()
        );
    }

    public function testInitializePaymentEmpty(): void
    {
        $merx = new Merx();
        $this->expectExceptionCode('error.merx.emptycart');
        $merx->initializePayment([]);
    }

    public function testCompletePaymentEmpty(): void
    {
        $merx = new Merx();
        $this->expectExceptionCode('error.merx.completePayment');
        $merx->completePayment([]);
    }

    public function testMessages(): void
    {
        $testMessage = 'Test Message';
        Merx::setMessage($testMessage);
        $this->assertEquals(
            $testMessage,
            Merx::getMessage()
        );
    }
}
