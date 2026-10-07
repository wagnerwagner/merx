<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Merx;
use Wagnerwagner\Merx\OrderPage;

/**
 * A shop’s own `/site/models/order.php` has to be used from the start of the
 * checkout, not only for the stored order. Otherwise its `errors()` never
 * validates the checkout, and the gateway and the hooks get an order page
 * without its methods.
 */
final class OrderModelTest extends TestCase
{
	/** Class of the order page each step was given */
	public array $classes = [];

	protected App $app;
	protected App $kirby;
	protected array $models;
	protected string $root;

	public function setUp(): void
	{
		$this->app = App::instance();

		// Registered models are static, and would outlive this test
		$this->models = Page::$models;

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		// `/tests/kirby` is ignored by git and used by the test Kirby instance
		$this->root = __DIR__ . '/kirby/order-model';
		Dir::remove($this->root);
		Dir::make($this->root . '/content');

		F::write($this->root . '/site/models/order.php', <<<'PHP'
			<?php

			class OrderPage extends \Wagnerwagner\Merx\OrderPage
			{
				public function errors(): array
				{
					$errors = parent::errors();
					if ($this->company()->isEmpty()) {
						$errors['company'] = [
							'label' => 'Company',
							'message' => ['required' => 'Please enter a company.'],
						];
					}
					return $errors;
				}
			}
			PHP);

		$this->classes = [];
		$test = $this;

		$this->kirby = new App([
			'roots' => [
				'index' => $this->root,
				'content' => $this->root . '/content',
				'site' => $this->root . '/site',
			],
			'options' => [
				'wagnerwagner.merx.logging' => false,
				'wagnerwagner.merx.gateways' => [
					'probe' => [
						'initializePayment' => function (OrderPage $virtualOrderPage) use ($test): OrderPage {
							$test->classes['initializePayment'] = $virtualOrderPage::class;
							return $virtualOrderPage;
						},
						'completePayment' => function (OrderPage $virtualOrderPage) use ($test): OrderPage {
							$test->classes['completePayment'] = $virtualOrderPage::class;
							return $virtualOrderPage;
						},
					],
				],
			],
			'hooks' => [
				'wagnerwagner.merx.initializeOrder:after' => function (OrderPage $virtualOrderPage) use ($test): void {
					$test->classes['initializeOrder:after'] = $virtualOrderPage::class;
				},
				'wagnerwagner.merx.createOrder:before' => function (OrderPage $virtualOrderPage) use ($test): void {
					$test->classes['createOrder:before'] = $virtualOrderPage::class;
				},
			],
		]);

		cart()->add(['key' => 'socks', 'title' => 'Socks', 'price' => 10.0]);
	}

	public function tearDown(): void
	{
		// The cart lives in the session, which Kirby would otherwise write to the
		// removed folder once the test is over
		$this->kirby->session()->destroy();

		Page::$models = $this->models;
		App::$enableWhoops = true;
		Dir::remove($this->root);
		App::instance($this->app);
	}

	public function testGatewayAndHooksGetTheOwnModel(): void
	{
		$merx = merx();
		$merx->initializeOrder([
			'paymentGateway' => 'probe',
			'email' => 'customer@example.com',
			'company' => 'ACME',
		]);
		$orderPage = $merx->createOrder([
			Merx::$returnNonceParameterName => $this->kirby->session()->get(Merx::$returnNonceSessionKey),
		]);
		$this->classes['createOrder'] = $orderPage::class;

		$this->assertSame([
			'initializePayment' => 'OrderPage',
			'initializeOrder:after' => 'OrderPage',
			'createOrder:before' => 'OrderPage',
			'completePayment' => 'OrderPage',
			'createOrder' => 'OrderPage',
		], $this->classes);
	}

	public function testOwnModelValidatesTheCheckout(): void
	{
		try {
			merx()->initializeOrder(['paymentGateway' => 'probe', 'email' => 'customer@example.com']);
			$this->fail('The checkout was accepted without a company.');
		} catch (Exception $exception) {
			$this->assertSame('error.merx.fieldsvalidation', $exception->getKey());
			$this->assertArrayHasKey('company', $exception->getDetails());
		}
	}
}
