<?php

use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\ListItem;

return [
	'ww.merx.stripe-hooks' => function (\Stripe\Event $stripeEvent): void
	{
		switch ($stripeEvent->type) {
			case 'payment_intent.succeeded':
				/** @var \Stripe\PaymentIntent $paymentIntent */
				$paymentIntent = $stripeEvent->data->object;
				$orderUid = $paymentIntent->metadata->order_uid;
				if ($orderUid) {
					try {
						/** @var ?OrderPage $orderPage */
						$orderPage = page(option('ww.merx.ordersPage'). '/' . $orderUid);
						if ($orderPage) {
							$kirby = $orderPage->kirby();
							$kirby->impersonate('kirby', function () use ($orderPage, $paymentIntent) {
								$orderPage?->update([
									'paymentDetails' => (array)$paymentIntent->toArray(),
									'paymentComplete' => true,
									'paidDate' => date('c'),
								]);
							});
						}
					} catch(Exception) {}
				}
				break;
		}
	},
	'ww.merx.cart.add:after' => function (Cart $cart):void {},
	'ww.merx.cart.add:before' => function (Cart $cart, array $data):void {},
	'ww.merx.cart.add:before' => function (Cart $cart, string|array|ListItem $data): void {},
	'ww.merx.cart.create:after' => function (Cart $cart):void {},
	'ww.merx.cart.create:before' => function (Cart $cart, array $data):void {},
	'ww.merx.cart.delete:after' => function (Cart $cart):void {},
	'ww.merx.cart.delete:before' => function (Cart $cart):void {},
	'ww.merx.cart.remove:after' => function (Cart $cart, string $key):void {},
	'ww.merx.cart.remove:before' => function (Cart $cart, string $key):void {},
	'ww.merx.cart.updateItem:after' => function (Cart $cart, string $key, array $data):void {},
	'ww.merx.cart.updateItem:before' => function (Cart $cart, string $key, array $data):void {},
	'ww.merx.initializeOrder:before' => function (Cart $cart, array $data):void {},
	'ww.merx.initializeOrder:after' => function (OrderPage $virtualOrderPage, string $redirect):void {},
	'ww.merx.createOrder:before' => function (OrderPage $virtualOrderPage, array $gateway, array $data):void {},
	'ww.merx.createOrder:after' => function (OrderPage $virtualOrderPage, array $gateway, array $data):void {},
];
