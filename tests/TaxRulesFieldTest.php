<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Form\Form;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\ProductPage;
use Wagnerwagner\Merx\TaxRulesField;

class TaxRulesFieldTest extends TestCase
{
	protected App $app;

	protected function app(array $taxRules): App
	{
		return new App([
			// The test site, so no site config adds tax rules of its own
			'roots' => [
				'index' => __DIR__,
				'content' => __DIR__ . '/kirby/content',
				'site' => __DIR__ . '/kirby/site',
			],
			'options' => [
				'wagnerwagner.merx.taxRules' => $taxRules,
			],
		]);
	}

	protected function field(mixed $value = null): TaxRulesField
	{
		return new TaxRulesField(
			model: new ProductPage(['slug' => 'test-product']),
			name: 'taxRule',
			value: $value,
		);
	}

	public function setUp(): void
	{
		$this->app = App::instance();

		// Kirby installs Whoops’ error and exception handlers with every instance
		// and leaves them behind, which PHPUnit reports as a risky test.
		App::$enableWhoops = false;

		$this->app([
			'default' => [
				'name' => 'Standard rate',
				'rate' => 0.19,
			],
			'reduced' => [
				'name' => 'Reduced rate',
				'rate' => 0.07,
			],
		]);
	}

	public function tearDown(): void
	{
		App::$enableWhoops = true;
		App::instance($this->app);
	}

	public function testEmptyIsTheDefaultRule(): void
	{
		$this->assertSame('default', $this->field()->toFormValue());
		$this->assertSame('reduced', $this->field('reduced')->toFormValue());

		// Content without the field at all
		$form = new Form(
			fields: ['taxRule' => ['type' => 'taxrules']],
			model: new ProductPage(['slug' => 'test-product']),
		);
		$this->assertSame('default', $form->toFormValues()['taxrule']);
	}

	public function testHiddenWithOneTaxRule(): void
	{
		$this->assertFalse($this->field()->isHidden());

		$this->app(['default' => ['rate' => 0.19]]);
		$this->assertTrue($this->field()->isHidden());
	}

	public function testNotTranslatable(): void
	{
		$this->assertFalse($this->field()->translate());
	}

	public function testOneOptionPerTaxRule(): void
	{
		$this->assertSame(
			[
				['disabled' => false, 'icon' => null, 'info' => null, 'text' => 'Standard rate', 'value' => 'default'],
				['disabled' => false, 'icon' => null, 'info' => null, 'text' => 'Reduced rate', 'value' => 'reduced'],
			],
			$this->field()->options(),
		);
	}

	public function testProductWithoutTaxRuleHasTheDefaultRate(): void
	{
		$product = new ProductPage([
			'slug' => 'no-tax-rule',
			'content' => ['price' => '119'],
		]);

		$this->assertSame(0.19, $product->tax()->rate);
	}

	public function testUnknownRuleIsInvalid(): void
	{
		$this->assertArrayHasKey('option', $this->field('zero')->errors());
	}
}
