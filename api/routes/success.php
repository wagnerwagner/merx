<?php

use Kirby\Toolkit\I18n;

/** @var string $endpoint ww.merx.api.endpoint option */

return [
	[
		'pattern' => $endpoint . '/success',
		'auth' => false,
		'method' => 'GET|POST',
		'action' => function ()
		{
			/** @var \Kirby\Cms\Api $this */
			I18n::$locale = $this->language();

			$merx = merx();
			$orderPage = $merx->createOrder($_GET);
			go($orderPage->url());
		},
	],
];
