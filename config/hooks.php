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
	'ww.merx.cart.add:before' => function (Cart $cart, string|array|ListItem $data): void {},
];
