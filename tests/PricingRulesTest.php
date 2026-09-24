<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Merx;

class PricingRulesTest extends TestCase
{
	protected App $app;

	public function setUp(): void
	{
		App::$enableWhoops = false;
		$this->app = App::instance();

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
		App::instance($this->app);
		App::$enableWhoops = true;
	}

	/**
	 * A new App rather than `clone()`, which would merge in the pricing rules
	 * of whatever instance an earlier test left behind.
	 */
	private function app(array $pricingRules): App
	{
		return new App([
			'options' => [
				'wagnerwagner.merx.pricingRules' => $pricingRules,
			],
		]);
	}

	public function testDefaultIsCheckedLast(): void
	{
		$this->app([
			'usd' => [
				'currency' => 'USD',
				'rule' => fn (): bool => true,
			],
			'default' => [
				'currency' => 'EUR',
			],
		]);

		// Kirby keeps the plugin’s key order when merging options
		$this->assertSame(['default', 'usd'], array_keys(option('wagnerwagner.merx.pricingRules')));
		$this->assertSame('usd', Merx::pricingRule()->key);
		$this->assertSame('USD', Merx::currency());
	}

	public function testDefaultIsTheFallback(): void
	{
		$this->app([
			'usd' => [
				'currency' => 'USD',
				'rule' => fn (): bool => false,
			],
			'default' => [
				'currency' => 'EUR',
			],
		]);

		$this->assertSame('default', Merx::pricingRule()->key);
	}

	public function testDefaultIsTheFallbackWhenNotConfigured(): void
	{
		$this->app([
			'usd' => [
				'currency' => 'USD',
				'rule' => fn (): bool => false,
			],
		]);

		$this->assertSame('default', Merx::pricingRule()->key);
		$this->assertSame('EUR', Merx::currency());
	}

	public function testOtherRulesAreCheckedInTheOrderTheyAreDefined(): void
	{
		$this->app([
			'monday' => [
				'rule' => fn (): bool => false,
			],
			'usd' => [
				'currency' => 'USD',
				'rule' => fn (): bool => true,
			],
			'gbp' => [
				'currency' => 'GBP',
				'rule' => fn (): bool => true,
			],
		]);

		$this->assertSame('usd', Merx::pricingRule()->key);
	}

	public function testNameAndTaxIncludedAreOptional(): void
	{
		$this->app([
			'usd' => [
				'currency' => 'USD',
			],
		]);

		$rule = Merx::pricingRules()->getRuleByKey('usd');
		$this->assertSame('usd', $rule->name);
		$this->assertTrue($rule->taxIncluded);
	}
}
