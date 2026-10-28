<?php

namespace Wagnerwagner\Merx;

use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Http\Url;
use Kirby\Session\Session;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\Locale;
use Kirby\Toolkit\Str;
use NumberFormatter;

/**
 * This is the core of the plugin. The class mainly contains helper methods.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class Merx
{
	/**
	 * Content fields of an `OrderPage` which Merx sets itself
	 *
	 * They are removed from the data handed to `initializeOrder()`, so a client
	 * cannot mark its own order as paid or rewrite the stored cart. Kirby stores
	 * content keys in lower case, so these are compared case-insensitively.
	 */
	public static array $protectedOrderFields = [
		'datecreated',
		'datepaid',
		'items',
		'ordernumber',
		'paymentdetails',
		'paypalorderid',
		'redirect',
		'sortingnumber',
		'stripepaymentintentid',
		'uuid',
	];
	public static string $returnNonceParameterName = 'nonce';

	/**
	 * Session key of the one-time nonce which ties a return from the payment
	 * provider to the checkout that started it
	 */
	public static string $returnNonceSessionKey = 'wagnerwagner.merx.returnNonce';
	public static string $sessionTokenParameterName = 'sessionToken';

	protected Cart $cart;

	/**
	 * Custom and default gateways
	 */
	protected array $gateways = [];
	protected PricingRules $pricingRules;

	/**
	 * Cache of `NumberFormatter` objects by locale
	 */
	protected static array $currencyFormatters = [];

	/**
	 * Cache of `NumberFormatter` objects by locale
	 */
	protected static array $decimalFormatters = [];

	/**
	 * Cache of `NumberFormatter` objects by locale
	 */
	protected static array $percentFormatters = [];

	/**
	 * Initializes cart and gateways
	 */
	public function __construct()
	{
		$this->cart = new Cart();
		$this->gateways = array_merge(Gateways::$gateways, option('wagnerwagner.merx.gateways', []));
	}

	/**
	 * Calculates tax amount from gross price and tax rate
	 *
	 * E.g. calculateTax(200, 0.19) → 31.932773109243698
	 *
	 * @param $grossPrice E.g. 200
	 * @param $taxRate Tax rate. E.g. 0.19
	 */
	public static function calculateTax(float $grossPrice, float $taxRate): float
	{
		return $grossPrice - ($grossPrice / (1 + $taxRate));
	}

	/**
	 * Returns visitors cart.
	 *
	 * @param array $data Optional data to create a new cart.
	 */
	public function cart(array|null $data = null): Cart
	{
		if ($data) {
			return $this->cart = new Cart($data);
		}
		return $this->cart;
	}

	/**
	 * Stores order page to file system
	 *
	 * Checks the stock again, calls `completePayment` of the payment gateway,
	 * lowers the stock of the ordered products while
	 * `wagnerwagner.merx.reduceStock` is true, and deletes the cart.
	 *
	 * @param $data Data required for payment gateway’s `completePayment()`
	 * @throws Exception error.merx.cart.stock or error.merx.cart.soldOut when the stock ran out before the payment was completed
	 */
	public function createOrder(array $data = []): OrderPage
	{
		$kirby = App::instance();

		try {
			$virtualOrderPage = $this->getVirtualOrderPageFromSession($data);
			$gateway = $this->getGateway($virtualOrderPage->paymentGateway()->toString());

			$kirby->trigger('wagnerwagner.merx.createOrder:before', ['virtualOrderPage' => $virtualOrderPage, 'gateway' => $gateway, 'data' => $data]);

			// The stock may have run out while the customer was at the payment
			// provider. Checked before `completePayment` captures the payment.
			$virtualOrderPage->cart()->validateStock();

			if (is_callable($gateway['completePayment'])) {
				$gateway['completePayment']($virtualOrderPage, $data);
			}

			// Set to default language to make sure content is saved in default language
			$currentLanguageCode = null;
			if ($kirby->multilang()) {
				$currentLanguageCode = $kirby->languageCode();
				$kirby->setCurrentLanguage($kirby->defaultLanguage()->code());
			}

			// Creating the order requires admin permissions. The callback makes sure the
			// impersonation is always reset, even when an error occurs. Without it the
			// `kirby` super user would stay active for the rest of the request.
			/** @var OrderPage $orderPage */
			$orderPage = $kirby->impersonate('kirby', function () use ($kirby, $virtualOrderPage, $currentLanguageCode): OrderPage {
				$ordersPage = $kirby->site()->ordersPage();
				if ($ordersPage === null) {
					// create orders page if it does not exist
					$ordersPage = $kirby->site()->createChild([
						'slug' => option('wagnerwagner.merx.ordersPage'),
						'template' => 'orders',
						'draft' => false,
						'content' => [
							'title' => t('field.orders'),
						],
					]);
				}
				$virtualOrderPageArray = $this->virtualOrderPageToArray($virtualOrderPage);
				$virtualOrderPageArray['model'] = 'order';
				$virtualOrderPageArray['draft'] = false;

				/** @var OrderPage $orderPage */
				$orderPage = static::lockOrders($ordersPage, function () use ($ordersPage, $virtualOrderPage, $virtualOrderPageArray): OrderPage {
					// `orderNumber` reads the orders through the virtual order page’s parent.
					// They may have been read before the lock was taken, and another
					// checkout may have written one since.
					$virtualOrderPage->parentModel()->purge();

					// Taken inside the lock, so the orders sort in the order they got their number
					$now = new DateTimeImmutable();
					$virtualOrderPageArray['content']['dateCreated'] = $now->format('c');
					// As a string: Kirby writes an int as a float, which keeps 14 digits of the 17
					$virtualOrderPageArray['content']['sortingNumber'] = (string)OrderPage::sortingNumberFromDate($now);

					if (is_callable(option('wagnerwagner.merx.orderNumber'))) {
						$virtualOrderPageArray['content']['orderNumber'] = option('wagnerwagner.merx.orderNumber')($virtualOrderPage);
					}

					/** @var OrderPage $orderPage */
					$orderPage = $ordersPage->createChild($virtualOrderPageArray);
					$orderPage = $orderPage->changeStatus('listed');

					// Inside the lock, so two orders cannot both lower the same stock
					// from the same number. Test orders leave the stock alone.
					if (option('wagnerwagner.merx.reduceStock') === true) {
						$orderPage->cart()->reduceStock();
					}

					return $orderPage;
				});

				// Gateways which complete the payment right away — PayPal, Stripe
				// Elements — create an order which is already paid. This is reported
				// here rather than from `page.create:after`, where the hooks would run
				// while the lock above holds back every other checkout.
				//
				// `apply()` rather than `trigger()`: a hook which updates the order
				// leaves the object it was given behind as an outdated copy. Updating
				// that copy again throws, and Kirby removes the order’s content from
				// disk while it does. `apply()` hands every hook, and Merx, the current one.
				if ($orderPage->isPaid() === true) {
					$orderPage = $kirby->apply('wagnerwagner.merx.orderPaid', ['orderPage' => $orderPage], 'orderPage');
				}

				// Reset language
				$kirby->setCurrentLanguage($currentLanguageCode);

				$this->cart->delete();

				// `apply()`, like `orderPaid` above, so a caller gets the order
				// with what the hooks wrote, not an outdated copy of it
				return $kirby->apply('wagnerwagner.merx.createOrder:after', ['orderPage' => $orderPage], 'orderPage');
			});

			return $orderPage;
		} catch (\Exception $ex) {
			if ($ex::class !== 'Kirby\Exception\Exception') {
				$ex = new Exception(
					key: 'merx.createOrder',
					httpCode: 500,
					details: static::exceptionDetails($ex),
					previous: $ex,
				);
			}
			if (option('wagnerwagner.merx.logging') === true) {
				Logger::log($ex, 'error');
			}
			throw $ex;
		}
	}

	/**
	 * Currency of the pricing rule
	 *
	 * @return string|null Three-letter ISO currency code, in uppercase. E.g. EUR or USD
	 */
	public static function currency(): string|null
	{
		return self::pricingRule()?->currency;
	}

	/**
	 * Returns (and creates) a currency number formatter for a given locale
	 * Similar to I18n::decimalNumberFormatter()
	 */
	protected static function currencyNumberFormatter(
		string $locale,
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
	 * Returns (and creates) a decimal number formatter for a given locale
	 *
	 * Used for prices without a currency. It keeps the two fraction digits of a
	 * price, where `NumberFormatter::DECIMAL` would print 10.20 as `10.2`.
	 */
	protected static function decimalNumberFormatter(
		string $locale,
	): NumberFormatter|null {
		if ($formatter = static::$decimalFormatters[$locale] ?? null) {
			return $formatter;
		}

		if (
			extension_loaded('intl') !== true ||
			class_exists('NumberFormatter') !== true
		) {
			return null; // @codeCoverageIgnore
		}

		$formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
		$formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, Price::roundingPrecision);
		$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, Price::roundingPrecision);

		return static::$decimalFormatters[$locale] = $formatter;
	}

	/**
	 * Diagnostic details of an exception, for use in `Exception::$details`
	 *
	 * The API is reachable without authentication, so internals such as the file
	 * path are only handed out while `debug` is on. The full exception is written
	 * to the log either way.
	 *
	 * @return array Empty array unless Kirby’s `debug` option is enabled
	 */
	public static function exceptionDetails(\Throwable $ex): array
	{
		if (App::instance()->option('debug') !== true) {
			return [];
		}

		return [
			'message' => $ex->getMessage(),
			'code' => $ex->getCode(),
			'file' => $ex->getFile(),
			'line' => $ex->getLine(),
		];
	}

	/**
	 * Removes the fields Merx sets itself from client submitted order data
	 *
	 * Kirby stores content keys in lower case, so `datePaid`, `DatePaid`
	 * and `datepaid` all address the same field and all have to be removed.
	 *
	 * @see Merx::$protectedOrderFields
	 */
	public static function filterOrderData(array $data): array
	{
		return array_filter(
			$data,
			fn ($key) => in_array(Str::lower((string)$key), static::$protectedOrderFields, true) === false,
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * Formats a currency number
	 * E.g. 1045.12 → € 1,045.12
	 * Similar to I18n::formatNumber()
	 *
	 * @see https://merx.wagnerwagner.de/guide/configuration/localization
	 */
	public static function formatCurrency(
		int|float $number,
		string|null $currency,
		string|null $locale = null,
		int|null $maxFractionDigits = null,
	): string {
		$locale ??= static::getLocale(LC_NUMERIC);

		// Without a currency code there is no symbol to place. `formatCurrency()`
		// would use the generic currency sign (¤), so the number is formatted on
		// its own instead.
		$formatter = $currency === null
			? static::decimalNumberFormatter($locale)
			: static::currencyNumberFormatter($locale);

		if (is_int($maxFractionDigits)) {
			$formatter?->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}

		if ($currency === null) {
			return (string)($formatter?->format($number) ?? $number);
		}

		$number = $formatter?->formatCurrency($number, $currency) ?? $number;
		return (string)$number;
	}

	/**
	 * Helper method to format IBAN
	 *
	 * @param $iban E.g. DE0000000000000000
	 * @return string
	 *                Space separated string with 4 characters per group.
	 *                E.g. DE00 0000 0000 0000 00
	 */
	public static function formatIBAN(string $iban): string
	{
		$ibanArray = str_split($iban, 4);
		return implode(' ', $ibanArray);
	}

	/**
	 * Formats a float to percent
	 * E.g. 0.19 → 19 %
	 * Similar to I18n::formatNumber()
	 *
	 * @see https://merx.wagnerwagner.de/guide/configuration/localization
	 */
	public static function formatPercent(
		int|float $number,
		string|null $locale = null,
		int|null $maxFractionDigits = null,
	): string {
		$locale ??= static::getLocale(LC_NUMERIC);

		$formatter = static::percentNumberFormatter($locale);
		if (is_int($maxFractionDigits)) {
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}
		$number = $formatter?->format($number) ?? $number;
		return (string)$number;
	}

	/**
	 * Get locale with fallback for `C` locale
	 */
	private static function getLocale(int|string $category = LC_ALL): string
	{
		$locale ??= Locale::get($category);
		if ($locale === 'C') {
			App::instance()->setCurrentLanguage();
			$locale = Locale::get($category);
		}
		return $locale;
	}

	/**
	 * Returns the virtual OrderPage as array for the session and `createChild()`
	 */
	private function virtualOrderPageToArray(OrderPage $virtualOrderPage): array
	{
			$array = $virtualOrderPage->toArray();
			unset($array['translations']);
			$array['template'] = $virtualOrderPage->template()->name();
			return $array;
	}


	private function getGateway(string $paymentGateway): array
	{
		if (!array_key_exists($paymentGateway, $this->gateways)) {
			throw new \Exception('No payment gateway named ' . $paymentGateway);
		}
		$gateway = $this->gateways[$paymentGateway];
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

	private function getVirtualOrderPageFromSession(array $data = []): OrderPage
	{
		$kirby = App::instance();
		$session = $kirby->session();
		$orderPageFromSession = $session->get('wagnerwagner.merx.virtualOrderPage');

		if (!$orderPageFromSession) {
			// The visitor may return in a different browser, where the session
			// cookie is not available. The session token names the session then.
			$sessionToken = $data[static::$sessionTokenParameterName] ?? $_GET[static::$sessionTokenParameterName] ?? null;

			if (is_string($sessionToken) === false || $sessionToken === '') {
				throw new \Exception('Session "wagnerwagner.merx.virtualOrderPage" does not exist.');
			}

			$session = $kirby->sessionHandler()->getManually($sessionToken);
			$orderPageFromSession = $session->get('wagnerwagner.merx.virtualOrderPage');

			if (!$orderPageFromSession) {
				throw new \Exception('Session "wagnerwagner.merx.virtualOrderPage" does not exist.');
			}
		}

		// The nonce lives in the same session as the order it belongs to
		static::validateReturnNonce($session, $data);

		$session->remove('wagnerwagner.merx.virtualOrderPage');

		$orderPageFromSession['parent'] = $kirby->site()->ordersPage();
		// The session holds no model, see `initializeOrder()`
		$orderPageFromSession['model'] = 'order';

		return OrderPage::factory((array)$orderPageFromSession);
	}

	/**
	 * Creates virtual OrderPage and validates it. Runs the payment gateway’s `initializePayment` function. Saves virtual OrderPage in the customer’s session.
	 *
	 * @param $data Content of `OrderPage`. Must contain `paymentGateway`.
	 * @throws Exception error.merx.cart.stock or error.merx.cart.soldOut when the cart holds more of a product than its stock
	 * @return string The return URL, or the `redirect` that the gateway’s `initializePayment` set on the virtual order page.
	 */
	public function initializeOrder(array $data): string
	{
		try {
			$kirby = App::instance();

			// Start a new nonce for this checkout, so a return url handed out
			// earlier cannot complete this order.
			$kirby->session()->remove(static::$returnNonceSessionKey);

			$redirect = $this->returnUrl();

			// cleaning up and secure post data
			$data = array_map(function ($item) {
				return is_string($item) ? Escape::html(Str::trim($item)) : $item;
			}, $data);

			// Drop fields which Merx sets itself. Without this a client could submit
			// `datePaid` and receive a paid order without paying.
			$data = static::filterOrderData($data);

			// get cart
			$cart = $this->cart;

			// run hook
			$kirby->trigger('wagnerwagner.merx.initializeOrder:before', ['cart' => $cart, 'data' => $data]);

			// check cart
			if ($cart->count() <= 0) {
				throw new Exception(
					key: 'merx.emptycart',
					data: [
						'cart' => $cart->toArray(),
					],
				);
			}

			// A cart lives in the session for days, while the stock runs out
			$cart->validateStock();

			// check if paymentGateway exists
			$paymentGateway = $data['paymentGateway'] ?? null;
			if (Str::length($paymentGateway) === 0) {
				throw new Exception(
					key: 'merx.noPaymentGateway',
					httpCode: 400,
				);
			}

			// add cart to content
			$data['items'] = $cart->toYaml();

			// create virtual order page. Through the model registry rather than
			// `new OrderPage()`, so the shop’s own `/site/models/order.php`
			// validates the order and is what the gateway and the hooks get.
			$virtualOrderPage = OrderPage::factory([
				'slug' => Str::random(16),
				'template' => 'order',
				'model' => 'order',
				'content' => $data,
				'parent' => $kirby->site()->ordersPage(),
			]);

			// check for validation errors
			$errors = $virtualOrderPage->errors();
			if (count($errors) > 0) {
				throw new Exception(
					key: 'merx.fieldsvalidation',
					httpCode: 400,
					details: $errors,
				);
			}

			// run gateway
			$gateway = $this->getGateway($paymentGateway);
			if (is_callable($gateway['initializePayment'])) {
				/** @var \Wagnerwagner\Merx\OrderPage */
				$virtualOrderPage = $gateway['initializePayment']($virtualOrderPage, $data);
				if ($virtualOrderPage->redirect()->isNotEmpty()) {
					$redirect = (string)$virtualOrderPage->redirect();
				}
			}
			// save virtual order page as session
			$kirby->session()->set('wagnerwagner.merx.virtualOrderPage', $this->virtualOrderPageToArray($virtualOrderPage));

			// run hook
			$kirby->trigger('wagnerwagner.merx.initializeOrder:after', ['virtualOrderPage' => $virtualOrderPage, 'redirect' => $redirect]);

			return $redirect;
		} catch (\Exception $ex) {
			if ($ex::class !== 'Kirby\Exception\Exception') {
				$ex = new Exception(
					key: 'merx.initializeOrder',
					httpCode: 500,
					details: static::exceptionDetails($ex),
					previous: $ex,
				);
			}
			if (option('wagnerwagner.merx.logging') === true) {
				Logger::log($ex, 'error');
			}
			throw $ex;
		}
	}

	/**
	 * Runs $callback while no other checkout can write an order
	 *
	 * The default `orderNumber` counts on from the last order, so two checkouts
	 * completing at the same moment would both read the same one. The lock is a
	 * file in the orders page’s folder; Kirby ignores files with a leading dot.
	 *
	 * The payment has gone through by the time an order is written. When the
	 * lock cannot be taken, the order is written anyway and the failure logged.
	 */
	protected static function lockOrders(Page $ordersPage, Closure $callback): OrderPage
	{
		$lockFile = $ordersPage->root() . '/.merx-orders.lock';
		$handle = @fopen($lockFile, 'c');
		$locked = $handle !== false && flock($handle, LOCK_EX);

		if ($locked === false && option('wagnerwagner.merx.logging') === true) {
			Logger::log('Could not lock ' . $lockFile . '. The order was written without a lock.', 'error');
		}

		try {
			return $callback();
		} finally {
			if ($handle !== false) {
				flock($handle, LOCK_UN);
				fclose($handle);
			}
		}
	}

	/**
	 * Returns (and creates) a percent number formatter for a given locale
	 * Similar to I18n::decimalNumberFormatter()
	 */
	protected static function percentNumberFormatter(
		string $locale,
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
	 * Gets a pricing rule by its key, or finds the one that applies to the current context
	 *
	 * @param $key Key of the pricing rule, or null for the pricing rule that applies to the current visitor
	 */
	public static function pricingRule(string|null $key = null): PricingRule|null
	{
		$pricingRules = static::pricingRules();
		if ($key !== null) {
			return $pricingRules->getRuleByKey($key);
		}
		return $pricingRules->findRule();
	}

	/**
	 * Gets all pricing rules defined by pricing rule option
	 */
	public static function pricingRules(array $options = []): PricingRules
	{
		return new PricingRules(option('wagnerwagner.merx.pricingRules', $options));
	}

	/**
	 * One-time nonce for the return url, created once per checkout
	 *
	 * `returnUrl()` is called more than once while a payment is set up, so the
	 * nonce is kept in the session and reused until `initializeOrder()` starts
	 * the next checkout.
	 */
	protected static function returnNonce(): string
	{
		$kirby = App::instance();
		$nonce = $kirby->session()->get(static::$returnNonceSessionKey);

		if (is_string($nonce) === false || $nonce === '') {
			$nonce = bin2hex(random_bytes(16));
			$kirby->session()->set(static::$returnNonceSessionKey, $nonce);
		}

		return $nonce;
	}

	/**
	 * Url to be used to complete the payment
	 *
	 * Includes the session token, a one-time nonce and the language code (for
	 * multilang pages)
	 *
	 * @return string e.g. https://example.com/api/shop/success?token=1753995556.cefe4a8da2189499186c.476d9b4d2e97335dd1f094d1f696b2618fadb2e4a11e39d7bf64563bc8b650f6&language=de
	 */
	public static function returnUrl(): string
	{
		$kirby = App::instance();
		$apiEndpint = $kirby->url('api') . '/' . $kirby->option('wagnerwagner.merx.api.endpoint', 'shop') . '/success';
		// Creating the nonce writes to the session, which starts it. Without that
		// `token()` below is null until something else has written to it.
		$nonce = static::returnNonce();

		$url = Url::build([
			'query' => [
				Merx::$sessionTokenParameterName => $kirby->session()->token(),
				Merx::$returnNonceParameterName => $nonce,
				'language' => $kirby->multilang() ? $kirby->currentLanguage()->code() : null,
			],
		], $apiEndpint);
		return $url;
	}

	/**
	 * Gets the tax rule by its key
	 */
	public static function taxRule(string|null $key): TaxRule|null
	{
		$taxRules = self::taxRules();
		return $taxRules->getRuleByKey($key);
	}

	/**
	 * Gets all tax rules defined by tax rule option
	 */
	public static function taxRules(array $options = []): TaxRules
	{
		return new TaxRules(option('wagnerwagner.merx.taxRules', $options));
	}

	/**
	 * Makes sure a return actually belongs to the checkout stored in $session
	 *
	 * `/api/shop/success` completes the payment on a GET request, and session
	 * cookies are sent with a top-level GET even across sites. Without this a
	 * link on any page could complete a pending payment of a visitor who
	 * approved it at the provider but never returned.
	 *
	 * @throws Exception merx.invalidReturn when the nonce is missing or does not match
	 */
	protected static function validateReturnNonce(Session $session, array $data): void
	{
		$nonce = $session->get(static::$returnNonceSessionKey);

		// Checkouts started before the nonce existed carry none
		if (is_string($nonce) === false || $nonce === '') {
			return;
		}

		$given = $data[static::$returnNonceParameterName] ?? null;

		if (is_string($given) === false || hash_equals($nonce, $given) === false) {
			throw new Exception(
				key: 'merx.invalidReturn',
				httpCode: 400,
			);
		}

		$session->remove(static::$returnNonceSessionKey);
	}
}
