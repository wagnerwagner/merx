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
	/**
	 * Prevent index of all orders
	 *
	 * SECURITY/PRIVACY
	 * An index of all orders could reveal the secret order page urls.
	 * Panel users may read orders, so they get it.
	 */
	public function index(bool $drafts = false): Pages
	{
		$user = $this->kirby()->user();
		if ($user !== null && !$user->isNobody()) {
			return parent::index($drafts);
		}

		return new Pages();
	}
}
