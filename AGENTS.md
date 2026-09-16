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

`composer install --no-dev` removes them again and restores the `vendor/` the
plugin ships — run it once you are done testing. It rewrites the tracked files
under `vendor/composer/` the way your composer version generates them, so check
`git diff vendor/composer` afterwards and discard what is only generated-code
churn (whitespace, `platform_check.php`).

Never clean `vendor/` with `git clean -fd`. It removes the packages’ `.php`
files but keeps their `LICENSE`, `*.md` and `*.json` (those are ignored), which
leaves composer believing the packages are still installed. The next
`composer install` then dies with `Could not scan for classes inside …/lib/
which does not appear to be a file nor a folder`. Delete the named package
directory so composer extracts it again, or use `--no-dev` to clean up.

### The suite is green

`OK (122 tests, 240 assertions)` at the time of writing. A failure is yours.

A test which builds its own `App` has to set `App::$enableWhoops = false`
first. Kirby installs Whoops’ error and exception handlers with every instance
and leaves them behind, which PHPUnit reports as a risky test.

It also has to put the previous instance back:

```php
public function setUp(): void { $this->app = App::instance(); }
public function tearDown(): void { App::instance($this->app); }
```

`App::clone()` makes the clone the global instance, and `option()` reads from
that. A test which clones to set an option therefore leaves its options behind
for every test after it. This is not only untidy: a leftover clone with
`wagnerwagner.merx.production` set sends later tests at the **live** payment
APIs, where the suite stops looking failed and starts looking hung.

`MerxTest::testinitializeOrderEmpty` depends on test order. It passes in a full
run because an earlier test fills the session cart, and fails under `--filter`
with `merx.emptycart`. A filtered run failing there is not a regression.

## Static analysis

```bash
composer analyze:psalm
```

Runs at `errorLevel` 7 (see `psalm.xml`) and currently exits 2 with 51
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

## Design

Merx is a small plugin on top of a framework that already does most of this.
Prefer the boring solution; the burden of proof is on the abstraction.

- **No interfaces, abstract classes or traits in `src/`.** There are currently
  none, on purpose. Two payment providers do not need a `PaymentInterface` —
  `StripePayment` and `PayPalPayment` are plain classes and `Gateways` picks
  between them.
- **Wait for the third case.** Do not factor out a shared base or a strategy
  until three real callers need it. Two similar blocks are cheaper to read than
  one indirection.
- **Use Kirby before writing your own.** `Str`, `Obj`, `Collection`, `Yaml`,
  `Remote`, `A`, field methods, the page and version API. If a helper looks
  generic, it probably exists in `Kirby\Toolkit`.
- **No new dependencies.** The only non-Kirby runtime dependency is
  `stripe/stripe-php`, and `vendor/` ships with the plugin, so every addition
  becomes our download size and our CVE. PayPal talks HTTP through
  `Remote::request()`.
- **No new config options.** The options in `config/config.php` are credentials
  and hooks into shop-specific logic, not switches. Pick a sensible default
  instead of adding one; every option is a supported combination forever.
- **New code goes in an existing class** unless it clearly owns new state. A
  method on `Cart` beats a `CartQuantityValidator`.
- **Delete instead of deprecating.** v2 is a breaking release; it is the moment
  to remove things, not to carry a compatibility shim forever.

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
