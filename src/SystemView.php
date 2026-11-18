<?php

namespace Wagnerwagner\Merx;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Role;
use Kirby\Cms\User;
use Kirby\Guards\PageGuards;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\Str;
use Stripe\Account;
use Throwable;

/**
 * Merx’ Panel view: security checks, options, rules and the keys in use
 *
 * Only admins see it. Keys are shown with their last four characters, which
 * is enough to tell them apart and not enough to use them.
 *
 * @internal
 */
class SystemView
{
	/**
	 * What Stripe and PayPal say about the keys in use
	 *
	 * Stripe names the account of its secret key. PayPal names no account for
	 * a client ID and secret, only whether they are valid. A gateway which is
	 * switched off or has no keys is not asked.
	 *
	 * @return array<'stripe'|'paypal', array{ok: bool, note: string}>
	 */
	public static function accounts(): array
	{
		$production = option('wagnerwagner.merx.production') === true;
		$gateways = Gateways::enabled();
		$accounts = [];

		$stripeKey = (string)option('wagnerwagner.merx.stripe.' . ($production ? 'live' : 'test') . '.secret_key');
		if (isset($gateways['stripe']) && $stripeKey !== '') {
			try {
				$account = Account::retrieve(null, ['api_key' => $stripeKey])->toArray();
				$name = $account['business_profile']['name'] ?? $account['settings']['dashboard']['display_name'] ?? $account['id'];
				$accounts['stripe'] = ['ok' => true, 'note' => Str::template(Escape::html(t('merx.system.account.stripe')), [
					'name' => Escape::html((string)$name),
					'id' => '<a href="https://dashboard.stripe.com/' . Escape::html($account['id']) . '" target="_blank"><code>' . Escape::html($account['id']) . '</code></a>',
				])];
			} catch (Throwable) {
				$accounts['stripe'] = ['ok' => false, 'note' => Escape::html(t('merx.system.account.invalid'))];
			}
		}

		$paypal = $production ? 'live' : 'sandbox';
		if (
			isset($gateways['paypal']) &&
			(string)option('wagnerwagner.merx.paypal.' . $paypal . '.clientID') !== '' &&
			(string)option('wagnerwagner.merx.paypal.' . $paypal . '.secret') !== ''
		) {
			try {
				PayPalPayment::getAccessToken();
				$accounts['paypal'] = ['ok' => true, 'note' => Escape::html(t('merx.system.account.valid'))];
			} catch (Throwable) {
				$accounts['paypal'] = ['ok' => false, 'note' => Escape::html(t('merx.system.account.invalid'))];
			}
		}

		return $accounts;
	}

	/**
	 * What the browser checks, as a visitor: robots.txt and sitemap.xml
	 *
	 * Requests from the server to its own domain hang on a server which runs
	 * one request at a time, and miss a proxy in front of it. Kirby checks
	 * exposed folders in its system view from the browser for the same reason.
	 * Each check carries the item to show when it fails.
	 */
	public static function browserChecks(): array
	{
		$kirby = App::instance();
		$ordersPage = $kirby->site()->ordersPage();

		if ($ordersPage === null) {
			return [];
		}

		$ordersUrl = $ordersPage->url();

		return [
			'robots' => [
				'url' => $kirby->url('index') . '/robots.txt',
				'path' => '/' . trim((string)parse_url($ordersUrl, PHP_URL_PATH), '/') . '/',
				'missing' => static::check(id: 'robots', ok: false, text: t('merx.system.check.robots.missing'), themeFailed: 'notice'),
				'orders' => static::check(id: 'robots', ok: false, text: t('merx.system.check.robots.orders'), themeFailed: 'notice'),
			],
			'sitemap' => [
				'url' => $kirby->url('index') . '/sitemap.xml',
				'prefix' => $ordersUrl . '/',
				'orders' => static::check(id: 'sitemap', ok: false, text: t('merx.system.check.sitemap')),
			],
		];
	}

	protected static function check(
		string $id,
		bool $ok,
		string $text,
		string $themeOk = 'positive',
		string $themeFailed = 'negative',
	): array {
		$theme = $ok ? $themeOk : $themeFailed;

		return [
			'id' => $id,
			'text' => $text,
			'theme' => $theme,
			// A problem links to what to do about it
			...($ok ? [] : ['link' => 'https://merx.wagnerwagner.de/guide/security', 'target' => '_blank']),
			'image' => [
				'back' => 'light-dark(var(--theme-color-200), var(--theme-color-900))',
				'icon' => match ($theme) {
					'positive' => 'check',
					'info' => 'info',
					default => 'alert',
				},
				'color' => 'var(--theme-color-icon)',
			],
		];
	}

	/**
	 * The keys Merx uses right now, with their last four characters
	 *
	 * @param $accounts What `accounts()` returns, asked for once per view
	 * @return array<array{option: string, value: string}>
	 */
	public static function keys(array|null $accounts = null): array
	{
		$production = option('wagnerwagner.merx.production') === true;
		$stripe = $production ? 'live' : 'test';
		$paypal = $production ? 'live' : 'sandbox';

		$options = [
			'stripe.' . $stripe . '.publishable_key',
			'stripe.' . $stripe . '.secret_key',
			'stripe.webhook_signing_secret',
			'paypal.' . $paypal . '.clientID',
			'paypal.' . $paypal . '.secret',
		];

		$accounts ??= static::accounts();
		$notes = [
			'stripe.' . $stripe . '.secret_key' => $accounts['stripe']['note'] ?? '',
			'paypal.' . $paypal . '.secret' => $accounts['paypal']['note'] ?? '',
		];

		return array_map(function (string $option) use ($notes): array {
			$key = (string)option('wagnerwagner.merx.' . $option);

			return [
				'option' => $option,
				'value' => $key === '' ? '–' : '••••' . Str::substr($key, -4),
				// `<note>`: the Panel trusts it as HTML and hands it on as `note`
				'<note>' => $notes[$option] ?? '',
			];
		}, $options);
	}

	/**
	 * The newest entries of Merx’ log, newest first, each as a whole
	 *
	 * @return array<array{time: string, level: string, type: string|null, message: string}>
	 */
	public static function logs(int $limit = 10): array
	{
		$files = glob(App::instance()->root('logs') . '/*' . Logger::$appendix . '.log') ?: [];
		rsort($files);

		$logs = [];
		foreach ($files as $file) {
			$entries = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}T)/m', (string)file_get_contents($file), -1, PREG_SPLIT_NO_EMPTY);

			foreach (array_reverse($entries) as $entry) {
				if (preg_match('/^\[([^\]]+)\] (\w+) (.*)$/s', trim($entry), $match) !== 1) {
					continue;
				}

				[, $time, $level, $message] = $match;

				$level = strtolower($level);
				$logs[] = [
					'time' => date('Y-m-d H:i:s', strtotime($time)),
					'level' => $level,
					// The color of the level’s code block, none for `info`
					'type' => match ($level) {
						'debug' => 'gray',
						'info' => null,
						'notice' => 'aqua',
						'warning' => 'yellow',
						'error' => 'orange',
						default => 'red',
					},
					'message' => $message,
				];

				if (count($logs) === $limit) {
					return $logs;
				}
			}
		}

		return $logs;
	}

	/**
	 * The pricing rules with key, name and currency
	 */
	public static function pricingRules(): array
	{
		return array_values(array_map(fn (PricingRule $rule): array => [
			'key' => $rule->key,
			'name' => $rule->name,
			'currency' => (string)$rule->currency,
		], Merx::pricingRules()->values()));
	}

	/**
	 * Props of the `k-merx-view` component: the sections of the view
	 *
	 * The component only renders them, so the view is built here.
	 */
	public static function props(): array
	{
		// The first column holds the row headers. `code` values are a string
		// or a list, `html` cells are HTML.
		// `$widths` of other columns than the first, which is 24ch unless given
		$table = function (array $columns, array $rows, array $types = [], array $widths = []): array {
			$widths += [array_key_first($columns) => '24ch'];
			foreach ($columns as $key => $label) {
				$columns[$key] = ['label' => $label, 'type' => $types[$key] ?? 'text', 'width' => $widths[$key] ?? null];
			}

			return ['columns' => $columns, 'rows' => $rows];
		};

		$accounts = static::accounts();

		return [
			'checks' => static::browserChecks(),
			'sections' => [
				['id' => 'security', 'headline' => t('merx.system.security'), 'items' => static::security($accounts)],
				['headline' => t('merx.system.settings'), ...$table(
					['option' => t('merx.system.option'), 'value' => t('merx.system.value'), 'note' => t('merx.system.note')],
					static::settings(),
					['value' => 'code', 'note' => 'html'],
					['value' => '40ch'],
				)],
				// Side by side
				['grid' => [
					['headline' => t('merx.system.pricingRules'), ...$table(
						['key' => 'key', 'name' => 'name', 'currency' => 'currency'],
						static::pricingRules(),
					)],
					['headline' => t('merx.system.taxRules'), ...$table(
						['key' => 'key', 'name' => 'name', 'rate' => 'rate'],
						static::taxRules(),
					)],
				]],
				['headline' => t('merx.system.keys'), ...$table(
					['option' => t('merx.system.option'), 'value' => t('merx.system.keys.value'), 'note' => t('merx.system.note')],
					static::keys($accounts),
					['note' => 'html'],
					['option' => '40ch', 'value' => '20ch'],
				)],
				['headline' => t('merx.system.log'), ...$table(
					['time' => t('merx.system.log.date'), 'level' => t('merx.system.log.level'), 'message' => t('merx.system.log.message')],
					static::logs(),
					['level' => 'code', 'message' => 'pre'],
					['level' => '20ch'],
				)],
			],
		];
	}

	/**
	 * Whether users of a role may list orders
	 *
	 * What `Page::isListable()` checks for the current user, for a user of the
	 * role who does not have to exist.
	 */
	protected static function roleMayListOrders(Role $role): bool
	{
		$user = new User(['id' => 'merx-role-check', 'role' => $role->id()]);
		$guards = new PageGuards(new OrderPage(['slug' => 'order', 'template' => 'order']), $user);

		return $guards->isAvailable('read') && $guards->isAvailable('access') && $guards->isAvailable('list');
	}

	/**
	 * Results of the security checks, as items of `k-items`
	 *
	 * @param $accounts What `accounts()` returns, asked for once per view
	 */
	public static function security(array|null $accounts = null): array
	{
		$kirby = App::instance();
		$production = option('wagnerwagner.merx.production') === true;
		$checks = [];

		// Who may see the list of all orders
		$roles = [];
		$frontendRoles = [];
		foreach ($kirby->roles() as $role) {
			if ($role->isNobody() || static::roleMayListOrders($role) === false) {
				continue;
			}
			$roles[] = $role->title();
			if ($role->permissions()->for('access', 'panel') !== true) {
				$frontendRoles[] = $role->title();
			}
		}
		$checks[] = static::check(
			id: 'roles',
			ok: count($roles) <= 1,
			text: tt('merx.system.check.roles', ['roles' => implode(', ', $roles)]),
			themeOk: 'info',
			themeFailed: 'notice',
		);

		if ($frontendRoles !== []) {
			$checks[] = static::check(
				id: 'frontendRoles',
				ok: false,
				text: tt('merx.system.check.frontendRoles', ['roles' => implode(', ', $frontendRoles)]),
			);
		}

		if (static::visitorsCannotListOrders() === false) {
			$checks[] = static::check(id: 'visitors', ok: false, text: t('merx.system.check.visitors'));
		}

		if ($production === false && $kirby->system()->isLocal() === false) {
			$checks[] = static::check(id: 'testMode', ok: false, text: t('merx.system.check.testMode'));
		}

		$liveKeys = (string)option('wagnerwagner.merx.stripe.live.secret_key') . ' ' . (string)option('wagnerwagner.merx.stripe.live.publishable_key');
		if ($production === true && Str::contains($liveKeys, '_test_')) {
			$checks[] = static::check(id: 'testKeys', ok: false, text: t('merx.system.check.testKeys'));
		}

		$stripeKey = (string)option('wagnerwagner.merx.stripe.' . ($production ? 'live' : 'test') . '.secret_key');
		if ($stripeKey !== '' && (string)option('wagnerwagner.merx.stripe.webhook_signing_secret') === '') {
			$checks[] = static::check(id: 'webhook', ok: false, text: t('merx.system.check.webhook'), themeFailed: 'notice');
		}

		foreach ($accounts ?? static::accounts() as $provider => $account) {
			if ($account['ok'] === false) {
				$checks[] = static::check(id: $provider . 'Keys', ok: false, text: t('merx.system.check.' . $provider . 'Keys'));
			}
		}

		return $checks;
	}

	/**
	 * Merx’ options as they are set, with a note where one differs from its default
	 *
	 * `type` colors booleans like Kirby’s code tokens, other values keep the
	 * default code color; a list is one code block per entry. Every option has
	 * a note, as HTML. The orders page has a `link`.
	 *
	 * @return array<array{option: string, value: string|list<string>, type: string|null, '<note>': string, link?: string}>
	 */
	public static function settings(): array
	{
		$kirby = App::instance();
		$defaults = $kirby->plugin('wagnerwagner/merx')?->extends()['options'] ?? [];
		$isChanged = fn (string $option): bool => option('wagnerwagner.merx.' . $option) !== ($defaults[$option] ?? null);
		$boolean = fn (string $option): string => option('wagnerwagner.merx.' . $option) === true ? 'true' : 'false';
		// A translation as HTML, with HTML in its placeholders
		$html = fn (string $key, array $data = []): string => Str::template(Escape::html(t($key)), $data);
		$code = fn (string $value): string => '<code>' . Escape::html($value) . '</code>';

		$ordersPage = $kirby->site()->ordersPage();
		$ordersPageOption = (string)option('wagnerwagner.merx.ordersPage');
		$endpoint = (string)option('wagnerwagner.merx.api.endpoint');
		$ownGateways = array_keys(array_filter((array)option('wagnerwagner.merx.gateways'), fn ($gateway) => $gateway !== false));

		$production = option('wagnerwagner.merx.production') === true;
		$logging = option('wagnerwagner.merx.logging') === true;
		$reduceStock = option('wagnerwagner.merx.reduceStock') === true;

		$logs = $kirby->root('logs');
		$base = $kirby->root('base') ?? $kirby->root('index');
		$logFile = Str::startsWith($logs, $base . '/') ? Str::after($logs, $base . '/') : $logs;
		$logFile .= '/YYYY-MM-DD' . Logger::$appendix . '.log';

		if ($ordersPage !== null) {
			$ordersNote = $html('merx.system.note.ordersPage.count', [
				'orders' => $ordersPage->children()->count(),
				'drafts' => $ordersPage->drafts()->count(),
			]);
		} else {
			$ordersNote = $html('merx.system.ordersPage.missing');
		}
		if ($isChanged('ordersPage')) {
			$ordersNote .= ' ' . $html('merx.system.note.ordersPage', ['path' => Escape::html($ordersPageOption)]);
		}

		// In production, an enabled Stripe or PayPal without its live keys can’t take payments
		$missingLiveKeys = '';
		if ($production === true) {
			$liveKeys = [
				'stripe' => ['stripe.live.publishable_key', 'stripe.live.secret_key'],
				'paypal' => ['paypal.live.clientID', 'paypal.live.secret'],
			];
			foreach (array_intersect_key($liveKeys, Gateways::enabled()) as $gateway => $options) {
				$missing = array_filter($options, fn (string $option): bool => (string)option('wagnerwagner.merx.' . $option) === '');
				if ($missing !== []) {
					$missingLiveKeys .= ' ' . $html('merx.system.note.production.missingKeys', [
						'gateway' => $code($gateway),
						'options' => implode(', ', array_map($code, $missing)),
					]);
				}
			}
		}

		$rows = [
			'production' => [
				$boolean('production'),
				$html($production ? 'merx.system.note.production.true' : 'merx.system.note.production.false') . $missingLiveKeys,
				'boolean',
			],
			'logging' => [
				$boolean('logging'),
				$logging ? $html('merx.system.note.logging.true', ['path' => $code($logFile)]) : $html('merx.system.note.logging.false'),
				'boolean',
			],
			'reduceStock' => [
				$boolean('reduceStock'),
				$html($reduceStock ? 'merx.system.note.reduceStock.true' : 'merx.system.note.reduceStock.false'),
				'boolean',
			],
			'ordersPage' => [$ordersPageOption, $ordersNote, null],
			'gateways' => [
				array_keys(Gateways::enabled()),
				$ownGateways === []
					? $html('merx.system.note.gateways.default')
					: $html('merx.system.note.gateways', ['gateways' => implode(', ', array_map($code, $ownGateways))]),
				null,
			],
		];

		// Only worth a row when a shop moved the API
		if ($isChanged('api.endpoint')) {
			$rows['api.endpoint'] = ['/api/' . $endpoint, $html('merx.system.note.endpoint', ['endpoint' => Escape::html($endpoint)]), null];
		}

		$settings = [];
		foreach ($rows as $option => [$value, $note, $type]) {
			$settings[$option] = [
				'option' => $option,
				'value' => $value,
				'type' => $type,
				// `<note>`: the Panel trusts it as HTML and hands it on as `note`
				'<note>' => $note,
			];
		}

		if ($ordersPage !== null) {
			$settings['ordersPage']['value'] = $ordersPage->id();
			$settings['ordersPage']['link'] = $ordersPage->panel()->url(true);
		}

		return array_values($settings);
	}

	/**
	 * The tax rules with key, name and rate
	 */
	public static function taxRules(): array
	{
		return array_values(array_map(fn (TaxRule $rule): array => [
			'key' => $rule->key,
			'name' => $rule->name,
			'rate' => $rule->rate() === null ? '–' : Merx::formatPercent($rule->rate()),
		], Merx::taxRules()->values()));
	}

	/**
	 * Visitors get neither the index of the orders nor the siblings of an order
	 *
	 * Fails as well when the orders or the order pages do not use Merx’ models,
	 * which hold these guards.
	 */
	protected static function visitorsCannotListOrders(): bool
	{
		$kirby = App::instance();
		$ordersPage = $kirby->site()->ordersPage();

		if (is_a(Page::$models['order'] ?? Page::class, OrderPage::class, true) === false) {
			return false;
		}

		if ($ordersPage === null) {
			return true;
		}

		if ($ordersPage instanceof OrdersPage === false) {
			return false;
		}

		return $kirby->impersonate('nobody', function () use ($ordersPage): bool {
			$order = $ordersPage->children()->first();

			return $ordersPage->index(true)->count() === 0 && ($order === null || $order->siblings()->count() === 1);
		});
	}
}
