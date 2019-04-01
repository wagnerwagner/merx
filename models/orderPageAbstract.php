<?php
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\ProductList;
use Kirby\Toolkit\V;
use Kirby\Cms\Page;

abstract class OrderPageAbstract extends Page {

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
};
