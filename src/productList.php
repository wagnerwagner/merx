<?php
namespace Wagnerwagner\Merx;

use Wagnerwagner\Merx\Merx;
use Kirby\Toolkit\Collection;
use Kirby\Toolkit\A;

/**
 * [
 *   [
 *     'id' => string 'nice-shoes'
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
     * @param mixed $args `($data)` or `($id, $data)`. $data must contain a valid product page id.
     */
    public function append(...$args): self
    {
        if (count($args) === 1) {
            if (!is_array($args[0])) {
                throw new \Exception('First argument has to be an array');
            }
            if (!array_key_exists('id', $args[0])) {
                throw new \Exception('Array must have an id');
            }
            $item = $args[0];
            $this->set($item['id'], $item);
        } else if (count($args) === 2) {
            $item = $args[1];
            $item['id'] = $args[0];
            $this->set($args[0], $item);
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
        if (!array_key_exists('id', $item)) {
            throw new \Exception('Array must have an id');
        }
        $id = $item['id'];
        $existingItem = $this->get($id);
        $quantity = $item['quantity'] ?? $existingItem['quantity'];
        if ($existingItem) {
            if ($quantity <= 0) {
                $this->remove($id);
            } else {
                $existingItem = A::merge($existingItem, $item, A::MERGE_OVERWRITE);
                $this->set($id, $existingItem);
            }
        } else if ($quantity > 0) {
            $this->set($id, $item);
        }
        return $this;
    }


    /**
     * Sets title, price and tax automagically if $key is a valid page slug.
     *
     * @param string $key
     * @param array $value
     */
    public function __set(string $key, $value): self
    {
        if (!isset($value['id'])) {
            $value['id'] = $key;
        }
        if (!isset($value['quantity'])) {
            $value['quantity'] = 1;
        }
        if ($page = page($value['id'])) {
            if (!isset($value['title'])) {
                $value['title'] = $page->title()->toString();
            }
            if (!isset($value['price'])) {
                $value['price'] = $page->price()->toFloat();
            }
            if (!isset($value['tax'])) {
                $value['tax'] = Merx::calculateTax($value['price'], $page->tax()->toFloat());
            }
        }
        if (!isset($value['price'])) {
            throw new \Exception('You have to provide a "price" or a valid "id".');
        }
        if (!array_key_exists('tax', $value)) {
            $value['tax'] = 0;
        }

        $value['sum'] = (string)($value['price'] * $value['quantity']);
        $value['sumTax'] = (string)($value['tax'] * $value['quantity']);

        if ($value['quantity'] < 0) {
            throw new \Exception('Quantity cannot be negative.');
        }
        $this->data[strtolower($key)] = $value;

        if ($this->getTax() < 0) {
            throw new \Exception('Tax of Cart must be positive');
        }

        if ($this->getSum() < 0) {
            throw new \Exception('Sum of Cart must be positive');
        }

        return $this;
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
        return array_map(function($item) {
            $item['price'] = Merx::formatPrice((float)$item['price']);
            $item['tax'] = Merx::formatPrice((float)$item['tax']);
            $item['sum'] = Merx::formatPrice((float)($item['sum']));
            return $item;
        }, $this->values());
    }
}
