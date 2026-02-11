<?php

use Stripe\Event;
use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\OrderPage;

return [
	'wagnerwagner.merx.stripe-hooks' => function (Event $stripeEvent): void
	{
		switch ($stripeEvent->type) {
			case 'payment_intent.succeeded':
				/** @var \Stripe\PaymentIntent $paymentIntent */
				$paymentIntent = $stripeEvent->data->object;
				$orderUid = $paymentIntent->metadata->order_uid;
				if ($orderUid) {
					try {
						/** @var ?OrderPage $orderPage */
						$orderPage = page(option('wagnerwagner.merx.ordersPage'). '/' . $orderUid);
						if ($orderPage) {
							$kirby = $orderPage->kirby();
							$orderPage = $kirby->impersonate('kirby', function () use ($orderPage, $paymentIntent): OrderPage {
								return $orderPage->update([
									'paymentDetails' => (array)$paymentIntent->toArray(),
									'paymentComplete' => true,
									'datePaid' => date('c'),
								]);
							});
						}
					} catch(Exception) {}
				}
				break;
		}
	},
	'wagnerwagner.merx.cart.add:after' => function (Cart $cart):void {},
	'wagnerwagner.merx.cart.add:before' => function (Cart $cart, array $data):void {},
	'wagnerwagner.merx.cart.add:before' => function (Cart $cart, string|array|ListItem $data): void {},
	'wagnerwagner.merx.cart.create:after' => function (Cart $cart):void {},
	'wagnerwagner.merx.cart.create:before' => function (Cart $cart, array $data):void {},
	'wagnerwagner.merx.cart.delete:after' => function (Cart $cart):void {},
	'wagnerwagner.merx.cart.delete:before' => function (Cart $cart):void {},
	'wagnerwagner.merx.cart.remove:after' => function (Cart $cart, string $key):void {},
	'wagnerwagner.merx.cart.remove:before' => function (Cart $cart, string $key):void {},
	'wagnerwagner.merx.cart.updateItem:after' => function (Cart $cart, string $key, array $data):void {},
	'wagnerwagner.merx.cart.updateItem:before' => function (Cart $cart, string $key, array $data):void {},
	'wagnerwagner.merx.createOrder:after' => function (OrderPage $orderPage):void {},
	'wagnerwagner.merx.createOrder:before' => function (OrderPage $virtualOrderPage, array $gateway, array $data):void {},
	'wagnerwagner.merx.initializeOrder:after' => function (OrderPage $virtualOrderPage, string $redirect):void {},
	'wagnerwagner.merx.initializeOrder:before' => function (Cart $cart, array $data):void {},
	'wagnerwagner.merx.paymentCompleted' => function (OrderPage $orderPage):void {},

	/** @internal */
	'page.update:after' => function (Kirby\Cms\Page $newPage, Kirby\Cms\Page $oldPage) {
		if ($newPage instanceof OrderPage && $oldPage instanceof OrderPage) {
			/** @var \Wagnerwagner\Merx\OrderPage $newPage */
			/** @var \Wagnerwagner\Merx\OrderPage $oldPage */
			if ($newPage->paymentComplete()->toBool() === true && $oldPage->paymentComplete()->toBool() === false) {
				$newPage->kirby()->trigger('wagnerwagner.merx.paymentCompleted', ['orderPage' => $newPage]);
			}
		}
	},
];
