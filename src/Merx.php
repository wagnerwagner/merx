<?php

namespace Wagnerwagner\Merx;

use I18n;
use NumberFormatter;
use Wagnerwagner\Merx\Gateways;
use Wagnerwagner\Merx\Cart;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\V;
use Kirby\Exception\Exception;
use Kirby\Toolkit\Config;
use OrderPage;

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


	public function __construct()
	{
		$this->cart = new Cart();
		$this->gateways = array_merge(Gateways::$gateways, option('ww.merx.gateways', []));
	}


	/**
	 * Url to be used to complete the payment
	 *
	 * @return string  e.g. https://example.com/api/shop/success
	 */
	static function successUrl(): string
	{
		$kirby = kirby();
		return (string)$kirby->url('api') . '/' . $kirby->option('ww.merx.api.endpoint', 'shop') . '/success';
	}


	/**
	 * Formats a currency number
	 * E.g. 1045.12 => $ 1,045.12
	 * Similar to I18n::formatNumber()
	 */
	public static function formatCurrency(
		int|float $number,
		string|null $currency,
		string|null $locale = null
	): string {
		$locale  ??= I18n::locale();
		$formatter = static::currencyNumberFormatter($locale);
		$number    = $formatter?->formatCurrency($number, $currency) ?? $number;
		return (string)$number;
	}


	/**
	 * Formats a float to percent
	 * E.g. 0.19 => 19 %
	 * Similar to I18n::formatNumber()
	 */
	public static function formatPercent(
		int|float $number,
		string|null $locale = null
	): string {
		$locale  ??= I18n::locale();
		$formatter = static::percentNumberFormatter($locale);
		$number    = $formatter?->format($number) ?? $number;
		return (string)$number;
	}


	/**
	 * Helper method to format IBAN (DE00 0000 0000 0000 00)
	 *
	 * @param string $iban E.g. DE0000000000000000
	 *
	 * @return string
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

		return static::$currencyFormatters[$locale] = new NumberFormatter($locale, NumberFormatter::PERCENT);
	}

	/**
	 * Helper method to calculate tax
	 *
	 * @param float $grossPrice Price including tax. E.g. 99.99
	 * @param float $tax In percent. E.g. 19
	 *
	 * @return float
	 */
	public static function calculateTax(float $grossPrice, float $tax): float
	{
		return $grossPrice / ($tax + 100) * $tax;
	}


	/**
	 * Helper method to calculate net price
	 *
	 * @param float $grossPrice Price including tax. E.g. 99.99
	 * @param float $tax In percent. E.g. 19
	 *
	 * @return float
	 */
	public static function calculateNet(float $grossPrice, float $tax): float
	{
		return $grossPrice - self::calculateTax($grossPrice, $tax);
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
		$session = (array)kirby()->session()->get('ww.merx.virtualOrderPage');
		if (!$session) {
			throw new \Exception('Session "ww.merx.virtualOrderPage" does not exist.');
		}

		return new OrderPage($session);
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
			$redirect = $this->successUrl();

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
			if (!array_key_exists('paymentMethod', $data) || Str::length($data['paymentMethod']) === 0) {
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
				'model' => 'order',
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
			$gateway = $this->getGateway($data['paymentMethod']);
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
	public function completePayment(array $data = []): OrderPage
	{
		try {
			$virtualOrderPage = $this->getVirtualOrderPageFromSession();
			$gateway = $this->getGateway($virtualOrderPage->paymentMethod()->toString());

			kirby()->trigger('ww.merx.completePayment:before', compact('virtualOrderPage', 'gateway', 'data'));

			if (is_callable($gateway['completePayment'])) {
				$gateway['completePayment']($virtualOrderPage, $data);
			}

			$virtualOrderPage->content()->update([
				'invoiceDate' => date('c'),
			]);

			$kirby = kirby();

			// Set to default language to make sure content is saved in default language
			$currentLanguageCode = null;
			if ($kirby->multilang()) {
				$currentLanguageCode = $kirby->languageCode();
				$kirby->setCurrentLanguage($kirby->defaultLanguage()->code());
			}

			$kirby->impersonate('kirby');
			$ordersPage = page(option('ww.merx.ordersPage', 'orders'));
			$virtualOrderPageArray = $virtualOrderPage->toArray();
			$virtualOrderPageArray['template'] = $virtualOrderPageArray['template']->name();

			/** @var OrderPage $orderPage */
			$orderPage = $ordersPage->createChild($virtualOrderPageArray)->publish()->changeStatus('listed');

			// Reset language
			$kirby->setCurrentLanguage($currentLanguageCode);

			$this->cart->delete();
			kirby()->session()->remove('ww.merx.virtualOrderPage');

			kirby()->trigger('ww.merx.completePayment:after', compact('orderPage'));

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


	public static function setMessage(mixed $message): void
	{
		kirby()->session()->set('ww.merx.message', $message);
	}


	/**
	 * Returns and removes message stored by `Merx::setMessage()`.
	 *
	 * @return null|mixed
	 */
	public static function getMessage(): mixed
	{
		$messageSession = kirby()->session()->get('ww.merx.message');
		if ($messageSession) {
			kirby()->session()->remove('ww.merx.message');
			return $messageSession;
		} else {
			return null;
		}
	}


	public static function getFieldError(\Field $field, array $rules): array
	{
		$errors = V::errors($field->value(), $rules);
		$fields = array_change_key_case($field->parent()->blueprint()->fields(), CASE_LOWER);
		if (sizeof($errors) > 0) {
			return [
				$field->key() => [
					'label' => $fields[$field->key()]['label'],
					'message' => $errors,
				],
			];
		} else {
			return [];
		}
	}

	public static function setCurrency(?string $currency): string
	{
		Config::set('ww.merx.currency.current', $currency);
		return self::currentCurrency();
	}

	public static function currentCurrency(): string
	{
		return Config::get('ww.merx.currency.current', option('ww.merx.currency.default'));
	}
}
