<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
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
				'logs' => $this->root . '/logs',
			],
			'options' => [
				'wagnerwagner.merx.logging' => true,
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

	protected function log(): string
	{
		$file = $this->root . '/logs/' . date('Y-m-d') . '-merx.log';
		return F::exists($file) ? F::read($file) : '';
	}

	public function testRenderIsLogged(): void
	{
		$orderPage = $this->orderPage();
		$this->assertSame('', $this->log());

		$orderPage->render();
		$log = $this->log();

		$this->assertStringContainsString('orderPage.render', $log);
		$this->assertStringContainsString('aaaabbbbccccdddd', $log);
	}

	public function testTheVisitorIsLoggedHashedOnly(): void
	{
		$ip = '203.0.113.9';
		$this->kirby->visitor()->ip($ip);

		$this->orderPage()->render();
		$log = $this->log();

		// The raw address must not be in there, a hash of it may
		$this->assertStringNotContainsString($ip, $log);
		$this->assertStringContainsString(substr(hash('sha256', $ip), 0, 50), $log);
	}

	public function testNothingIsLoggedWhenLoggingIsOff(): void
	{
		$this->kirby = $this->kirby->clone(['options' => ['wagnerwagner.merx.logging' => false]]);
		$this->orderPage()->render();

		$this->assertSame('', $this->log());
	}
}
