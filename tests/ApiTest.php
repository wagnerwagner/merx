<?php

namespace Wagnerwagner\Merx;

use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
	protected const PRODUCTS = ['lorem', 'ipsum', 'dolor', 'amet'];

	/**
	 * Every test starts with an empty cart and does not depend on the tests before it
	 */
	public function setUp(): void
	{
		cart()->delete();
	}

	/**
	 * The cart only takes products, so the test site gets some. They are
	 * written to `/tests/kirby`, which is ignored by git.
	 */
	public static function setUpBeforeClass(): void
	{
		foreach (static::PRODUCTS as $slug) {
			F::write(
				kirby()->root('content') . '/' . $slug . '/product.txt',
				'Title: ' . ucfirst($slug) . "\n\n----\n\nPrice: 10\n\n----\n\nUuid: " . $slug,
			);
		}
		kirby()->site()->purge();
	}

	public function tearDown(): void
	{
		cart()->delete();
	}

	public static function tearDownAfterClass(): void
	{
		foreach (static::PRODUCTS as $slug) {
			Dir::remove(kirby()->root('content') . '/' . $slug);
		}
		kirby()->site()->purge();
	}

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

	public function testCartAddUnknownPageIsRefused(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionCode('error.merx.cart.add');

		kirby()->api()->call('shop/cart', 'POST', [
			'body' => [
				'page' => 'no-such-product',
			],
		]);
	}

	/**
	 * A quantity may be a fraction, e.g. metres. The cart reports what it
	 * calculates with.
	 */
	public function testCartAddWithAFractionAsQuantity(): void
	{
		$response = kirby()->api()->call('shop/cart', 'POST', [
			'body' => [
				'key' => 'ipsum',
				'quantity' => 1.5,
			],
		]);

		$this->assertSame(1.5, $response['data']['quantity']);
		$this->assertSame(1.5, $response['data']['products']['items'][0]['quantity']);
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
		$this->assertEquals(3, $response['data']['quantity']);
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
		$this->assertEquals(200, $response['code']);
		$this->assertEquals(0, $response['data']['quantity']);
	}

	public function testCartGet(): void
	{
		$kirby = kirby();
		$kirby->api()->call('shop/cart', 'POST', [
			'body' => [
				'key' => 'lorem',
			],
		]);
		$response = $kirby->api()->call('shop/cart', 'GET');
		$this->assertEquals(200, $response['code']);
		$this->assertEquals(1, $response['data']['quantity']);
	}

	/**
	 * A cart shows what lowers its total
	 */
	public function testCartListsDiscounts(): void
	{
		$kirby = kirby();
		$kirby->api()->call('shop/cart', 'POST', ['body' => ['key' => 'ipsum']]);
		$this->assertNull($kirby->api()->call('shop/cart', 'GET')['data']['discounts']);

		cart()->add(['key' => 'coupon', 'title' => 'Coupon', 'type' => 'discount', 'price' => -1.0]);
		$response = $kirby->api()->call('shop/cart', 'GET');

		$this->assertSame('coupon', $response['data']['discounts']['items'][0]['key']);
		$this->assertSame(1, count($response['data']['products']['items']));
		$this->assertSame(-1.0, $response['data']['discounts']['total']['priceRaw']);
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
		$this->assertEquals('dolor', $response['data']['products']['items'][0]['key']);
		$this->assertEquals(5, $response['data']['products']['items'][0]['quantity']);
	}

	/**
	 * A value of the wrong type is the client’s mistake, not a server error
	 */
	public function testCartRefusesValuesOfTheWrongType(): void
	{
		kirby()->api()->call('shop/cart', 'POST', ['body' => ['key' => 'lorem']]);

		$requests = [
			['POST', ['page' => ['lorem']]],
			['POST', ['key' => 1]],
			['POST', ['page' => 'lorem', 'data' => 'text']],
			['PATCH', []],
			['PATCH', ['key' => ['lorem'], 'quantity' => 2]],
			['PATCH', ['key' => 'lorem', 'data' => 'text']],
			['DELETE', []],
		];

		foreach ($requests as [$method, $body]) {
			try {
				kirby()->api()->call('shop/cart', $method, ['body' => $body]);
				$this->fail($method . ' ' . json_encode($body) . ' was accepted');
			} catch (Exception $exception) {
				$this->assertSame(400, $exception->getHttpCode(), $method . ' ' . json_encode($body));
			}
		}
	}
}
