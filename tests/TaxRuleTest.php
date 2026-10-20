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

	public function testRatePassesKirbyInstance()
	{
		$kirby = $this->createMock(App::class);
		$rule = new TaxRule('vat', null, function ($passedKirby) use ($kirby) {
			$this->assertSame($kirby, $passedKirby);
			return 0.07;
		});
		$this->assertEquals(0.07, $rule->rate($kirby));
	}

	public function testRateReturnsCorrectValue()
	{
		$rule = new TaxRule('vat', null, fn () => 0.19);
		$this->assertEquals(0.19, $rule->rate());
	}

	public function testRateCanBeAFloat()
	{
		$rule = new TaxRule('vat', null, 0.19);
		$this->assertSame(0.19, $rule->rate());
	}

	public function testRateReturnsNullIfNoRate()
	{
		$rule = new TaxRule('no-tax');
		$this->assertNull($rule->rate());
	}

	public function testToArrayIncludesRate()
	{
		$rule = new TaxRule('vat', 'VAT', function () {
			return 0.19;
		});
		$array = $rule->toArray();
		$this->assertArrayHasKey('rate', $array);
		$this->assertEquals(0.19, $array['rate']);
	}
}
