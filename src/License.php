<?php

namespace Wagnerwagner\Merx;

use Kirby\Filesystem\F;
use Kirby\Plugin\LicenseStatus;
use Kirby\Plugin\Plugin;
use Kirby\Toolkit\Str;

/**
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 *
 * @internal
 */
class License extends \Kirby\Plugin\License
{
	public function __construct(
		protected Plugin $plugin,
	) {
		if ($this->licenseKey() !== null) {

			if ($this->isValid()) {
				$this->name = 'Merx License';
				$this->link = 'https://merx.wagnerwagner.de/license';
				$this->status = new LicenseStatus(
					value: 'valid',
					label: $this->privateLicense(),
					theme: 'positive',
					icon: 'check',
				);
			} else {
				// Masked: the Panel shows this to every user who can see plugins
				$this->name = 'Invalid Merx License (' . $this->privateLicense() . ')';
				$this->link = 'https://merx.wagnerwagner.de/reference/options/config-options/license';
				$this->status = new LicenseStatus(
					value: 'missing',
					theme: 'negative',
					label: 'Check your license key',
					icon: 'alert',
				);
			}
		} else {
			$this->name = 'Get Merx License';
			$this->link = 'https://merx.wagnerwagner.de/buy';

			$this->status = new LicenseStatus(
				value: 'valid',
				label: 'Get a license please',
				theme: 'love',
				icon: 'key',
			);
		}
	}

	/**
	 * What Merx knows of a license key
	 *
	 * The keys are issued by merx.wagnerwagner.de, from a secret which never
	 * leaves it. Merx ships the fingerprints of all of them, which is enough
	 * to recognize a key and not enough to make one.
	 */
	public static function fingerprint(string $licenseKey): string
	{
		return substr(hash('sha256', Str::upper(trim($licenseKey)), true), 0, 10);
	}

	/**
	 * The fingerprints of all license keys, ten bytes each
	 */
	protected function fingerprints(): string
	{
		return F::read(dirname(__DIR__) . '/config/licenses.bin') ?: '';
	}

	public function isValid(): bool
	{
		return in_array(static::fingerprint($this->licenseKey()), str_split($this->fingerprints(), 10), true);
	}

	public function licenseKey(): string|null
	{
		// The default is an empty string, which is no license key
		return option('wagnerwagner.merx.license') ?: null;
	}

	public function privateLicense(): string
	{
		return 'MERX2-XXXXXXXX-XXXX' . Str::substr($this->licenseKey(), -4);
	}
}
