<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductList;

/**
 * Page model for order page
 *
 * This model is used to store and validate order data.
 *
 * @method \Kirby\Content\Field email() User’s email address
 * @method \Kirby\Content\Field dateCreated() Date, when the order was created (date('c')).
 * @method \Kirby\Content\Field datePaid() Date, when the order was paid (date('c')).
 * @method \Kirby\Content\Field paymentComplete() True, when payment is complete
 * @method \Kirby\Content\Field payPalOrderId()
 * @method \Kirby\Content\Field stripePaymentIntentId()
 * @method \Kirby\Content\Field redirect() URL the user is redirected to
 * @method \Kirby\Content\Field orderNumber() Sequential number for each order. Can be customized with wagnerwagner.merx.orderNumber option.
 *
 * @see https://merx.wagnerwagner.de/guide/getting-started/displaying-order
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class OrderPage extends Page
{
	/**
	 * Returns order number
	 */
	public function title(): Field
	{
		return new Field($this, 'title', $this->orderNumber());
	}

	/**
	 * List of products of the Order
	 */
	public function cart(): ProductList
	{
		$data = $this->items()->yaml();
		$data = array_map(function (mixed $item) {
			$page = is_string($item['page']) ? $item['page'] : $item['page'][0] ?? null;
			$price = $item['price'] ? new Price(price: $item['price'], currency: $item['currency'] ?? null, tax: $item['taxrate'] ?? null) : null;
			return new ListItem(
				key: $item['key'],
				title: $item['title'] ?? null,
				page: $page,
				price: $price,
				quantity: $item['quantity'] ?? 1.0,
				quantifier: $item['quantifier'] ?? null,
				type: $item['type'] ?? null,
				data: $item['data'] ?? null,
				priceUpdate: false,
			);
		}, $data);

		return new ProductList($data);
	}

	/**
	 * Page uuids of products in this order
	 *
	 * Used in `ProductPage::orders()` to get the order pages for a product.
	 *
	 * @return string[]
	 */
	public function productUuids(): array
	{
		return array_map(fn (?Page $page) => (string)$page?->uuid(), $this->cart()->pluck('page'));
	}

	/**
	 * Total price of the order
	 */
	public function total(): ?Price
	{
		return $this->cart()->total();
	}

	/**
	 * Reconciliation record of the payment
	 *
	 * @see \Wagnerwagner\Merx\PaymentDetails::$keys
	 */
	public function paymentDetails(): array
	{
		return $this->content()->get('paymentDetails')->yaml();
	}

	/**
	 * Name of the payment provider which processed the order, e.g. `stripe`
	 */
	public function paymentProvider(): ?string
	{
		$provider = $this->paymentDetails()['provider'] ?? null;

		return is_string($provider) === true ? $provider : null;
	}

	/**
	 * Link to this order’s transaction in the provider’s dashboard
	 *
	 * Whether the live or the test dashboard is linked follows the `livemode`
	 * of the stored record. Records written before `livemode` was kept fall
	 * back to the `production` option.
	 *
	 * Returns `null` when Merx cannot build a link: invoices, custom gateways
	 * and PayPal orders without a capture.
	 */
	public function paymentProviderUrl(): ?string
	{
		$details = $this->paymentDetails();
		$livemode = $details['livemode'] ?? (option('wagnerwagner.merx.production') === true);

		return match ($this->paymentProvider()) {
			// Stripe addresses a payment by its PaymentIntent id
			'stripe' => isset($details['id'])
				? 'https://dashboard.stripe.com/' . ($livemode ? '' : 'test/') . 'payments/' . rawurlencode((string)$details['id'])
				: null,
			// PayPal’s activity view knows captures, not orders, so `reference` is used
			'paypal' => isset($details['reference'])
				? 'https://www.' . ($livemode ? '' : 'sandbox.') . 'paypal.com/activity/payment/' . rawurlencode((string)$details['reference'])
				: null,
			default => null,
		};
	}
}
