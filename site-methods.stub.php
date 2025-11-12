<?php

namespace Kirby\Cms;

/**
 * @method \Wagnerwagner\Merx\Cart cart() The cart instance
 * @method ?\Kirby\Cms\Page checkoutPage() The checkout page. Finds the first child page with the template 'checkout'
 * @method \Wagnerwagner\Merx\Merx merx() The Merx instance
 * @method ?\Kirby\Cms\Page ordersPage() The orders page as defined in the config with the option 'ww.merx.ordersPage'
 * @method \Wagnerwagner\Merx\PricingRules pricingRules() All pricing rules defined in the config
 * @method \Wagnerwagner\Merx\TaxRules taxRules() All tax rules defined in the config
 */
class Site extends \Kirby\Cms\Site {}
