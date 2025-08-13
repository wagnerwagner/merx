<?php

namespace Wagnerwagner\Merx;

use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
	public function testCartAdd1(): void
	{
		$kirby = kirby();
		$response = $kirby->api()->call('shop/cart', 'POST', [
			'body' => [
				'key' => 'lorem',
			],
		]);
		$this->assertEquals(200, $response['code']);
	}

	public function testCartGet(): void
	{
		$kirby = kirby();
		$response = $kirby->api()->call('shop/cart', 'GET');
		$this->assertEquals(200, $response['code']);
		$this->assertEquals(1, $response['data']['quantity']);
	}

	public function testCartAddWithQuantity(): void
	{
		$kirby = kirby();
		$response = $kirby->api()->call('shop/cart', 'POST', [
			'body' => [
				'key' => 'ipsum',
				'quantity' => 3,
			],
		]);
		$this->assertEquals(200, $response['code']);
		$this->assertIsArray($response['data']['products']);
		$this->assertEquals(4, $response['data']['quantity']);
	}

	public function testCartPatch(): void
	{
		$kirby = kirby();
		// Add item first
		$kirby->api()->call('shop/cart', 'POST', [
			'body' => [
				'key' => 'dolor',
				'quantity' => 1,
			],
		]);
		// Update item
		$response = $kirby->api()->call('shop/cart', 'PATCH', [
			'body' => [
				'key' => 'dolor',
				'quantity' => 5,
			],
		]);
		$this->assertIsArray($response['data']['products']);
		$this->assertEquals('dolor', $response['data']['products']['items'][2]['key']);
		$this->assertEquals(5, $response['data']['products']['items'][2]['quantity']);
	}

	public function testCartDelete(): void
	{
		$kirby = kirby();
		// Add item first
		$kirby->api()->call('shop/cart', 'POST', [
			'body' => [
				'key' => 'amet',
				'quantity' => 2,
			],
		]);
		// Remove item
		$response = $kirby->api()->call('shop/cart', 'DELETE', [
			'body' => [
				'key' => 'amet',
			],
		]);
		$this->assertIsArray($response['data']['products']);
	}
}
