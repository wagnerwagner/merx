<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use NumberFormatter;
use Wagnerwagner\Merx\Gateways;
use Wagnerwagner\Merx\Cart;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\Escape;
use Kirby\Exception\Exception;
use Kirby\Toolkit\Locale;

/**
 * This is the core of the plugin. The class mainly contains helper methods.
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

	/**
	 * Initializes cart and gateways
	 */
	public function __construct()
	{
		$this->cart = new Cart();
		$this->gateways = array_merge(Gateways::$gateways, option('wagnerwagner.merx.gateways', []));
	}


	/**
	 * Url to be used to complete the payment
	 *
	 * @return string e.g. https://example.com/api/shop/success?token=1753995556.cefe4a8da2189499186c.476d9b4d2e97335dd1f094d1f696b2618fadb2e4a11e39d7bf64563bc8b650f6
	 */
	static function returnUrl(): string
	{
		$kirby = kirby();
		$tokenQuery = Merx::$sessionTokenParameterName . '=' . $kirby->session()->token();
		$apiEndpint = $kirby->url('api') . '/' . $kirby->option('wagnerwagner.merx.api.endpoint', 'shop') . '/success';
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
		$locale ??= Locale::get(LC_NUMERIC);
		$formatter = static::currencyNumberFormatter($locale);
		if (is_int($maxFractionDigits)) {
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}
		$number = $formatter?->formatCurrency($number, $currency) ?? $number;
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
		$locale ??= Locale::get(LC_NUMERIC);
		$formatter = static::percentNumberFormatter($locale);
		if (is_int($maxFractionDigits)) {
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}
		$number = $formatter?->format($number) ?? $number;
		return (string)$number;
	}

	/**
	 * Calculates tax amount from gross price and tax rate
	 *
	 * E.g. calculateTax(200, 0.19) → 31.932773109243698
	 *
	 * @param float $grossPrice E.g. 200
	 * @param float $taxRate Tax rate. E.g. 0.19
	 */
	public static function calculateTax(float $grossPrice, float $taxRate): float
	{
		return $grossPrice - ($grossPrice / (1 + $taxRate));
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
		$orderPageFromSession = $kirby->session()->get('wagnerwagner.merx.virtualOrderPage');
		if (!$orderPageFromSession) {
			$orderPageFromSession = $kirby->sessionHandler()->getManually($_GET[static::$sessionTokenParameterName])->get('wagnerwagner.merx.virtualOrderPage');

			if (!$orderPageFromSession) {
				throw new \Exception('Session "wagnerwagner.merx.virtualOrderPage" does not exist.');
			}
		}

		$orderPageFromSession['parent'] = $kirby->site()->ordersPage();

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
	 * Creates virtual OrderPage and validates it. Runs payment gateway’s initializeOrder function. Saves virtual OrderPage in user session.
	 *
	 * `initializePayment` of the payment gateway is called
	 *
	 * @param array $data Content of `OrderPage`. Must contain `paymentMethod`.
	 * @return string `api/shop/success` or result of `initializeOrder()` of `paymentMethod` gateway.
	 */
	public function initializeOrder(array $data): string
	{
		try {
			$redirect = $this->returnUrl();
			$kirby = kirby();

			// set language for single language installations
			if (!option('languages', false) && option('locale', false)) {
				$locale = \Kirby\Toolkit\Locale::normalize(option('locale'));
				$lang = substr($locale[LC_ALL] ?? $locale[LC_MESSAGES], 0, 2);
				$kirby->setCurrentTranslation($lang);
				$kirby->setCurrentLanguage($lang);
			}

			// cleaning up and secure post data
			$data = array_map(function ($item) {
				return is_string($item) ? Escape::html(Str::trim($item)) : $item;
			}, $data);

			// get cart
			$cart = $this->cart;

			// run hook
			$kirby->trigger('wagnerwagner.merx.initializeOrder:before', ['cart' => $cart, 'data' => $data]);

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
				'parent' => $kirby->site()->ordersPage(),
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
				/** @var \OrderPage */
				$virtualOrderPage = $gateway['initializePayment']($virtualOrderPage);
				if ($virtualOrderPage->redirect()->isNotEmpty()) {
					$redirect = (string)$virtualOrderPage->redirect();
				}
			}

			// save virtual order page as session
			$kirby->session()->set('wagnerwagner.merx.virtualOrderPage', $virtualOrderPage->toArray());

			// run hook
			$kirby->trigger('wagnerwagner.merx.initializeOrder:after', ['virtualOrderPage' => $virtualOrderPage, 'redirect' => $redirect]);

			return $redirect;
		} catch (\Exception $ex) {
			if (get_class($ex) !== 'Kirby\Exception\Exception') {
				$ex = new Exception([
					'key' => 'merx.initializeOrder',
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
			if (option('wagnerwagner.merx.logging') === true) {
				Logger::log($ex, 'error');
			}
			throw $ex;
		}
	}


	/**
	 * Stores order page to file system
	 *
	 * Calls `completePayment` of the payment gateway
	 * Deletes cart
	 *
	 * @param array $data Data required for payment gateway’s `completePayment()`
	 */
	public function createOrder(array $data = []): OrderPage
	{
		try {
			$virtualOrderPage = $this->getVirtualOrderPageFromSession();
			$gateway = $this->getGateway($virtualOrderPage->paymentMethod()->toString());

			kirby()->trigger('wagnerwagner.merx.createOrder:before', ['virtualOrderPage' => $virtualOrderPage, 'gateway' => $gateway, 'data' => $data]);

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
					'id' => option('wagnerwagner.merx.ordersPage'),
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
			$virtualOrderPageArray['draft'] = false;
			$virtualOrderPageArray['content']['dateCreated'] = date('c');

			if (is_callable(option('wagnerwagner.merx.orderNumber'))) {
				$virtualOrderPageArray['content']['orderNumber'] = option('wagnerwagner.merx.orderNumber')($virtualOrderPage);
			}

			/** @var OrderPage $orderPage */
			$orderPage = $ordersPage->createChild($virtualOrderPageArray);
			$orderPage = $orderPage->changeStatus('listed');

			// Reset language
			$kirby->setCurrentLanguage($currentLanguageCode);

			$this->cart->delete();
			$kirby->session()->remove('wagnerwagner.merx.virtualOrderPage');

			kirby()->trigger('wagnerwagner.merx.createOrder:after', ['orderPage' => $orderPage]);

			return $orderPage;
		} catch (\Exception $ex) {
			if (get_class($ex) !== 'Kirby\Exception\Exception') {
				$ex = new Exception([
					'key' => 'merx.createOrder',
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
			if (option('wagnerwagner.merx.logging') === true) {
				Logger::log($ex, 'error');
			}
			throw $ex;
		}
	}

	/**
	 * Gets all tax rules defined by tax rule option
	 */
	public static function taxRules(array $options = []): TaxRules
	{
		return new TaxRules(option('wagnerwagner.merx.taxRules', $options));
	}

	/**
	 * Gets the tax rule by its key
	 */
	public static function taxRule(null|string $key): ?TaxRule
	{
		$taxRules = self::taxRules();
		return $taxRules->getRuleByKey($key);
	}

	/**
	 * Gets all pricing rules defined by pricing rule option
	 */
	public static function pricingRules(array $options = []): PricingRules
	{
		return new PricingRules(option('wagnerwagner.merx.pricingRules', $options));
	}

	/**
	 * Finds the pricing rule that applies to the current context
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
