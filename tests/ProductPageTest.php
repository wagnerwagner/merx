<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\Price;
use Wagnerwagner\Merx\ProductPage;

class ProductPageTest extends TestCase
{
	protected App $kirby;
	protected ProductPage $product;

	public function setUp(): void
	{
		$this->kirby = new App([
			'options' => [
				'ww.merx.pricingRules' => [
					'de' => [
						'name' => 'de (EUR)',
						'currency' => 'EUR',
						'rule' => fn (?App $kirby): bool => $kirby->languageCode() === 'de',
						'taxIncluded' => true,
					],
					'en' => [
						'name' => 'en (USD)',
						'currency' => 'USD',
						'rule' => fn (?App $kirby): bool => $kirby->languageCode() === 'en',
						'taxIncluded' => false,
					],
				],
				'ww.merx.taxRules' => [
					'default' => [
						'name' => fn (): string => t('taxRule.default', 'default'),
						'rule' => fn (?App $kirby): float => $kirby->languageCode() === 'de' ? 19 : 20,
					],
					'reduced' => [
						'name' => fn (): string => t('taxRule.reduced', 'reduced'),
						'rule' => fn (?App $kirby): float => $kirby->languageCode() === 'de' ? 7 : 5.5,
					],
				],
			],
			'site' => [
				'children' => [
					[
						'slug' => 'orders',
					],
				],
			],
		]);

		$this->product = new ProductPage([
			'slug' => 'test-product',
			'content' => [
				'prices' => [
					[
						'price' => '19.99',
						'pricingKey' => 'de'
					],
					[
						'price' => '22.99',
						'pricingKey' => 'en'
					],
				],
			],
		]);
		if (!$this->product instanceof ProductPage) {
			throw new \RuntimeException('Failed to create ProductPage instance');
		}
	}

	public function testPrice(): void
	{
		$price = $this->product->price('de');
		$this->assertInstanceOf(Price::class, $price);
		$this->assertEquals(19.99, $price->price);
		$this->assertEquals('EUR', $price->currency);

		$usdPrice = $this->product->price('en');
		$this->assertInstanceOf(Price::class, $usdPrice);
		$this->assertEquals(22.99, $usdPrice->price);
		$this->assertEquals('USD', $usdPrice->currency);
	}

	public function testPrices(): void
	{
		$prices = $this->product->prices();
		$this->assertEquals(2, $prices->count());

		$firstPrice = $prices->first();
		$this->assertInstanceOf(Price::class, $firstPrice);
		$this->assertEquals(19.99, $firstPrice->price);
		$this->assertEquals('EUR', $firstPrice->currency);
	}

	public function testOrders(): void
	{
		$orders = $this->product->orders();
		$this->assertInstanceOf(\Kirby\Cms\Pages::class, $orders);
	}
}
