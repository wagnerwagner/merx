<?php

use Kirby\Cms\Field;
use Kirby\Cms\Page;
use Kirby\Form\Form;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\ProductList;

abstract class OrderPageAbstract extends Page
{
    /**
     * Returns all content validation errors
     * This is required since otherwise errors won’t show for virtual pages.
     *
     * @return array
     */
    public function errors(): array
    {
        $kirby = $this->kirby();
        if ($kirby->multilang() === true) {
            Kirby\Toolkit\I18n::$locale = $kirby->language()->code();
        }

        $fields = array_change_key_case($this->blueprint()->fields());
        // add model to each field
        $fields = array_map(function ($field) {
            $field['model'] = $this;
            return $field;
        }, $fields);

        $form = new Form([
            'fields' => $fields,
            'values' => $this->content()->toArray(),
        ]);
        return $form->errors();
    }

    /**
     * Returns invoiceNumber
     */
    public function title(): Field
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
    public function payedDate(): Field
    {
        if ($this->content()->payedDate()->isNotEmpty()) {
            return $this->content()->payedDate();
        }
        return $this->content()->paidDate();
    }
}
