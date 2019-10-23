<?php
namespace Wagnerwagner\Merx;

use Wagnerwagner\Merx\ProductList;
use Kirby\Exception\Exception;

class Cart extends ProductList
{
    protected $sessionName = 'ww.merx.cartItems';


    /**
     * @param array $data List of product items. Product items must contain `id`. `quantity`, `title`, `price`, `tax` are optional.
     */
    public function __construct(array $data = [])
    {
        $kirby = kirby();
        if (count($data) === 0 && is_array($kirby->session()->get($this->sessionName))) {
            $data = $kirby->session()->get($this->sessionName);
        }
        parent::__construct($data);
        $kirby->trigger('ww.merx.cart', $this);
    }


    /**
     * Adds item to cart.
     *
     * @param array $cartItem Must contain a valid product page slug as id.
     * @throws Exception error.merx.cart.add
     */
    public function add(array $cartItem): self
    {
        try {
            if (!isset($cartItem['id'])) {
                throw new \Exception('No "id" is provided.');
            }
            $page = page($cartItem['id']);
            if (!$page) {
                throw new \Exception('Page not found.');
            } else if ((string)$page->intendedTemplate() !== 'product') {
                throw new \Exception('Page is not a product page.');
            }

            $this->append($cartItem);
            $this->save();
            return $this;
        } catch (\Exception $ex) {
            throw new Exception([
                'key' => 'merx.cart.add',
                'data' => [
                    'id' => $cartItem['id'] ?? '',
                ],
                'details' => [
                    'exception' => $ex,
                ],
            ]);
        }
    }


    /**
     * Removes item from Cart by key
     *
     * @param mixed $key the name of the key
     */
    public function remove($key)
    {
        parent::remove($key);
        $this->save();
    }



    /**
     * Updates existing items.
     *
     * @param array $cartItems List of cart items.
     */
    public function update(array $cartItems): parent
    {
        try {
            foreach ($cartItems as $cartItem) {
                parent::updateItem($cartItem);
            }
            $this->save();
            return $this;
        } catch (\Exception $ex) {
            throw new Exception([
                'key' => 'merx.cart.update',
                'details' => [
                    'exception' => $ex,
                ],
            ]);
        }
    }


    /**
     * Updates existing item.
     *
     * @param array $updatedItem Must contain a valid product page id.
     */
    public function updateItem(array $item): parent
    {
        parent::updateItem($item);
        $this->save();
        return $this;
    }

    /**
     * Get Stripe’s PaymentIntent.
     */

    public function getStripePaymentIntent(): object
    {
        return Payment::createStripePaymentIntent($this->getSum());
    }


    /**
     * Removes Cart from user’s session.
     */
    public function delete(): void
    {
        kirby()->session()->remove($this->sessionName);
    }


    private function save(): self
    {
        kirby()->session()->set($this->sessionName, $this->toArray());
        return $this;
    }
}
