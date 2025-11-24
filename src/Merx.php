<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use NumberFormatter;
use Wagnerwagner\Merx\Gateways;
use Wagnerwagner\Merx\Cart;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\Escape;
use Kirby\Exception\Exception;
use Kirby\Toolkit\I18n;

/**
 * Main class for Merx plugin
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class Merx
{
	protected Cart $cart;

	/**
	 * Custom and default gateways
	 */
	protected array $gateways = [];

	/**
	 * Cache of `NumberFormatter` objects by locale
	 */
	protected static array $currencyFormatters = [];

	/**
	 * Cache of `NumberFormatter` objects by locale
	 */
	protected static array $percentFormatters = [];

	protected PricingRules $pricingRules;

	public static string $sessionTokenParameterName = 'sessionToken';

	public function __construct()
	{
		$this->cart = new Cart();
		$this->gateways = array_merge(Gateways::$gateways, option('ww.merx.gateways', []));
	}


	/**
	 * Url to be used to complete the payment
	 *
	 * @return string  e.g. https://example.com/api/shop/success?token=1753995556.cefe4a8da2189499186c.476d9b4d2e97335dd1f094d1f696b2618fadb2e4a11e39d7bf64563bc8b650f6
	 */
	static function returnUrl(): string
	{
		$kirby = kirby();
		$tokenQuery = Merx::$sessionTokenParameterName . '=' . $kirby->session()->token();
		$apiEndpint = $kirby->url('api') . '/' . $kirby->option('ww.merx.api.endpoint', 'shop') . '/success';
		return $apiEndpint . '?' . $tokenQuery;
	}


	/**
	 * Formats a currency number
	 * E.g. 1045.12 → € 1,045.12
	 * Similar to I18n::formatNumber()
	 */
	public static function formatCurrency(
		int|float $number,
		string|null $currency,
		string|null $locale = null,
		int|null $maxFractionDigits = null,
	): string {
		$locale  ??= I18n::locale();
		$formatter = static::currencyNumberFormatter($locale);
		if (is_int($maxFractionDigits)) {
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}
		$number    = $formatter?->formatCurrency($number, $currency) ?? $number;
		return (string)$number;
	}


	/**
	 * Formats a float to percent
	 * E.g. 0.19 → 19 %
	 * Similar to I18n::formatNumber()
	 */
	public static function formatPercent(
		int|float $number,
		string|null $locale = null,
		int|null $maxFractionDigits = null,
	): string {
		$locale  ??= I18n::locale();
		$formatter = static::percentNumberFormatter($locale);
		if (is_int($maxFractionDigits)) {
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}
		$number    = $formatter?->format($number) ?? $number;
		return (string)$number;
	}

	/**
	 * Calculate tax amount from gross price and tax rate
	 *
	 * E.g. calculateTax(200, 19) → 31.932773109243698
	 *
	 * @param float $grossPrice E.g. 200
	 * @param float $taxRate Tax rate in percent. E.g. 19
	 */
	public static function calculateTax(float $grossPrice, float $taxRate): float
	{
		return $grossPrice - ($grossPrice / (1 + $taxRate / 100));
	}


	/**
	 * Helper method to format IBAN
   *
	 * @param string $iban E.g. DE0000000000000000
	 * @return string
   * Space separated string with 4 characters per group.
   * E.g. DE00 0000 0000 0000 00
	 */
	public static function formatIBAN(string $iban): string
	{
		$ibanArray = str_split($iban, 4);
		return implode(' ', $ibanArray);
	}


	/**
	 * Returns (and creates) a currency number formatter for a given locale
	 * Similar to I18n::decimalNumberFormatter()
	 */
	protected static function currencyNumberFormatter(
		string $locale
	): NumberFormatter|null {
		if ($formatter = static::$currencyFormatters[$locale] ?? null) {
			return $formatter;
		}

		if (
			extension_loaded('intl') !== true ||
			class_exists('NumberFormatter') !== true
		) {
			return null; // @codeCoverageIgnore
		}

		return static::$currencyFormatters[$locale] = new NumberFormatter($locale, NumberFormatter::CURRENCY);
	}

	/**
	 * Returns (and creates) a percent number formatter for a given locale
	 * Similar to I18n::decimalNumberFormatter()
	 */
	protected static function percentNumberFormatter(
		string $locale
	): NumberFormatter|null {
		if ($formatter = static::$percentFormatters[$locale] ?? null) {
			return $formatter;
		}

		if (
			extension_loaded('intl') !== true ||
			class_exists('NumberFormatter') !== true
		) {
			return null; // @codeCoverageIgnore
		}

		return static::$percentFormatters[$locale] = new NumberFormatter($locale, NumberFormatter::PERCENT);
	}

	/**
	 * Returns visitors cart.
	 *
	 * @param array $data Optional data to create a new cart.
	 */
	public function cart(?array $data = null): Cart
	{
		if ($data) {
			return $this->cart = new Cart($data);
		}
		return $this->cart;
	}

	private function getVirtualOrderPageFromSession(): OrderPage
	{
		$kirby = App::instance();
		$orderPageFromSession = $kirby->session()->get('ww.merx.virtualOrderPage');
		if (!$orderPageFromSession) {
			$orderPageFromSession = $kirby->sessionHandler()->getManually($_GET[static::$sessionTokenParameterName])->get('ww.merx.virtualOrderPage');

			if (!$orderPageFromSession) {
				throw new \Exception('Session "ww.merx.virtualOrderPage" does not exist.');
			}
		}

		return new OrderPage((array)$orderPageFromSession);
	}


	private function getGateway(string $paymentMethod): array
	{
		if (!array_key_exists($paymentMethod, $this->gateways)) {
			throw new \Exception('No gateway for payment method (' . $paymentMethod . ')');
		}
		$gateway = $this->gateways[$paymentMethod];
		if (!is_array($gateway)) {
			$gateway = [];
		}
		if (!array_key_exists('initializePayment', $gateway)) {
			$gateway['initializePayment'] = null;
		}
		if (!array_key_exists('completePayment', $gateway)) {
			$gateway['completePayment'] = null;
		}
		return $gateway;
	}


	/**
	 * Creates virtual OrderPage and validates it. Runs payment gateway’s initializePayment function. Saves virtual OrderPage in user session.
	 *
	 * @param array $data Content of `OrderPage`. Must contain `paymentMethod`.
	 * @return string `api/shop/success` or result of `initializePayment()` of `paymentMethod` gateway.
	 */
	public function initializePayment(array $data): string
	{
		try {
			$redirect = $this->returnUrl();

			// set language for single language installations
			if (!option('languages', false) && option('locale', false)) {
				$locale = \Kirby\Toolkit\Locale::normalize(option('locale'));
				$lang = substr($locale[LC_ALL] ?? $locale[LC_MESSAGES], 0, 2);
				kirby()->setCurrentTranslation($lang);
				kirby()->setCurrentLanguage($lang);
			}

			// cleaning up and secure post data
			$data = array_map(function (string $item) {
				return Escape::html(Str::trim($item));
			}, $data);

			// get cart
			$cart = $this->cart;

			// run hook
			kirby()->trigger('ww.merx.initializePayment:before', compact('data', 'cart'));

			// check cart
			if ($cart->count() <= 0) {
				throw new Exception([
					'key' => 'merx.emptycart',
					'httpCode' => 500,
					'details' => [
						'message' => 'Cart contains zero items.',
					],
					'data' => [
						'cart' => $cart->toArray(),
					],
				]);
			}

			// check if paymentMethod exists
			$paymentMethod = $data['paymentMethod'] ?? null;
			if (Str::length($paymentMethod) === 0) {
				throw new Exception([
					'key' => 'merx.noPaymentMethod',
					'httpCode' => 400,
				]);
			}

			// add cart to content
			$data['items'] = $cart->toYaml();

			// create virtual order page
			$virtualOrderPage = new OrderPage([
				'slug' => Str::random(16),
				'template' => 'order',
				'content' => $data,
			]);

			// check for validation errors
			$errors = $virtualOrderPage->errors();
			if (sizeof($errors) > 0) {
				throw new Exception([
					'key' => 'merx.fieldsvalidation',
					'httpCode' => 400,
					'details' => $errors,
				]);
			}

			// run gateway
			$gateway = $this->getGateway($paymentMethod);
			if (is_callable($gateway['initializePayment'])) {
				$virtualOrderPage = $gateway['initializePayment']($virtualOrderPage);
				if ($virtualOrderPage->redirect()->isNotEmpty()) {
					$redirect = (string)$virtualOrderPage->redirect();
				}
			}

			// save virtual order page as session
			kirby()->session()->set('ww.merx.virtualOrderPage', $virtualOrderPage->toArray());

			// run hook
			kirby()->trigger('ww.merx.initializePayment:after', compact('virtualOrderPage', 'redirect'));

			return $redirect;
		} catch (\Exception $ex) {
			if (get_class($ex) === 'Kirby\Exception\Exception') {
				throw $ex;
			}
			throw new Exception([
				'key' => 'merx.initializePayment',
				'httpCode' => 500,
				'details' => [
					'message' => $ex->getMessage(),
					'code' => $ex->getCode(),
					'file' => $ex->getFile(),
					'line' => $ex->getLine(),
				],
				'previous' => $ex,
			]);
		}
	}


	/**
	 * Runs payment gateway’s completePayment function
	 *
	 * @param array $data Data required for payment gateway’s `completePayment()`
	 */
	public function createOrder(array $data = []): Page|OrderPage
	{
		try {
			$virtualOrderPage = $this->getVirtualOrderPageFromSession();
			$gateway = $this->getGateway($virtualOrderPage->paymentMethod()->toString());

			kirby()->trigger('ww.merx.completePayment:before', compact('virtualOrderPage', 'gateway', 'data'));

			if (is_callable($gateway['completePayment'])) {
				$gateway['completePayment']($virtualOrderPage, $data);
			}

			$kirby = kirby();

			// Set to default language to make sure content is saved in default language
			$currentLanguageCode = null;
			if ($kirby->multilang()) {
				$currentLanguageCode = $kirby->languageCode();
				$kirby->setCurrentLanguage($kirby->defaultLanguage()->code());
			}

			$kirby->impersonate('kirby');
			$ordersPage = $kirby->site()->ordersPage();
			if ($ordersPage === null) {
				// create orders page if it does not exist
				$ordersPage = $kirby->site()->createChild([
					'id' => option('ww.merx.ordersPage'),
					'template' => 'orders',
					'draft' => false,
					'content' => [
						'title' => t('field.orders'),
					],
				]);
			}
			$virtualOrderPageArray = $virtualOrderPage->toArray();
			$virtualOrderPageArray['template'] = 'order';
			$virtualOrderPageArray['model'] = 'order';
			$virtualOrderPageArray['content']['created'] = date('c');
			$virtualOrderPageArray['draft'] = false;

			/** @var OrderPage $orderPage */
			$orderPage = $ordersPage->createChild($virtualOrderPageArray);
			$orderPage = $orderPage->changeStatus('listed');

			// Reset language
			$kirby->setCurrentLanguage($currentLanguageCode);

			$this->cart->delete();
			$kirby->session()->remove('ww.merx.virtualOrderPage');

			kirby()->trigger('ww.merx.completePayment:after', compact('orderPage'));

			/** @todo make customizable */
			// $latestOrder = $ordersPage->children()->listed()->last();
			// $orderPage->version()->update([
			// 	'invoiceNumber' => $latestOrder?->invoiceNumber()->toInt() + 1 ?? 1,
			// ]);

			$orderPage->changeStatus('listed');

			return $orderPage;
		} catch (\Exception $ex) {
			if (get_class($ex) === 'Kirby\Exception\Exception') {
				throw $ex;
			}
			throw new Exception([
				'key' => 'merx.completePayment',
				'httpCode' => 500,
				'details' => [
					'message' => $ex->getMessage(),
					'code' => $ex->getCode(),
					'file' => $ex->getFile(),
					'line' => $ex->getLine(),
				],
				'previous' => $ex,
			]);
		}
	}

	public static function taxRules(array $options = []): TaxRules
	{
		return new TaxRules(option('ww.merx.taxRules', $options));
	}

	public static function taxRule(null|string $key): ?TaxRule
	{
		$taxRules = self::taxRules();
		return $taxRules->getRuleByKey($key);
	}

	public static function pricingRules(array $options = []): PricingRules
	{
		return new PricingRules(option('ww.merx.pricingRules', $options));
	}

  /**
   * Finds the pricing rule that applies to the current context
   *
   * @return PricingRule|null
   */
	public static function pricingRule(): ?PricingRule
	{
		$pricingRules = static::pricingRules();
		return $pricingRules->findRule();
	}

  /**
   * Currency of the pricing rule
   *
   * @return string|null Three-letter ISO currency code, in uppercase. E.g. EUR or USD
   */
	public static function currency(): ?string
	{
		return self::pricingRule()?->currency;
	}
}
