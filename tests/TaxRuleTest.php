<?php

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\TaxRule;
use Kirby\Cms\App;

class TaxRuleTest extends TestCase
{
	public function testConstructorSetsProperties()
	{
		$rule = new TaxRule('vat', 'VAT', function () { return 19; });
		$this->assertEquals('vat', $rule->key);
		$this->assertEquals('VAT', $rule->name);
		$this->assertTrue(is_callable($rule->rule));
	}

	public function testConstructorDefaultsNameToKey()
	{
		$rule = new TaxRule('mwst');
		$this->assertEquals('mwst', $rule->name);
	}

	public function testTaxRateReturnsNullIfNoRule()
	{
		$rule = new TaxRule('no-tax');
		$this->assertNull($rule->taxRate());
	}

	public function testTaxRateReturnsCorrectValue()
	{
		$rule = new TaxRule('vat', null, function () { return 19; });
		$this->assertEquals(0.19, $rule->taxRate());
	}

	public function testTaxRatePassesKirbyInstance()
	{
		$kirby = $this->createMock(App::class);
		$rule = new TaxRule('vat', null, function ($passedKirby) use ($kirby) {
			$this->assertSame($kirby, $passedKirby);
			return 7;
		});
		$this->assertEquals(0.07, $rule->taxRate($kirby));
	}

	public function testToArrayIncludesTaxRate()
	{
		$rule = new TaxRule('vat', 'VAT', function () { return 19; });
		$array = $rule->toArray();
		$this->assertArrayHasKey('rule', $array);
		$this->assertEquals(0.19, $array['rule']);
	}
}
