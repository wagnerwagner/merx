<?php

namespace Wagnerwagner\Merx;

use Kirby\Plugin\License as PluginLicense;
use Kirby\Plugin\LicenseStatus;
use Kirby\Plugin\Plugin;
use Str;

class License extends PluginLicense
{
	public function __construct(
		protected Plugin $plugin
	){
		if ($this->licenseKey() !== null) {

			if ($this->isValid()) {
				$this->name = 'Merx License';
				$this->link = 'https://merx.wagnerwagner.de/license';
				$this->status = new LicenseStatus(
					value: 'valid',
					label: $this->privateLicense(),
					theme: 'positive',
					icon: 'check'
				);
			} else {
				$this->name = 'Invalid Merx License (' . $this->licenseKey() . ')';
				$this->link = 'https://merx.wagnerwagner.de/docs/options#license';
				$this->status = new LicenseStatus(
					value: 'missing',
					theme: 'negative',
					label: 'Check your license key',
					icon: 'alert'
				);
			}
		} else {
			$this->name = 'Get Merx License';
			$this->link = 'https://merx.wagnerwagner.de/buy';

			$this->status = new LicenseStatus(
				value: 'valid',
				label: 'Get a license please',
				theme: 'love',
				icon: 'key'
			);
		}
	}

	public function privateLicense(): string
	{
		return 'MERX-XXXXXXX-XXXX' . Str::substr($this->licenseKey(), -4);
	}

	public function licenseKey(): ?string
	{
		return option('ww.merx.license');
	}

	public function isValid(): bool
	{
		function crossfoot(int $int): string
		{
			$r = 0;
			foreach (str_split($int) as $v) {
				$r += $v;
			}
			return $r;
		}

		$licenseArr = Str::split(Str::after($this->licenseKey(), 'MERX-'), '-');
		return crossfoot(hexdec($licenseArr[0])) + crossfoot(hexdec($licenseArr[1])) === 90;
	}
}
