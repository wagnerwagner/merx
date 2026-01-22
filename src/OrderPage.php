<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Language;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Content\Content;
use Kirby\Content\Field;
use Kirby\Content\VersionId;
use Kirby\Exception\AuthException;
use Kirby\Toolkit\Str;
use Wagnerwagner\Merx\ListItem;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductList;

/**
 * Page model for order page
 *
 * This model is used to store and validate order data.
 *
 * @method \Kirby\Content\Field email() User’s email address
 * @method \Kirby\Content\Field created() Date, when the order was created (date('c')).
 * @method \Kirby\Content\Field paidDate() Date, when the order was paid (date('c')).
 * @method \Kirby\Content\Field paymentComplete() True, when payment is complete
 * @method \Kirby\Content\Field paymentDetails() Details from the payment provider. Array stored as yaml
 * @method \Kirby\Content\Field payPalOrderId()
 * @method \Kirby\Content\Field stripePaymentIntentId()
 * @method \Kirby\Content\Field redirect() URL the user is redirected to
 * @method \Kirby\Content\Field invoiceNumber()
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class OrderPage extends Page
{
	static $allowedRoles = ['admin'];

	/**
	 * Returns invoiceNumber
	 */
	public function title(): Field
	{
		return new Field($this, 'title', $this->invoiceNumber());
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
	 * Returns the Url with security hash
	 *
	 * E.g. example.com/orders/kw69gcfauhczi479?hash=1f2-6ab
	 *
	 * @param array|string|null $options
	 */
	public function secureUrl($options = null): string
	{
		return parent::url($options) . '?hash=' . $this->securityHash($this->content(bypassSecurityCheck: true));
	}

	/**
	 * Returns the security hash for the content
	 *
	 * @param Content $content The content to get the security hash for
	 * @return string The security hash
	 */
	public function securityHash(Content $content): string
	{
		$hash = hash('sha256', $content->uuid());
		return Str::substr($hash, 0, 3) . '-' . Str::substr($hash, 3, 3);
	}

	/**
	 * Checks if the current user or the current request has security access to the content
	 *
	 * @param Content|null $content The content to check access for
	 * @return bool True if access is granted, false otherwise
	 * @throws \Kirby\Exception\AuthException If the user is not allowed to access the content
	 */
	public function hasSecurityAccess(Content|null $content = null): ?bool
	{
		$content = $content ?? $this->content(bypassSecurityCheck: true);
		$hasSecurityAccess = in_array($this->kirby()->user()?->role()->id(), self::$allowedRoles)
			|| $this->kirby()->request()->get('hash') === $this->securityHash($content);

		if ($hasSecurityAccess === false) {
			if ($this->kirby()->option('debug') === true) {
				throw new AuthException(fallback: 'Your are not allowed to access this content');
			}
		}

		return $hasSecurityAccess;
	}

	/**
	 * Renders the order page with security check
	 *
	 * Renders the error page if the user is not allowed to access the content.
	 */
	public function render(array $data = [], $contentType = 'html', null|VersionId|string $versionId = null): string
	{
		if ($this->hasSecurityAccess() === false) {
			return $this->kirby()->render('error');
		}
		return parent::render($data, $contentType, $versionId);
	}

	/**
	 * Returns the content only to admins and for requestswith valid security hash
	 *
	 * @throws \Kirby\Exception\InvalidArgumentException If the language for the given code does not exist
	 */
	public function content(string|null $languageCode = null, bool $bypassSecurityCheck = false): Content
	{
		// get the targeted language
		$language  = Language::ensure($languageCode ?? 'current');
		$versionId = VersionId::$render ?? 'latest';
		$version   = $this->version($versionId);

		if ($version->exists($language) === true) {
			$content = $version->content($language);
		} else {
			$content = $this->version()->content($language);
		}

		if ($bypassSecurityCheck === true || $this->hasSecurityAccess($content)) {
			return $content;
		}

		return new Content();
	}

	/**
	 * Returns siblings of all order page only to admins
	 *
	 * SECURITY/PRIVACY
	 * An index of all orders could reveal the secret order page urls.
	 */
	protected function siblingsCollection(): Pages
	{
		if (in_array($this->kirby()->user()?->role()->id(), self::$allowedRoles)) {
			return parent::siblingsCollection();
		}

		return new Pages();
	}
}
