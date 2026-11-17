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
	 * An index of all orders could reveal the secret order page urls. Only
	 * users who may list orders get it, as set by the `list` option of the
	 * order blueprint. All orders share that blueprint, so one stands for all.
	 */
	public function index(bool $drafts = false): Pages
	{
		if ($this->childrenAndDrafts()->first()?->isListable() === true) {
			return parent::index($drafts);
		}

		return new Pages();
	}
}
