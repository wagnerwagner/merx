<?php

use Kirby\Data\Yaml;
use Stripe\Event;
use Wagnerwagner\Merx\Cart;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Logger;
use Wagnerwagner\Merx\OrderPage;
use Wagnerwagner\Merx\PaymentDetails;

return [
	'wagnerwagner.merx.stripe-hooks' => function (Event $stripeEvent): void
	{
		switch ($stripeEvent->type) {
			case 'payment_intent.succeeded':
				/** @var \Stripe\PaymentIntent $paymentIntent */
				$paymentIntent = $stripeEvent->data->object;

				// Set by `Gateways::completeStripePayment()` when it captures
				$orderUid = $paymentIntent->metadata->order_uid ?? null;
				if (!$orderUid) {
					break;
				}

				$fail = function (string $reason) use ($orderUid): void {
					// This is the only path which completes payments the customer’s
					// browser never confirms, e.g. SEPA debit. Failing here silently
					// leaves an order unpaid with nobody knowing.
					if (option('wagnerwagner.merx.logging') === true) {
						Logger::log('Stripe webhook for order "' . $orderUid . '" ignored: ' . $reason, 'error');
					}
				};

				try {
					$orderPage = page(option('wagnerwagner.merx.ordersPage'). '/' . $orderUid);

					// `page()` resolves any page at that path, not necessarily an order
					if ($orderPage instanceof OrderPage === false) {
						$fail($orderPage === null ? 'no such order' : 'not an order page');
						break;
					}

					// The event has to be about the payment this order was created for
					if ($orderPage->stripePaymentIntentId()->value() !== $paymentIntent->id) {
						$fail('payment intent does not belong to it');
						break;
					}

					$kirby = $orderPage->kirby();
					$kirby->impersonate('kirby', function () use ($orderPage, $paymentIntent): OrderPage {
						return $orderPage->update([
							'paymentDetails' => Yaml::encode(PaymentDetails::fromStripePaymentIntent($paymentIntent)),
							'paymentComplete' => true,
							'datePaid' => date('c'),
						]);
					});
				} catch (Throwable $ex) {
					$fail($ex->getMessage());
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

	/**
	 * Orders which are paid after they have been created
	 *
	 * Gateways which are completed by the payment provider at a later point, e.g.
	 * the Stripe webhook. Orders which are paid when they are created are
	 * reported by `Merx::createOrder()`, and do not pass here a second time,
	 * because their `paymentComplete` was already true before.
	 *
	 * `apply()` rather than `trigger()`, for the same reason as in
	 * `Merx::createOrder()`: every hook gets the current order. Returning it
	 * makes `update()` return the order with what the hooks wrote.
	 *
	 * @internal
	 */
	'page.update:after' => function (Kirby\Cms\Page $newPage, Kirby\Cms\Page $oldPage) {
		if ($newPage instanceof OrderPage && $oldPage instanceof OrderPage) {
			if ($newPage->paymentComplete()->toBool() === true && $oldPage->paymentComplete()->toBool() === false) {
				return $newPage->kirby()->apply('wagnerwagner.merx.paymentCompleted', ['orderPage' => $newPage], 'orderPage');
			}
		}
	},
];
