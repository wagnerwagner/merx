<?php

namespace Wagnerwagner\Merx\Tests;

use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\TaxRules;

class TaxRulesTest extends TestCase
{
	public function setUp(): void
	{
		// Whoops turns warnings into exceptions on a real site, but the
		// bootstrap silences them. Throw here too, so an option key read
		// without a fallback fails the test.
		set_error_handler(function (int $level, string $message, string $file, int $line): never {
			throw new \ErrorException($message, 0, $level, $file, $line);
		});
	}

	public function tearDown(): void
	{
		restore_error_handler();
	}

	public function testDefaultRuleWithoutOptions(): void
	{
		$taxRules = new TaxRules();

		$this->assertSame('default', $taxRules->getRuleByKey('default')?->key);
	}

	public function testNameCanBeAFunction(): void
	{
		$taxRules = new TaxRules([
			'reduced' => [
				'name' => fn (): string => 'Reduced',
			],
		]);

		$this->assertSame('Reduced', $taxRules->getRuleByKey('reduced')->name);
	}

	public function testNameIsOptional(): void
	{
		$taxRules = new TaxRules([
			'reduced' => [
				'rate' => 0.07,
			],
		]);

		$rule = $taxRules->getRuleByKey('reduced');
		$this->assertSame('reduced', $rule->name);
		$this->assertSame(0.07, $rule->rate());
	}

	public function testRateCanBeAFunction(): void
	{
		$taxRules = new TaxRules([
			'reduced' => [
				'rate' => fn (): float => 0.07,
			],
		]);

		$this->assertSame(0.07, $taxRules->getRuleByKey('reduced')->rate());
	}
}
