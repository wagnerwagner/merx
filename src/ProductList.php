<?php

namespace Wagnerwagner\Merx;

use Wagnerwagner\Merx\Merx;
use Kirby\Toolkit\Collection;
use Kirby\Toolkit\A;

/**
 * [
 *   [
 *     'id' => string 'nice-shoes'
 *     'key' => string 'nice-shoes'
 *     'title' => string 'Nice Shoes',
 *     'quantity' => float 2.0,
 *     'price' => float 45.0,
 *     'tax' => float 7.18,
 *   ]
 * ]
 */


class ProductList extends Collection
{
    /**
     * Appends item to ProductList
     *
     * @param mixed $args `($data)` or `($id, $data)`.
     * @return $this
     */
    public function append(...$args)
    {
        if (count($args) === 1) {
            if (!is_array($args[0])) {
                throw new \Exception('First argument has to be an array');
            }
            $item = $args[0];
            $item['key'] = $item['key'] ?? $item['id'];
            if (!array_key_exists('key', $item)) {
                throw new \Exception('Array must have a ‘key’ or ‘id’');
            }
            $this->set($item['key'], $item);
        } elseif (count($args) === 2) {
            $this->set($args[0], $args[1]);
        }

        return $this;
    }


    /**
     * Updates existing ProductList item
     *
     * @param array $item Must contain a valid product page id.
     */
    public function updateItem(array $item): self
    {
        $item['key'] = $item['key'] ?? $item['id'];
        if (!array_key_exists('key', $item)) {
            throw new \Exception('Array must have a ‘key’ or ‘id’');
        }
        $key = $item['key'];
        $existingItem = $this->get($key);
        $quantity = (float)($item['quantity'] ?? $existingItem['quantity']);
        if ($existingItem) {
            if ($quantity <= 0) {
                $this->remove($key);
            } else {
                $existingItem = A::merge($existingItem, $item, A::MERGE_OVERWRITE);
                $this->set($key, $existingItem);
            }
        } elseif ($quantity > 0) {
            $this->set($key, $item);
        }
        return $this;
    }


    /**
     * Sets title, price and tax automagically if $key is a valid page slug.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set(string $key, $value): void
    {
        $key = $value['key'] ?? $value['id'] ?? $key;
        if (!isset($value['id'])) {
            $value['id'] = $key;
        }
        if (!isset($value['key'])) {
            $value['key'] = $key;
        }
        if (!isset($value['quantity'])) {
            $value['quantity'] = 1;
        }
        if (isset($value['taxrate'])) {
            $value['taxRate'] = $value['taxrate'];
        }
        if ($page = page($value['id'])) {
            if (!isset($value['title'])) {
                $value['title'] = $page->title()->toString();
            }
            if (!isset($value['price'])) {
                if (is_numeric($page->price())) {
                    $value['price'] = (float)$page->price();
                } else {
                    $value['price'] = $page->price()->toFloat();
                }
            }
            if (!isset($value['taxRate'])) {
                if (is_numeric($page->tax())) {
                    $value['taxRate'] = (float)$page->tax();
                } else {
                    $value['taxRate'] = $page->tax()->exists() ? $page->tax()->toFloat() : 0;
                }
            }
            if (!isset($value['template'])) {
                $value['template'] = $page->intendedTemplate()->name();
            }
            if (!isset($value['uid'])) {
                $value['uid'] = $page->uid();
            }
            foreach (option('ww.merx.cart.fields', []) as $fieldName) {
                $field = $page->{$fieldName}();
                if (is_a($field, '\Kirby\Cms\Field') && $field->isNotEmpty()) {
                    $value[$fieldName] = $field->toString();
                } elseif (
                    $field === null ||
                    is_scalar($field) ||
                    is_string($field) ||
                    (is_object($field) && method_exists($field, '__toString'))
                ) {
                    $value[$fieldName] = (string)$field;
                }
            }
        }
        if (!isset($value['price'])) {
            throw new \Exception('You have to provide a "price" or a page with a price field.');
        }
        if (!isset($value['taxRate'])) {
            $value['taxRate'] = 0;
        }
        if (!isset($value['tax'])) {
            $value['tax'] = Merx::calculateTax($value['price'], $value['taxRate']);
        }

        $value['sum'] = (float)($value['price'] * $value['quantity']);
        $value['sumTax'] = (float)($value['tax'] * $value['quantity']);
        $value['quantity'] = (float)$value['quantity'];

        if ($value['quantity'] < 0) {
            throw new \Exception('The quantity of the cart must not be negative.');
        }

        $value['key'] = $key;

        $this->data[strtolower($key)] = $value;

        if ($this->getTax() < 0) {
            throw new \Exception('The tax of the cart must not be negative');
        }

        if ($this->getSum() < 0) {
            throw new \Exception('The sum of the cart must not be negative');
        }
    }


    /**
     * Tax of all items.
     */
    public function getTax(): float
    {
        $tax = 0.0;
        foreach ($this->data() as $item) {
            $tax += (float)$item['quantity'] * ((float)$item['tax'] ?? 0);
        }
        return $tax;
    }


    /**
     * Taxes grouped by tax rate
     * @since 1.3.0
     */
    public function getTaxRates(): array
    {
        $taxRates = array_unique($this->pluck('taxRate'));
        $taxRates = array_filter($taxRates, function ($taxRate) {
            return (float)$taxRate !== (float)0;
        });
        sort($taxRates);
        $taxRates = array_map(function ($taxRate) {
            $sum = 0;
            foreach ($this->data() as $item) {
                if ($item['taxRate'] === $taxRate) {
                    $sum += (float)$item['sumTax'];
                }
            }
            return [
                'taxRate' => (float)$taxRate,
                'sum' => (float)$sum,
            ];
        }, $taxRates);

        return $taxRates;
    }


    /**
     * Sum of all items including tax.
     */
    public function getSum(): float
    {
        $sum = 0.0;
        foreach ($this->data() as $item) {
            $sum += (float)$item['quantity'] * (float)$item['price'];
        }
        return $sum;
    }


    /**
     * Formats price, tax and sum.
     */
    public function getFormattedItems(): array
    {
        return array_map(function ($item) {
            $item['price'] = Merx::formatPrice((float)$item['price']);
            $item['tax'] = Merx::formatPrice((float)$item['tax']);
            $item['sum'] = Merx::formatPrice((float)($item['sum']));
            $item['sumTax'] = Merx::formatPrice((float)($item['sumTax']));
            return $item;
        }, $this->values());
    }
}
