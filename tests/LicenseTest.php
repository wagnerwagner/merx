<?php

namespace Wagnerwagner\Merx\Tests;

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;
use Wagnerwagner\Merx\License;

/**
 * Merx knows a license key by its fingerprint. The real keys are made from a
 * secret this repository does not have, so the tests bring their own list.
 */
final class LicenseTest extends TestCase
{
	protected App $app;

	protected function license(string $licenseKey): License
	{
		$kirby = $this->app->clone(['options' => [
			'wagnerwagner.merx.license' => $licenseKey,
		]]);

		return new class ($kirby->plugin('wagnerwagner/merx')) extends License {
			protected function fingerprints(): string
			{
				return str_repeat('a', 10) . License::fingerprint('MERX2-ABCDEFGH-IJKLMNOP') . str_repeat('b', 10);
			}
		};
	}

	public function setUp(): void
	{
		App::$enableWhoops = false;
		$this->app = App::instance();
	}

	public function tearDown(): void
	{
		App::instance($this->app);
	}

	public function testAKeyOnTheListIsValid(): void
	{
		$license = $this->license('MERX2-ABCDEFGH-IJKLMNOP');

		$this->assertTrue($license->isValid());
		$this->assertSame('Merx License', $license->name());
		$this->assertSame('MERX2-XXXXXXXX-XXXXMNOP', $license->status()->label());
	}

	public function testAKeyIsValidHoweverItWasPasted(): void
	{
		$this->assertTrue($this->license(" merx2-abcdefgh-ijklmnop\n")->isValid());
	}

	public function testAKeyWhichIsNotOnTheListIsInvalid(): void
	{
		$license = $this->license('MERX2-ABCDEFGH-IJKLMNOQ');

		$this->assertFalse($license->isValid());
		$this->assertSame('negative', $license->status()->theme());
	}

	public function testAFingerprintIsNotAKey(): void
	{
		// Whoever reads the list must not be able to use what is on it
		$this->assertFalse($this->license(str_repeat('a', 10))->isValid());
	}

	public function testNoKeyAsksForALicense(): void
	{
		// The default of the option is an empty string
		$this->assertSame('Get Merx License', $this->license('')->name());
	}

	public function testTheShippedListHoldsWholeFingerprints(): void
	{
		$fingerprints = file_get_contents(dirname(__DIR__) . '/config/licenses.bin');

		$this->assertGreaterThan(0, strlen($fingerprints));
		$this->assertSame(0, strlen($fingerprints) % 10);
	}
}
