<?php

namespace Wagnerwagner\Merx;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Content\VersionId;

/**
 * Page model for order page
 *
 * This model is used to store and validate order data.
 *
 * @method \Kirby\Content\Field email() Content field: Customer’s email address
 * @method \Kirby\Content\Field dateCreated() Content field: Date, when the order was created (date('c')).
 * @method \Kirby\Content\Field datePaid() Content field: Date, when the order was paid (date('c')). Empty while the payment is pending.
 * @method \Kirby\Content\Field payPalOrderId() Content field: Id of the PayPal order, used to complete the payment when the customer returns
 * @method \Kirby\Content\Field stripePaymentIntentId() Content field: Id of the Stripe PaymentIntent, used to complete the payment when the customer returns
 * @method \Kirby\Content\Field redirect() Content field: URL the customer is redirected to
 * @method \Kirby\Content\Field orderNumber() Content field: Sequential number for each order. Can be customized with wagnerwagner.merx.orderNumber option.
 * @method \Kirby\Content\Field items() Content field: Cart items as YAML
 *
 * @see https://merx.wagnerwagner.de/guide/getting-started/displaying-order
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class OrderPage extends Page
{
	/**
	 * List of products of the Order
	 */
	public function cart(): ProductList
	{
		$data = $this->items()->yaml();
		$data = array_map(function (mixed $item) {
			$page = $item['page'] ?? null;
			$page = is_string($page) ? $page : ($page[0] ?? null);
			$page = $page === '' ? null : $page;
			$price = $item['price'] ?? null;
			$price = $price ? new Price(price: $price, currency: $item['currency'] ?? null, tax: $item['taxrate'] ?? null) : null;
			$listItem = new ListItem(
				key: $item['key'],
				title: $item['title'] ?? null,
				page: $page,
				price: $price,
				quantity: $item['quantity'] ?? 1.0,
				type: $item['type'] ?? null,
				data: $item['data'] ?? null,
				refreshPrice: false,
			);
			// The stored price already includes the quantifier. Passed to the
			// constructor, it would be applied a second time.
			$listItem->quantifier = $item['quantifier'] ?? null;
			return $listItem;
		}, $data);

		return new ProductList($data);
	}

	/**
	 * Do not cache order pages, to ensure that all security
	 * guards remain effective at all times.
	 */
	public function isCacheable(VersionId|null $versionId = null): bool
	{
		return false;
	}

	/**
	 * Whether the order is paid
	 *
	 * An order is paid once it has a `datePaid`.
	 */
	public function isPaid(): bool
	{
		return $this->datePaid()->isNotEmpty();
	}

	/**
	 * Records that this order was looked at
	 *
	 * The url is the only thing protecting an order, and it never expires. A
	 * log is what turns “someone may have the link” into something that can be
	 * answered afterwards.
	 *
	 * The visitor’s ip is hashed: enough to tell one visitor from another, not
	 * enough to identify them. Follows the `wagnerwagner.merx.logging` option.
	 */
	protected function logRender(): void
	{
		if (option('wagnerwagner.merx.logging') !== true) {
			return;
		}

		$kirby = $this->kirby();

		Logger::log([
			'event' => 'orderPage.render',
			'order' => $this->slug(),
			'orderNumber' => $this->orderNumber()->value(),
			// Panel users are named, so an order opened from the Panel can be
			// told apart from one opened with the link alone
			'user' => $kirby->user()?->id(),
			'visitor' => $kirby->visitor()->ip(hash: true),
		]);
	}

	/**
	 * Reconciliation record of the payment
	 *
	 * @see PaymentDetails::$keys
	 */
	public function paymentDetails(): array
	{
		return $this->content()->get('paymentDetails')->yaml();
	}

	/**
	 * Name of the payment provider which processed the order, e.g. `stripe`
	 */
	public function paymentProvider(): string|null
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
	public function paymentProviderUrl(): string|null
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

	/**
	 * Page uuids of products in this order
	 *
	 * Used in `ProductPage::orders()` to get the order pages for a product.
	 *
	 * @return string[]
	 */
	public function productUuids(): array
	{
		return array_map(fn (Page|null $page) => (string)$page?->uuid(), $this->cart()->pluck('page'));
	}

	/**
	 * Renders the order page
	 *
	 * An order is reachable by its url alone — the slug is the only secret —
	 * and it shows the customer’s name, address and what they bought. The
	 * headers keep that page out of shared caches, out of search engines, and
	 * keep its url from travelling to other sites in a referrer.
	 *
	 * They are set before the template runs, so a shop can still override any
	 * of them.
	 */
	public function render(
		array $data = [],
		$contentType = 'html',
		VersionId|string|null $versionId = null
	): string {
		$response = $this->kirby()->response();
		$response->header('Cache-Control', 'private, no-store');
		$response->header('Referrer-Policy', 'same-origin');
		$response->header('X-Robots-Tag', 'noindex, nofollow');

		$this->logRender();

		return parent::render($data, $contentType, $versionId);
	}

	/**
	 * Sorting number of the order: the time it was created, to the millisecond
	 *
	 * The order blueprint uses it as `num`, so the order folders sort in the order
	 * the orders were created, and the default `orderNumber` can count on from
	 * the last one. With seconds, two orders created in the same second would
	 * sort by their random slug instead.
	 *
	 * `Merx::createOrder()` stores it in its own field, because Kirby runs
	 * `dateCreated` through the date field, which keeps whole seconds only.
	 * Orders without that field fall back to `dateCreated`.
	 *
	 * @return int E.g. 20260923150808123
	 */
	public function sortingNumber(): int
	{
		$sortingNumber = $this->content()->get('sortingNumber');

		if ($sortingNumber->isNotEmpty()) {
			return $sortingNumber->toInt();
		}

		return static::sortingNumberFromDate(new DateTimeImmutable((string)$this->dateCreated()->value()));
	}

	/**
	 * Sorting number for an order created at $date
	 *
	 * The time is in UTC, so the hour that repeats when daylight saving time
	 * ends cannot sort a later order before an earlier one.
	 *
	 * @return int E.g. 20260923150808123
	 */
	public static function sortingNumberFromDate(DateTimeImmutable $date): int
	{
		return (int)$date->setTimezone(new DateTimeZone('UTC'))->format('YmdHisv');
	}

	/**
	 * Returns order number
	 */
	public function title(): Field
	{
		return new Field($this, 'title', $this->orderNumber());
	}

	/**
	 * Total price of the order
	 */
	public function total(): Price|null
	{
		return $this->cart()->total();
	}
}
