<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * Page model for orders page
 *
 * Parent page for all order pages.
 * @internal
 */
class OrdersPage extends Page
{
	static $allowedRoles = ['admin'];

	/**
	 * Prevent index of all orders
	 *
	 * SECURITY/PRIVACY
	 * An index of all orders could reveal the secret order page urls.
	 */
	public function index(bool $drafts = false): Pages
	{
		if (in_array($this->kirby()->user()?->role()->id(), self::$allowedRoles)) {
			return parent::index();
		}

		return new Pages();
	}
}
