<?php

namespace Wagnerwagner\Merx;

use Wagnerwagner\Merx\Gateways;
use Wagnerwagner\Merx\Cart;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\V;
use Kirby\Exception\Exception;
use Kirby\Cms\Field;
use Kirby\Data\Yaml;
use OrderPage;

class Merx
{
    protected $cart;
    protected $gateways = [];

    public function __construct()
    {
        $this->cart = new Cart();
        $this->gateways = array_merge(Gateways::$gateways, option('ww.merx.gateways', []));
    }


    /**
     * Localizes the price. Currency symbol is shown before or after price based on arguments, options or `localeconv`.
     *
     * @param float $price
     * @param bool $currencyPositionPrecedes `true` if currency symbol precedes, `false` if it succeeds one
     * @param bool $currencySeparateBySpace `true` if a space separates currency_symbol, `false` otherwise
     *
     * @return string
     */
    public static function formatPrice(float $price, bool $currencyPositionPrecedes = null, bool $currencySeparateBySpace = null): string
    {
        // set locale for single language installations
        if (!option('languages', false) && option('locale', false)) {
            \Kirby\Toolkit\Locale::set(option('locale'));
        }

        $localeFormatting = localeconv();
        if ($currencyPositionPrecedes === null) {
            $currencyPositionPrecedes = option('ww.merx.currencyPositionPrecedes', $localeFormatting['p_cs_precedes']);
        }
        if ($currencySeparateBySpace === null) {
            $currencySeparateBySpace = option('ww.merx.currencySeparateBySpace', $localeFormatting['p_sep_by_space']);
        }

        $string = '';
        if ($currencyPositionPrecedes) {
            $string .= option('ww.merx.currencySymbol', '€');
            if ($currencySeparateBySpace) {
                $string .= ' '; // non breaking space
            }
        }
        if (option('ww.merx.currencyDecimalPoint', false)) {
            $localeFormatting['decimal_point'] = option('ww.merx.currencyDecimalPoint');
        }
        if (option('ww.merx.currencyThousandsSeparator', false)) {
            $localeFormatting['thousands_sep'] = option('ww.merx.currencyThousandsSeparator');
        }
        $string .= number_format($price, 2, $localeFormatting['decimal_point'] ?? '.', $localeFormatting['thousands_sep'] ?? ',');
        if (!$currencyPositionPrecedes) {
            if ($currencySeparateBySpace) {
                $string .= ' '; // non breaking space
            }
            $string .= option('ww.merx.currencySymbol', '€');
        }
        return $string;
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
     * @return string `option('ww.merx.successPage')` or result of `initializePayment()` of `paymentMethod` gateway.
     */
    public function initializePayment(array $data): string
    {
        try {
            $redirect = option('ww.merx.successPage');

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
            $data['items'] = yaml::encode($cart->values());

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
            $licenseArr = Str::split(Str::after(option('ww.merx.license', ''), 'MERX-'), '-');
            if (option('ww.merx.production') && crossfoot(hexdec($licenseArr[0])) + crossfoot(hexdec($licenseArr[1])) !== 90) {
                throw new Exception('Invalid License');
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


    public static function setMessage($message)
    {
        kirby()->session()->set('ww.merx.message', $message);
    }


    /**
     * Returns and removes message stored by `Merx::setMessage()`.
     *
     * @return null|mixed
     */
    public static function getMessage()
    {
        $messageSession = kirby()->session()->get('ww.merx.message');
        if ($messageSession) {
            kirby()->session()->remove('ww.merx.message');
            return $messageSession;
        } else {
            return null;
        }
    }


    public static function getFieldError(Field $field, array $rules): array
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
}
