<?php

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\PricingRule;

class PricingRuleTest extends TestCase
{
	public function testConstructorSetsProperties()
	{
		$rule = new PricingRule('test', 'Test Rule', 'eur', null, false);

		$this->assertEquals('test', $rule->key);
		$this->assertEquals('Test Rule', $rule->name);
		$this->assertEquals('EUR', $rule->currency);
		$this->assertFalse($rule->taxIncluded);
	}

	public function testConstructorDefaults()
	{
		$rule = new PricingRule('default');

		$this->assertEquals('default', $rule->key);
		$this->assertEquals('default', $rule->name);
		$this->assertNull($rule->currency);
		$this->assertTrue($rule->taxIncluded);
	}

	public function testCurrencyIsUppercase()
	{
		$rule = new PricingRule('key', null, 'usd');
		$this->assertEquals('USD', $rule->currency);
	}

	public function testCheckRuleReturnsTrueIfNoRule()
	{
		$rule = new PricingRule('key');
		$this->assertTrue($rule->checkRule());
	}

	public function testCheckRuleCallsCallable()
	{
		$called = false;
		$callable = function ($kirby) use (&$called) {
			$called = true;
			return true;
		};
		$rule = new PricingRule('key', null, null, $callable);
		$this->assertTrue($rule->checkRule());
		$this->assertTrue($called);
	}

	public function testCheckRuleReturnsFalseIfCallableReturnsFalse()
	{
		$callable = function () {
			return false;
		};
		$rule = new PricingRule('key', null, null, $callable);
		$this->assertFalse($rule->checkRule());
	}

	public function testToArrayRemovesRule()
	{
		$rule = new PricingRule('key', 'Name', 'eur', function () {});
		$array = $rule->toArray();
		$this->assertArrayNotHasKey('rule', $array);
		$this->assertEquals('key', $array['key']);
		$this->assertEquals('Name', $array['name']);
		$this->assertEquals('EUR', $array['currency']);
	}
}
