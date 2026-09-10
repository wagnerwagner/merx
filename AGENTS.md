# Working on Merx

Merx is a commercial Kirby 5 plugin that adds a cart, checkout, orders and
payments (Stripe, PayPal) to a Kirby site. This file is for agents and
developers working **on the plugin**. It is not user documentation.

User documentation lives at <https://merx.wagnerwagner.de> and is indexed for
LLMs at <https://merx.wagnerwagner.de/llms.txt>. Every page there has a
Markdown version — append `.md` to its URL. Read those instead of guessing at
the public API. Methods Merx inherits from Kirby are documented at
<https://getkirby.com/llms.txt>.

## Layout

| Path | Contents |
| --- | --- |
| `src/` | Classes, namespace `Wagnerwagner\Merx\`, PSR-4 |
| `api/` | Kirby API routes, models, collections. All routes are `auth: false` |
| `blueprints/` | Panel blueprints for orders, products and shop settings |
| `config/` | Default options (`config.php`) and hook definitions (`hooks.php`) |
| `translations/` | `en.php` and `de.php` |
| `templates/`, `models/` | Order and success templates, page models |
| `index.php` | Plugin registration, helper functions `merx()` and `cart()` |
| `vendor/` | Committed on purpose — the plugin ships its dependencies |

## Running the tests

```bash
composer install   # dev dependencies: phpunit, getkirby/cms, psalm
composer test
```

`vendor/` is committed, but **without** dev dependencies, so a fresh clone has
no `vendor/bin/phpunit` and no `vendor/getkirby/cms/src`. `composer install`
installs both and `composer test` then runs.

### `composer install` dirties the working tree

Because `vendor/` is tracked, installing dev dependencies leaves around forty
untracked directories under `vendor/` plus modified `vendor/composer/autoload_*`
and `installed.php`. **Never stage them** — `git add -A` would commit phpunit,
psalm and Kirby into the distributed plugin. Stage the files you actually
changed, by name.

### The suite is not green

Four tests fail before you change anything, plus one warning and three risky
tests. Compare against this baseline rather than assuming you broke something:

- `ListItemTest::testPriceTotalCalculation`, `MerxTest::testFormatCurrencyDE`,
  `PriceTest::test__toStringReturnsPriceAsString`,
  `TaxRuleTest::testTaxRatePassesKirbyInstance` — locale and calculation
  assertions
- `CartTest` is empty and reports a warning
- `Tests\ProductPageTest::testPrice`, `testPrices`, `testOrders` — risky; they
  leave their own error and exception handlers installed

At the time of writing that is `Tests: 72, Assertions: 151, Failures: 4,
PHPUnit Warnings: 1, PHPUnit Deprecations: 1, Risky: 3`.

`MerxTest::testinitializeOrderEmpty` additionally depends on test order. It
passes in a full run because an earlier test fills the session cart, and fails
under `--filter` with `merx.emptycart`. A filtered run failing there is not a
regression.

## Static analysis

```bash
composer analyze:psalm
```

Runs at `errorLevel` 7 (see `psalm.xml`) and currently exits 2 with 53
pre-existing issues, 31 of them `UnusedClosureParam`. Check that your change
does not add new ones rather than expecting a clean run.

## Conventions

- **Tabs** for indentation in PHP, spaces in YAML. See `.editorconfig`.
- **Named arguments** when throwing Kirby exceptions:
  ```php
  throw new Exception(
      key: 'merx.cart.maxQuantity',
      httpCode: 400,
      data: ['title' => $listItem->title],
  );
  ```
- **Every error key needs both translations.** A `key: 'merx.foo'` needs
  `error.merx.foo` in `translations/en.php` *and* `translations/de.php`. The
  files are sorted alphabetically. The same goes for `field.*` keys used as
  blueprint labels.
- **Never return provider payloads or internals to the caller.** The API routes
  are unauthenticated. Stripe and PayPal responses carry customer data and, in
  Stripe's case, the PaymentIntent's `client_secret`. Log them and return a key
  plus an HTTP code. `Merx::exceptionDetails()` exposes `file`/`line` only while
  Kirby's `debug` is on.
- **Payment records go through `PaymentDetails`.** Never write a raw provider
  response into `paymentDetails`; use `PaymentDetails::create()` or one of its
  provider methods, and encode the result as YAML.

## Gotchas

- **Kirby lower cases content keys.** `paymentComplete` and `PaymentComplete`
  address the same field, so anything filtering client input must compare
  case-insensitively. See `Merx::$protectedOrderFields`.
- **`paymentDetails` is a textarea field**, so its value must be a string.
  `Page::update()` runs through Kirby's form layer and throws
  `TypeError: Invalid value for "value"` when handed an array.
  `Version::update()` and `createChild()` bypass that layer and accept arrays,
  which is why a mistake here only breaks the Stripe webhook.
- **Impersonation needs the callback form.** `$kirby->impersonate('kirby')`
  without a closure leaves the super user active for the rest of the request.
  Always pass the callback so Kirby resets it, including on exceptions.
- **Virtual order pages** live in the session between `initializeOrder()` and
  `createOrder()`. Content written before `createOrder()` uses
  `$virtualOrderPage->version()->update()`.
- The plugin is proprietary and checks a license key; see `src/License.php`.
