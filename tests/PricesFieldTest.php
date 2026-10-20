<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Data\Yaml;
use Kirby\Form\Form;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\PricesField;
use Wagnerwagner\Merx\ProductPage;

class PricesFieldTest extends TestCase
{
	protected App $app;
	protected ProductPage $product;

	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		new App([
			// The test site, so no site config adds pricing rules of its own
			'roots' => [
				'index' => __DIR__,
				'content' => __DIR__ . '/kirby/content',
				'site' => __DIR__ . '/kirby/site',
			],
			'options' => [
				'wagnerwagner.merx.pricingRules' => [
					'default' => [
						'name' => 'Euro',
						'currency' => 'EUR',
					],
					'US' => [
						'name' => 'US dollar',
						'currency' => 'USD',
					],
				],
			],
		]);

		$this->product = new ProductPage([
			'slug' => 'test-product',
			'content' => [
				'price' => Yaml::encode([
					['price' => 19.99, 'pricingRule' => 'default'],
					['price' => 21.99, 'pricingRule' => 'US'],
				]),
			],
		]);
	}

	public function tearDown(): void
	{
		App::$enableWhoops = true;
		App::instance($this->app);
	}

	protected function field(mixed $value = null): PricesField
	{
		return new PricesField(
			model: $this->product,
			name: 'price',
			value: $value ?? $this->product->content()->get('price')->value(),
		);
	}

	public function testOneFieldPerPricingRule(): void
	{
		$fields = $this->field()->fields();

		$this->assertSame(['default', 'us'], array_keys($fields));
		$this->assertSame('number', $fields['us']['type']);
		$this->assertSame('US dollar', $fields['us']['label']);
		$this->assertSame('USD', $fields['us']['after']);
	}

	public function testNotTranslatable(): void
	{
		$this->assertFalse($this->field()->translate());
	}

	public function testPlainNumberIsTheDefaultPrice(): void
	{
		$this->assertEquals(['default' => 45.0, 'us' => null], $this->field('45')->toFormValue());

		$product = new ProductPage([
			'slug' => 'plain-product',
			'content' => ['price' => '45'],
		]);
		$this->assertSame(45.0, $product->price('default')->price);
		$this->assertSame('EUR', $product->price('default')->currency);
	}

	public function testFormValueHasOnePricePerRule(): void
	{
		$this->assertEquals(
			['default' => 19.99, 'us' => 21.99],
			$this->field()->toFormValue(),
		);
	}

	public function testStoredValueIsList(): void
	{
		$field = $this->field()->submit(['default' => 18.5, 'us' => '']);

		$this->assertEquals(
			[['price' => 18.5, 'pricingrule' => 'default']],
			$field->toStoredValue(),
		);
	}

	public function testStoredValueKeepsRuleKeyCase(): void
	{
		$field = $this->field()->submit(['default' => 18.5, 'us' => 20]);

		$this->assertSame('US', $field->toStoredValue()[1]['pricingrule']);
	}

	public function testValidPricesHaveNoErrors(): void
	{
		$this->assertSame([], $this->field()->errors());
	}

	public function testRequired(): void
	{
		$form = new Form(
			fields: ['price' => ['type' => 'prices', 'required' => true]],
			model: $this->product,
		);

		$this->assertArrayHasKey('price', $form->submit(input: ['price' => []])->errors());
	}

	public function testProductReadsStoredPrices(): void
	{
		$field = $this->field()->submit(['default' => 18.5, 'us' => 20]);
		$product = new ProductPage([
			'slug' => 'stored-product',
			'content' => ['price' => Yaml::encode($field->toStoredValue())],
		]);

		$this->assertSame(20.0, $product->price('US')->price);
		$this->assertSame('USD', $product->price('US')->currency);
	}
}
