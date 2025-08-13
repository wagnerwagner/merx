<?php

use Wagnerwagner\Merx\Cart;

return [
	'cart' => function(): Cart {
		/** @var \Kirby\Cms\Api $this */
		return cart();
	}
];
