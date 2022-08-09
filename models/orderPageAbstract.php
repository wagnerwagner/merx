<?php

use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\ProductList;

abstract class OrderPageAbstract extends Page
{
    public function title(): \Kirby\Cms\Field
    {
        return new Field($this, 'title', $this->invoiceNumber());
    }

    /**
     * Cart of this Order.
     */
    public function cart(): ProductList
    {
        return new ProductList($this->items()->yaml());
    }


    public function formattedSum(): string
    {
        return Merx::formatPrice($this->cart()->getSum());
    }


    /**
     * 5 character long string based on sorting number.
     */
    public function invoiceNumber(): string
    {
        if ($this->num()) {
            return str_pad($this->num(), 5, 0, STR_PAD_LEFT);
        } else {
            return '';
        }
    }


    /**
    * Helper method that returns paidDate or payedDate field of the OrderPage.
    * Before Merx 1.7 a “payedDate” field was stored in the OrderPage for complete payments.
    * Since Merx 1.7 a “paidDate” field is stored instead.
    *
    * @deprecated Rename “Payeddate” fields to “Paiddate” in every order page and use $orderPage->paidDate()
    */
    public function payedDate(): \Kirby\Cms\Field
    {
        if ($this->content()->payedDate()->isNotEmpty()) {
            return $this->content()->payedDate();
        }
        return $this->content()->paidDate();
    }
}
