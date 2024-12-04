<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
	public function testCartAdd1(): void
	{
		$kirby = kirby();
		$kirby->api()->call('merx/cart/add', 'POST', [
			''
		]);
	}
}
