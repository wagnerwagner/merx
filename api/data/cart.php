<?php

use Wagnerwagner\Merx\Cart;

return [
	'cart' => function(): Cart {
		/** @var \Kirby\Api\Api $this */
		return cart();
	}
];
