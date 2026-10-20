<?php

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\TaxRule;

class TaxRuleTest extends TestCase
{
	public function testConstructorDefaultsNameToKey()
	{
		$rule = new TaxRule('mwst');
		$this->assertEquals('mwst', $rule->name);
	}

	public function testConstructorSetsProperties()
	{
		$rule = new TaxRule('vat', 'VAT', fn () => 0.19);
		$this->assertEquals('vat', $rule->key);
		$this->assertEquals('VAT', $rule->name);
		$this->assertTrue(is_callable($rule->rate));
	}

	public function testTaxRatePassesKirbyInstance()
	{
		$kirby = $this->createMock(App::class);
		$rule = new TaxRule('vat', null, function ($passedKirby) use ($kirby) {
			$this->assertSame($kirby, $passedKirby);
			return 0.07;
		});
		$this->assertEquals(0.07, $rule->taxRate($kirby));
	}

	public function testTaxRateReturnsCorrectValue()
	{
		$rule = new TaxRule('vat', null, fn () => 0.19);
		$this->assertEquals(0.19, $rule->taxRate());
	}

	public function testTaxRateCanBeAFloat()
	{
		$rule = new TaxRule('vat', null, 0.19);
		$this->assertSame(0.19, $rule->taxRate());
	}

	public function testTaxRateReturnsNullIfNoRate()
	{
		$rule = new TaxRule('no-tax');
		$this->assertNull($rule->taxRate());
	}

	public function testToArrayIncludesTaxRate()
	{
		$rule = new TaxRule('vat', 'VAT', function () {
			return 0.19;
		});
		$array = $rule->toArray();
		$this->assertArrayHasKey('rate', $array);
		$this->assertEquals(0.19, $array['rate']);
	}
}
