<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Blueprint\Blueprint;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;
use Wagnerwagner\Merx\SystemView;

/**
 * Who may list the orders, and what Merx’ Panel view reports
 */
final class SystemViewTest extends TestCase
{
	protected App $kirby;
	protected string $root;

	/**
	 * Answers every request to the Stripe API with one response
	 */
	protected function stripeAnswers(int $code, array $body): void
	{
		// `accounts()` asks once per request; a test is a new one
		(new ReflectionProperty(SystemView::class, 'accounts'))->setValue(null, null);

		ApiRequestor::setHttpClient(new class ($code, json_encode($body)) implements ClientInterface {
			public function __construct(private int $code, private string $body)
			{
			}

			public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
			{
				return [$this->body, $this->code, new CaseInsensitiveArray([])];
			}
		});
	}

	public function setUp(): void
	{
		App::$enableWhoops = false;
		$this->stripeAnswers(200, ['id' => 'acct_probe', 'object' => 'account', 'country' => 'DE', 'business_profile' => ['name' => 'Probe Shop']]);

		$this->root = __DIR__ . '/kirby/system-view';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'roles' => [
				['name' => 'admin'],
				['name' => 'editor', 'title' => 'Editor'],
				['name' => 'customer', 'title' => 'Customer', 'permissions' => ['access' => ['panel' => false]]],
				['name' => 'author', 'title' => 'Author', 'permissions' => ['pages' => ['list' => false]]],
			],
			'users' => [
				['email' => 'admin@example.com', 'role' => 'admin'],
				['email' => 'customer@example.com', 'role' => 'customer'],
				['email' => 'editor@example.com', 'role' => 'editor'],
			],
			'options' => [
				'wagnerwagner.merx.logging' => false,
				'wagnerwagner.merx.stripe.test.secret_key' => 'sk_test_1234567890abcd',
			],
		]);

		$this->kirby->impersonate('kirby', function (): void {
			$ordersPage = $this->kirby->site()->createChild([
				'slug' => 'orders',
				'template' => 'orders',
				'draft' => false,
				'content' => ['title' => 'Orders'],
			]);
			foreach (['aaaabbbbccccdddd', 'eeeeffffgggghhhh'] as $slug) {
				$ordersPage->createChild([
					'slug' => $slug,
					'template' => 'order',
					'draft' => false,
				])->changeStatus('listed');
			}
		});
	}

	public function tearDown(): void
	{
		ApiRequestor::setHttpClient(null);
		// Kirby keeps loaded blueprints in static properties
		Blueprint::$loaded = [];
		Blueprint::$normalized = [];
		App::$enableWhoops = true;
		Dir::remove($this->root);
	}

	public function testAShopBlueprintCanLetMoreRolesListOrders(): void
	{
		// Like a shop’s own `/site/blueprints/pages/order.yml`
		F::write($this->root . '/site/blueprints/pages/order.yml', <<<'YAML'
			extends: wagnerwagner.merx.pages/order
			options:
			  list:
			    editor: true
			    customer: true
			YAML);
		Blueprint::$loaded = [];
		Blueprint::$normalized = [];

		$this->assertSame([2, 2], $this->visibleOrders('editor@example.com'));
		// The blueprint decides alone, even for a role without Panel access …
		$this->assertSame([2, 2], $this->visibleOrders('customer@example.com'));
		$this->assertSame([0, 1], $this->visibleOrders('nobody'));

		// … which the Panel view reports
		$checks = array_column(SystemView::security(), null, 'id');
		$this->assertStringContainsString('Admin, Customer, Editor', $checks['roles']['text']);
		$this->assertSame('negative', $checks['frontendRoles']['theme']);
		$this->assertStringContainsString('Customer', $checks['frontendRoles']['text']);
	}

	public function testLogsShowTheNewestEntriesFirst(): void
	{
		$logs = $this->kirby->root('logs');
		F::write($logs . '/2026-11-16-merx.log', "[2026-11-16T10:00:00+01:00] ERROR Payment failed\nwith details\n");
		F::write($logs . '/2026-11-17-merx.log', implode('', [
			"[2026-11-17T09:00:00+01:00] INFO " . json_encode(['event' => 'orderPage.render', 'order' => 'aaaa', 'orderNumber' => '42', 'user' => null], JSON_PRETTY_PRINT) . "\n",
			"[2026-11-17T10:00:00+01:00] INFO " . json_encode(['event' => 'orderPage.render', 'order' => 'bbbb', 'orderNumber' => null, 'user' => 'abc'], JSON_PRETTY_PRINT) . "\n",
		]));
		// Not Merx’ log
		F::write($logs . '/2026-11-18-other.log', "[2026-11-18T10:00:00+01:00] INFO Other\n");

		$entries = SystemView::logs();
		// Each entry as a whole
		$this->assertStringContainsString('"order": "bbbb"', $entries[0]['message']);
		$this->assertStringContainsString('"orderNumber": "42"', $entries[1]['message']);
		$this->assertSame("Payment failed\nwith details", $entries[2]['message']);
		$this->assertSame('error', $entries[2]['level']);
		$this->assertSame('orange', $entries[2]['type']);
		$this->assertNull($entries[0]['type']);
		$this->assertSame(1, count(SystemView::logs(1)));
	}

	public function testKeysNameTheStripeAccount(): void
	{
		$keys = array_column(SystemView::keys(), null, 'option');
		$this->assertSame('Account Probe Shop (<a href="https://dashboard.stripe.com/acct_probe" target="_blank"><code>acct_probe</code></a>)', $keys['stripe.test.secret_key']['<note>']);
		$this->assertSame('', $keys['stripe.test.publishable_key']['<note>']);
		// No PayPal keys, nothing to ask
		$this->assertArrayNotHasKey('paypal', SystemView::accounts());

		// A key Stripe refuses is a security problem
		$this->stripeAnswers(401, ['error' => ['message' => 'Invalid API Key provided', 'type' => 'invalid_request_error']]);
		$keys = array_column(SystemView::keys(), null, 'option');
		$this->assertSame('Not valid', $keys['stripe.test.secret_key']['<note>']);
		$checks = array_column(SystemView::security(), null, 'id');
		$this->assertSame('negative', $checks['stripeKeys']['theme']);
	}

	public function testKeysShowTheirLastFourCharacters(): void
	{
		$keys = array_column(SystemView::keys(), 'value', 'option');

		$this->assertSame('••••abcd', $keys['stripe.test.secret_key']);
		$this->assertSame('–', $keys['stripe.test.publishable_key']);
		$this->assertArrayNotHasKey('stripe.live.secret_key', $keys);
	}

	public function testOnlyAdminsMayListOrdersByDefault(): void
	{
		$this->assertSame([2, 2], $this->visibleOrders('admin@example.com'));
		$this->assertSame([0, 1], $this->visibleOrders('editor@example.com'));
		$this->assertSame([0, 1], $this->visibleOrders('customer@example.com'));
		$this->assertSame([0, 1], $this->visibleOrders('nobody'));
		// Merx itself, e.g. for the order number
		$this->assertSame([2, 2], $this->visibleOrders('kirby'));
	}

	public function testRulesHaveTheirOwnTables(): void
	{
		$this->assertSame([['key' => 'default', 'name' => 'default', 'currency' => 'EUR']], SystemView::pricingRules());
		$this->assertSame([['key' => 'default', 'name' => 'default', 'rate' => '–']], SystemView::taxRules());

		// Side by side in one grid
		$grid = array_column(SystemView::props()['sections'], 'grid');
		$sections = array_column($grid[0], null, 'headline');
		$this->assertSame(['Pricing rules', 'Tax rules'], array_keys($sections));
		$this->assertSame(['key', 'name', 'rate'], array_keys($sections['Tax rules']['columns']));
	}

	public function testSecurityNamesTheRolesWhichMayListOrders(): void
	{
		$checks = array_column(SystemView::security(), null, 'id');

		$this->assertStringEndsWith(': Admin', $checks['roles']['text']);
		$this->assertSame('info', $checks['roles']['theme']);
		// Only problems are shown
		$this->assertArrayNotHasKey('visitors', $checks);
		// Information has no link, a problem links to the security guide
		$this->assertArrayNotHasKey('link', $checks['roles']);
		$this->assertSame('https://merx.wagnerwagner.de/guide/security', $checks['webhook']['link']);
		// A Stripe key without a signing secret
		$this->assertSame('notice', $checks['webhook']['theme']);
	}

	public function testSettingsExplainEveryOption(): void
	{
		$settings = array_column(SystemView::settings(), null, 'option');
		// The API endpoint only when it was changed
		$this->assertArrayNotHasKey('api.endpoint', $settings);
		$this->assertSame(['invoice', 'paypal', 'stripe'], $settings['gateways']['value']);
		$this->assertStringContainsString('No real money', $settings['production']['<note>']);
		$this->assertStringContainsString('not logged', $settings['logging']['<note>']);
		$this->assertStringContainsString('lowers the stock', $settings['reduceStock']['<note>']);
		$this->assertSame('The gateways Merx ships.', $settings['gateways']['<note>']);

		$this->kirby = $this->kirby->clone(['options' => [
			'wagnerwagner.merx.api.endpoint' => 'kasse',
			'wagnerwagner.merx.gateways' => ['prepayment' => true, 'invoice' => false],
			'wagnerwagner.merx.logging' => true,
			'wagnerwagner.merx.production' => true,
		]]);
		$settings = array_column(SystemView::settings(), null, 'option');

		$this->assertStringContainsString('live keys', $settings['production']['<note>']);
		// Stripe and PayPal are enabled, their live keys are not set
		$this->assertStringContainsString('<code>stripe</code> is enabled, without <code>stripe.live.publishable_key</code>, <code>stripe.live.secret_key</code>.', $settings['production']['<note>']);
		$this->assertStringContainsString('<code>paypal</code> is enabled', $settings['production']['<note>']);

		// A gateway which is switched off needs no keys, one with its keys has none missing
		$this->kirby = $this->kirby->clone(['options' => [
			'wagnerwagner.merx.gateways' => ['paypal' => false],
			'wagnerwagner.merx.stripe.live.publishable_key' => 'pk_live_1234',
			'wagnerwagner.merx.stripe.live.secret_key' => 'sk_live_1234',
		]]);
		$production = array_column(SystemView::settings(), null, 'option')['production'];
		$this->assertStringNotContainsString('without', $production['<note>']);
		$this->assertStringContainsString('-merx.log</code>', $settings['logging']['<note>']);

		$this->assertSame('/api/kasse', $settings['api.endpoint']['value']);
		$this->assertStringContainsString('/api/kasse/', $settings['api.endpoint']['<note>']);
		$this->assertSame(['paypal', 'stripe', 'prepayment'], $settings['gateways']['value']);
		$this->assertStringContainsString('<code>prepayment</code>', $settings['gateways']['<note>']);
		$this->assertStringNotContainsString('invoice', $settings['gateways']['<note>']);
	}

	public function testSettingsLinkTheOrdersPage(): void
	{
		$settings = array_column(SystemView::settings(), null, 'option');

		$this->assertSame('false', $settings['production']['value']);
		$this->assertSame('false', $settings['logging']['value']);
		$this->assertSame('true', $settings['reduceStock']['value']);
		$this->assertSame('orders', $settings['ordersPage']['value']);
		$this->assertSame('/pages/orders', $settings['ordersPage']['link']);
		$this->assertSame('2 orders, 0 not completed.', $settings['ordersPage']['<note>']);

		// Before the first order there is no orders page
		$this->kirby = $this->kirby->clone(['options' => ['wagnerwagner.merx.ordersPage' => 'bestellungen']]);
		$settings = array_column(SystemView::settings(), null, 'option');
		$this->assertSame('bestellungen', $settings['ordersPage']['value']);
		$this->assertArrayNotHasKey('link', $settings['ordersPage']);
		$this->assertStringContainsString('first order', $settings['ordersPage']['<note>']);
		// A changed path is worth a hint for robots.txt and .gitignore
		$this->assertStringContainsString('/bestellungen', $settings['ordersPage']['<note>']);
	}

	public function testTheBlueprintWinsOverThePermissionsOfARole(): void
	{
		F::write($this->root . '/site/blueprints/pages/order.yml', <<<'YAML'
			extends: wagnerwagner.merx.pages/order
			options:
			  list:
			    author: true
			YAML);
		Blueprint::$loaded = [];
		Blueprint::$normalized = [];

		// `author` has `pages.list: false`
		$checks = array_column(SystemView::security(), null, 'id');
		$this->assertStringContainsString('Author', $checks['roles']['text']);
	}

	public function testTheBrowserChecksRobotsAndTheSitemap(): void
	{
		$checks = SystemView::browserChecks();

		$this->assertStringEndsWith('/robots.txt', $checks['robots']['url']);
		$this->assertSame('/orders/', $checks['robots']['path']);
		$this->assertStringEndsWith('/sitemap.xml', $checks['sitemap']['url']);
		$this->assertStringEndsWith('/orders/', $checks['sitemap']['prefix']);
		// Each carries the item to show when it fails
		$this->assertSame('negative', $checks['sitemap']['orders']['theme']);

		// Nothing to check before the first order
		$this->kirby = $this->kirby->clone(['options' => ['wagnerwagner.merx.ordersPage' => 'bestellungen']]);
		$this->assertSame([], SystemView::browserChecks());
	}

	public function testVisitorsCheckFailsWithoutMerxModel(): void
	{
		$models = Page::$models;
		Page::$models['order'] = Page::class;

		try {
			$checks = array_column(SystemView::security(), null, 'id');
			$this->assertSame('negative', $checks['visitors']['theme']);
		} finally {
			Page::$models = $models;
		}
	}

	/**
	 * How many orders a user gets from `index()`, and how many siblings an order has
	 */
	protected function visibleOrders(string $user): array
	{
		$ordersPage = $this->kirby->site()->ordersPage();

		return $this->kirby->impersonate($user, fn () => [
			$ordersPage->index()->count(),
			$ordersPage->children()->first()->siblings()->count(),
		]);
	}
}
