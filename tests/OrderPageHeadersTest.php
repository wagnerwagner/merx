<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\OrderPage;

/**
 * An order is reachable by its url alone and shows the customer’s name,
 * address and order. The response has to say so.
 */
final class OrderPageHeadersTest extends TestCase
{
	protected App $kirby;

	protected string $root;

	public function setUp(): void
	{
		App::$enableWhoops = false;

		$this->root = __DIR__ . '/kirby/order-headers';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
		]);
	}

	public function tearDown(): void
	{
		App::$enableWhoops = true;
		Dir::remove($this->root);
	}

	protected function orderPage(): OrderPage
	{
		return $this->kirby->impersonate('kirby', function (): OrderPage {
			$ordersPage = $this->kirby->site()->createChild([
				'slug' => 'orders',
				'template' => 'orders',
				'draft' => false,
				'content' => ['title' => 'Orders'],
			]);

			/** @var OrderPage $orderPage */
			$orderPage = $ordersPage->createChild([
				'slug' => 'aaaabbbbccccdddd',
				'template' => 'order',
				'model' => 'order',
				'draft' => false,
				'content' => ['dateCreated' => date('c'), 'name' => 'Marta Doe'],
			]);

			return $orderPage->changeStatus('listed');
		});
	}

	public function testRenderSetsTheProtectiveHeaders(): void
	{
		$this->orderPage()->render();

		$headers = $this->kirby->response()->headers();

		$this->assertSame('private, no-store', $headers['Cache-Control'] ?? null);
		$this->assertSame('same-origin', $headers['Referrer-Policy'] ?? null);
		$this->assertSame('noindex, nofollow', $headers['X-Robots-Tag'] ?? null);
	}

	public function testAShopCanStillOverrideThem(): void
	{
		// The headers are set before the template runs, so they are defaults
		$orderPage = $this->orderPage();
		$orderPage->render();
		$this->kirby->response()->header('Referrer-Policy', 'no-referrer');

		$this->assertSame('no-referrer', $this->kirby->response()->headers()['Referrer-Policy'] ?? null);
	}
}
