<?php

namespace Wagnerwagner\Merx;

use Closure;
use DateTimeImmutable;
use Kirby\Api\Api;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Http\Url;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Locale;
use Kirby\Toolkit\Str;
use NumberFormatter;

/**
 * This is the core of the plugin. The class mainly contains helper methods.
 *
 * Get an instance for the visitor’s cart with the global helper `merx()`.
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class Merx
{
	/**
	 * Session key of the error which sent the customer back to the checkout
	 *
	 * @see Merx::error()
	 */
	public static string $errorSessionKey = 'wagnerwagner.merx.error';

	/**
	 * Session key of the uid of the order draft of the current checkout
	 *
	 * Not used to find the order of a return, which the return url names.
	 * `returnUrl()` needs the uid before the draft is written, and the next
	 * checkout of the session needs it to replace a draft which was left open.
	 */
	public static string $orderDraftSessionKey = 'wagnerwagner.merx.orderDraft';

	/** Query parameter of the return url which names the order */
	public static string $orderParameterName = 'order';

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
		'language',
		'ordernumber',
		'paymentdetails',
		'paypalorderid',
		'redirect',
		'sortingnumber',
		'stripepaymentintentid',
		'uuid',
	];

	protected Cart $cart;

	/**
	 * Custom and default gateways
	 */
	protected array $gateways = [];

	/**
	 * Cache of `NumberFormatter` objects by style, locale and fraction digits
	 */
	protected static array $numberFormatters = [];

	/**
	 * Initializes cart and gateways
	 */
	public function __construct()
	{
		$this->cart = new Cart();
		$this->gateways = Gateways::enabled();
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
	 * Turns the order draft of a checkout into an order
	 *
	 * Calls `completePayment` of the payment gateway, publishes the draft
	 * `initializeOrder()` wrote, lowers the stock of the ordered products while
	 * `wagnerwagner.merx.reduceStock` is true, and deletes the cart.
	 *
	 * The stock is not checked again: the customer may have paid by now, and
	 * an order which is paid has to exist. A product which ran out since the
	 * checkout gets a negative stock, which is logged.
	 *
	 * The draft is found by the `order` of the return url, so a customer may
	 * come back in another browser or after their session ended. The uid is
	 * random and only known to the customer and the payment provider, and a
	 * payment gateway verifies the payment before the order is written.
	 * A return to an order which is completed already gets that order.
	 *
	 * @param $data Query of the return url, and whatever the payment gateway’s `completePayment()` needs
	 * @throws Exception error.merx.invalidReturn when there is no draft for the return
	 */
	public function createOrder(array $data = []): OrderPage
	{
		$kirby = App::instance();

		try {
			$uid = $data[static::$orderParameterName] ?? null;
			$ordersPage = $kirby->site()->ordersPage();

			// The uid ends up in the name of a lock file
			if (is_string($uid) === false || preg_match('/^[a-z0-9]{1,64}$/', $uid) !== 1 || $ordersPage === null) {
				throw new Exception(
					key: 'merx.invalidReturn',
					httpCode: 400,
				);
			}

			// One return per order at a time. A second one, e.g. a reload, waits
			// here and then finds the order instead of the draft.
			$orderPage = static::lock($ordersPage, '.merx-order-' . $uid . '.lock', function () use ($kirby, $ordersPage, $uid, $data): OrderPage {
				$ordersPage->purge();
				$draft = $ordersPage->draft($uid);

				if ($draft instanceof OrderPage === false) {
					$orderPage = $ordersPage->find($uid);

					if ($orderPage instanceof OrderPage) {
						static::useLanguageOf($orderPage);

						// This is the customer returning, with their session, to an
						// order the payment provider’s webhook has completed before
						// them. The webhook has no session, so the cart they ordered
						// is still theirs. Only the session which started the order
						// is emptied, not one which merely opens the return url.
						if ($kirby->session()->get(static::$orderDraftSessionKey) === $uid) {
							$kirby->session()->remove(static::$orderDraftSessionKey);
							$this->cart->delete();
						}

						return $orderPage;
					}

					throw new Exception(
						key: 'merx.invalidReturn',
						httpCode: 400,
					);
				}

				static::useLanguageOf($draft);

				// A copy in memory, which the gateway and the hooks may change
				// freely. The draft is only written once the payment is completed.
				$content = $draft->content($kirby->defaultLanguage()?->code())->toArray();

				$virtualOrderPage = OrderPage::factory([
					'slug' => $uid,
					'template' => 'order',
					'model' => 'order',
					'content' => $content,
					'parent' => $ordersPage,
				]);

				$gateway = $this->getGateway($virtualOrderPage->paymentGateway()->toString());

				$kirby->trigger('wagnerwagner.merx.createOrder:before', ['virtualOrderPage' => $virtualOrderPage, 'gateway' => $gateway, 'data' => $data]);

				// What the gateway returns is written, as with `initializePayment`
				if (is_callable($gateway['completePayment'])) {
					/** @var OrderPage */
					$virtualOrderPage = $gateway['completePayment']($virtualOrderPage, $data);
				}

				$orderPage = static::publishDraft($ordersPage, $draft, $virtualOrderPage);

				// Gateways which complete the payment right away — PayPal, Stripe —
				// create an order which is already paid. This is reported
				// here rather than from `page.create:after`, where the hooks would run
				// while the lock of `publishDraft()` holds back every other checkout.
				//
				// `apply()` rather than `trigger()`: a hook which updates the order
				// leaves the object it was given behind as an outdated copy. Updating
				// that copy again throws, and Kirby removes the order’s content from
				// disk while it does. `apply()` hands every hook, and Merx, the current one.
				//
				// The order is written by now. A hook which fails, e.g. with a mail,
				// is logged: it must not send the customer back to the checkout.
				$apply = function (string $hook) use ($kirby, &$orderPage): void {
					try {
						$orderPage = $kirby->impersonate('kirby', fn () => $kirby->apply($hook, ['orderPage' => $orderPage], 'orderPage'));
					} catch (\Throwable $ex) {
						Logger::log($ex, 'error');
					}
				};

				if ($orderPage->isPaid() === true) {
					$apply('wagnerwagner.merx.orderPaid');
				}

				$kirby->session()->remove(static::$orderDraftSessionKey);
				$this->cart->delete();

				$apply('wagnerwagner.merx.createOrder:after');

				return $orderPage;
			}, true);

			return $orderPage;
		} catch (\Throwable $ex) {
			// Kirby’s exceptions carry a translated message and a status code,
			// also the ones a hook throws to refuse an order, e.g.
			// `InvalidArgumentException`. Everything else is an internal error.
			if ($ex instanceof Exception === false) {
				$ex = new Exception(
					key: 'merx.createOrder',
					httpCode: 500,
					details: static::exceptionDetails($ex),
					previous: $ex,
				);
			}
			// A client error, e.g. a canceled payment, is a warning
			Logger::log($ex, $ex->getHttpCode() < 500 ? 'warning' : 'error');
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
	 * Deletes order drafts which were never completed
	 *
	 * A draft is written with every checkout and published when the customer
	 * returns from the payment. One who never returns leaves it behind, with
	 * their personal data in it.
	 *
	 * @param $threshold Relative date string. Drafts which were not changed since are deleted.
	 */
	public static function deleteOldOrderDrafts(string $threshold = '-1 week'): void
	{
		$kirby = App::instance();
		$ordersPage = $kirby->site()->ordersPage();
		$threshold = (new DateTimeImmutable($threshold))->getTimestamp();

		if ($ordersPage === null) {
			return;
		}

		// Orders are written in the default language. Asked in another one,
		// a draft has no content file and would look infinitely old.
		$languageCode = $kirby->defaultLanguage()?->code();

		$kirby->impersonate('kirby', function () use ($ordersPage, $threshold, $languageCode): void {
			foreach ($ordersPage->drafts() as $draft) {
				$modified = $draft instanceof OrderPage ? $draft->modified(languageCode: $languageCode) : null;

				if (is_int($modified) === true && $modified > 0 && $modified < $threshold) {
					$draft->delete(true);
				}
			}
		});
	}

	/**
	 * Why the customer is back on the checkout page
	 *
	 * When an order cannot be completed after the payment, e.g. because the
	 * customer canceled at PayPal, they are redirected to the checkout page.
	 * This returns the error of that attempt, once: it is removed from the
	 * session as it is read.
	 *
	 * ```php
	 * <?php if ($error = merx()->error()): ?>
	 *   <p role="alert"><?= esc($error['message']) ?></p>
	 * <?php endif ?>
	 * ```
	 *
	 * @return array{key: string|null, message: string}|null Translation key and translated message, or null when there was no error
	 */
	public function error(): array|null
	{
		$error = App::instance()->session()->pull(static::$errorSessionKey);

		return is_array($error) ? $error : null;
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
	 * Keeps the fields of client submitted order data which a customer may fill in
	 *
	 * These are the fields of the order blueprint which Merx does not set
	 * itself. Anything else would be stored as well: Kirby writes every key,
	 * and writes it as its slug, so `datePaid_` would end up as `datePaid`.
	 * Field names are compared in lower case, as Kirby stores them.
	 *
	 * @param $fields The fields of the order blueprint, e.g. `$orderPage->blueprint()->fields()`
	 * @see Merx::$protectedOrderFields
	 */
	public static function filterOrderData(array $data, array $fields): array
	{
		$names = array_map(fn ($name) => Str::lower((string)$name), array_keys($fields));
		$names = array_diff($names, static::$protectedOrderFields);

		return array_filter(
			$data,
			fn ($key) => in_array(Str::lower((string)$key), $names, true),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * Formats a currency number
	 * E.g. 1045.12 → €1,045.12
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
		$formatter = static::numberFormatter(
			$locale,
			$currency === null ? NumberFormatter::DECIMAL : NumberFormatter::CURRENCY,
			$maxFractionDigits,
		);

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

		$formatter = static::numberFormatter($locale, NumberFormatter::PERCENT, $maxFractionDigits);
		$number = $formatter?->format($number) ?? $number;
		return (string)$number;
	}

	private function getGateway(string $paymentGateway): array
	{
		if (!array_key_exists($paymentGateway, $this->gateways)) {
			throw new Exception(
				key: 'merx.noPaymentGateway',
				httpCode: 400,
				details: [
					'paymentGateway' => $paymentGateway,
				],
			);
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

	/**
	 * Get locale with fallback for `C` locale
	 *
	 * `C` is what PHP starts with, before Kirby sets the locale, and what stays
	 * when the configured locale is not installed on the server. Number
	 * formatting refuses it, so the last resort is `en_US`.
	 */
	private static function getLocale(int|string $category = LC_ALL): string
	{
		$isC = fn (string $locale): bool => preg_match('/^(C|POSIX)(\.|$)/', $locale) === 1;

		$locale = Locale::get($category);
		if ($isC($locale) === true) {
			App::instance()->setCurrentLanguage();
			$locale = Locale::get($category);
		}

		return $isC($locale) === true ? 'en_US' : $locale;
	}

	/**
	 * Creates virtual OrderPage and validates it. Runs the payment gateway’s `initializePayment` function. Saves the order as a draft, which `createOrder()` publishes when the customer returns from the payment.
	 *
	 * @param $data The fields of the checkout form: `paymentGateway`, the key of a payment gateway, and the fields of your order blueprint, e.g. `email`. Anything else is dropped, as well as fields Merx sets itself, such as `datePaid`.
	 * @throws Exception error.merx.cart.notOrderable when the cart has an item without a price, prices in two currencies or a negative total
	 * @throws Exception error.merx.cart.stock or error.merx.cart.soldOut when the cart holds more of a product than its stock
	 * @return string The return URL, or the `redirect` that the gateway’s `initializePayment` set on the virtual order page.
	 */
	public function initializeOrder(array $data): string
	{
		$kirby = App::instance();
		$previousUid = null;
		$orderPage = null;

		try {
			// Start a new order for this checkout, so a return url handed out
			// earlier cannot complete this order. A draft of an earlier attempt
			// in this session is replaced by the new one.
			$previousUid = $kirby->session()->pull(static::$orderDraftSessionKey);

			$redirect = $this->returnUrl();

			// Stored as the customer entered it. Escaping belongs to the output:
			// an order is printed as HTML, sent as plain text and exported, and
			// a value escaped here would be escaped a second time by a template.
			$data = array_map(function ($item) {
				return is_string($item) ? Str::trim($item) : $item;
			}, $data);

			// One of the shop’s gateways, or refused right away
			$paymentGateway = $data['paymentGateway'] ?? null;
			$gateway = $this->getGateway(is_string($paymentGateway) ? $paymentGateway : '');

			// Only the fields of the order blueprint. Without this a client could
			// submit `datePaid_` and receive a paid order without paying.
			$fields = OrderPage::factory([
				'slug' => static::orderUid(),
				'template' => 'order',
				'model' => 'order',
				'parent' => $kirby->site()->ordersPage(),
			])->blueprint()->fields();
			$data = static::filterOrderData($data, $fields);
			$data['paymentGateway'] = $paymentGateway;

			// get cart
			$cart = $this->cart;

			// run hook
			$kirby->trigger('wagnerwagner.merx.initializeOrder:before', ['cart' => $cart, 'data' => $data]);

			// check cart
			if ($cart->count() <= 0) {
				throw new Exception(
					key: 'merx.emptycart',
					httpCode: 400,
					data: [
						'cart' => $cart->toArray(),
					],
				);
			}

			// A line without a price, prices in two currencies, e.g. a shipping
			// line added before the customer switched the currency, or a
			// discount which is worth more than the cart
			if ($cart->isOrderable() === false) {
				throw new Exception(
					key: 'merx.cart.notOrderable',
					httpCode: 400,
				);
			}

			// A cart lives in the session for days, while the stock runs out
			$cart->validateStock();

			// add cart to content
			$data['items'] = $cart->toYaml();

			// The language the customer orders in. Neither the return url nor
			// a payment provider’s webhook, which complete the order, have one.
			if ($kirby->multilang() === true) {
				$data['language'] = $kirby->languageCode();
			}

			// create virtual order page. Through the model registry rather than
			// `new OrderPage()`, so the shop’s own `/site/models/order.php`
			// validates the order and is what the gateway and the hooks get.
			$virtualOrderPage = OrderPage::factory([
				'slug' => static::orderUid(),
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
			if (is_callable($gateway['initializePayment'])) {
				/** @var OrderPage */
				$virtualOrderPage = $gateway['initializePayment']($virtualOrderPage, $data);
				if ($virtualOrderPage->redirect()->isNotEmpty()) {
					$redirect = (string)$virtualOrderPage->redirect();
				}
			}
			// Save the order as a draft. Not in the session: the customer may
			// return in another browser, or after their session has ended.
			$content = $virtualOrderPage->content()->toArray();

			// `createChild()` writes the default language, whichever is current
			$orderPage = $kirby->impersonate('kirby', function () use ($virtualOrderPage, $content, $previousUid): OrderPage {
				$ordersPage = static::ordersPage();

				if (is_string($previousUid) === true) {
					$previousDraft = $ordersPage->draft($previousUid);
					if ($previousDraft instanceof OrderPage) {
						$previousDraft->delete(true);
					}
				}

				return $ordersPage->createChild([
					'slug' => $virtualOrderPage->slug(),
					'template' => 'order',
					'model' => 'order',
					'draft' => true,
					'content' => $content,
				]);
			});

			static::deleteOldOrderDrafts();

			// run hook
			$kirby->trigger('wagnerwagner.merx.initializeOrder:after', ['orderPage' => $orderPage, 'redirect' => $redirect]);

			return $redirect;
		} catch (\Throwable $ex) {
			// Kirby’s exceptions carry a translated message and a status code,
			// also the ones a hook throws to refuse an order, e.g.
			// `InvalidArgumentException`. Everything else is an internal error.
			if ($ex instanceof Exception === false) {
				$ex = new Exception(
					key: 'merx.initializeOrder',
					httpCode: 500,
					details: static::exceptionDetails($ex),
					previous: $ex,
				);
			}
			Logger::log($ex, $ex->getHttpCode() < 500 ? 'warning' : 'error');

			// No new draft: the one of the previous attempt stays the session’s,
			// so the next checkout replaces it. Otherwise it would be left behind
			// with the session’s Stripe payment, for the webhook to complete.
			if ($orderPage === null && is_string($previousUid) === true) {
				$kirby->session()->set(static::$orderDraftSessionKey, $previousUid);
			}

			throw $ex;
		}
	}

	/**
	 * The translation for the `locale` option, e.g. `de` for `de_DE.utf-8`
	 *
	 * The full code first, for translations such as `pt_BR`, then the language
	 * alone. Null, which is English, when Kirby has no translation for either.
	 */
	protected static function localeTranslation(): string|null
	{
		$kirby = App::instance();
		$locale = $kirby->option('locale', 'en_US.utf-8');
		if (is_array($locale) === true) {
			$locale = $locale[LC_MESSAGES] ?? $locale[LC_ALL] ?? reset($locale);
		}

		$code = explode('.', (string)$locale)[0];
		foreach (array_unique([$code, preg_split('/[_@-]/', $code)[0]]) as $translation) {
			if ($kirby->translation($translation)->data() !== []) {
				return $translation;
			}
		}

		return null;
	}

	/**
	 * Runs $callback while it holds a lock file in the orders page’s folder
	 *
	 * `.merx-orders.lock` is held while an order gets its number: the default
	 * `orderNumber` counts on from the last order, so two checkouts completing
	 * at the same moment would both read the same one. A lock per order keeps
	 * two returns of the same order from completing the payment twice. Kirby
	 * ignores files with a leading dot.
	 *
	 * The payment has gone through by the time an order is written. When the
	 * lock cannot be taken, the callback runs anyway and the failure is logged.
	 *
	 * @param $remove Removes the lock file afterwards. For locks which are used for one order only.
	 */
	protected static function lock(Page $ordersPage, string $filename, Closure $callback, bool $remove = false): OrderPage
	{
		$lockFile = $ordersPage->root() . '/' . $filename;
		$handle = @fopen($lockFile, 'c');
		$locked = $handle !== false && flock($handle, LOCK_EX);

		if ($locked === false) {
			Logger::log('Could not lock ' . $lockFile . '. The order was written without a lock.', 'error');
		}

		try {
			return $callback();
		} finally {
			if ($handle !== false) {
				flock($handle, LOCK_UN);
				fclose($handle);
			}
			if ($remove === true) {
				@unlink($lockFile);
			}
		}
	}

	/**
	 * Returns (and creates) a number formatter for a locale
	 *
	 * One per style, locale and fraction digits: a formatter which is changed
	 * for one call would keep the change for every later one. A decimal number
	 * is a price without a currency and keeps its two fraction digits, where
	 * `NumberFormatter::DECIMAL` would print 10.20 as `10.2`.
	 *
	 * @param $style `NumberFormatter::CURRENCY`, `DECIMAL` or `PERCENT`
	 */
	protected static function numberFormatter(string $locale, int $style, int|null $maxFractionDigits = null): NumberFormatter|null
	{
		$key = $style . '/' . $locale . '/' . $maxFractionDigits;
		if ($formatter = static::$numberFormatters[$key] ?? null) {
			return $formatter;
		}

		if (class_exists('NumberFormatter') !== true) {
			return null; // @codeCoverageIgnore
		}

		$formatter = new NumberFormatter($locale, $style);
		if ($style === NumberFormatter::DECIMAL) {
			$formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, Price::roundingPrecision);
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, Price::roundingPrecision);
		}
		if (is_int($maxFractionDigits)) {
			$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
		}

		return static::$numberFormatters[$key] = $formatter;
	}

	/**
	 * Orders page, created when the shop gets its first order
	 */
	private static function ordersPage(): Page
	{
		$kirby = App::instance();

		return $kirby->site()->ordersPage() ?? $kirby->site()->createChild([
			'slug' => option('wagnerwagner.merx.ordersPage'),
			'template' => 'orders',
			'draft' => false,
			'content' => [
				'title' => t('field.orders'),
			],
		]);
	}

	/**
	 * Uid of the order of the current checkout
	 *
	 * Known before the order is written, because the return url carries it
	 * and the payment gateway needs that url to set up the payment. Kept in
	 * the session until `initializeOrder()` starts the next checkout.
	 */
	protected static function orderUid(): string
	{
		$kirby = App::instance();
		$uid = $kirby->session()->get(static::$orderDraftSessionKey);

		if (is_string($uid) === false || $uid === '') {
			// Lower case, as Kirby writes a slug
			$uid = Str::lower(Str::random(16, 'alphaNum'));
			$kirby->session()->set(static::$orderDraftSessionKey, $uid);
		}

		return $uid;
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
	public static function pricingRules(): PricingRules
	{
		return new PricingRules(option('wagnerwagner.merx.pricingRules') ?? []);
	}

	/**
	 * Publishes the draft of an order with what its virtual order page holds
	 *
	 * In the default language, which orders are written in, and inside
	 * `.merx-orders.lock`: the order gets its number and lowers the stock, and
	 * another checkout must not do the same in between.
	 */
	protected static function publishDraft(Page $ordersPage, OrderPage $draft, OrderPage $virtualOrderPage): OrderPage
	{
		$kirby = App::instance();

		// Set to default language to make sure content is saved in default language
		$currentLanguageCode = null;
		$currentTranslationCode = I18n::locale();
		if ($kirby->multilang()) {
			$currentLanguageCode = $kirby->languageCode();
			$kirby->setCurrentLanguage($kirby->defaultLanguage()->code());
		}

		try {
			// Publishing the order requires admin permissions. The callback makes sure the
			// impersonation is always reset, even when an error occurs. Without it the
			// `kirby` super user would stay active for the rest of the request.
			return $kirby->impersonate('kirby', function () use ($ordersPage, $draft, $virtualOrderPage): OrderPage {
				return static::lock($ordersPage, '.merx-orders.lock', function () use ($draft, $virtualOrderPage): OrderPage {
					// `orderNumber` reads the orders through the virtual order page’s parent.
					// They may have been read before the lock was taken, and another
					// checkout may have written one since.
					$virtualOrderPage->parentModel()->purge();

					$content = $virtualOrderPage->content()->toArray();

					// Taken inside the lock, so the orders sort in the order they got their number
					$now = new DateTimeImmutable();
					$content['datecreated'] = $now->format('c');
					// As a string: Kirby writes an int as a float, which keeps 14 digits of the 17
					$content['sortingnumber'] = (string)OrderPage::sortingNumberFromDate($now);

					if (is_callable(option('wagnerwagner.merx.orderNumber'))) {
						$content['ordernumber'] = option('wagnerwagner.merx.orderNumber')($virtualOrderPage);
					}

					/** @var OrderPage $orderPage */
					$orderPage = $draft->save($content, null, true);
					$orderPage = $orderPage->changeStatus('listed');

					// Inside the lock, so two orders cannot both lower the same stock
					// from the same number.
					if (option('wagnerwagner.merx.reduceStock') === true) {
						$orderPage->cart()->reduceStock();
					}

					return $orderPage;
				});
			});
		} finally {
			// Back to the language of the customer, for the hooks and
			// the error messages. The translation as well: for the sorting
			// number of the order Kirby clones the App, which sets the
			// translation back to the default language.
			$kirby->setCurrentLanguage($currentLanguageCode);
			$kirby->setCurrentTranslation($currentTranslationCode);
		}
	}

	/**
	 * Url to be used to complete the payment
	 *
	 * Includes the uid of the order. Nothing in it identifies the customer’s
	 * session.
	 *
	 * @return string e.g. https://example.com/api/shop/success?order=k3m9x1p0q7w2e5r8
	 */
	public static function returnUrl(): string
	{
		$kirby = App::instance();
		$apiEndpint = $kirby->url('api') . '/' . $kirby->option('wagnerwagner.merx.api.endpoint', 'shop') . '/success';
		$url = Url::build([
			'query' => [
				Merx::$orderParameterName => static::orderUid(),
			],
		], $apiEndpint);
		return $url;
	}

	/**
	 * Sets the translation of an API request
	 *
	 * The requested language (`X-Language` header or `language` query),
	 * otherwise the current language of the site, which is the default one.
	 * A site without languages has none, so its `locale` option decides.
	 *
	 * @internal
	 */
	public static function setApiTranslation(Api $api): void
	{
		$kirby = App::instance();
		$kirby->setCurrentTranslation($api->language() ?? $kirby->language()?->code() ?? static::localeTranslation());
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
	public static function taxRules(): TaxRules
	{
		return new TaxRules(option('wagnerwagner.merx.taxRules') ?? []);
	}

	/**
	 * Switches to the language the customer ordered in
	 *
	 * The return url and a payment provider’s webhook have no language. The
	 * hooks, error messages and the url of the order use the one of the order.
	 */
	protected static function useLanguageOf(OrderPage $orderPage): void
	{
		$kirby = App::instance();
		$languageCode = $orderPage->content($kirby->defaultLanguage()?->code())->get('language')->value();

		if (is_string($languageCode) === true && $kirby->languages()->has($languageCode) === true) {
			$kirby->setCurrentLanguage($languageCode);
			$kirby->setCurrentTranslation($languageCode);
		}
	}
}
