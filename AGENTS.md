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

`composer test` does **not** work in this repository: `vendor/bin/phpunit` is
missing and `vendor/getkirby/cms` is not installed in full, because
`extra.kirby-cms-path` is `false` and Kirby is provided by the host project.
`tests/bootstrap.php` therefore fails with `Class "Kirby\Cms\App" not found`.

Use a phpunit on your `PATH` and a bootstrap that loads the Kirby of the
surrounding project. Create `tests/bootstrap.local.php` (untracked):

```php
<?php

error_reporting(0);

// Kirby of the surrounding project; vendor/getkirby/cms is not installed in full
require_once dirname(__DIR__, 4) . '/kirby/bootstrap.php';
require_once dirname(__DIR__) . '/index.php';

new Kirby(['roots' => ['index' => __DIR__]]);
```

```bash
phpunit --bootstrap tests/bootstrap.local.php tests
```

Adjust `dirname(__DIR__, 4)` if the plugin does not sit in
`<project>/site/plugins/merx`.

### The suite is not green

Ten tests fail before you change anything. Establish a baseline before you
start and compare against it — do not try to fix these as part of unrelated
work:

- `ApiTest::testCartAdd1`, `testCartGet`, `testCartAddWithQuantity`,
  `testCartPatch`, `testCartDelete` — error with `The site is not accessible`;
  the fixture roots `tests/kirby/content` and `tests/kirby/site` do not exist
- `Tests\ProductPageTest::testOrders` — error
- `ListItemTest::testPriceTotalCalculation`, `MerxTest::testFormatCurrencyDE`,
  `PriceTest::test__toStringReturnsPriceAsString`,
  `TaxRuleTest::testTaxRatePassesKirbyInstance` — locale and calculation
  assertions
- `CartTest` is empty and reports a warning

`MerxTest::testinitializeOrderEmpty` additionally depends on test order. It
passes in a full run because an earlier test fills the session cart, and fails
under `--filter` with `merx.emptycart`. A filtered run failing there is not a
regression.

### Running the suite writes into the repository

Kirby resolves its roots from the index root, so a run creates `tests/site/`
with `sessions/` and `logs/`. That path is **not** in `.gitignore` — only
`/tests/kirby` is. Delete it after a run, or do not stage it; session files
from a payment plugin have no business in the repository.

### Do not use `git worktree` to run tests

`vendor/composer/installed.json` is listed in `.gitignore`, so a fresh worktree
gets an incomplete `vendor/` and phpunit dies with exit 255 part way through.
This happens on a clean `HEAD` too. Verify changes in the real working copy.

## Static analysis

```bash
psalm            # errorLevel 7, see psalm.xml
```

`vendor/bin/psalm` is missing as well, so this needs a psalm on your `PATH`.

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
